<?php
/**
 * Circuit Breaker - Fault tolerance for SAP connectivity
 *
 * States:
 *   CLOSED   -> Normal operation, requests pass through
 *   OPEN     -> SAP unreachable, requests fail immediately
 *   HALF_OPEN -> Testing recovery, allow 1 request through
 *
 * Transitions:
 *   CLOSED -> OPEN: N consecutive failures within time window
 *   OPEN -> HALF_OPEN: After cooldown period
 *   HALF_OPEN -> CLOSED: 1 successful request
 *   HALF_OPEN -> OPEN: 1 failed request (reset cooldown)
 *
 * @package SAP_WooCommerce_Sync
 * @since 2.0.0
 */

namespace SAPWCSync\Queue;

use SAPWCSync\Constants\Config;
use SAPWCSync\Exceptions\SAP_Circuit_Open_Exception;

defined('ABSPATH') || exit;

class Circuit_Breaker
{
    const STATE_CLOSED    = 'closed';
    const STATE_OPEN      = 'open';
    const STATE_HALF_OPEN = 'half_open';

    const OPTION_KEY = 'sap_wc_circuit_breaker';

    /**
     * Check if circuit allows request. Throws if open.
     *
     * @throws SAP_Circuit_Open_Exception If circuit is open
     */
    public static function check(): void
    {
        $state = self::get_state();

        if ($state['state'] === self::STATE_OPEN) {
            // Check if cooldown has passed
            if (time() - $state['last_failure'] >= Config::CIRCUIT_COOLDOWN) {
                // Transition to half-open
                self::set_state(self::STATE_HALF_OPEN, $state);
                return; // Allow one test request
            }

            throw new SAP_Circuit_Open_Exception(
                'Circuit breaker is OPEN. SAP appears unavailable. Will retry after cooldown.'
            );
        }

        // CLOSED or HALF_OPEN: allow request
    }

    /**
     * Record a successful request.
     */
    public static function record_success(): void
    {
        $state = self::get_state();

        if ($state['state'] === self::STATE_HALF_OPEN) {
            // Recovery confirmed - close circuit
            self::set_state(self::STATE_CLOSED, [
                'failure_count' => 0,
                'last_failure' => 0,
                'opened_at' => 0,
            ]);
            return;
        }

        // Reset failure count on success
        if ($state['failure_count'] > 0) {
            $state['failure_count'] = 0;
            self::set_state(self::STATE_CLOSED, $state);
        }
    }

    /**
     * Record a failed request.
     */
    public static function record_failure(): void
    {
        $state = self::get_state();

        if ($state['state'] === self::STATE_HALF_OPEN) {
            // Test request failed - reopen circuit
            self::set_state(self::STATE_OPEN, [
                'failure_count' => $state['failure_count'] + 1,
                'last_failure' => time(),
                'opened_at' => time(),
            ]);
            return;
        }

        $state['failure_count']++;
        $state['last_failure'] = time();

        // Check if within failure window
        $window_start = time() - Config::CIRCUIT_FAILURE_WINDOW;
        if ($state['failure_count'] >= Config::CIRCUIT_FAILURE_THRESHOLD && $state['last_failure'] >= $window_start) {
            // Trip the circuit
            self::set_state(self::STATE_OPEN, array_merge($state, ['opened_at' => time()]));
        } else {
            self::set_state(self::STATE_CLOSED, $state);
        }
    }

    /**
     * Get current circuit state.
     */
    public static function get_state(): array
    {
        $default = [
            'state' => self::STATE_CLOSED,
            'failure_count' => 0,
            'last_failure' => 0,
            'opened_at' => 0,
        ];

        $saved = get_option(self::OPTION_KEY);
        if (!is_array($saved)) {
            return $default;
        }

        return array_merge($default, $saved);
    }

    /**
     * Get human-readable status.
     */
    public static function get_status(): array
    {
        $state = self::get_state();
        return [
            'state' => $state['state'],
            'is_healthy' => $state['state'] === self::STATE_CLOSED,
            'failure_count' => $state['failure_count'],
            'last_failure' => $state['last_failure'] ? gmdate('Y-m-d H:i:s', $state['last_failure']) : null,
        ];
    }

    /**
     * Force reset circuit to closed state.
     */
    public static function reset(): void
    {
        delete_option(self::OPTION_KEY);
    }

    private static function set_state(string $new_state, array $data): void
    {
        $data['state'] = $new_state;
        update_option(self::OPTION_KEY, $data, false);
    }
}
