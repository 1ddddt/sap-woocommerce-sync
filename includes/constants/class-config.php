<?php
/**
 * Centralized Configuration Constants
 *
 * All business logic constants, SAP defaults, and plugin configuration
 * are defined here to avoid hardcoded values scattered across files.
 * Values can be overridden via WordPress options or filters.
 *
 * @package SAP_WooCommerce_Sync
 * @since 2.0.0
 */

namespace SAPWCSync\Constants;

defined('ABSPATH') || exit;

class Config
{
    // Plugin version
    const VERSION = '2.0.3';

    // Database version for migration tracking
    const DB_VERSION = '2.0.1';

    // Table names (without prefix)
    const TABLE_PRODUCT_MAP  = 'sap_wc_product_map';
    const TABLE_ORDER_MAP    = 'sap_wc_order_map';
    const TABLE_CUSTOMER_MAP = 'sap_wc_customer_map';
    const TABLE_SYNC_LOG     = 'sap_wc_sync_log';
    const TABLE_EVENT_QUEUE  = 'sap_wc_event_queue';
    const TABLE_DEAD_LETTER  = 'sap_wc_dead_letter_queue';
    const TABLE_MIGRATIONS   = 'sap_wc_migrations';

    // Option keys
    const OPT_BASE_URL            = 'sap_wc_base_url';
    const OPT_COMPANY_DB          = 'sap_wc_company_db';
    const OPT_USERNAME            = 'sap_wc_username';
    const OPT_PASSWORD            = 'sap_wc_password';
    const OPT_DEFAULT_WAREHOUSE   = 'sap_wc_default_warehouse';
    const OPT_SYNC_INTERVAL       = 'sap_wc_sync_interval';
    const OPT_ENABLE_LOGGING      = 'sap_wc_enable_logging';
    const OPT_ENABLE_ORDER_SYNC   = 'sap_wc_enable_order_sync';
    const OPT_ENABLE_INVENTORY    = 'sap_wc_enable_inventory_sync';
    const OPT_LOG_RETENTION_DAYS  = 'sap_wc_log_retention_days';
    const OPT_DB_VERSION          = 'sap_wc_sync_db_version';
    const OPT_LAST_INVENTORY_SYNC = 'sap_wc_last_inventory_sync';
    const OPT_DEFAULT_CUSTOMER    = 'sap_wc_default_customer';
    const OPT_TRANSFER_ACCOUNT    = 'sap_wc_transfer_account';
    const OPT_WEBHOOK_SECRET      = 'sap_wc_webhook_secret';
    const OPT_ENABLE_WEBHOOKS     = 'sap_wc_enable_webhooks';
    const OPT_CREDIT_CARD_CODE    = 'sap_wc_credit_card_code';
    const OPT_CREDIT_CARD_ACCOUNT = 'sap_wc_credit_card_account';
    const OPT_FREIGHT_EXPENSE_CODE = 'sap_wc_freight_expense_code';
    const OPT_SHIPPING_TAX_CODE   = 'sap_wc_shipping_tax_code';
    const OPT_SHIPPING_ITEM_CODE  = 'sap_wc_shipping_item_code';
    const OPT_IMMEDIATE_SYNC      = 'sap_wc_immediate_sync';

    // SAP Business Logic Defaults (overridable via options/filters)
    const DEFAULT_WAREHOUSE        = 'WEB-GEN';
    const DEFAULT_SALES_ORDER_SERIES = 91;
    const DEFAULT_ACCOUNT_CODE     = '41110001';
    const DEFAULT_TAX_CODE         = 'VAT@18';
    const DEFAULT_DOC_DUE_DAYS     = 7;
    const DEFAULT_PRICE_LIST       = 1;
    const DEFAULT_FREIGHT_EXPENSE_CODE = 1;  // SAP Expense Code for Freight (deprecated, using line item)
    const DEFAULT_SHIPPING_ITEM_CODE = 'NON-00002';  // SAP Item Code for shipping service (Delivery Chgs - Web)

