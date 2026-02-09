<?php
/**
 * Plugin Activator - Database schema creation and migration framework
 *
 * Creates all required database tables on activation and runs
 * incremental migrations when upgrading from a previous version.
 *
 * Best practices applied:
 * - Version-tracked migrations (never re-run a migration)
 * - dbDelta for idempotent table creation
 * - All cron hooks use 5-minute intervals (not 30-second polling)
 * - Password encryption migration from v1
 * - Event queue + dead letter queue tables for guaranteed delivery
 *
 * @package SAP_WooCommerce_Sync
 * @since 2.0.0
 */

namespace SAPWCSync;

use SAPWCSync\Constants\Config;

defined('ABSPATH') || exit;

class Activator
{
    /**
     * Run on plugin activation.
     */
    public static function activate(): void
    {
        self::create_tables();
        self::create_default_options();
        self::schedule_cron_jobs();
        self::run_migrations();
        flush_rewrite_rules();
    }

    /**
     * Create all database tables using dbDelta (idempotent).
     */
    private static function create_tables(): void
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $sql = [];

        // Product mapping table
        $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}" . Config::TABLE_PRODUCT_MAP . " (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            wc_product_id BIGINT UNSIGNED NOT NULL,
            sap_item_code VARCHAR(50) NOT NULL,
            sap_barcode VARCHAR(50) DEFAULT NULL,
            sap_stock INT DEFAULT NULL,
            sap_in_stock INT DEFAULT NULL,
            sap_committed INT DEFAULT NULL,
            sync_status ENUM('synced', 'pending', 'error') DEFAULT 'pending',
            last_sync_at DATETIME DEFAULT NULL,
            error_message TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_wc_product (wc_product_id),
            UNIQUE KEY unique_sap_item (sap_item_code),
            KEY idx_sync_status (sync_status),
            KEY idx_barcode (sap_barcode),
            KEY idx_stale_sync (sync_status, last_sync_at)
        ) {$charset_collate};";

        // Order mapping table
        $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}" . Config::TABLE_ORDER_MAP . " (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            wc_order_id BIGINT UNSIGNED NOT NULL,
            sap_doc_entry INT DEFAULT NULL,
            sap_doc_num INT DEFAULT NULL,
            sap_doc_type VARCHAR(50) DEFAULT NULL,
            payment_type ENUM('cod', 'prepaid') NOT NULL,
            sap_dp_invoice_entry INT DEFAULT NULL,
            sap_delivery_entry INT DEFAULT NULL,
            sap_ar_invoice_entry INT DEFAULT NULL,
            sync_status VARCHAR(30) DEFAULT 'pending',
            error_message TEXT DEFAULT NULL,
            retry_count INT DEFAULT 0,
            next_retry_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_wc_order (wc_order_id),
            KEY idx_sap_doc (sap_doc_entry, sap_doc_type),
            KEY idx_sync_status (sync_status),
            KEY idx_retry_queue (next_retry_at, retry_count),
            KEY idx_status_updated (sync_status, updated_at)
        ) {$charset_collate};";

        // Customer mapping table
        $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}" . Config::TABLE_CUSTOMER_MAP . " (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            wc_customer_id BIGINT UNSIGNED NOT NULL,
            sap_card_code VARCHAR(50) NOT NULL,
            sync_status ENUM('synced', 'pending', 'error') DEFAULT 'pending',
            last_sync_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_wc_customer (wc_customer_id),
            UNIQUE KEY unique_sap_card (sap_card_code)
        ) {$charset_collate};";

        // Sync log table
        $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}" . Config::TABLE_SYNC_LOG . " (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            entity_type VARCHAR(20) NOT NULL,
            entity_id BIGINT UNSIGNED DEFAULT NULL,
            sync_direction VARCHAR(20) DEFAULT 'sap_to_wc',
            status VARCHAR(20) NOT NULL,
            message TEXT DEFAULT NULL,
            request_payload LONGTEXT DEFAULT NULL,
            response_payload LONGTEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY idx_entity (entity_type, entity_id),
            KEY idx_status (status),
            KEY idx_created (created_at),
            KEY idx_entity_status_date (entity_type, status, created_at)
        ) {$charset_collate};";

        // Event queue table (persistent message queue)
        $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}" . Config::TABLE_EVENT_QUEUE . " (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            event_type VARCHAR(50) NOT NULL,
            event_source VARCHAR(10) NOT NULL DEFAULT 'sap',
            payload LONGTEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            priority TINYINT UNSIGNED NOT NULL DEFAULT 5,
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            max_attempts INT UNSIGNED NOT NULL DEFAULT 5,
            last_error TEXT DEFAULT NULL,
            process_after DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            locked_at DATETIME DEFAULT NULL,
            locked_by VARCHAR(100) DEFAULT NULL,
            completed_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_dequeue (status, process_after, priority, created_at),
            KEY idx_locked (status, locked_at),
            KEY idx_event_type (event_type),
            KEY idx_completed_cleanup (status, completed_at)
        ) {$charset_collate};";

        // Dead letter queue (permanently failed events)
        $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}" . Config::TABLE_DEAD_LETTER . " (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            original_event_id BIGINT UNSIGNED NOT NULL,
            event_type VARCHAR(50) NOT NULL,
            event_source VARCHAR(10) NOT NULL DEFAULT 'sap',
            payload LONGTEXT NOT NULL,
            error_history LONGTEXT DEFAULT NULL,
            total_attempts INT UNSIGNED NOT NULL DEFAULT 0,
            resolved TINYINT(1) NOT NULL DEFAULT 0,
            resolved_at DATETIME DEFAULT NULL,
            resolution_note TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY idx_unresolved (resolved, created_at),
            KEY idx_event_type (event_type),
            KEY idx_original (original_event_id)
        ) {$charset_collate};";

        // Migrations tracking table
        $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}" . Config::TABLE_MIGRATIONS . " (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            migration VARCHAR(100) NOT NULL,
            batch INT UNSIGNED NOT NULL DEFAULT 1,
            ran_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_migration (migration)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        foreach ($sql as $query) {
            dbDelta($query);
        }

        update_option(Config::OPT_DB_VERSION, Config::DB_VERSION);
    }

    /**
     * Create default option values (only if not already set).
     */
    private static function create_default_options(): void
    {
        $defaults = [
            Config::OPT_BASE_URL          => '',
            Config::OPT_COMPANY_DB        => '',
            Config::OPT_USERNAME          => '',
            Config::OPT_PASSWORD          => '',
            Config::OPT_DEFAULT_WAREHOUSE => Config::DEFAULT_WAREHOUSE,
            Config::OPT_SYNC_INTERVAL     => 5,
            Config::OPT_ENABLE_LOGGING    => 'yes',
            Config::OPT_ENABLE_ORDER_SYNC => 'yes',
            Config::OPT_ENABLE_INVENTORY  => 'yes',
            Config::OPT_LOG_RETENTION_DAYS => Config::DEFAULT_LOG_RETENTION,
            Config::OPT_ENABLE_WEBHOOKS   => 'no',
            Config::OPT_WEBHOOK_SECRET    => '',
        ];

        foreach ($defaults as $key => $value) {
            if (get_option($key) === false) {
                add_option($key, $value);
            }
        }
    }

    /**
     * Schedule all cron jobs (5-minute intervals, not 30-second polling).
     */
    private static function schedule_cron_jobs(): void
    {
        // Register custom 5-minute schedule before using it.
        // During activation, the Plugin class hasn't loaded yet so
        // the cron_schedules filter isn't registered. Without this,
        // wp_schedule_event() rejects the unknown schedule on WP 5.7+.
        add_filter('cron_schedules', function ($schedules) {
            if (!isset($schedules[Config::SCHEDULE_5MIN])) {
                $schedules[Config::SCHEDULE_5MIN] = [
                    'interval' => 300,
                    'display'  => 'Every 5 Minutes',
                ];
            }
            if (!isset($schedules[Config::SCHEDULE_DAILY])) {
                $schedules[Config::SCHEDULE_DAILY] = [
                    'interval' => DAY_IN_SECONDS,
                    'display'  => 'Once Daily',
                ];
            }
            return $schedules;
        });

        // Inventory sync every 5 minutes (fallback for events)
        if (!wp_next_scheduled(Config::CRON_INVENTORY_SYNC)) {
            wp_schedule_event(time(), Config::SCHEDULE_5MIN, Config::CRON_INVENTORY_SYNC);
        }

        // Retry failed orders every 5 minutes
        if (!wp_next_scheduled(Config::CRON_RETRY_ORDERS)) {
            wp_schedule_event(time(), Config::SCHEDULE_5MIN, Config::CRON_RETRY_ORDERS);
        }

        // Poll SAP order statuses every 5 minutes
        if (!wp_next_scheduled(Config::CRON_POLL_ORDERS)) {
            wp_schedule_event(time(), Config::SCHEDULE_5MIN, Config::CRON_POLL_ORDERS);
        }

        // Process event queue every 5 minutes
        if (!wp_next_scheduled(Config::CRON_PROCESS_QUEUE)) {
            wp_schedule_event(time(), Config::SCHEDULE_5MIN, Config::CRON_PROCESS_QUEUE);
        }

        // Health check every 5 minutes
        if (!wp_next_scheduled(Config::CRON_HEALTH_CHECK)) {
            wp_schedule_event(time(), Config::SCHEDULE_5MIN, Config::CRON_HEALTH_CHECK);
        }

        // Log cleanup daily at 2am
        if (!wp_next_scheduled(Config::CRON_CLEANUP_LOGS)) {
            $tomorrow_2am = strtotime('tomorrow 2:00am');
            wp_schedule_event($tomorrow_2am, Config::SCHEDULE_DAILY, Config::CRON_CLEANUP_LOGS);
        }
    }

    /**
     * Run pending migrations.
     *
     * Each migration runs exactly once. The migrations table tracks
     * which migrations have already been applied.
     */
    private static function run_migrations(): void
    {
        global $wpdb;

        $migration_table = $wpdb->prefix . Config::TABLE_MIGRATIONS;

        // Check if migration table exists yet
        $table_exists = $wpdb->get_var(
            $wpdb->prepare("SHOW TABLES LIKE %s", $migration_table)
        );
        if (!$table_exists) {
            return;
        }

        $ran = $wpdb->get_col("SELECT migration FROM {$migration_table}");
        $batch = (int) $wpdb->get_var("SELECT COALESCE(MAX(batch), 0) FROM {$migration_table}") + 1;

        $migrations = self::get_migrations();

        foreach ($migrations as $name => $callback) {
            if (in_array($name, $ran, true)) {
                continue;
            }

            try {
                $callback();
                $wpdb->insert($migration_table, [
                    'migration' => $name,
                    'batch' => $batch,
                    'ran_at' => current_time('mysql'),
                ]);
            } catch (\Exception $e) {
                error_log("SAP-WC Sync migration '{$name}' failed: " . $e->getMessage());
            }
        }
    }

    /**
     * Define all available migrations.
     *
     * Add new migrations at the bottom. They run in order and are
     * tracked in the migrations table so they never re-run.
     */
    private static function get_migrations(): array
    {
        return [
            '2.0.0_migrate_passwords' => function () {
                $password = get_option(Config::OPT_PASSWORD);
                if (empty($password)) {
                    return;
                }

                // Check if already encrypted (base64 pattern)
                if (!preg_match('/^[A-Za-z0-9+\/=]+$/', $password)) {
                    try {
                        $encrypted = \SAPWCSync\Security\Encryption::encrypt($password);
                        update_option(Config::OPT_PASSWORD, $encrypted);
                    } catch (\Exception $e) {
                        error_log('SAP-WC Sync: Password encryption failed: ' . $e->getMessage());
                    }
                }
            },

            '2.0.0_generate_webhook_secret' => function () {
                $secret = get_option(Config::OPT_WEBHOOK_SECRET);
                if (empty($secret)) {
                    update_option(Config::OPT_WEBHOOK_SECRET, wp_generate_password(64, true, true));
                }
            },

            '2.0.0_clear_legacy_cron' => function () {
                // Clear v1 30-second polling cron (bad practice)
                wp_clear_scheduled_hook('sap_wc_30sec');
                wp_clear_scheduled_hook('sap_wc_sync_products');
            },

            '2.0.1_fix_sku_to_itemcode' => function () {
                // Fix: SKU must be SAP ItemCode, not barcode.
                // Moves barcode from SKU to _sap_barcode product meta.
                global $wpdb;
                $table = $wpdb->prefix . Config::TABLE_PRODUCT_MAP;
                $mappings = $wpdb->get_results("SELECT wc_product_id, sap_item_code, sap_barcode FROM {$table}");

                $fixed = 0;
                foreach ($mappings as $mapping) {
                    $product = wc_get_product($mapping->wc_product_id);
                    if (!$product) {
                        continue;
                    }

                    $current_sku = $product->get_sku();
                    $item_code = $mapping->sap_item_code;

                    // Ensure _sap_barcode meta is populated from mapping table
                    if (!empty($mapping->sap_barcode)) {
                        $product->update_meta_data('_sap_barcode', $mapping->sap_barcode);
                    }

                    // If SKU is already the ItemCode, just save barcode meta
                    if ($current_sku === $item_code) {
                        if (!empty($mapping->sap_barcode)) {
                            $product->save();
                        }
                        continue;
                    }

                    // SKU is not the ItemCode (likely a barcode) — fix it
                    if (!empty($current_sku) && $current_sku !== $item_code) {
                        $product->update_meta_data('_sap_barcode', $current_sku);
                    }

                    try {
                        $product->set_sku($item_code);
                        $product->save();
                        $fixed++;
                    } catch (\Exception $e) {
                        error_log("SAP-WC migration: Could not set SKU to {$item_code} for product #{$mapping->wc_product_id}: " . $e->getMessage());
                    }
                }

                if ($fixed > 0) {
                    error_log("SAP-WC migration: Fixed SKU to ItemCode for {$fixed} products");
                }
            },
        ];
    }
}
