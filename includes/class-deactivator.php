<?php
/**
 * Plugin Deactivator - Properly clears ALL managed resources
 *
 * Fixes v1 bug where only 2 of 4+ cron hooks were cleared.
 * Uses Config::all_cron_hooks() to ensure every hook is removed.
 *
 * @package SAP_WooCommerce_Sync
 * @since 2.0.0
 */

namespace SAPWCSync;

use SAPWCSync\Constants\Config;

defined('ABSPATH') || exit;

class Deactivator
{
    /**
     * Run on plugin deactivation.
     */
    public static function deactivate(): void
    {
        self::clear_all_cron_hooks();
        self::release_queue_locks();
        flush_rewrite_rules();
    }

    /**
     * Clear ALL scheduled cron hooks managed by this plugin.
     *
     * v1 BUG FIX: The old deactivator only cleared 2 hooks
     * (sap_wc_sync_inventory, sap_wc_sync_products), leaving
     * retry_orders, poll_orders, and cleanup_logs running forever.
     */
    private static function clear_all_cron_hooks(): void
    {
        foreach (Config::all_cron_hooks() as $hook) {
            wp_clear_scheduled_hook($hook);
        }

        // Also clear any legacy v1 hooks
        wp_clear_scheduled_hook('sap_wc_30sec');
        wp_clear_scheduled_hook('sap_wc_sync_products');
    }

    /**
     * Release any stale queue locks to prevent stuck events
     * when the plugin is reactivated.
     */
    private static function release_queue_locks(): void
    {
        global $wpdb;

        $queue_table = $wpdb->prefix . Config::TABLE_EVENT_QUEUE;

        // Check if table exists before querying
        $table_exists = $wpdb->get_var(
            $wpdb->prepare("SHOW TABLES LIKE %s", $queue_table)
        );

        if ($table_exists) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$queue_table}
                 SET status = 'pending', locked_at = NULL, locked_by = NULL
                 WHERE status = %s",
                'processing'
            ));
        }
    }
}
