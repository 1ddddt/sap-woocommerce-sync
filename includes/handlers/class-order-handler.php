<?php
/**
 * Order Handler - WooCommerce order lifecycle hooks
 *
 * Listens to WooCommerce order events and enqueues them for async processing.
 * All SAP operations happen via the queue — this handler never calls SAP directly.
 *
 * Best practices applied:
 * - Queue-first: enqueue events instead of direct SAP calls
 * - SAP is SSOT: WooCommerce stock reduction is prevented
 * - Config constants: no hardcoded option keys
 * - Repository pattern: no direct $wpdb calls
 * - Idempotent: safe to process duplicate events
 *
 * @package SAP_WooCommerce_Sync
 * @since 2.0.0
 */

namespace SAPWCSync\Handlers;

use SAPWCSync\Constants\Config;
use SAPWCSync\Constants\Sync_Status;
use SAPWCSync\Helpers\Logger;
use SAPWCSync\Queue\Queue_Manager;
use SAPWCSync\Repositories\Order_Map_Repository;

defined('ABSPATH') || exit;

class Order_Handler
{
    private $logger;
    private $queue;
    private $order_repo;

    public function __construct()
    {
        $this->logger = new Logger();
        $this->queue = new Queue_Manager();
        $this->order_repo = new Order_Map_Repository();
    }

    /**
     * Register all WooCommerce hooks.
     */
    public function register_hooks(): void
    {
        // Order creation (classic + block checkout)
        add_action('woocommerce_checkout_order_processed', [$this, 'on_order_created'], 10, 1);
        add_action('woocommerce_store_api_checkout_order_processed', [$this, 'on_order_created'], 10, 1);

        // Order status transitions
        add_action('woocommerce_order_status_completed', [$this, 'on_order_completed'], 10, 1);
        add_action('woocommerce_order_status_cancelled', [$this, 'on_order_cancelled'], 10, 1);

        // Refunds
        add_action('woocommerce_order_refunded', [$this, 'on_order_refunded'], 10, 2);

        // Deferred stock refresh (fires after SAP async committed count update)
        add_action(Config::CRON_DEFERRED_STOCK, [$this, 'deferred_stock_refresh'], 10, 1);

        // Prevent WooCommerce from reducing stock — SAP is the single source of truth.
        // SAP tracks committed quantities; our sync fetches Available = InStock - Committed.
        // If WC also reduces stock, it causes double-counting.
        add_filter('woocommerce_can_reduce_order_stock', [$this, 'prevent_stock_reduction'], 10, 2);
    }

    /**
     * Handle new order -> Enqueue for async SAP processing.
     *
     * Instead of calling SAP directly (which can fail and break checkout),
     * we enqueue an order.placed event. The Event_Processor handles SAP sync
     * asynchronously with automatic retry on failure.
     *
     * @param int|\WC_Order $order_id Order ID or order object (block checkout passes object)
     */
    public function on_order_created($order_id): void
    {
        if (Config::get(Config::OPT_ENABLE_ORDER_SYNC) !== 'yes') {
            return;
        }

        // Handle both order ID and order object (block checkout compatibility)
        if (is_object($order_id)) {
            $order_id = $order_id->get_id();
        }

        $order_id = (int) $order_id;

        // Guard: don't enqueue if already synced
        if ($this->order_repo->is_synced($order_id)) {
            return;
        }

        // Create initial mapping record (pending state)
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $is_cod = in_array($order->get_payment_method(), ['cod', 'cashondelivery'], true);

        $this->order_repo->upsert($order_id, [
            'payment_type' => $is_cod ? 'cod' : 'prepaid',
            'sync_status' => Sync_Status::PENDING,
        ]);

        // Enqueue for async processing (priority 1 = highest)
        $this->queue->enqueue('order.placed', 'wc', [
            'order_id' => $order_id,
        ], 1);

        $this->logger->info("Order #{$order_id} enqueued for SAP sync", [
            'entity_type' => Sync_Status::ENTITY_ORDER,
            'entity_id' => $order_id,
        ]);

        // If immediate sync is enabled, trigger queue processing now
        // instead of waiting for cron (5-minute interval)
        if (Config::get(Config::OPT_IMMEDIATE_SYNC) === 'yes') {
            $this->logger->info("Order #{$order_id}: Immediate sync enabled, processing queue now");

            // Process the queue in background via async request
            // This prevents blocking the checkout process
            wp_remote_post(admin_url('admin-ajax.php'), [
                'timeout' => 0.01,
                'blocking' => false,
                'body' => [
                    'action' => 'sap_wc_process_queue_immediate',
                ],
            ]);
        }

        // Schedule deferred stock refresh (60s later) to catch SAP's
        // async committed count update after the SO is created
        wp_schedule_single_event(time() + 60, Config::CRON_DEFERRED_STOCK, [$order_id]);
    }

