<?php
/**
 * Order Map Repository
 *
 * @package SAP_WooCommerce_Sync
 * @since 2.0.0
 */

namespace SAPWCSync\Repositories;

use SAPWCSync\Constants\Config;
use SAPWCSync\Constants\Sync_Status;

defined('ABSPATH') || exit;

class Order_Map_Repository extends Base_Repository
{
    protected function get_table_name(): string
    {
        return Config::TABLE_ORDER_MAP;
    }

    public function find_by_wc_order(int $order_id): ?object
    {
        return $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE wc_order_id = %d",
            $order_id
        ));
    }

    public function find_by_sap_doc(int $doc_entry): ?object
    {
        return $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE sap_doc_entry = %d",
            $doc_entry
        ));
    }

    /**
     * Save or update order mapping (upsert).
     */
    public function upsert(int $order_id, array $data): void
    {
        $existing = $this->find_by_wc_order($order_id);

        if ($existing) {
            $data['error_message'] = $data['error_message'] ?? null;
            $data['retry_count'] = $data['retry_count'] ?? 0;
            $data['next_retry_at'] = $data['next_retry_at'] ?? null;
            $this->wpdb->update($this->table, $data, ['wc_order_id' => $order_id]);
        } else {
            $data['wc_order_id'] = $order_id;
            $data['created_at'] = current_time('mysql');
            $this->wpdb->insert($this->table, $data);
        }
    }

    /**
     * Check if order has been successfully synced.
     */
    public function is_synced(int $order_id): bool
    {
        $status = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT sync_status FROM {$this->table} WHERE wc_order_id = %d",
            $order_id
        ));

        return in_array($status, Sync_Status::order_synced_statuses(), true);
    }

    /**
     * Get orders due for retry.
     *
     * Only retries 'error' status orders. 'pending' orders are handled
     * by the queue worker — including them here causes duplicate SOs.
     */
    public function get_retry_candidates(int $limit = 10): array
    {
        return $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE sync_status = 'error'
             AND retry_count < %d
             AND next_retry_at IS NOT NULL
             AND next_retry_at <= %s
             ORDER BY next_retry_at ASC
             LIMIT %d",
            Config::MAX_RETRY_ATTEMPTS,
            current_time('mysql'),
            $limit
        ));
    }

    /**
     * Schedule retry with exponential backoff.
     */
    public function schedule_retry(int $order_id, string $error_message, int $current_attempt): void
    {
        $delays = Config::RETRY_DELAYS;
        $delay = $delays[min($current_attempt, count($delays) - 1)];

        $this->wpdb->update(
            $this->table,
            [
                'sync_status' => Sync_Status::ERROR,
                'error_message' => $error_message,
                'retry_count' => $current_attempt + 1,
                'next_retry_at' => gmdate('Y-m-d H:i:s', time() + $delay),
            ],
            ['wc_order_id' => $order_id]
        );
    }

    /**
     * Get failed orders count (last 24 hours).
     */
    public function get_failed_count_24h(): int
    {
        return (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table}
             WHERE sync_status IN ('error', 'failed')
             AND updated_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
    }

    /**
     * Get pending orders count.
     */
    public function get_pending_count(): int
    {
        return (int) $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE sync_status = %s",
            Sync_Status::PENDING
        ));
    }
}
