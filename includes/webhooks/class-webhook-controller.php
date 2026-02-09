<?php
/**
 * Webhook Controller - REST API endpoint for SAP events
 *
 * Receives incoming events from SAP and enqueues them for processing.
 * All processing happens asynchronously via the queue system.
 *
 * Endpoint: POST /wp-json/sap-wc/v1/webhook
 * Authentication: HMAC-SHA256 signature in X-SAP-Signature header
 *
 * @package SAP_WooCommerce_Sync
 * @since 2.0.0
 */

namespace SAPWCSync\Webhooks;

use SAPWCSync\Constants\Config;
use SAPWCSync\Queue\Queue_Manager;
use SAPWCSync\Helpers\Logger;

defined('ABSPATH') || exit;

class Webhook_Controller
{
    private $logger;

    public function __construct()
    {
        $this->logger = new Logger();
    }

    /**
     * Register all REST API routes.
     */
    public function register_routes(): void
    {
        $this->register_webhook_route();
        $this->register_health_route();
    }

    /**
     * Register webhook ingestion route (requires HMAC auth).
     */
    public function register_webhook_route(): void
    {
        register_rest_route('sap-wc/v1', '/webhook', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_webhook'],
            'permission_callback' => [$this, 'verify_signature'],
        ]);
    }

    /**
     * Register health check route (no auth, always available).
     */
    public function register_health_route(): void
    {
        register_rest_route('sap-wc/v1', '/health', [
            'methods' => 'GET',
            'callback' => [$this, 'health_check'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Verify HMAC-SHA256 webhook signature.
     */
    public function verify_signature(\WP_REST_Request $request): bool
    {
        $secret = Config::get(Config::OPT_WEBHOOK_SECRET);
        if (empty($secret)) {
            $this->logger->error('Webhook secret not configured');
            return false;
        }

        $signature = $request->get_header('X-SAP-Signature');
        if (empty($signature)) {
            $this->logger->warning('Webhook received without signature');
            return false;
        }

        $body = $request->get_body();
        $expected = hash_hmac('sha256', $body, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Handle incoming webhook event.
     *
     * Immediately enqueues the event and returns 202 Accepted.
     * Actual processing happens asynchronously.
     */
    public function handle_webhook(\WP_REST_Request $request): \WP_REST_Response
    {
        $body = $request->get_json_params();

        if (empty($body['event_type'])) {
            return new \WP_REST_Response(['error' => 'Missing event_type'], 400);
        }

        $event_type = sanitize_text_field($body['event_type']);
        $payload = $body['data'] ?? [];

        // Validate known event types
        $valid_events = [
            'item.created', 'item.updated', 'item.stock_changed',
            'item.code_changed', 'item.deactivated', 'item.returned',
            'order.placed', 'order.status_changed', 'order.cancelled',
            'order.delivered', 'order.refunded',
        ];

        if (!in_array($event_type, $valid_events, true)) {
            $this->logger->warning("Unknown webhook event type: {$event_type}");
            return new \WP_REST_Response(['error' => 'Unknown event type'], 400);
        }

        // Determine priority based on event type
        $priority = $this->get_event_priority($event_type);

        // Enqueue for async processing
        $queue = new Queue_Manager();
        $event_id = $queue->enqueue($event_type, 'sap', $payload, $priority);

        $this->logger->info("Webhook received: {$event_type} -> queued as event #{$event_id}", [
            'entity_type' => 'queue',
        ]);

        return new \WP_REST_Response([
            'status' => 'accepted',
            'event_id' => $event_id,
        ], 202);
    }

    /**
     * Health check endpoint.
     */
    public function health_check(): \WP_REST_Response
    {
        $queue = new Queue_Manager();
        $circuit = \SAPWCSync\Queue\Circuit_Breaker::get_status();

        return new \WP_REST_Response([
            'status' => $circuit['is_healthy'] ? 'healthy' : 'degraded',
            'version' => Config::VERSION,
            'circuit_breaker' => $circuit['state'],
            'queue_depth' => $queue->get_queue_depth(),
            'dead_letters' => $queue->get_dead_letter_count(),
        ], 200);
    }

    /**
     * Assign priority based on event type.
     * Lower number = higher priority.
     */
    private function get_event_priority(string $event_type): int
    {
        $priorities = [
            'order.placed'         => 1,
            'order.status_changed' => 1,
            'order.cancelled'      => 1,
            'order.refunded'       => 1,
            'item.stock_changed'   => 2,
            'item.returned'        => 2,
            'item.code_changed'    => 3,
            'order.delivered'      => 3,
            'item.updated'         => 4,
            'item.created'         => 5,
            'item.deactivated'     => 5,
        ];

        return $priorities[$event_type] ?? 5;
    }
}