    // Sync configuration
    const INVENTORY_SYNC_INTERVAL  = 300;  // 5 minutes (not 30 seconds!)
    const RETRY_QUEUE_INTERVAL     = 300;  // 5 minutes
    const ORDER_POLL_INTERVAL      = 300;  // 5 minutes
    const HEALTH_CHECK_INTERVAL    = 300;  // 5 minutes
    const LOG_CLEANUP_INTERVAL     = 86400; // Daily

    // SAP API limits
    const SAP_PAGE_SIZE            = 20;   // SAP Service Layer hard limit
    const SAP_BATCH_SIZE           = 20;   // Match page size to avoid silent drops
    const SAP_SESSION_TIMEOUT      = 30;   // Minutes
    const SAP_SESSION_REFRESH_PCT  = 0.8;  // Refresh at 80% of timeout

    // Retry configuration
    const MAX_RETRY_ATTEMPTS       = 5;
    const RETRY_DELAYS             = [60, 300, 900, 3600, 7200]; // 1min, 5min, 15min, 1hr, 2hr

    // Queue configuration
    const QUEUE_BATCH_SIZE         = 10;   // Events processed per worker cycle
    const QUEUE_LOCK_TIMEOUT       = 300;  // 5 min lock duration
    const DEAD_LETTER_THRESHOLD    = 5;    // Max attempts before dead letter

    // Circuit breaker
    const CIRCUIT_FAILURE_THRESHOLD = 5;    // Consecutive failures to trip
    const CIRCUIT_FAILURE_WINDOW    = 60;   // Seconds to count failures
    const CIRCUIT_COOLDOWN          = 30;   // Seconds before half-open test
    const CIRCUIT_SUCCESS_THRESHOLD = 1;    // Successes to close circuit

    // Rate limiting
    const RATE_TEST_CONNECTION     = 5;    // per minute
    const RATE_MANUAL_SYNC         = 3;    // per minute
    const RATE_BULK_SYNC           = 2;    // per minute
    const RATE_PRODUCT_MAPPING     = 10;   // per minute

    // Fuzzy match threshold (unified across all matchers)
    const FUZZY_MATCH_THRESHOLD    = 85;   // Percentage similarity

    // Log configuration
    const DEFAULT_LOG_RETENTION    = 30;   // Days
    const MAX_PAYLOAD_SIZE         = 65536; // 64KB max for log payloads
    const LOG_CLEANUP_BATCH_SIZE   = 1000;  // Delete in batches

    // CardCode generation
    const CARDCODE_PREFIX          = 'WC_';
    const CARDCODE_HASH_LENGTH     = 12;   // Characters (more than 8 to reduce collision)

    // Cron hook names
    const CRON_INVENTORY_SYNC      = 'sap_wc_sync_inventory';
    const CRON_RETRY_ORDERS        = 'sap_wc_retry_orders';
    const CRON_POLL_ORDERS         = 'sap_wc_poll_orders';
    const CRON_CLEANUP_LOGS        = 'sap_wc_cleanup_logs';
    const CRON_PROCESS_QUEUE       = 'sap_wc_process_queue';
    const CRON_HEALTH_CHECK        = 'sap_wc_health_check';
    const CRON_DEFERRED_STOCK      = 'sap_wc_deferred_stock_refresh';

    // Cron schedule names
    const SCHEDULE_5MIN            = 'sap_wc_5min';
    const SCHEDULE_DAILY           = 'sap_wc_daily';

    // SAP Payment Method mapping (extensible via filter 'sap_wc_payment_method_map')
    const PAYMENT_METHOD_MAP = [
        'cod'              => 'Cash On Delivery',
        'cashondelivery'   => 'Cash On Delivery',
        'bacs'             => 'Bank Transfer',
        'cheque'           => 'Cheque',
        'paypal'           => 'Online Transfer',
        'stripe'           => 'Online Transfer',
        'square'           => 'Online Transfer',
        'razorpay'         => 'Online Transfer',
        'woocommerce_payments' => 'Online Transfer',
    ];

