<?php
/**
 * Log Cleanup - Automated log retention management
 *
 * @package SAP_WooCommerce_Sync
 * @since 2.0.0
 */

namespace SAPWCSync\Helpers;

use SAPWCSync\Constants\Config;

defined('ABSPATH') || exit;

class Log_Cleanup
{
    /**
     * Run cleanup and return stats.
     */
    public static function cleanup(): array
    {
        $retention_days = (int) Config::get(Config::OPT_LOG_RETENTION_DAYS, Config::DEFAULT_LOG_RETENTION);
        $logger = new Logger();
        $deleted = $logger->cleanup_old_logs($retention_days);

        // Optimize table periodically after large deletions
        if ($deleted > 100) {
            global $wpdb;
            $table = $wpdb->prefix . Config::TABLE_SYNC_LOG;
            $wpdb->query("OPTIMIZE TABLE {$table}");
        }

        return [
            'deleted' => $deleted,
            'retention_days' => $retention_days,
        ];
    }
}
