<?php
/**
 * Inventory Sync - SAP to WooCommerce stock synchronization
 *
 * Handles both event-driven single-product sync and batch sync
 * (used as health-check fallback when event system is active).
 *
 * Stock formula: Available = InStock - Committed
 * Only updates WC when stock actually changed (saves DB writes).
 *
 * @package SAP_WooCommerce_Sync
 * @since 2.0.0
 */

namespace SAPWCSync\Sync;

use SAPWCSync\API\SAP_Client;
use SAPWCSync\Constants\Config;
use SAPWCSync\Constants\Sync_Status;
use SAPWCSync\Helpers\Logger;
use SAPWCSync\Helpers\Product_Helper;
use SAPWCSync\Helpers\SAP_Filter_Builder;
use SAPWCSync\Helpers\API_Cache;
use SAPWCSync\Repositories\Product_Map_Repository;

defined('ABSPATH') || exit;

class Inventory_Sync
{
    private $sap_client;
    private $logger;
    private $product_repo;
    private $warehouse;

    public function __construct(SAP_Client $sap_client)
    {
        $this->sap_client = $sap_client;
        $this->logger = new Logger();
        $this->product_repo = new Product_Map_Repository();
        $this->warehouse = Config::warehouse();
    }

    /**
     * Sync stock for ALL mapped products (batch).
     *
     * Used as health-check fallback or manual sync.
     * In event-driven mode, this runs less frequently as a safety net.
     */
    public function sync_stock_levels(): array
    {
        $results = ['updated' => 0, 'skipped' => 0, 'errors' => [], 'started_at' => current_time('mysql')];

        // Concurrency lock
        $lock_key = 'sap_wc_inventory_sync_lock';
        if (get_transient($lock_key)) {
            return $results;
        }
        set_transient($lock_key, time(), 300);

        // Bypass cache for fresh data
        API_Cache::enable_bypass();

        try {
            $mappings = $this->product_repo->find_all_active();

            if (empty($mappings)) {
                delete_transient($lock_key);
                return $results;
            }

            $item_codes = array_column((array) $mappings, 'sap_item_code');
            $total = count($item_codes);
            $processed = 0;
            $batches = array_chunk($item_codes, Config::SAP_BATCH_SIZE);
            $total_batches = count($batches);

            // Initial progress
            $this->update_progress($total, 0, 0, 0, 0, $total_batches);

            // Process in batches of 20 (SAP hard page limit)
            foreach ($batches as $batch_index => $batch) {
                try {
                    $this->sync_batch($batch, $mappings, $results);
                } catch (\Exception $e) {
                    $results['errors'][] = ['batch' => $batch, 'error' => $e->getMessage()];
                }

                $processed += count($batch);
                $this->update_progress(
                    $total, $processed,
                    $results['updated'], $results['skipped'],
                    count($results['errors']),
                    $total_batches, $batch_index + 1
                );
            }

            $results['completed_at'] = current_time('mysql');

            if ($results['updated'] > 0 || !empty($results['errors'])) {
                $this->logger->info(sprintf(
                    'Inventory sync: Updated %d, Skipped %d, Errors %d',
                    $results['updated'], $results['skipped'], count($results['errors'])
                ), ['entity_type' => Sync_Status::ENTITY_INVENTORY]);
            }

            update_option(Config::OPT_LAST_INVENTORY_SYNC, current_time('mysql'));

        } catch (\Exception $e) {
            $results['errors'][] = ['error' => $e->getMessage()];
            $this->logger->error('Inventory sync failed: ' . $e->getMessage(), [
                'entity_type' => Sync_Status::ENTITY_INVENTORY,
            ]);
        }

        delete_transient('sap_wc_sync_progress');
        delete_transient($lock_key);
        API_Cache::disable_bypass();

        return $results;
    }

    /**
     * Update sync progress transient (read by ajax_sync_status polling).
     */
    private function update_progress(int $total, int $processed, int $updated, int $skipped, int $errors, int $total_batches, int $batch_done = 0): void
    {
        set_transient('sap_wc_sync_progress', [
            'total'         => $total,
            'processed'     => $processed,
            'updated'       => $updated,
            'skipped'       => $skipped,
            'errors'        => $errors,
            'total_batches' => $total_batches,
            'batch_done'    => $batch_done,
            'percent'       => $total > 0 ? (int) round(($processed / $total) * 100) : 0,
        ], 600);
    }