    // Countries with SAP state master data
    const COUNTRIES_WITH_STATES = ['US', 'CA', 'IN', 'AU', 'BR'];

    /**
     * Get option value with fallback to constant default.
     */
    public static function get(string $option_key, $default = null)
    {
        $value = get_option($option_key, $default);
        return $value !== false ? $value : $default;
    }

    /**
     * Get warehouse code from options or default.
     */
    public static function warehouse(): string
    {
        return self::get(self::OPT_DEFAULT_WAREHOUSE, self::DEFAULT_WAREHOUSE);
    }

    /**
     * Get all cron hooks managed by this plugin.
     */
    public static function all_cron_hooks(): array
    {
        return [
            self::CRON_INVENTORY_SYNC,
            self::CRON_RETRY_ORDERS,
            self::CRON_POLL_ORDERS,
            self::CRON_CLEANUP_LOGS,
            self::CRON_PROCESS_QUEUE,
            self::CRON_HEALTH_CHECK,
        ];
    }

    /**
     * Get all database table names (with prefix).
     */
    public static function all_tables(): array
    {
        global $wpdb;
        return [
            $wpdb->prefix . self::TABLE_PRODUCT_MAP,
            $wpdb->prefix . self::TABLE_ORDER_MAP,
            $wpdb->prefix . self::TABLE_CUSTOMER_MAP,
            $wpdb->prefix . self::TABLE_SYNC_LOG,
            $wpdb->prefix . self::TABLE_EVENT_QUEUE,
            $wpdb->prefix . self::TABLE_DEAD_LETTER,
            $wpdb->prefix . self::TABLE_MIGRATIONS,
        ];
    }

    /**
     * Get all option keys managed by this plugin.
     */
    public static function all_options(): array
    {
        return [
            self::OPT_BASE_URL,
            self::OPT_COMPANY_DB,
            self::OPT_USERNAME,
            self::OPT_PASSWORD,
            self::OPT_DEFAULT_WAREHOUSE,
            self::OPT_SYNC_INTERVAL,
            self::OPT_ENABLE_LOGGING,
            self::OPT_ENABLE_ORDER_SYNC,
            self::OPT_ENABLE_INVENTORY,
            self::OPT_LOG_RETENTION_DAYS,
            self::OPT_DB_VERSION,
            self::OPT_LAST_INVENTORY_SYNC,
            self::OPT_DEFAULT_CUSTOMER,
            self::OPT_TRANSFER_ACCOUNT,
            self::OPT_WEBHOOK_SECRET,
            self::OPT_ENABLE_WEBHOOKS,
            self::OPT_CREDIT_CARD_CODE,
            self::OPT_CREDIT_CARD_ACCOUNT,
            self::OPT_FREIGHT_EXPENSE_CODE,
            self::OPT_SHIPPING_TAX_CODE,
            self::OPT_SHIPPING_ITEM_CODE,
            self::OPT_IMMEDIATE_SYNC,
        ];
    }

    /**
     * Map WC payment method to SAP payment method string.
     */
    public static function map_payment_method(string $wc_method, string $wc_title = ''): string
    {
        $slug = strtolower($wc_method);

        if (isset(self::PAYMENT_METHOD_MAP[$slug])) {
            return self::PAYMENT_METHOD_MAP[$slug];
        }

        // Fallback: keyword match on display title
        $title_lower = strtolower($wc_title);
        if (strpos($title_lower, 'cash') !== false || strpos($title_lower, 'cod') !== false) {
            return 'Cash On Delivery';
        }
        if (strpos($title_lower, 'bank') !== false || strpos($title_lower, 'wire') !== false) {
            return 'Bank Transfer';
        }
        if (strpos($title_lower, 'cheque') !== false || strpos($title_lower, 'check') !== false) {
            return 'Cheque';
        }

        return 'Online Transfer';
    }
}
