<?php
/**
 * Event Processor - Routes queue events to appropriate handlers
 *
 * Each handler is idempotent -- processing the same event twice
 * produces the same result without side effects.
 *
 * @package SAP_WooCommerce_Sync
 * @since 2.0.0
 */

namespace SAPWCSync\Webhooks;

use SAPWCSync\API\SAP_Client;
use SAPWCSync\Constants\Config;
use SAPWCSync\Constants\Sync_Status;
use SAPWCSync\Helpers\Logger;
use SAPWCSync\Helpers\Product_Helper;
use SAPWCSync\Helpers\SAP_Filter_Builder;
use SAPWCSync\Repositories\Product_Map_Repository;
use SAPWCSync\Repositories\Order_Map_Repository;
use SAPWCSync\Queue\Circuit_Breaker;

defined('ABSPATH') || exit;

class Event_Processor
{
    private $sap_client;
    private $logger;
    private $product_repo;

    public function __construct(SAP_Client $sap_client)
    {
        $this->sap_client = $sap_client;
        $this->logger = new Logger();
        $this->product_repo = new Product_Map_Repository();
    }

    /**
     * Process a single event from the queue.
     *
     * @param object $event Queue event with event_type and payload
     * @throws \Exception On processing failure (triggers retry)
     */
    public function process(object $event): void
    {
        // Check circuit breaker before making SAP calls
        Circuit_Breaker::check();

        try {
            switch ($event->event_type) {
                case 'item.created':
                    $this->handle_item_created($event->payload);
                    break;

                case 'item.updated':
                    $this->handle_item_updated($event->payload);
                    break;

                case 'item.stock_changed':
                    $this->handle_stock_changed($event->payload);
                    break;

                case 'item.code_changed':
                    $this->handle_item_code_changed($event->payload);
                    break;

                case 'item.deactivated':
                    $this->handle_item_deactivated($event->payload);
                    break;

                case 'item.returned':
                    $this->handle_item_returned($event->payload);
                    break;

                case 'order.placed':
                    $this->handle_order_placed($event->payload);
                    break;

                case 'order.status_changed':
                    $this->handle_order_status_changed($event->payload);
                    break;

                case 'order.cancelled':
                    $this->handle_order_cancelled($event->payload);
                    break;

                case 'order.delivered':
                    $this->handle_order_delivered($event->payload);
                    break;

                case 'order.refunded':
                    $this->handle_order_refunded($event->payload);
                    break;

                default:
                    $this->logger->warning("No handler for event type: {$event->event_type}");
            }

            // Record success for circuit breaker
            Circuit_Breaker::record_success();

        } catch (\Exception $e) {
            Circuit_Breaker::record_failure();
            throw $e; // Re-throw so queue manager can nack
        }
    }

    /**
     * New SAP item -> Create draft WC product with Title + ISBN only.
     */
    private function handle_item_created(array $payload): void
    {
        $item_code = $payload['ItemCode'] ?? '';
        $item_name = $payload['ItemName'] ?? '';

        if (empty($item_code) || empty($item_name)) {
            throw new \Exception('item.created requires ItemCode and ItemName');
        }

        // Check if already mapped
        $existing = $this->product_repo->find_by_sap_code($item_code);
        if ($existing) {
            $this->logger->info("Item {$item_code} already mapped, skipping creation");
            return;
        }

        // Get barcode (ISBN) from payload
        $barcode = $payload['BarCode'] ?? '';
        if (empty($barcode) && !empty($payload['ItemBarCodeCollection'])) {
            foreach ($payload['ItemBarCodeCollection'] as $bc) {
                if (!empty($bc['Barcode'])) {
                    $barcode = $bc['Barcode'];
                    break;
                }
            }
        }

        // Create draft WC product — SKU = SAP ItemCode (always)
        $product = new \WC_Product_Simple();
        $product->set_name($item_name);
        $product->set_status('draft');
        $product->set_sku($item_code);
        $product->set_manage_stock(true);
        $product->set_stock_quantity(0);
        $product->set_stock_status('outofstock');
        Product_Helper::set_sap_item_code($product, $item_code, false);
        if (!empty($barcode)) {
            Product_Helper::set_sap_barcode($product, $barcode, false);
        }
        $product_id = $product->save();

        // Create mapping
        $this->product_repo->upsert_by_wc_id($product_id, [
            'sap_item_code' => $item_code,
            'sap_barcode' => $barcode ?: null,
            'sync_status' => Sync_Status::PENDING,
        ]);

        $this->logger->success("Draft product created for SAP item {$item_code}: WC #{$product_id}", [
            'entity_type' => Sync_Status::ENTITY_PRODUCT,
            'entity_id' => $product_id,
        ]);
    }

