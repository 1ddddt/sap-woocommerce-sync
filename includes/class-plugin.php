<?php
/**
 * Main Plugin Orchestrator
 *
 * Singleton that wires all components together. Creates a single shared
 * SAP client instance and registers all hooks, cron jobs, and AJAX handlers.
 *
 * Best practices applied:
 * - Single shared SAP client (not recreated per cron job)
 * - No 30-second cron polling (5-minute intervals + event-driven)
 * - Autoloader handles all class loading (no manual requires)
 * - Config constants for all option keys
 * - Rate limiter on all AJAX endpoints
 * - Repository pattern for all DB access
 * - Product_Helper for HPOS-compatible WC updates
 *
 * @package SAP_WooCommerce_Sync
 * @since 2.0.0
 */

namespace SAPWCSync;

use SAPWCSync\API\SAP_Client;
use SAPWCSync\Constants\Config;
use SAPWCSync\Constants\Sync_Status;
use SAPWCSync\Helpers\Logger;
use SAPWCSync\Helpers\Product_Helper;
use SAPWCSync\Queue\Queue_Manager;
use SAPWCSync\Queue\Queue_Worker;
use SAPWCSync\Repositories\Product_Map_Repository;
use SAPWCSync\Repositories\Order_Map_Repository;
use SAPWCSync\Security\Rate_Limiter;

defined('ABSPATH') || exit;

class Plugin
{
    private static $instance = null;

    /** @var SAP_Client Shared SAP client instance */
    private $sap_client;

    /** @var Logger */
    private $logger;

