<?php
/**
 * Product Helper - WooCommerce product stock utilities
 *
 * Always uses WC API (never direct post meta) for HPOS compatibility
 * and proper hook triggering.
 *
 * @package SAP_WooCommerce_Sync
 * @since 2.0.0
 */

namespace SAPWCSync\Helpers;

defined('ABSPATH') || exit;

class Product_Helper
{
    /**
     * Update product stock using WooCommerce API.
     *
     * This method MUST be used instead of direct update_post_meta()
     * to ensure HPOS compatibility, cache invalidation, and proper
     * WooCommerce hook firing.
     *
     * @param \WC_Product $product WC product object
     * @param int $quantity New stock quantity
     * @param bool $save Whether to save the product immediately
     */
    public static function update_stock(\WC_Product $product, int $quantity, bool $save = true): void
    {
        $product->set_manage_stock(true);
        $product->set_stock_quantity($quantity);

        if ($quantity <= 0) {
            $product->set_stock_status('outofstock');
        } else {
            $product->set_stock_status('instock');
        }

        if ($save) {
            $product->save();
        }
    }

    /**
     * Get SAP item code from product meta.
     */
    public static function get_sap_item_code(\WC_Product $product): ?string
    {
        $meta = $product->get_meta('_sap_item_code');
        return !empty($meta) ? $meta : null;
    }

    /**
     * Set SAP item code in product meta.
     */
    public static function set_sap_item_code(\WC_Product $product, string $item_code, bool $save = true): void
    {
        $product->update_meta_data('_sap_item_code', $item_code);
        if ($save) {
            $product->save();
        }
    }

    /**
     * Get SAP barcode from product meta.
     */
    public static function get_sap_barcode(\WC_Product $product): ?string
    {
        $meta = $product->get_meta('_sap_barcode');
        return !empty($meta) ? $meta : null;
    }

    /**
     * Set SAP barcode in product meta.
     */
    public static function set_sap_barcode(\WC_Product $product, ?string $barcode, bool $save = true): void
    {
        if ($barcode !== null) {
            $product->update_meta_data('_sap_barcode', $barcode);
        }
        if ($save) {
            $product->save();
        }
    }

    /**
     * Update product SKU using WC API (HPOS compatible).
     */
    public static function update_sku(\WC_Product $product, string $sku, bool $save = true): void
    {
        // Preserve original SKU before overwriting
        $original = $product->get_sku();
        if (!empty($original) && $original !== $sku) {
            $product->update_meta_data('_original_sku', $original);
        }

        $product->set_sku($sku);

        if ($save) {
            $product->save();
        }
    }
}
