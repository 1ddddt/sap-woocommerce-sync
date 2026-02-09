<?php
/**
 * Database Logger
 *
 * Structured logging to wp_sap_wc_sync_log with entity tracking,
 * payload truncation, and configurable log levels.
 *
 * @package SAP_WooCommerce_Sync
 * @since 2.0.0
 */

namespace SAPWCSync\Helpers;

use SAPWCSync\Constants\Config;
use SAPWCSync\Constants\Sync_Status;
use SAPWCSync\Interfaces\Logger_Interface;

defined('ABSPATH') || exit;

class Logger implements Logger_Interface
{
    /**
     * Log a sync event to the database.
     */
    public function log(string $entity_type, ?int $entity_id, string $direction, string $status, string $message, $request = null, $response = null): void
    {
        if (Config::get(Config::OPT_ENABLE_LOGGING) !== 'yes') {
            return;
        }

        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . Config::TABLE_SYNC_LOG,
            [
                'entity_type'      => sanitize_text_field($entity_type),
                'entity_id'        => $entity_id,
                'sync_direction'   => sanitize_text_field($direction),
                'status'           => sanitize_text_field($status),
                'message'          => sanitize_text_field(substr($message, 0, 65535)),
                'request_payload'  => $request ? self::truncate_payload($request) : null,
                'response_payload' => $response ? self::truncate_payload($response) : null,
                'created_at'       => current_time('mysql'),
            ],
            ['%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s']
        );
    }

    /**
     * Log info message.
     */
    public function info(string $message, array $context = []): void
    {
        $this->log(
            $context['entity_type'] ?? Sync_Status::ENTITY_SYSTEM,
            $context['entity_id'] ?? null,
            $context['direction'] ?? Sync_Status::DIRECTION_SAP_TO_WC,
            Sync_Status::LOG_INFO,
            $message,
            $context['request'] ?? null,
            $context['response'] ?? null
        );
    }

    /**
     * Log error message.
     */
    public function error(string $message, array $context = []): void
    {
        $this->log(
            $context['entity_type'] ?? Sync_Status::ENTITY_SYSTEM,
            $context['entity_id'] ?? null,
            $context['direction'] ?? Sync_Status::DIRECTION_SAP_TO_WC,
            Sync_Status::LOG_ERROR,
            $message,
            $context['request'] ?? null,
            $context['response'] ?? null
        );

        // Always write errors to PHP error log as well
        error_log('[SAP-WC] ' . $message);
    }

    /**
     * Log warning message.
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log(
            $context['entity_type'] ?? Sync_Status::ENTITY_SYSTEM,
            $context['entity_id'] ?? null,
            $context['direction'] ?? Sync_Status::DIRECTION_SAP_TO_WC,
            Sync_Status::LOG_WARNING,
            $message,
            $context['request'] ?? null,
            $context['response'] ?? null
        );
    }

    /**
     * Log success message.
     */
    public function success(string $message, array $context = []): void
    {
        $this->log(
            $context['entity_type'] ?? Sync_Status::ENTITY_SYSTEM,
            $context['entity_id'] ?? null,
            $context['direction'] ?? Sync_Status::DIRECTION_SAP_TO_WC,
            Sync_Status::LOG_SUCCESS,
            $message,
            $context['request'] ?? null,
            $context['response'] ?? null
        );
    }

    /**
     * Get recent logs (implements Logger_Interface).
     */
    public function get_recent_logs(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $page = $offset > 0 ? (int) floor($offset / $limit) + 1 : 1;

        return self::get_logs(array_merge($filters, [
            'page'     => $page,
            'per_page' => $limit,
        ]));
    }

    /**
     * Static: Get logs with pagination (used by admin templates).
     */
    public static function get_logs(array $args = []): array
    {
        global $wpdb;

        $table    = $wpdb->prefix . Config::TABLE_SYNC_LOG;
        $page     = max(1, (int) ($args['page'] ?? 1));
        $per_page = max(1, (int) ($args['per_page'] ?? 50));
        $offset   = ($page - 1) * $per_page;

        $where  = ['1=1'];
        $values = [];

        if (!empty($args['entity_type'])) {
            $where[] = 'entity_type = %s';
            $values[] = $args['entity_type'];
        }
        if (!empty($args['status'])) {
            $where[] = 'status = %s';
            $values[] = $args['status'];
        }

        $where_clause = implode(' AND ', $where);
        $values[] = $per_page;
        $values[] = $offset;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $values
        ));
    }

    /**
     * Static: Get log count (used by admin templates).
     */
    public static function get_log_count(array $args = []): int
    {
        global $wpdb;

        $table  = $wpdb->prefix . Config::TABLE_SYNC_LOG;
        $where  = ['1=1'];
        $values = [];

        if (!empty($args['entity_type'])) {
            $where[] = 'entity_type = %s';
            $values[] = $args['entity_type'];
        }

        if (!empty($args['status'])) {
            $where[] = 'status = %s';
            $values[] = $args['status'];
        }

        $where_clause = implode(' AND ', $where);

        if (!empty($values)) {
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE {$where_clause}",
                $values
            ));
        }

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }

    /**
     * Delete logs older than specified days.
     */
    public function cleanup_old_logs(int $days = 30): int
    {
        global $wpdb;

        $table = $wpdb->prefix . Config::TABLE_SYNC_LOG;
        $batch_size = Config::LOG_CLEANUP_BATCH_SIZE;
        $total_deleted = 0;

        // Delete in batches to avoid locking the table for too long
        do {
            $deleted = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY) LIMIT %d",
                $days,
                $batch_size
            ));

            $total_deleted += max(0, (int) $deleted);
        } while ($deleted > 0 && $deleted >= $batch_size);

        return $total_deleted;
    }

    /**
     * Truncate payload to prevent DB bloat.
     */
    private static function truncate_payload($data): ?string
    {
        if (is_array($data) || is_object($data)) {
            $json = wp_json_encode($data);
        } else {
            $json = (string) $data;
        }

        if (strlen($json) > Config::MAX_PAYLOAD_SIZE) {
            $json = substr($json, 0, Config::MAX_PAYLOAD_SIZE) . '... [TRUNCATED]';
        }

        return $json;
    }
}
