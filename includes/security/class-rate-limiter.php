<?php
/**
 * Rate Limiter - Transient-based IP rate limiting
 *
 * @package SAP_WooCommerce_Sync
 * @since 2.0.0
 */

namespace SAPWCSync\Security;

use SAPWCSync\Exceptions\SAP_Rate_Limit_Exception;

defined('ABSPATH') || exit;

class Rate_Limiter
{
    /**
     * Check rate limit and throw if exceeded.
     *
     * @param string $action Action identifier
     * @param int $max_requests Max requests per window
     * @param int $window_seconds Time window in seconds (default 60)
     * @throws SAP_Rate_Limit_Exception
     */
    public static function enforce(string $action, int $max_requests, int $window_seconds = 60): void
    {
        $ip = self::get_client_ip();
        $key = 'sap_wc_rate_' . md5($action . $ip);

        $current = get_transient($key);

        if ($current === false) {
            set_transient($key, 1, $window_seconds);
            return;
        }

        if ((int) $current >= $max_requests) {
            throw new SAP_Rate_Limit_Exception(
                sprintf('Rate limit exceeded for %s. Max %d requests per %d seconds.', $action, $max_requests, $window_seconds)
            );
        }

        set_transient($key, (int) $current + 1, $window_seconds);
    }

    /**
     * Get client IP address safely.
     */
    private static function get_client_ip(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        return filter_var($ip, FILTER_VALIDATE_IP) ?: '127.0.0.1';
    }
}