    /**
     * Get singleton instance.
     */
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->logger = new Logger();
        $this->init_sap_client();
        $this->register_hooks();
        $this->init_components();
        $this->ensure_cron_scheduled();
    }

    /**
     * Initialize shared SAP client.
     */
    private function init_sap_client(): void
    {
        $this->sap_client = new SAP_Client([
            'base_url'   => Config::get(Config::OPT_BASE_URL),
            'company_db' => Config::get(Config::OPT_COMPANY_DB),
            'username'   => Config::get(Config::OPT_USERNAME),
            'password'   => Config::get(Config::OPT_PASSWORD),
        ]);
    }

    /**
     * Register all WordPress hooks.
     */
    private function register_hooks(): void
    {
        // Custom cron schedules (5min + daily only, no 30-second polling)
        add_filter('cron_schedules', [$this, 'add_cron_schedules']);

        // Admin hooks
        if (is_admin()) {
            add_action('admin_menu', [$this, 'add_admin_menu']);
            add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
            add_action('admin_notices', [$this, 'show_admin_notices']);
        }

        // Cron handlers
        add_action(Config::CRON_INVENTORY_SYNC, [$this, 'cron_inventory_sync']);
        add_action(Config::CRON_RETRY_ORDERS, [$this, 'cron_retry_orders']);
        add_action(Config::CRON_POLL_ORDERS, [$this, 'cron_poll_orders']);
        add_action(Config::CRON_CLEANUP_LOGS, [$this, 'cron_cleanup_logs']);
        add_action(Config::CRON_PROCESS_QUEUE, [$this, 'cron_process_queue']);
        add_action(Config::CRON_HEALTH_CHECK, [$this, 'cron_health_check']);

        // REST API routes
        add_action('rest_api_init', [$this, 'register_rest_routes']);

        // AJAX handlers
        add_action('wp_ajax_sap_wc_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_sap_wc_manual_sync', [$this, 'ajax_manual_sync']);
        add_action('wp_ajax_sap_wc_sync_products', [$this, 'ajax_sync_products']);
        add_action('wp_ajax_sap_wc_sync_products_chunk', [$this, 'ajax_sync_products_chunk']);
        add_action('wp_ajax_sap_wc_sync_single', [$this, 'ajax_sync_single_product']);
        add_action('wp_ajax_sap_wc_auto_map', [$this, 'ajax_auto_map']);
        add_action('wp_ajax_sap_wc_manual_map', [$this, 'ajax_manual_map_product']);
        add_action('wp_ajax_sap_wc_retry_order', [$this, 'ajax_retry_order']);
        add_action('wp_ajax_sap_wc_retry_dead_letter', [$this, 'ajax_retry_dead_letter']);
        add_action('wp_ajax_sap_wc_sync_status', [$this, 'ajax_sync_status']);

        // Immediate queue processing (no-priv for frontend/checkout access)
        add_action('wp_ajax_sap_wc_process_queue_immediate', [$this, 'ajax_process_queue_immediate']);
        add_action('wp_ajax_nopriv_sap_wc_process_queue_immediate', [$this, 'ajax_process_queue_immediate']);

        // Background sync handler (triggered via wp_schedule_single_event)
        add_action('sap_wc_run_background_sync', [$this, 'do_background_inventory_sync']);
    }

    /**
     * Initialize runtime components.
     */
    private function init_components(): void
    {
        // Order Handler hooks into WooCommerce order lifecycle
        if (Config::get(Config::OPT_ENABLE_ORDER_SYNC) === 'yes') {
            $order_handler = new Handlers\Order_Handler();
            $order_handler->register_hooks();
        }
    }

    /**
     * Register REST API routes (webhook endpoint).
     */
    public function register_rest_routes(): void
    {
        $webhook = new Webhooks\Webhook_Controller();

        // Health endpoint is always available
        $webhook->register_health_route();

        // Webhook endpoint only when enabled
        if (Config::get(Config::OPT_ENABLE_WEBHOOKS) === 'yes') {
            $webhook->register_webhook_route();
        }
    }

    /**
     * Ensure all cron jobs are scheduled (self-healing after deploys).
     *
     * Activator::activate() schedules crons, but rsync/FTP deploys
     * don't trigger activation. This checks once per request and
     * reschedules any missing hooks.
     */
    private function ensure_cron_scheduled(): void
    {
        $hooks_5min = [
            Config::CRON_INVENTORY_SYNC,
            Config::CRON_RETRY_ORDERS,
            Config::CRON_POLL_ORDERS,
            Config::CRON_PROCESS_QUEUE,
            Config::CRON_HEALTH_CHECK,
        ];

        foreach ($hooks_5min as $hook) {
            if (!wp_next_scheduled($hook)) {
                wp_schedule_event(time(), Config::SCHEDULE_5MIN, $hook);
            }
        }

        if (!wp_next_scheduled(Config::CRON_CLEANUP_LOGS)) {
            wp_schedule_event(strtotime('tomorrow 2:00am'), Config::SCHEDULE_DAILY, Config::CRON_CLEANUP_LOGS);
        }
    }

    // ──────────────────────────────────────────────────────
    // Cron schedules
    // ──────────────────────────────────────────────────────

    /**
     * Add custom cron schedules.
     * Only 5-minute and daily — no 30-second polling.
     */
    public function add_cron_schedules(array $schedules): array
    {
        $schedules[Config::SCHEDULE_5MIN] = [
            'interval' => 300,
            'display' => __('Every 5 minutes', 'sap-wc-sync'),
        ];

        $schedules[Config::SCHEDULE_DAILY] = [
            'interval' => 86400,
            'display' => __('Once Daily', 'sap-wc-sync'),
        ];

        return $schedules;
    }

    // ──────────────────────────────────────────────────────
    // Cron handlers
    // ──────────────────────────────────────────────────────

    /**
     * Cron: Batch inventory sync (fallback for event-driven).
     */
    public function cron_inventory_sync(): void
    {
        if (Config::get(Config::OPT_ENABLE_INVENTORY) !== 'yes') {
            return;
        }

        $sync = new Sync\Inventory_Sync($this->sap_client);
        $sync->sync_stock_levels();
    }

    /**
     * Cron: Retry failed orders.
     */
    public function cron_retry_orders(): void
    {
        if (Config::get(Config::OPT_ENABLE_ORDER_SYNC) !== 'yes') {
            return;
        }

        $order_repo = new Order_Map_Repository();
        $candidates = $order_repo->get_retry_candidates();

        foreach ($candidates as $mapping) {
            try {
                $order_sync = new Sync\Order_Sync($this->sap_client);
                $order_sync->process_new_order((int) $mapping->wc_order_id);
            } catch (\Exception $e) {
                // Already logged and retry scheduled in Order_Sync
            }
        }
    }

    /**
     * Cron: Poll SAP for order status updates.
     * This is a fallback for when webhooks are not available.
     */
    public function cron_poll_orders(): void
    {
        if (Config::get(Config::OPT_ENABLE_ORDER_SYNC) !== 'yes') {
            return;
        }

        // Only poll if webhooks are disabled (polling is the fallback)
        if (Config::get(Config::OPT_ENABLE_WEBHOOKS) === 'yes') {
            return;
        }

        $order_repo = new Order_Map_Repository();
        $recent_orders = $order_repo->find_all(['sync_status' => Sync_Status::SO_CREATED], 50);

        foreach ($recent_orders as $mapping) {
            if (empty($mapping->sap_doc_entry)) {
                continue;
            }

            try {
                $so = $this->sap_client->get("Orders({$mapping->sap_doc_entry})", [
                    '$select' => 'DocEntry,DocStatus',
                ]);

                if (!empty($so['DocStatus']) && $so['DocStatus'] === 'bost_Close') {
                    $order = wc_get_order($mapping->wc_order_id);
                    if ($order && $order->get_status() !== 'completed') {
                        $order->update_status('completed', 'SAP order closed');
                    }
                }
            } catch (\Exception $e) {
                // Skip and continue polling other orders
            }
        }
    }

    /**
     * Cron: Process event queue.
     */
    public function cron_process_queue(): void
    {
        $worker = new Queue_Worker($this->sap_client);
        $worker->process_batch();
    }

    /**
     * Cron: Health check and queue maintenance.
     */
    public function cron_health_check(): void
    {
        $queue = new Queue_Manager();

        // Release stale locks (events stuck in processing state)
        $released = $queue->release_stale_locks();
        if ($released > 0) {
            $this->logger->warning("Released {$released} stale queue locks", [
                'entity_type' => Sync_Status::ENTITY_SYSTEM,
            ]);
        }

        // Cleanup old completed events (keep 7 days)
        $queue->cleanup_completed(7);
    }

    /**
     * Cron: Log cleanup.
     */
    public function cron_cleanup_logs(): void
    {
        $stats = Helpers\Log_Cleanup::cleanup();

        if ($stats['deleted'] > 0) {
            $this->logger->info("Log cleanup: {$stats['deleted']} entries deleted (retention: {$stats['retention_days']} days)", [
                'entity_type' => Sync_Status::ENTITY_SYSTEM,
            ]);
        }
    }

    // ──────────────────────────────────────────────────────
    // Admin UI
    // ──────────────────────────────────────────────────────

    /**
     * Add admin menu items under WooCommerce.
     */
    public function add_admin_menu(): void
    {
        add_submenu_page(
            'woocommerce',
            __('SAP Sync', 'sap-wc-sync'),
            __('SAP Sync', 'sap-wc-sync'),
            'manage_woocommerce',
            'sap-wc-sync',
            [$this, 'render_settings_page']
        );

        add_submenu_page(
            'woocommerce',
            __('SAP Products', 'sap-wc-sync'),
            __('SAP Products', 'sap-wc-sync'),
            'manage_woocommerce',
            'sap-wc-products',
            [$this, 'render_products_page']
        );

        add_submenu_page(
            'woocommerce',
            __('SAP Sync Logs', 'sap-wc-sync'),
            __('SAP Sync Logs', 'sap-wc-sync'),
            'manage_woocommerce',
            'sap-wc-sync-logs',
            [$this, 'render_logs_page']
        );
    }

    /**
     * Enqueue admin CSS and JS.
     */
    public function enqueue_admin_assets(string $hook): void
    {
        if (strpos($hook, 'sap-wc') === false) {
            return;
        }

        wp_enqueue_style(
            'sap-wc-sync-admin',
            SAP_WC_PLUGIN_URL . 'assets/css/admin.css',
            [],
            SAP_WC_VERSION
        );

        wp_enqueue_script(
            'sap-wc-sync-admin',
            SAP_WC_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            SAP_WC_VERSION,
            true
        );

        wp_localize_script('sap-wc-sync-admin', 'sapWcSync', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sap_wc_sync_nonce'),
            'strings' => [
                'testing' => __('Testing connection...', 'sap-wc-sync'),
                'syncing' => __('Syncing...', 'sap-wc-sync'),
                'success' => __('Success!', 'sap-wc-sync'),
                'error' => __('Error:', 'sap-wc-sync'),
            ],
        ]);
    }

    /**
     * Show admin notices (SSL, encryption key warnings).
     */
    public function show_admin_notices(): void
    {
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'sap-wc') === false) {
            return;
        }

        $this->show_ssl_warning();
        $this->show_encryption_key_warning();
    }

    private function show_ssl_warning(): void
    {
        $ssl_verify = apply_filters(
            'sap_wc_ssl_verify',
            defined('SAP_WC_SSL_VERIFY') ? SAP_WC_SSL_VERIFY : true
        );

        if ($ssl_verify !== false) {
            return;
        }

        echo '<div class="notice notice-warning is-dismissible"><p>';
        echo '<strong>' . esc_html__('SAP WooCommerce Sync: Security Warning', 'sap-wc-sync') . '</strong><br>';
        echo esc_html__('SSL certificate verification is disabled. This is a security risk in production. ', 'sap-wc-sync');
        echo esc_html__('Remove or set SAP_WC_SSL_VERIFY to true in wp-config.php.', 'sap-wc-sync');
        echo '</p></div>';
    }

    private function show_encryption_key_warning(): void
    {
        $validation = Security\Encryption::validate_key();
        if ($validation['valid']) {
            return;
        }

        echo '<div class="notice notice-error"><p>';
        echo '<strong>' . esc_html__('SAP WooCommerce Sync: Encryption Key Error', 'sap-wc-sync') . '</strong><br>';
        echo esc_html($validation['message']) . '<br><br>';
        echo '<strong>' . esc_html__('Setup:', 'sap-wc-sync') . '</strong><br>';
        echo '1. Generate key: <code>openssl rand -hex 32</code><br>';
        echo '2. Add to wp-config.php: <code>define(\'SAP_WC_ENCRYPTION_KEY\', \'YOUR_KEY\');</code>';
        echo '</p></div>';
    }

    /**
     * Render settings page.
     */
    public function render_settings_page(): void
    {
        $settings = new Admin\Settings_Page();
        $settings->render();
    }

    /**
     * Render products page.
     */
    public function render_products_page(): void
    {
        require_once SAP_WC_PLUGIN_DIR . 'templates/admin/products.php';
    }

    /**
     * Render logs page.
     */
    public function render_logs_page(): void
    {
        require_once SAP_WC_PLUGIN_DIR . 'templates/admin/logs.php';
    }

    // ──────────────────────────────────────────────────────
    // AJAX handlers
    // ──────────────────────────────────────────────────────

    /**
     * AJAX: Test SAP connection.
     */
    public function ajax_test_connection(): void
    {
        check_ajax_referer('sap_wc_sync_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        try {
            Rate_Limiter::enforce('test_connection', Config::RATE_TEST_CONNECTION);

            $client = new SAP_Client([
                'base_url'   => sanitize_text_field(wp_unslash($_POST['base_url'] ?? '')),
                'company_db' => sanitize_text_field(wp_unslash($_POST['company_db'] ?? '')),
                'username'   => sanitize_text_field(wp_unslash($_POST['username'] ?? '')),
                'password'   => wp_unslash($_POST['password'] ?? ''),
            ]);

            $result = $client->login();

            if ($result) {
                $client->logout();
                wp_send_json_success([
                    'message' => __('Connection successful!', 'sap-wc-sync'),
                    'version' => $client->get_version(),
                ]);
            } else {
                wp_send_json_error(['message' => __('Login failed', 'sap-wc-sync')]);
            }
        } catch (\SAPWCSync\Exceptions\SAP_Rate_Limit_Exception $e) {
            wp_send_json_error(['message' => __('Too many requests. Please wait.', 'sap-wc-sync')]);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * AJAX: Manual inventory sync (background).
     *
     * Spawns the sync as a background process to avoid LiteSpeed/Cloudflare
     * HTTP idle connection timeouts (~31s). Returns immediately and JS polls
     * ajax_sync_status() for results.
     *
     * Primary: WP-CLI exec (independent of web server).
     * Fallback: WP-Cron single event with ignore_user_abort.
     */
    public function ajax_manual_sync(): void
    {
        check_ajax_referer('sap_wc_sync_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        try {
            Rate_Limiter::enforce('manual_sync', Config::RATE_MANUAL_SYNC);
        } catch (\SAPWCSync\Exceptions\SAP_Rate_Limit_Exception $e) {
            wp_send_json_error(['message' => __('Too many sync requests. Please wait.', 'sap-wc-sync')]);
        }

        // Check if sync is already running
        if (get_transient('sap_wc_inventory_sync_lock')) {
            wp_send_json_success([
                'message' => __('Inventory sync is already in progress...', 'sap-wc-sync'),
                'status'  => 'running',
            ]);
        }

        // Clear any stale result from previous run
        delete_transient('sap_wc_last_sync_result');

        // Try WP-CLI background process first (no web server timeout)
        if ($this->spawn_cli_sync()) {
            wp_send_json_success([
                'message' => __('Inventory sync started in background...', 'sap-wc-sync'),
                'status'  => 'started',
            ]);
        }

        // Fallback: WP-Cron single event
        wp_schedule_single_event(time(), 'sap_wc_run_background_sync');
        spawn_cron();

        wp_send_json_success([
            'message' => __('Inventory sync started in background...', 'sap-wc-sync'),
            'status'  => 'started',
        ]);
    }

    /**
     * Spawn inventory sync via WP-CLI background process.
     */
    private function spawn_cli_sync(): bool
    {
        if (!function_exists('exec')) {
            return false;
        }

        $wp_cli = '/usr/local/bin/wp';
        if (!file_exists($wp_cli)) {
            $wp_cli = trim(shell_exec('which wp 2>/dev/null') ?? '');
        }
        if (empty($wp_cli) || !is_executable($wp_cli)) {
            return false;
        }

        $runner = SAP_WC_PLUGIN_DIR . 'includes/cli/run-inventory-sync.php';
        $wp_path = rtrim(ABSPATH, '/');

        $cmd = sprintf(
            '%s eval-file %s --path=%s > /dev/null 2>&1 &',
            escapeshellarg($wp_cli),
            escapeshellarg($runner),
            escapeshellarg($wp_path)
        );

        exec($cmd);
        return true;
    }

    /**
     * Background handler: Run inventory sync (called via WP-Cron fallback).
     */
    public function do_background_inventory_sync(): void
    {
        ignore_user_abort(true);
        @set_time_limit(300);

        try {
            $sync = new Sync\Inventory_Sync($this->sap_client);
            $result = $sync->sync_stock_levels();
            set_transient('sap_wc_last_sync_result', $result, 3600);
        } catch (\Exception $e) {
            set_transient('sap_wc_last_sync_result', [
                'error'   => $e->getMessage(),
                'updated' => 0,
                'skipped' => 0,
                'errors'  => [['error' => $e->getMessage()]],
            ], 3600);
        }
    }

    /**
     * AJAX: Check inventory sync status (polled by JS).
     */
    public function ajax_sync_status(): void
    {
        check_ajax_referer('sap_wc_sync_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $is_running = (bool) get_transient('sap_wc_inventory_sync_lock');
        $progress   = get_transient('sap_wc_sync_progress');
        $result     = get_transient('sap_wc_last_sync_result');

        if ($is_running) {
            $data = [
                'status'  => 'running',
                'message' => __('Sync in progress...', 'sap-wc-sync'),
            ];
            if ($progress) {
                $data['progress'] = $progress;
                $data['message'] = sprintf(
                    __('Syncing: %d%% (%d/%d products, %d updated)', 'sap-wc-sync'),
                    $progress['percent'] ?? 0,
                    $progress['processed'] ?? 0,
                    $progress['total'] ?? 0,
                    $progress['updated'] ?? 0
                );
            }
            wp_send_json_success($data);
        } elseif ($result) {
            delete_transient('sap_wc_last_sync_result');
            delete_transient('sap_wc_sync_progress');
            wp_send_json_success([
                'status'  => 'completed',
                'message' => sprintf(
                    __('Sync completed. Updated: %d, Skipped: %d, Errors: %d', 'sap-wc-sync'),
                    $result['updated'] ?? 0,
                    $result['skipped'] ?? 0,
                    count($result['errors'] ?? [])
                ),
                'result' => $result,
            ]);
        } else {
            wp_send_json_success([
                'status'  => 'idle',
                'message' => '',
            ]);
        }
    }

    /**
     * AJAX handler for immediate queue processing.
     *
     * Triggered by async request after order creation when immediate sync is enabled.
     * No authentication required as this is a background request.
     *
     * Throttling Strategy:
     * - Rate limit: Maximum 1 processing request per second
     * - Queue_Worker has its own lock (sap_wc_queue_worker_lock) for concurrency control
     * - Multiple order creations within 1 second will be handled by cron or next immediate trigger
     *
     * This prevents server crashes when many orders arrive simultaneously while ensuring
     * orders are still processed (either immediately or by the next cron cycle).
     */
    public function ajax_process_queue_immediate(): void
    {
        // Ignore user abort - continue processing even if request is cancelled
        ignore_user_abort(true);
        @set_time_limit(60); // Max 60 seconds for this batch

        // Throttle lock: Only allow 1 immediate sync request per second
        // This prevents multiple simultaneous requests from overwhelming the server
        $throttle_key = 'sap_wc_immediate_sync_throttle';
        if (get_transient($throttle_key)) {
            // Already processing or too soon since last run
            // Orders will be picked up by cron or next immediate trigger
            wp_send_json_success([
                'processed' => false,
                'status' => 'throttled',
                'message' => 'Queue processing already in progress',
            ]);
            return;
        }

        // Set throttle lock for 1 second
        set_transient($throttle_key, time(), 1);

        try {
            // Run queue worker (has its own concurrency lock)
            $worker = new Queue\Queue_Worker($this->sap_client);
            $results = $worker->process_batch();

            // Return processing results
            wp_send_json_success([
                'processed' => true,
                'status' => 'completed',
                'results' => $results,
                'message' => sprintf(
                    'Processed: %d, Failed: %d',
                    $results['processed'] ?? 0,
                    $results['failed'] ?? 0
                ),
            ]);
        } catch (\Exception $e) {
            // Log error but don't fail - orders will be retried by cron
            $this->logger->error('Immediate queue processing failed: ' . $e->getMessage(), [
                'entity_type' => Sync_Status::ENTITY_QUEUE,
                'exception' => get_class($e),
            ]);

            wp_send_json_success([
                'processed' => false,
                'status' => 'error',
                'message' => 'Queue processing failed, will retry via cron',
            ]);
        }
    }

    /**
     * AJAX: Bulk sync products (auto-map).
     */
    public function ajax_sync_products(): void
    {
        check_ajax_referer('sap_wc_sync_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        @set_time_limit(300);

        try {
            Rate_Limiter::enforce('sync_products', Config::RATE_BULK_SYNC);

            $matcher = new Sync\Product_Matcher($this->sap_client);
            $result = $matcher->execute_full_mapping();

            wp_send_json_success([
                'message' => sprintf(
                    __('Products matched. SKU: %d, Barcode: %d, Title: %d, Fuzzy: %d', 'sap-wc-sync'),
                    $result['sku_matched'] ?? 0,
                    $result['barcode_matched'] ?? 0,
                    $result['exact_matched'] ?? 0,
                    $result['fuzzy_matched'] ?? 0
                ),
                'result' => $result,
            ]);
        } catch (\SAPWCSync\Exceptions\SAP_Rate_Limit_Exception $e) {
            wp_send_json_error(['message' => __('Too many requests. Please wait.', 'sap-wc-sync')]);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * AJAX: Sync products in chunks with progress tracking.
     */
    public function ajax_sync_products_chunk(): void
    {
        check_ajax_referer('sap_wc_sync_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $offset = absint($_POST['offset'] ?? 0);
        $limit = absint($_POST['limit'] ?? 50);

        try {
            $matcher = new Sync\Product_Matcher($this->sap_client);
            $result = $matcher->execute_chunk($offset, $limit);

            wp_send_json_success($result);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * AJAX: Sync single product stock.
     */
    public function ajax_sync_single_product(): void
    {
        check_ajax_referer('sap_wc_sync_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $product_id = absint($_POST['product_id'] ?? 0);
        if (!$product_id) {
            wp_send_json_error(['message' => __('Invalid product ID', 'sap-wc-sync')]);
        }

        try {
            $sync = new Sync\Inventory_Sync($this->sap_client);
            $result = $sync->sync_single_product($product_id);

            wp_send_json_success([
                'message' => $result
                    ? __('Stock updated!', 'sap-wc-sync')
                    : __('Stock unchanged', 'sap-wc-sync'),
            ]);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * AJAX: Auto-map products using unified matcher.
     */
    public function ajax_auto_map(): void
    {
        check_ajax_referer('sap_wc_sync_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        try {
            Rate_Limiter::enforce('auto_map', Config::RATE_BULK_SYNC);

            $matcher = new Sync\Product_Matcher($this->sap_client);
            $result = $matcher->execute_full_mapping();

            $total = ($result['sku_matched'] ?? 0) + ($result['barcode_matched'] ?? 0)
                   + ($result['exact_matched'] ?? 0) + ($result['fuzzy_matched'] ?? 0);

            wp_send_json_success([
                'message' => sprintf('Mapped %d products', $total),
                'result' => $result,
            ]);
        } catch (\SAPWCSync\Exceptions\SAP_Rate_Limit_Exception $e) {
            wp_send_json_error(['message' => __('Too many requests. Please wait.', 'sap-wc-sync')]);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * AJAX: Manual map single product to SAP item.
     */
    public function ajax_manual_map_product(): void
    {
        check_ajax_referer('sap_wc_sync_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        try {
            Rate_Limiter::enforce('manual_map', Config::RATE_PRODUCT_MAPPING);
        } catch (\SAPWCSync\Exceptions\SAP_Rate_Limit_Exception $e) {
            wp_send_json_error(['message' => __('Too many requests. Please wait.', 'sap-wc-sync')]);
            return;
        }

        $product_id = absint($_POST['product_id'] ?? 0);
        $item_code = sanitize_text_field(wp_unslash($_POST['item_code'] ?? ''));

        if (!$product_id || !$item_code) {
            wp_send_json_error(['message' => 'Product ID and ItemCode are required']);
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error(['message' => 'Invalid product']);
        }

        $product_repo = new Product_Map_Repository();
        if ($product_repo->find_by_wc_id($product_id)) {
            wp_send_json_error(['message' => 'Product is already mapped']);
        }

        try {
            $sap_item = $this->sap_client->get("Items('{$item_code}')");
            if (empty($sap_item['ItemCode'])) {
                wp_send_json_error(['message' => 'SAP ItemCode not found']);
            }

            // Extract barcode
            $barcode = null;
            if (!empty($sap_item['ItemBarCodeCollection'])) {
                foreach ($sap_item['ItemBarCodeCollection'] as $bc) {
                    if (!empty($bc['Barcode'])) {
                        $barcode = $bc['Barcode'];
                        break;
                    }
                }
            }

            // SKU = SAP ItemCode (always). Barcode stored separately in meta.
            Product_Helper::update_sku($product, $item_code, false);
            Product_Helper::set_sap_item_code($product, $item_code, false);
            if (!empty($barcode)) {
                Product_Helper::set_sap_barcode($product, $barcode, false);
            }
            $product->save();

            // Create mapping via repository
            $product_repo->upsert_by_wc_id($product_id, [
                'sap_item_code' => $item_code,
                'sap_barcode' => $barcode,
                'sync_status' => Sync_Status::PENDING,
            ]);

            wp_send_json_success(['message' => "Product mapped to {$item_code}"]);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * AJAX: Manual order retry.
     */
    public function ajax_retry_order(): void
    {
        check_ajax_referer('sap_wc_sync_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $order_id = absint($_POST['order_id'] ?? 0);
        if (!$order_id) {
            wp_send_json_error(['message' => 'Invalid order ID']);
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(['message' => 'Order not found']);
        }

        try {
            $order_repo = new Order_Map_Repository();
            $mapping = $order_repo->find_by_wc_order($order_id);

            if (!$mapping) {
                wp_send_json_error(['message' => 'No sync record found for this order']);
            }

            // Reset retry state and attempt immediate sync
            $order_repo->upsert($order_id, [
                'sync_status' => Sync_Status::PENDING,
                'retry_count' => 0,
                'next_retry_at' => null,
                'error_message' => null,
            ]);

            $order_sync = new Sync\Order_Sync($this->sap_client);
            $order_sync->process_new_order($order_id);

            // Check result
            $updated = $order_repo->find_by_wc_order($order_id);
            if ($updated && in_array($updated->sync_status, Sync_Status::order_synced_statuses(), true)) {
                $order->add_order_note('Order sync manually retried and succeeded.');
                wp_send_json_success(['message' => 'Order synced to SAP successfully.']);
            } else {
                $order->add_order_note('Order sync manually retried: ' . ($updated->error_message ?? 'Unknown error'));
                wp_send_json_success(['message' => 'Retry initiated. Check logs for details.']);
            }
        } catch (\Exception $e) {
            $order->add_order_note('Manual retry failed: ' . $e->getMessage());
            wp_send_json_error(['message' => 'Retry failed: ' . $e->getMessage()]);
        }
    }

    /**
     * AJAX: Retry a dead letter event.
     */
    public function ajax_retry_dead_letter(): void
    {
        check_ajax_referer('sap_wc_sync_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $dead_letter_id = absint($_POST['dead_letter_id'] ?? 0);
        if (!$dead_letter_id) {
            wp_send_json_error(['message' => 'Invalid dead letter ID']);
        }

        global $wpdb;
        $dead_table = $wpdb->prefix . Config::TABLE_DEAD_LETTER;

        $dead_event = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$dead_table} WHERE id = %d AND resolved = 0",
            $dead_letter_id
        ));

        if (!$dead_event) {
            wp_send_json_error(['message' => 'Dead letter event not found or already resolved']);
        }

        // Re-enqueue the event
        $queue = new Queue_Manager();
        $new_id = $queue->enqueue(
            $dead_event->event_type,
            $dead_event->event_source,
            json_decode($dead_event->payload, true),
            1 // High priority for retries
        );

        // Mark as resolved
        $wpdb->update($dead_table, [
            'resolved' => 1,
            'resolved_at' => current_time('mysql'),
            'resolution_note' => "Re-enqueued as event #{$new_id}",
        ], ['id' => $dead_letter_id]);

        wp_send_json_success([
            'message' => "Dead letter re-enqueued as event #{$new_id}",
        ]);
    }

    /**
     * Get the shared SAP client instance.
     */
    public function get_sap_client(): SAP_Client
    {
        return $this->sap_client;
    }
}
