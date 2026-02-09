<?php
/**
 * Product Map Repository
 *
 * @package SAP_WooCommerce_Sync
 * @since 2.0.0
 */

namespace SAPWCSync\Repositories;

use SAPWCSync\Constants\Config;

defined('ABSPATH') || exit;

class Product_Map_Repository extends Base_Repository
{
    protected function get_table_name(): string
    {
        return Config::TABLE_PRODUCT_MAP;
    }

    public function find_by_wc_id(int $wc_product_id): ?object
    {
        return $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE wc_product_id = %d",
            $wc_product_id
        ));
    }

    public function find_by_sap_code(string $item_code): ?object
    {
        return $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE sap_item_code = %s",
            $item_code
        ));
    }

    public function find_by_barcode(string $barcode): ?object
    {
        return $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE sap_barcode = %s",
            $barcode
        ));
    }

    /**
     * Get all mappings (excluding error status).
     */
    public function find_all_active(): array
    {
        return $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT wc_product_id, sap_item_code FROM {$this->table} WHERE sync_status != %s OR sync_status IS NULL",
            'error'
        ));
    }

    /**
     * Get all mapped SAP item codes.
     */
    public function get_mapped_codes(): array
    {
        return $this->wpdb->get_col("SELECT sap_item_code FROM {$this->table}");
    }

    /**
     * Update mapping by WC product ID (upsert).
     */
    public function upsert_by_wc_id(int $wc_product_id, array $data): void
    {
        $existing = $this->find_by_wc_id($wc_product_id);

        if ($existing) {
            $data['updated_at'] = current_time('mysql');
            $this->wpdb->update($this->table, $data, ['wc_product_id' => $wc_product_id]);
        } else {
            $data['wc_product_id'] = $wc_product_id;
            $data['created_at'] = current_time('mysql');
            $this->wpdb->insert($this->table, $data);
        }
    }

    /**
     * Update stock and sync info by SAP item code.
     */
    public function update_stock(string $item_code, int $stock, int $in_stock, int $committed): void
    {
        $this->wpdb->update(
            $this->table,
            [
                'sap_stock' => $stock,
                'sap_in_stock' => $in_stock,
                'sap_committed' => $committed,
                'last_sync_at' => current_time('mysql'),
                'sync_status' => 'synced',
            ],
            ['sap_item_code' => $item_code],
            ['%d', '%d', '%d', '%s', '%s'],
            ['%s']
        );
    }
}