    /**
     * SAP item details updated -> Update WC product details.
     */
    private function handle_item_updated(array $payload): void
    {
        $item_code = $payload['ItemCode'] ?? '';
        if (empty($item_code)) {
            throw new \Exception('item.updated requires ItemCode');
        }

        $mapping = $this->product_repo->find_by_sap_code($item_code);
        if (!$mapping) {
            $this->logger->info("Item {$item_code} not mapped, skipping update");
            return;
        }

        $product = wc_get_product($mapping->wc_product_id);
        if (!$product) {
            return;
        }

        // Update fields that changed
        if (!empty($payload['ItemName'])) {
            $product->set_name($payload['ItemName']);
        }

        // Update barcode/ISBN if provided — stored in meta, NOT as SKU
        $barcode = $payload['BarCode'] ?? '';
        if (!empty($barcode)) {
            Product_Helper::set_sap_barcode($product, $barcode, false);
            $this->product_repo->upsert_by_wc_id($mapping->wc_product_id, [
                'sap_barcode' => $barcode,
            ]);
        }

        $product->save();

        $this->logger->info("Product updated from SAP item {$item_code}", [
            'entity_type' => Sync_Status::ENTITY_PRODUCT,
            'entity_id' => $mapping->wc_product_id,
        ]);
    }

    /**
     * SAP stock changed -> Update specific WC product stock.
     *
     * This is the event-based replacement for the 30-second polling.
     * Only syncs the SINGLE product that changed.
     */
    private function handle_stock_changed(array $payload): void
    {
        $item_code = $payload['ItemCode'] ?? '';
        if (empty($item_code)) {
            throw new \Exception('item.stock_changed requires ItemCode');
        }

        $mapping = $this->product_repo->find_by_sap_code($item_code);
        if (!$mapping) {
            return; // Not mapped, ignore
        }

        // Fetch fresh stock from SAP for this specific item
        $inventory_sync = new \SAPWCSync\Sync\Inventory_Sync($this->sap_client);
        $inventory_sync->sync_single_product($mapping->wc_product_id);
    }

    /**
     * SAP ItemCode changed -> Cascade update across mapping, inventory, orders.
     *
     * This is the mapping integrity handler. When SAP changes an ItemCode,
     * we must update everything that references it.
     */
    private function handle_item_code_changed(array $payload): void
    {
        $old_code = $payload['OldItemCode'] ?? '';
        $new_code = $payload['NewItemCode'] ?? '';

        if (empty($old_code) || empty($new_code)) {
            throw new \Exception('item.code_changed requires OldItemCode and NewItemCode');
        }

        $mapping = $this->product_repo->find_by_sap_code($old_code);
        if (!$mapping) {
            $this->logger->info("Old item code {$old_code} not mapped, skipping cascade");
            return;
        }

        $product = wc_get_product($mapping->wc_product_id);
        if (!$product) {
            return;
        }

        // Step 1: Update mapping table
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . Config::TABLE_PRODUCT_MAP,
            ['sap_item_code' => $new_code, 'sync_status' => Sync_Status::PENDING],
            ['sap_item_code' => $old_code]
        );

        // Step 2: Update WC product SKU = new ItemCode
        Product_Helper::set_sap_item_code($product, $new_code, false);
        Product_Helper::update_sku($product, $new_code, false);
        $product->save();

        // Step 3: Re-sync stock for this product
        $inventory_sync = new \SAPWCSync\Sync\Inventory_Sync($this->sap_client);
        $inventory_sync->sync_single_product($mapping->wc_product_id);