    /**
     * Sync a batch of items from SAP.
     */
    private function sync_batch(array $item_codes, array $all_mappings, array &$results): void
    {
        $filter = SAP_Filter_Builder::or_equals($item_codes, 'ItemCode');

        $response = $this->sap_client->get('Items', [
            '$filter' => $filter,
            '$select' => 'ItemCode,ItemName,Valid,ItemWarehouseInfoCollection',
            '$top' => count($item_codes),
        ]);

        $sap_items = $response['value'] ?? [];

        // Build lookups
        $sap_stock = [];
        foreach ($sap_items as $item) {
            $stock_info = $this->get_available_stock($item);
            $sap_stock[$item['ItemCode']] = [
                'stock' => $stock_info['available'],
                'in_stock' => $stock_info['in_stock'],
                'committed' => $stock_info['committed'],
                'found' => $stock_info['found'],
                'valid' => ($item['Valid'] ?? 'tYES') === 'tYES',
            ];
        }

        $mapping_lookup = [];
        foreach ($all_mappings as $m) {
            $mapping_lookup[$m->sap_item_code] = $m->wc_product_id;
        }

        foreach ($item_codes as $code) {
            if (!isset($mapping_lookup[$code]) || !isset($sap_stock[$code])) {
                $results['skipped']++;
                continue;
            }

            try {
                $updated = $this->update_product_stock(
                    (int) $mapping_lookup[$code],
                    $code,
                    $sap_stock[$code]
                );
                $results[$updated ? 'updated' : 'skipped']++;
            } catch (\Exception $e) {
                $results['errors'][] = ['item_code' => $code, 'error' => $e->getMessage()];
            }
        }
    }

    /**
     * Sync single product stock from SAP.
     *
     * Used by event-driven handlers for targeted stock updates.
     */
    public function sync_single_product(int $product_id): bool
    {
        $mapping = $this->product_repo->find_by_wc_id($product_id);
        if (!$mapping) {
            return false;
        }

        return $this->sync_product_stock($mapping->sap_item_code, $product_id);
    }

    /**
     * Sync stock by item code and product ID.
     */
    public function sync_product_stock(string $item_code, int $product_id): bool
    {
        try {
            API_Cache::enable_bypass();

            $response = $this->sap_client->get("Items('{$item_code}')", [
                '$select' => 'ItemCode,ItemName,Valid,ItemWarehouseInfoCollection',
            ]);

            if (empty($response)) {
                return false;
            }

            $raw = $this->get_available_stock($response);
            $stock_info = [
                'stock' => $raw['available'],
                'in_stock' => $raw['in_stock'],
                'committed' => $raw['committed'],
                'found' => $raw['found'],
                'valid' => ($response['Valid'] ?? 'tYES') === 'tYES',
            ];

            $result = $this->update_product_stock($product_id, $item_code, $stock_info);

            API_Cache::disable_bypass();
            return $result;

        } catch (\Exception $e) {
            API_Cache::disable_bypass();
            $this->logger->error("Stock sync failed for {$item_code}: " . $e->getMessage(), [
                'entity_type' => Sync_Status::ENTITY_INVENTORY,
                'entity_id' => $product_id,
            ]);
            return false;
        }
    }

    /**
     * Calculate available stock for configured warehouse.
     */
    private function get_available_stock(array $sap_item): array
    {
        if (!empty($sap_item['ItemWarehouseInfoCollection'])) {
            foreach ($sap_item['ItemWarehouseInfoCollection'] as $wh) {
                if (($wh['WarehouseCode'] ?? '') === $this->warehouse) {
                    $in_stock = (float) ($wh['InStock'] ?? 0);
                    $committed = (float) ($wh['Committed'] ?? 0);
                    return [
                        'available' => max(0, (int) floor($in_stock - $committed)),
                        'in_stock' => (int) floor($in_stock),
                        'committed' => (int) floor($committed),
                        'found' => true,
                    ];
                }
            }
        }

        return ['available' => 0, 'in_stock' => 0, 'committed' => 0, 'found' => false];
    }

    /**
     * Update WC product stock using WC API (HPOS compatible).
     *
     * Only updates when stock actually changed to minimize DB writes.
     */
    private function update_product_stock(int $product_id, string $item_code, array $stock_info): bool
    {
        $new_stock = $stock_info['stock'];
        $product = wc_get_product($product_id);
        if (!$product) {
            return false;
        }

        $old_stock = $product->get_stock_quantity();

        // Always update mapping table sync timestamp
        $this->product_repo->update_stock(
            $item_code,
            $new_stock,
            $stock_info['in_stock'] ?? 0,
            $stock_info['committed'] ?? 0
        );

        // Skip WC update if stock unchanged
        if ($old_stock === $new_stock) {
            return false;
        }

        // Update stock using WC API (not direct post meta!)
        Product_Helper::update_stock($product, $new_stock, false);

        // Deactivate if SAP marked invalid
        if (isset($stock_info['valid']) && !$stock_info['valid']) {
            $product->set_status('draft');
        }

        $product->save();

        $this->logger->info(sprintf(
            'Stock updated %s: %d -> %d (InStock: %d, Committed: %d)',
            $item_code, $old_stock ?? 0, $new_stock,
            $stock_info['in_stock'] ?? 0, $stock_info['committed'] ?? 0
        ), [
            'entity_type' => Sync_Status::ENTITY_INVENTORY,
            'entity_id' => $product_id,
        ]);

        return true;
    }
}