    /**
     * Handle order completed -> Enqueue delivery note creation.
     *
     * When WC order is marked "completed", we need to create:
     * 1. Delivery Note (from Sales Order)
     * 2. AR Invoice (from Delivery Note)
     */
    public function on_order_completed($order_id): void
    {
        if (Config::get(Config::OPT_ENABLE_ORDER_SYNC) !== 'yes') {
            return;
        }

        $order_id = (int) $order_id;

        // Only proceed if order has been synced to SAP
        $mapping = $this->order_repo->find_by_wc_order($order_id);
        if (!$mapping || empty($mapping->sap_doc_entry)) {
            $this->logger->info("Order #{$order_id} completed but not in SAP, skipping delivery note");
            return;
        }

        // Enqueue delivery event
        $this->queue->enqueue('order.delivered', 'wc', [
            'order_id' => $order_id,
            'sap_doc_entry' => (int) $mapping->sap_doc_entry,
        ], 3);

        $this->logger->info("Order #{$order_id} completed, delivery note enqueued", [
            'entity_type' => Sync_Status::ENTITY_ORDER,
            'entity_id' => $order_id,
        ]);
    }

    /**
     * Handle order cancelled -> Enqueue SAP Sales Order cancellation.
     *
     * When a WC order is cancelled, close/cancel the corresponding
     * Sales Order in SAP to release committed stock.
     */
    public function on_order_cancelled($order_id): void
    {
        if (Config::get(Config::OPT_ENABLE_ORDER_SYNC) !== 'yes') {
            return;
        }

        $order_id = (int) $order_id;

        $mapping = $this->order_repo->find_by_wc_order($order_id);
        if (!$mapping || empty($mapping->sap_doc_entry)) {
            return;
        }

        $this->queue->enqueue('order.cancelled', 'wc', [
            'order_id' => $order_id,
            'sap_doc_entry' => (int) $mapping->sap_doc_entry,
        ], 1);

        $this->logger->info("Order #{$order_id} cancelled, SAP cancellation enqueued", [
            'entity_type' => Sync_Status::ENTITY_ORDER,
            'entity_id' => $order_id,
        ]);
    }

    /**
     * Handle refund -> Enqueue credit note + outgoing payment creation.
     *
     * Creates an A/R Credit Note in SAP based on the AR Invoice,
     * then creates an Outgoing Payment to record the refund.
     */
    public function on_order_refunded($order_id, $refund_id): void
    {
        if (Config::get(Config::OPT_ENABLE_ORDER_SYNC) !== 'yes') {
            return;
        }

        $order_id = (int) $order_id;
        $refund_id = (int) $refund_id;

        $mapping = $this->order_repo->find_by_wc_order($order_id);
        if (!$mapping || empty($mapping->sap_doc_entry)) {
            return;
        }

        $this->queue->enqueue('order.refunded', 'wc', [
            'order_id' => $order_id,
            'refund_id' => $refund_id,
            'sap_doc_entry' => (int) $mapping->sap_doc_entry,
        ], 1);

        $this->logger->info("Refund enqueued for order #{$order_id}", [
            'entity_type' => Sync_Status::ENTITY_ORDER,
            'entity_id' => $order_id,
        ]);
    }

    /**
     * Deferred stock refresh - runs 60s after order creation.
     *
     * SAP updates committed stock asynchronously after order creation.
     * This deferred refresh ensures WooCommerce stock reflects SAP's
     * updated committed count, preventing overselling.
     */
    public function deferred_stock_refresh($order_id): void
    {
        $order_id = (int) $order_id;

        // Enqueue stock refresh for each product in the order
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $product_repo = new \SAPWCSync\Repositories\Product_Map_Repository();

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) {
                continue;
            }

            $mapping = $product_repo->find_by_wc_id($product->get_id());
            if ($mapping && !empty($mapping->sap_item_code)) {
                // Enqueue targeted stock sync for this product
                $this->queue->enqueue('item.stock_changed', 'wc', [
                    'ItemCode' => $mapping->sap_item_code,
                ], 2, 0);
            }
        }
    }

    /**
     * Prevent WooCommerce from reducing stock on order placement.
     *
     * SAP is the single source of truth for stock. SAP tracks committed
     * quantities, and our sync calculates Available = InStock - Committed.
     * If WooCommerce also reduces stock, it causes double-counting:
     * SAP deducts via Committed + WC deducts via stock reduction = 2x deduction.
     *
     * @param bool $reduce Whether WooCommerce should reduce stock
     * @param \WC_Order $order The order being processed
     * @return bool False to prevent reduction when SAP sync is active
     */
    public function prevent_stock_reduction($reduce, $order): bool
    {
        if (Config::get(Config::OPT_ENABLE_ORDER_SYNC) !== 'yes') {
            return $reduce;
        }
        return false;
    }
}
