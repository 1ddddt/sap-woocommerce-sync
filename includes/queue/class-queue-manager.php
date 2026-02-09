<?php
/**
 * Event Queue Manager
 *
 * Persistent database-backed message queue with pessimistic locking,
 * priority ordering, and dead letter handling.
 *
 * This is the core of the guaranteed delivery system. Events are enqueued
 * immediately and processed asynchronously by the Queue Worker.
 *
 * Queue guarantees:
 * - At-least-once delivery (events may be processed more than once; consumers must be idempotent)
 * - Ordered by priority then creation time
 * - Automatic retry with exponential backoff
 * - Dead letter queue for permanent failures
 * - Pessimistic locking prevents concurrent processing of same event
 *
 * @package SAP_WooCommerce_Sync
 * @since 2.0.0
 */

namespace SAPWCSync\Queue;

use SAPWCSync\Constants\Config;
use SAPWCSync\Constants\Sync_Status;
use SAPWCSync\Helpers\Logger;

defined('ABSPATH') || exit;

class Queue_Manager
{
    private $wpdb;
    private $table;
    private $dead_table;
    private $logger;
    private $worker_id;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . Config::TABLE_EVENT_QUEUE;
        $this->dead_table = $wpdb->prefix . Config::TABLE_DEAD_LETTER;
        $this->logger = new Logger();
        $this->worker_id = gethostname() . '-' . getmypid();
    }

    /**
     * Enqueue a new event for processing.
     *
     * @param string $event_type e.g., 'order.placed', 'item.stock_changed'
     * @param string $source 'sap' or 'wc'
     * @param array $payload Event data
     * @param int $priority 1=highest, 10=lowest (default 5)
     * @param int $delay_seconds Seconds to wait before processing
     * @return int Event ID
     */
    public function enqueue(string $event_type, string $source, array $payload, int $priority = 5, int $delay_seconds = 0): int
    {
        $process_after = current_time('mysql');
        if ($delay_seconds > 0) {
            $process_after = gmdate('Y-m-d H:i:s', time() + $delay_seconds);
        }

        $this->wpdb->insert($this->table, [
            'event_type'    => $event_type,
            'event_source'  => $source,
            'payload'       => wp_json_encode($payload),
            'status'        => Sync_Status::QUEUE_PENDING,
            'priority'      => $priority,
            'attempts'      => 0,
            'max_attempts'  => Config::DEAD_LETTER_THRESHOLD,
            'process_after' => $process_after,
            'created_at'    => current_time('mysql'),
            'updated_at'    => current_time('mysql'),
        ], ['%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s']);

        $event_id = (int) $this->wpdb->insert_id;

        $this->logger->info("Event enqueued: {$event_type} (ID: {$event_id})", [
            'entity_type' => Sync_Status::ENTITY_QUEUE,
            'entity_id' => $event_id,
        ]);

        return $event_id;
    }

    /**
     * Dequeue next batch of events for processing.
     *
     * Uses SELECT ... FOR UPDATE to prevent concurrent workers
     * from processing the same events.
     *
     * @param int $batch_size Max events to dequeue
     * @return array Events ready for processing
     */
    public function dequeue(?int $batch_size = null): array
    {
        $batch_size = $batch_size ?? Config::QUEUE_BATCH_SIZE;

        // Atomically claim events using pessimistic lock
        $this->wpdb->query('START TRANSACTION');

        $events = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE status = %s
             AND process_after <= %s
             AND (locked_at IS NULL OR locked_at < DATE_SUB(NOW(), INTERVAL %d SECOND))
             ORDER BY priority ASC, created_at ASC
             LIMIT %d
             FOR UPDATE",
            Sync_Status::QUEUE_PENDING,
            current_time('mysql'),
            Config::QUEUE_LOCK_TIMEOUT,
            $batch_size
        ));

        if (empty($events)) {
            $this->wpdb->query('COMMIT');
            return [];
        }

        // Lock claimed events
        $ids = array_map(function ($e) { return (int) $e->id; }, $events);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->table}
             SET status = %s, locked_at = %s, locked_by = %s, updated_at = %s
             WHERE id IN ({$placeholders})",
            array_merge(
                [Sync_Status::QUEUE_PROCESSING, current_time('mysql'), $this->worker_id, current_time('mysql')],
                $ids
            )
        ));

        $this->wpdb->query('COMMIT');

        // Decode payloads
        foreach ($events as &$event) {
            $event->payload = json_decode($event->payload, true);
        }

        return $events;
    }

    /**
     * Acknowledge successful processing.
     */
    public function ack(int $event_id): void
    {
        $this->wpdb->update(
            $this->table,
            [
                'status'       => Sync_Status::QUEUE_COMPLETED,
                'completed_at' => current_time('mysql'),
                'locked_at'    => null,
                'locked_by'    => null,
                'updated_at'   => current_time('mysql'),
            ],
            ['id' => $event_id]
        );
    }

    /**
     * Negative acknowledge - event failed, schedule retry or dead letter.
     */
    public function nack(int $event_id, string $error_message): void
    {
        $event = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d",
            $event_id
        ));

        if (!$event) {
            return;
        }

        $new_attempts = (int) $event->attempts + 1;

        if ($new_attempts >= (int) $event->max_attempts) {
            // Move to dead letter queue
            $this->move_to_dead_letter($event, $error_message, $new_attempts);
            return;
        }

        // Schedule retry with exponential backoff
        $delays = Config::RETRY_DELAYS;
        $delay = $delays[min($new_attempts - 1, count($delays) - 1)];

        $this->wpdb->update(
            $this->table,
            [
                'status'        => Sync_Status::QUEUE_PENDING,
                'attempts'      => $new_attempts,
                'last_error'    => substr($error_message, 0, 65535),
                'process_after' => gmdate('Y-m-d H:i:s', time() + $delay),
                'locked_at'     => null,
                'locked_by'     => null,
                'updated_at'    => current_time('mysql'),
            ],
            ['id' => $event_id]
        );

        $this->logger->warning("Event {$event_id} failed (attempt {$new_attempts}), retrying in {$delay}s: {$error_message}", [
            'entity_type' => Sync_Status::ENTITY_QUEUE,
            'entity_id' => $event_id,
        ]);
    }

    /**
     * Move permanently failed event to dead letter queue.
     */
    private function move_to_dead_letter(object $event, string $error_message, int $total_attempts): void
    {
        // Build error history
        $error_history = [];
        if (!empty($event->last_error)) {
            $error_history[] = $event->last_error;
        }
        $error_history[] = $error_message;

        $this->wpdb->insert($this->dead_table, [
            'original_event_id' => $event->id,
            'event_type'        => $event->event_type,
            'event_source'      => $event->event_source,
            'payload'           => $event->payload, // Already JSON string
            'error_history'     => wp_json_encode($error_history),
            'total_attempts'    => $total_attempts,
            'resolved'          => 0,
            'created_at'        => current_time('mysql'),
        ], ['%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s']);

        // Mark original as dead
        $this->wpdb->update(
            $this->table,
            [
                'status'     => Sync_Status::QUEUE_DEAD,
                'locked_at'  => null,
                'locked_by'  => null,
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $event->id]
        );

        $this->logger->error("Event {$event->id} ({$event->event_type}) moved to dead letter after {$total_attempts} attempts: {$error_message}", [
            'entity_type' => Sync_Status::ENTITY_QUEUE,
            'entity_id' => $event->id,
        ]);
    }

    /**
     * Get queue depth (pending events).
     */
    public function get_queue_depth(): int
    {
        return (int) $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE status = %s",
            Sync_Status::QUEUE_PENDING
        ));
    }

    /**
     * Get dead letter count (unresolved).
     */
    public function get_dead_letter_count(): int
    {
        return (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->dead_table} WHERE resolved = 0"
        );
    }

    /**
     * Release stale locks (events locked longer than timeout).
     */
    public function release_stale_locks(): int
    {
        $result = $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->table}
             SET status = %s, locked_at = NULL, locked_by = NULL, updated_at = %s
             WHERE status = %s AND locked_at < DATE_SUB(NOW(), INTERVAL %d SECOND)",
            Sync_Status::QUEUE_PENDING,
            current_time('mysql'),
            Sync_Status::QUEUE_PROCESSING,
            Config::QUEUE_LOCK_TIMEOUT
        ));

        return max(0, (int) $result);
    }

    /**
     * Cleanup old completed events (keep last N days).
     */
    public function cleanup_completed(int $days = 7): int
    {
        $result = $this->wpdb->query($this->wpdb->prepare(
            "DELETE FROM {$this->table}
             WHERE status = %s AND completed_at < DATE_SUB(NOW(), INTERVAL %d DAY)
             LIMIT 1000",
            Sync_Status::QUEUE_COMPLETED,
            $days
        ));

        return max(0, (int) $result);
    }
}
