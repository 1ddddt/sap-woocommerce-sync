<?php
/**
 * Sync Status Constants
 *
 * Eliminates magic strings throughout the plugin. All sync statuses
 * are defined here as class constants for type-safety and IDE support.
 *
 * @package SAP_WooCommerce_Sync
 * @since 2.0.0
 */

namespace SAPWCSync\Constants;

defined('ABSPATH') || exit;

class Sync_Status
{
    // Product sync statuses
    const PENDING   = 'pending';
    const SYNCED    = 'synced';
    const ERROR     = 'error';
    const UNMAPPED  = 'unmapped';

    // Order sync statuses
    const SO_CREATED   = 'so_created';
    const DP_CREATED   = 'dp_created';
    const DELIVERED    = 'delivered';
    const INVOICED     = 'invoiced';
    const CANCELED     = 'canceled';
    const FAILED       = 'failed';

    // Queue event statuses
    const QUEUE_PENDING    = 'pending';
    const QUEUE_PROCESSING = 'processing';
    const QUEUE_COMPLETED  = 'completed';
    const QUEUE_FAILED     = 'failed';
    const QUEUE_DEAD       = 'dead';

    // Sync directions
    const DIRECTION_SAP_TO_WC = 'sap_to_wc';
    const DIRECTION_WC_TO_SAP = 'wc_to_sap';

    // Entity types for logging
    const ENTITY_PRODUCT   = 'product';
    const ENTITY_ORDER     = 'order';
    const ENTITY_CUSTOMER  = 'customer';
    const ENTITY_INVENTORY = 'inventory';
    const ENTITY_SYSTEM    = 'system';
    const ENTITY_API       = 'api';
    const ENTITY_QUEUE     = 'queue';

    // Log levels
    const LOG_SUCCESS = 'success';
    const LOG_ERROR   = 'error';
    const LOG_WARNING = 'warning';
    const LOG_INFO    = 'info';
    const LOG_DEBUG   = 'debug';

    /**
     * Statuses considered as "successfully synced" for orders.
     * Used to determine if an order should be re-synced.
     */
    public static function order_synced_statuses(): array
    {
        return [
            self::SYNCED,
            self::SO_CREATED,
            self::DP_CREATED,
            self::DELIVERED,
            self::INVOICED,
            self::CANCELED,
        ];
    }

    /**
     * Statuses that indicate a retryable failure.
     */
    public static function retryable_statuses(): array
    {
        return [
            self::PENDING,
            self::ERROR,
            self::FAILED,
        ];
    }
}
