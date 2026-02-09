<?php
/**
 * API Response Cache
 *
 * Targeted caching for SAP API responses. Uses a cache group
 * to allow selective invalidation WITHOUT calling wp_cache_flush()
 * which would destroy the entire WordPress object cache.
 *
 * @package SAP_WooCommerce_Sync
 * @since 2.0.0
 */

namespace SAPWCSync\Helpers;

defined('ABSPATH') || exit;

class API_Cache
{
    const CACHE_GROUP = 'sap_wc_api';
    const CACHE_TTL = 300; // 5 minutes
    const CACHE_KEYS_TRANSIENT = 'sap_wc_cache_keys';

    private static $bypass = false;

    /**
     * Enable cache bypass (for real-time sync operations).
     */
    public static function enable_bypass(): void
    {
        self::$bypass = true;
    }

    /**
     * Disable cache bypass.
     */
    public static function disable_bypass(): void
    {
        self::$bypass = false;
    }

    /**
     * Determine if this endpoint should be cached.
     * Only cache GET requests to read-only endpoints.
     */
    public static function should_cache(string $method, string $endpoint): bool
    {
        if (self::$bypass || $method !== 'GET') {
            return false;
        }

        // Don't cache login/logout
        $no_cache = ['Login', 'Logout'];
        foreach ($no_cache as $pattern) {
            if (strpos($endpoint, $pattern) !== false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get cached response.
     */
    public static function get(string $endpoint, array $params = [])
    {
        if (self::$bypass) {
            return false;
        }

        $key = self::build_key($endpoint, $params);
        $cached = wp_cache_get($key, self::CACHE_GROUP);

        return $cached !== false ? $cached : false;
    }

    /**
     * Store response in cache.
     */
    public static function set(string $endpoint, array $params, array $data): void
    {
        $key = self::build_key($endpoint, $params);
        wp_cache_set($key, $data, self::CACHE_GROUP, self::CACHE_TTL);

        // Track key for targeted invalidation
        self::track_key($key);
    }

    /**
     * Invalidate cache for a specific endpoint.
     * Does NOT call wp_cache_flush() - only removes tracked keys matching the endpoint.
     */
    public static function invalidate_endpoint(string $endpoint): void
    {
        $tracked = get_transient(self::CACHE_KEYS_TRANSIENT);
        if (!is_array($tracked)) {
            return;
        }

        $prefix = 'sap_wc_' . md5($endpoint);
        foreach ($tracked as $index => $key) {
            if (strpos($key, $prefix) === 0) {
                wp_cache_delete($key, self::CACHE_GROUP);
                unset($tracked[$index]);
            }
        }

        set_transient(self::CACHE_KEYS_TRANSIENT, array_values($tracked), 3600);
    }

    /**
     * Flush all SAP cache entries (targeted, not wp_cache_flush).
     */
    public static function flush_all(): void
    {
        $tracked = get_transient(self::CACHE_KEYS_TRANSIENT);
        if (is_array($tracked)) {
            foreach ($tracked as $key) {
                wp_cache_delete($key, self::CACHE_GROUP);
            }
        }

        delete_transient(self::CACHE_KEYS_TRANSIENT);
    }

    /**
     * Build cache key from endpoint and params.
     */
    private static function build_key(string $endpoint, array $params): string
    {
        return 'sap_wc_' . md5($endpoint . wp_json_encode($params));
    }

    /**
     * Track cache key for later invalidation.
     */
    private static function track_key(string $key): void
    {
        $tracked = get_transient(self::CACHE_KEYS_TRANSIENT);
        if (!is_array($tracked)) {
            $tracked = [];
        }

        if (!in_array($key, $tracked, true)) {
            $tracked[] = $key;
            // Limit tracked keys to prevent unbounded growth
            if (count($tracked) > 500) {
                $tracked = array_slice($tracked, -250);
            }
            set_transient(self::CACHE_KEYS_TRANSIENT, $tracked, 3600);
        }
    }
}