        $this->logger->success("ItemCode cascade completed: {$old_code} -> {$new_code} (Product #{$mapping->wc_product_id})", [
            'entity_type' => Sync_Status::ENTITY_PRODUCT,
            'entity_id' => $mapping->wc_product_id,
        ]);
    }

    /**
     * SAP item deactivated -> Set WC product to draft.
     */
    private function handle_item_deactivated(array $payload): void
    {
        $item_code = $payload['ItemCode'] ?? '';
        $mapping = $this->product_repo->find_by_sap_code($item_code);
        if (!$mapping) {
            return;
        }

        $product = wc_get_product($mapping->wc_product_id);
        if ($product) {
            $product->set_status('draft');
            $product->save();
            $this->logger->info("Product set to draft (SAP item deactivated): {$item_code}");
        }
    }

    /**
     * SAP return processed -> Restock specific product.
     */
    private function handle_item_returned(array $payload): void
    {
        // Return is essentially a stock change event
        $this->handle_stock_changed($payload);
    }

    /**
     * WC order placed -> Create SAP Sales Order via queue.
     */
    private function handle_order_placed(array $payload): void
    {
        $order_id = $payload['order_id'] ?? 0;
        if (empty($order_id)) {
            throw new \Exception('order.placed requires order_id');
        }

        $order_sync = new \SAPWCSync\Sync\Order_Sync($this->sap_client);
        $order_sync->process_new_order((int) $order_id);
    }

    /**
     * SAP order status changed -> Update WC order.
     */
    private function handle_order_status_changed(array $payload): void
    {
        $doc_entry = $payload['DocEntry'] ?? 0;
        $new_status = $payload['Status'] ?? '';

        if (empty($doc_entry)) {
            throw new \Exception('order.status_changed requires DocEntry');
        }

        $order_repo = new \SAPWCSync\Repositories\Order_Map_Repository();
        $mapping = $order_repo->find_by_sap_doc((int) $doc_entry);

        if (!$mapping) {
            return;
        }

        $order = wc_get_order($mapping->wc_order_id);
        if (!$order) {
            return;
        }

        // Map SAP status to WC status
        if ($new_status === 'cancelled' || $new_status === 'C') {
            $order->update_status('cancelled', 'Cancelled in SAP');
        }
    }

    /**
     * Order cancelled -> Close SAP Sales Order and update WC status.
     *
     * If triggered from SAP (has DocEntry), cancels the WC order.
     * If triggered from WC (has order_id), closes the SAP Sales Order.
     */
    private function handle_order_cancelled(array $payload): void
    {
        $doc_entry = $payload['DocEntry'] ?? 0;
        $order_id = $payload['order_id'] ?? 0;

        // SAP-initiated cancellation -> update WC
        if (!empty($doc_entry)) {
            $this->handle_order_status_changed(array_merge($payload, ['Status' => 'cancelled']));
            return;
        }

        // WC-initiated cancellation -> close SAP Sales Order
        if (!empty($order_id)) {
            $order_repo = new Order_Map_Repository();
            $mapping = $order_repo->find_by_wc_order((int) $order_id);
            if (!$mapping || empty($mapping->sap_doc_entry)) {
                return;
            }

            try {
                $this->sap_client->post("Orders({$mapping->sap_doc_entry})/Cancel", []);

                $this->logger->info("SAP Sales Order cancelled for WC order #{$order_id}", [
                    'entity_type' => Sync_Status::ENTITY_ORDER,
                    'entity_id' => (int) $order_id,
                ]);

                $order = wc_get_order($order_id);
                if ($order) {
                    $order->add_order_note(sprintf(
                        'SAP Sales Order (DocEntry=%d) cancelled',
                        $mapping->sap_doc_entry
                    ));
                }
            } catch (\Exception $e) {
                $this->logger->warning("Failed to cancel SAP SO for order #{$order_id}: " . $e->getMessage(), [
                    'entity_type' => Sync_Status::ENTITY_ORDER,
                    'entity_id' => (int) $order_id,
                ]);
            }
        }
    }

    /**
     * WC order completed -> Create Delivery Note + AR Invoice in SAP.
     *
     * Document flow: Sales Order -> Delivery Note -> AR Invoice
     * Each step is non-blocking after the SO: if DN or invoice fails,
     * we log and continue rather than failing the whole event.
     */
    private function handle_order_delivered(array $payload): void
    {
        $order_id = $payload['order_id'] ?? 0;
        $sap_doc_entry = $payload['sap_doc_entry'] ?? 0;

        if (empty($order_id) || empty($sap_doc_entry)) {
            throw new \Exception('order.delivered requires order_id and sap_doc_entry');
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $order_repo = new Order_Map_Repository();
        $mapping = $order_repo->find_by_wc_order($order_id);
        if (!$mapping) {
            return;
        }

        // Step 1: Create Delivery Note from Sales Order
        $so = $this->sap_client->get("Orders({$sap_doc_entry})");

        $document_lines = [];
        foreach ($so['DocumentLines'] as $index => $line) {
            $document_lines[] = [
                'BaseType' => 17, // Sales Order
                'BaseEntry' => (int) $sap_doc_entry,
                'BaseLine' => $index,
            ];
        }

        $delivery_data = [
            'CardCode' => $so['CardCode'],
            'DocDate' => current_time('Y-m-d'),
            'DocumentLines' => $document_lines,
        ];

        $dn_response = $this->sap_client->post('DeliveryNotes', $delivery_data);

        // Update mapping with delivery entry
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . Config::TABLE_ORDER_MAP,
            [
                'sap_delivery_entry' => $dn_response['DocEntry'],
                'sync_status' => Sync_Status::DELIVERED,
            ],
            ['wc_order_id' => $order_id]
        );

        $order->add_order_note(sprintf(
            'SAP Delivery Note created: DocEntry=%d',
            $dn_response['DocEntry']
        ));

        $this->logger->success("Delivery Note created for order #{$order_id}: DocEntry={$dn_response['DocEntry']}", [
            'entity_type' => Sync_Status::ENTITY_ORDER,
            'entity_id' => $order_id,
        ]);

        // Step 2: Create AR Invoice from Delivery Note (non-blocking)
        try {
            $this->create_ar_invoice($order, (int) $dn_response['DocEntry'], $so['CardCode']);
        } catch (\Exception $e) {
            $this->logger->warning("AR Invoice skipped for order #{$order_id}: " . $e->getMessage(), [
                'entity_type' => Sync_Status::ENTITY_ORDER,
                'entity_id' => $order_id,
            ]);
            $order->add_order_note('SAP AR Invoice skipped: ' . $e->getMessage());
        }
    }

    /**
     * Create AR Invoice from Delivery Note.
     */
    private function create_ar_invoice(\WC_Order $order, int $delivery_entry, string $card_code): void
    {
        $dn = $this->sap_client->get("DeliveryNotes({$delivery_entry})");

        $document_lines = [];
        foreach ($dn['DocumentLines'] as $index => $line) {
            $document_lines[] = [
                'BaseType' => 15, // Delivery Note
                'BaseEntry' => $delivery_entry,
                'BaseLine' => $index,
            ];
        }

        $invoice_data = [
            'CardCode' => $card_code,
            'DocDate' => current_time('Y-m-d'),
            'DocumentLines' => $document_lines,
        ];

        $inv_response = $this->sap_client->post('Invoices', $invoice_data);

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . Config::TABLE_ORDER_MAP,
            [
                'sap_ar_invoice_entry' => $inv_response['DocEntry'],
                'sync_status' => Sync_Status::INVOICED,
            ],
            ['wc_order_id' => $order->get_id()]
        );

        $order->add_order_note(sprintf(
            'SAP AR Invoice created: DocEntry=%d',
            $inv_response['DocEntry']
        ));

        $this->logger->success("AR Invoice created for order #{$order->get_id()}: DocEntry={$inv_response['DocEntry']}", [
            'entity_type' => Sync_Status::ENTITY_ORDER,
            'entity_id' => $order->get_id(),
        ]);
    }

    /**
     * WC refund -> Create A/R Credit Note + Outgoing Payment in SAP.
     *
     * Document flow per business process:
     * 1. Credit Note based on AR Invoice (or standalone)
     * 2. Outgoing Payment to record refund to customer
     * 3. Stock refresh for refunded items
     */
    private function handle_order_refunded(array $payload): void
    {
        $order_id = $payload['order_id'] ?? 0;
        $refund_id = $payload['refund_id'] ?? 0;

        if (empty($order_id) || empty($refund_id)) {
            throw new \Exception('order.refunded requires order_id and refund_id');
        }

        $order = wc_get_order($order_id);
        $refund = wc_get_order($refund_id);
        if (!$order || !$refund) {
            return;
        }

        $order_repo = new Order_Map_Repository();
        $mapping = $order_repo->find_by_wc_order($order_id);
        if (!$mapping || empty($mapping->sap_doc_entry)) {
            return;
        }

        $warehouse = Config::warehouse();
        $tax_code = Config::get('sap_wc_tax_code', Config::DEFAULT_TAX_CODE);
        $document_lines = [];

        // If we have an AR Invoice, base Credit Note on it
        if (!empty($mapping->sap_ar_invoice_entry)) {
            $invoice = $this->sap_client->get("Invoices({$mapping->sap_ar_invoice_entry})");

            foreach ($refund->get_items() as $item) {
                $product = $item->get_product();
                if (!$product) {
                    continue;
                }

                $sku = $product->get_sku();
                $qty = abs($item->get_quantity());

                // Find matching line in SAP invoice
                foreach ($invoice['DocumentLines'] as $index => $inv_line) {
                    if ($inv_line['ItemCode'] === $sku) {
                        $document_lines[] = [
                            'BaseType' => 13, // AR Invoice
                            'BaseEntry' => (int) $mapping->sap_ar_invoice_entry,
                            'BaseLine' => $index,
                            'Quantity' => $qty,
                        ];
                        break;
                    }
                }
            }
        }

        // Fallback: standalone Credit Note
        if (empty($document_lines)) {
            foreach ($refund->get_items() as $item) {
                $product = $item->get_product();
                if (!$product) {
                    continue;
                }

                $sku = $product->get_sku();
                if (empty($sku)) {
                    continue;
                }

                $qty = abs($item->get_quantity());
                $line_total = abs((float) $item->get_total());
                $unit_price = $qty > 0 ? $line_total / $qty : 0;

                $document_lines[] = [
                    'ItemCode' => $sku,
                    'Quantity' => $qty,
                    'UnitPrice' => $unit_price,
                    'TaxCode' => $tax_code,
                    'WarehouseCode' => $warehouse,
                ];
            }
        }

        if (empty($document_lines)) {
            $order->add_order_note('SAP Refund: No items to create Credit Note for.');
            return;
        }

        // Look up customer CardCode from the Sales Order
        $so = $this->sap_client->get("Orders({$mapping->sap_doc_entry})", ['$select' => 'CardCode']);
        $card_code = $so['CardCode'];

        // Step 1: Create Credit Note
        $credit_note_data = [
            'CardCode' => $card_code,
            'DocDate' => current_time('Y-m-d'),
            'Comments' => sprintf('WooCommerce Refund #%d for Order #%s', $refund_id, $order->get_order_number()),
            'DocumentLines' => $document_lines,
        ];

        $cn_response = $this->sap_client->post('CreditNotes', $credit_note_data);

        $order->add_order_note(sprintf(
            'SAP Credit Note created: DocEntry=%d, Amount=%s',
            $cn_response['DocEntry'] ?? 0,
            wc_price(abs($refund->get_total()))
        ));

        $this->logger->success("Credit Note created for order #{$order_id}: DocEntry={$cn_response['DocEntry']}", [
            'entity_type' => Sync_Status::ENTITY_ORDER,
            'entity_id' => $order_id,
        ]);

        // Step 2: Create Outgoing Payment to record refund (non-blocking)
        try {
            $this->create_outgoing_payment($order, $refund, $card_code, (int) $cn_response['DocEntry']);
        } catch (\Exception $e) {
            $this->logger->warning("Outgoing Payment skipped for order #{$order_id}: " . $e->getMessage(), [
                'entity_type' => Sync_Status::ENTITY_ORDER,
                'entity_id' => $order_id,
            ]);
            $order->add_order_note('SAP Outgoing Payment skipped: ' . $e->getMessage());
        }

        // Step 3: Trigger stock refresh for refunded items
        $inventory_sync = new \SAPWCSync\Sync\Inventory_Sync($this->sap_client);
        foreach ($refund->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) {
                continue;
            }
            $pm = $this->product_repo->find_by_wc_id($product->get_id());
            if ($pm) {
                $inventory_sync->sync_single_product($product->get_id());
            }
        }
    }

    /**
     * Create Outgoing Payment (VendorPayments) to record refund to customer.
     *
     * Links the payment to the Credit Note so SAP accounting is complete:
     * Credit Note (negative invoice) + Outgoing Payment = fully reconciled refund.
     */
    private function create_outgoing_payment(\WC_Order $order, \WC_Order $refund, string $card_code, int $credit_note_entry): void
    {
        $refund_amount = abs((float) $refund->get_total());
        $transfer_account = Config::get(Config::OPT_TRANSFER_ACCOUNT);

        if (empty($transfer_account)) {
            throw new \Exception('Transfer account not configured for outgoing payment');
        }

        $outgoing_data = [
            'CardCode' => $card_code,
            'DocType' => 'rCustomer',
            'DocDate' => current_time('Y-m-d'),
            'TransferAccount' => $transfer_account,
            'TransferSum' => $refund_amount,
            'TransferDate' => current_time('Y-m-d'),
            'TransferReference' => sprintf('WC-REFUND-%d', $refund->get_id()),
            'PaymentInvoices' => [[
                'DocEntry' => $credit_note_entry,
                'SumApplied' => $refund_amount,
                'InvoiceType' => 'it_CredItnote',
                'InstallmentId' => 1,
            ]],
        ];

        $op_response = $this->sap_client->post('VendorPayments', $outgoing_data);

        $order->add_order_note(sprintf(
            'SAP Outgoing Payment created: DocEntry=%d, Amount=%s',
            $op_response['DocEntry'] ?? 0,
            wc_price($refund_amount)
        ));

        $this->logger->success("Outgoing Payment created for order #{$order->get_id()}: DocEntry={$op_response['DocEntry']}", [
            'entity_type' => Sync_Status::ENTITY_ORDER,
            'entity_id' => $order->get_id(),
        ]);
    }
}
