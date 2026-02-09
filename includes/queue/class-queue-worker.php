<?php
/**
 * Queue Worker - Processes events from the queue
 *
 * Routes events to appropriate handlers based on event_type.
 * Each handler is responsible for idempotent processing.
 *
 * @package SAP_WooCommerce_Sync
 * @since 2.0.0
 */

namespace SAPWCSync\Queue;

use SAPWCSync\Constants\Config;
use SAPWCSync\Constants\Sync_Status;
use SAPWCSync\Helpers\Logger;
use SAPWCSync\API\SAP_Client;
use SAPWCSync\Webhooks\Event_Processor;

defined('ABSPATH') || exit;

class Queue_Worker
{
    private $queue_manager;
    private $event_processor;
    private $logger;

    public function __construct(SAP_Client $sap_client)
    {
        $this->queue_manager = new Queue_Manager();
        $this->event_processor = new Event_Processor($sap_client);
        $this->logger = new Logger();
    }

    /**
     * Process a batch of queued events.
     *
     * Called by WP-Cron every 5 minutes.
     * Uses concurrency lock to prevent overlapping runs.
     *
     * @param int|null $batch_size Max events to dequeue (null = Config default)
     * @return array Processing results
     */
    public function process_batch(?int $batch_size = null): array
    {
        $results = ['processed' => 0, 'failed' => 0, 'skipped' => 0];

        // Concurrency lock
        $lock_key = 'sap_wc_queue_worker_lock';
        if (get_transient($lock_key)) {
            return $results;
        }
        set_transient($lock_key, time(), Config::QUEUE_LOCK_TIMEOUT);

        try {
            // Release any stale locks first
            $released = $this->queue_manager->release_stale_locks();
            if ($released > 0) {
                $this->logger->warning("Released {$released} stale queue locks", [
                    'entity_type' => Sync_Status::ENTITY_QUEUE,
                ]);
            }

            // Dequeue events
            $events = $this->queue_manager->dequeue($batch_size);

            foreach ($events as $event) {
                try {
                    $this->event_processor->process($event);
                    $this->queue_manager->ack($event->id);
                    $results['processed']++;
                } catch (\Exception $e) {
                    $this->queue_manager->nack($event->id, $e->getMessage());
                    $results['failed']++;
                }
            }

        } finally {
            delete_transient($lock_key);
        }

        // Log summary if anything happened
        if ($results['processed'] > 0 || $results['failed'] > 0) {
            $this->logger->info(sprintf(
                'Queue batch completed: %d processed, %d failed',
                $results['processed'],
                $results['failed']
            ), ['entity_type' => Sync_Status::ENTITY_QUEUE]);
        }

        return $results;
    }
}
