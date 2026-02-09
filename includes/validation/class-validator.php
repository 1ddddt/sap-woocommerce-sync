<?php
/**
 * Input Validator
 *
 * @package SAP_WooCommerce_Sync
 * @since 2.0.0
 */

namespace SAPWCSync\Validation;

use SAPWCSync\Exceptions\SAP_Validation_Exception;

defined('ABSPATH') || exit;

class Validator
{
    /**
     * Validate and sanitize URL.
     */
    public static function url(string $url, string $field = 'URL'): string
    {
        $url = esc_url_raw(trim($url));
        if (empty($url)) {
            throw new SAP_Validation_Exception("{$field} is required");
        }
        return $url;
    }

    /**
     * Validate non-empty string.
     */
    public static function required_string(string $value, string $field = 'Field'): string
    {
        $value = sanitize_text_field(trim($value));
        if (empty($value)) {
            throw new SAP_Validation_Exception("{$field} is required");
        }
        return $value;
    }

    /**
     * Validate positive integer.
     */
    public static function positive_int($value, string $field = 'Field'): int
    {
        $int = absint($value);
        if ($int <= 0) {
            throw new SAP_Validation_Exception("{$field} must be a positive integer");
        }
        return $int;
    }

    /**
     * Validate order ID exists.
     */
    public static function order_id(int $id): \WC_Order
    {
        $order = wc_get_order($id);
        if (!$order) {
            throw new SAP_Validation_Exception("Order #{$id} not found");
        }
        return $order;
    }

    /**
     * Validate product ID exists.
     */
    public static function product_id(int $id): \WC_Product
    {
        $product = wc_get_product($id);
        if (!$product) {
            throw new SAP_Validation_Exception("Product #{$id} not found");
        }
        return $product;
    }

    /**
     * Sanitize SAP item code.
     */
    public static function item_code(string $code): string
    {
        return sanitize_text_field(trim($code));
    }
}
