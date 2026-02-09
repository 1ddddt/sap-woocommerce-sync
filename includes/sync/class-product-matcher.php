<?php
/**
 * Unified Product Matcher
 *
 * SINGLE implementation of product matching logic (replaces 3 separate
 * implementations in v1). Uses strategy pattern for different match methods.
 *
 * Matching strategies (in priority order):
 * 1. SKU Match - WC SKU === SAP ItemCode (exact)
 * 2. Barcode Match - WC SKU or _sap_barcode meta === SAP Barcode (exact)
 * 3. Title Match - Normalized exact string comparison
 * 4. Fuzzy Title Match - Normalized fuzzy string comparison (85% threshold)
 *
 * All strategies use the unified Config::FUZZY_MATCH_THRESHOLD constant.
 *
 * IMPORTANT: Does NOT use N+1 API queries. Fetches ItemWarehouseInfoCollection
 * in the main paginated query (same approach as Inventory_Sync).
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
use SAPWCSync\Repositories\Product_Map_Repository;

defined('ABSPATH') || exit;

class Product_Matcher
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
     * Execute full mapping process.
     *
     * @param bool $execute If false, dry-run only
     * @return array Statistics including per-strategy match counts
     */
    public function execute_full_mapping(bool $execute = true): array
    {
        $stats = [
            'matched'          => 0,
            'skipped'          => 0,
            'errors'           => [],
            'total_sap'        => 0,
            'total_wc'         => 0,
            'sku_matched'      => 0,
            'barcode_matched'  => 0,
            'exact_matched'    => 0,
            'fuzzy_matched'    => 0,
        ];

        // Fetch all SAP items (paginated, with warehouse info in single query - NO N+1)
        $sap_items = $this->fetch_all_sap_items();
        $stats['total_sap'] = count($sap_items);

        // Get already mapped codes
        $mapped_codes = array_flip($this->product_repo->get_mapped_codes());

        // Get unmapped WC products
        $unmapped = $this->get_unmapped_products();
        $stats['total_wc'] = count($unmapped);

        // Build SAP lookup indexes
        $sap_by_code = [];
        $sap_by_barcode = [];
        $sap_by_name = [];

        foreach ($sap_items as $item) {
            $code = $item['ItemCode'];
            $sap_by_code[$code] = $item;

            if (!empty($item['Barcode'])) {
                $sap_by_barcode[$item['Barcode']] = $item;
            }

            $norm = self::normalize_name($item['ItemName']);
            if (!empty($norm)) {
                $sap_by_name[$norm] = $item;
            }
        }

        // Match each unmapped WC product
        foreach ($unmapped as $wc) {
            $result = $this->find_match_with_type($wc, $sap_by_code, $sap_by_barcode, $sap_by_name, $mapped_codes);

            if (!$result) {
                $stats['skipped']++;
                continue;
            }

            $match = $result['item'];
            $match_type = $result['type'];

            if (!$execute) {
                $stats['matched']++;
                $stats[$match_type]++;
                continue;
            }

            try {
                $this->create_mapping($wc, $match);
                $mapped_codes[$match['ItemCode']] = true;
                $stats['matched']++;
                $stats[$match_type]++;
            } catch (\Exception $e) {
                $stats['errors'][] = ['product_id' => $wc->ID, 'error' => $e->getMessage()];
            }
        }

        $this->logger->info(sprintf(
            'Product mapping completed: %d SAP items, %d unmapped WC products, %d matched (SKU:%d, Barcode:%d, Exact:%d, Fuzzy:%d), %d skipped, %d errors',
            $stats['total_sap'], $stats['total_wc'],
            $stats['matched'], $stats['sku_matched'], $stats['barcode_matched'],
            $stats['exact_matched'], $stats['fuzzy_matched'],
            $stats['skipped'], count($stats['errors'])
        ), ['entity_type' => Sync_Status::ENTITY_PRODUCT]);

        return $stats;
    }

    /**
     * Execute mapping in chunks for progressive UI updates.
     *
     * @param int $offset Starting offset for WC products
     * @param int $limit Max products to process in this chunk
     * @return array Results with has_more flag
     */
    public function execute_chunk(int $offset = 0, int $limit = 10): array
    {
        $stats = [
            'matched'          => 0,
            'skipped'          => 0,
            'errors'           => [],
            'sku_matched'      => 0,
            'barcode_matched'  => 0,
            'exact_matched'    => 0,
            'fuzzy_matched'    => 0,
            'offset'           => $offset,
            'limit'            => $limit,
            'has_more'         => false,
        ];

        // Use cached SAP items if available (set on first chunk, expires in 10 min)
        $sap_items = get_transient('sap_wc_product_map_cache');
        if (!$sap_items || !is_array($sap_items)) {
            $sap_items = $this->fetch_all_sap_items();
            set_transient('sap_wc_product_map_cache', $sap_items, 600);
        }
        $mapped_codes = array_flip($this->product_repo->get_mapped_codes());

        // Build SAP lookup indexes
        $sap_by_code = [];
        $sap_by_barcode = [];
        $sap_by_name = [];

        foreach ($sap_items as $item) {
            $sap_by_code[$item['ItemCode']] = $item;
            if (!empty($item['Barcode'])) {
                $sap_by_barcode[$item['Barcode']] = $item;
            }
            $norm = self::normalize_name($item['ItemName']);
            if (!empty($norm)) {
                $sap_by_name[$norm] = $item;
            }
        }

        // Get total unmapped count for progress display
        $total_unmapped = $this->count_unmapped_products();
        $stats['total'] = $total_unmapped;
        $stats['processed'] = min($offset + $limit, $total_unmapped);

        // Get chunk of unmapped products
        $unmapped = $this->get_unmapped_products_chunk($offset, $limit + 1);
        $stats['has_more'] = count($unmapped) > $limit;
        $unmapped = array_slice($unmapped, 0, $limit);

        foreach ($unmapped as $wc) {
            $result = $this->find_match_with_type($wc, $sap_by_code, $sap_by_barcode, $sap_by_name, $mapped_codes);

            if (!$result) {
                $stats['skipped']++;
                continue;
            }

            try {
                $this->create_mapping($wc, $result['item']);
                $mapped_codes[$result['item']['ItemCode']] = true;
                $stats['matched']++;
                $stats[$result['type']]++;
            } catch (\Exception $e) {
                $stats['errors'][] = ['product_id' => $wc->ID, 'error' => $e->getMessage()];
            }
        }

        // Clean up cache when done
        if (!$stats['has_more']) {
            delete_transient('sap_wc_product_map_cache');
        }

        return $stats;
    }

    /**
     * Fetch all SAP items with barcodes and warehouse info in ONE query per page.
     * No N+1 pattern - warehouse info included via $select.
     */
    private function fetch_all_sap_items(): array
    {
        $all_items = [];
        $skip = 0;
        $max_items = 15000; // Safety limit

        while ($skip < $max_items) {
            $response = $this->sap_client->get('Items', [
                '$select' => 'ItemCode,ItemName,BarCode,ItemBarCodeCollection,ItemWarehouseInfoCollection',
                '$top' => Config::SAP_PAGE_SIZE,
                '$skip' => $skip,
            ]);

            $batch = $response['value'] ?? [];
            if (empty($batch)) {
                break;
            }

            foreach ($batch as $item) {
                // Extract barcode
                $barcode = null;
                if (!empty($item['ItemBarCodeCollection'])) {
                    foreach ($item['ItemBarCodeCollection'] as $bc) {
                        if (!empty($bc['Barcode'])) {
                            $barcode = trim($bc['Barcode']);
                            break;
                        }
                    }
                }
                if (!$barcode && !empty($item['BarCode'])) {
                    $barcode = trim($item['BarCode']);
                }

                // Extract warehouse stock (NO separate API call)
                $stock_info = $this->extract_warehouse_stock($item);

                $all_items[] = [
                    'ItemCode' => $item['ItemCode'],
                    'ItemName' => $item['ItemName'],
                    'Barcode' => $barcode,
                    'stock' => $stock_info,
                ];
            }

            $skip += count($batch);
            if (count($batch) < Config::SAP_PAGE_SIZE) {
                break;
            }
        }

        return $all_items;
    }

    /**
     * Extract warehouse stock from ItemWarehouseInfoCollection.
     * Inline calculation - no separate API call.
     */
    private function extract_warehouse_stock(array $item): array
    {
        if (!empty($item['ItemWarehouseInfoCollection'])) {
            foreach ($item['ItemWarehouseInfoCollection'] as $wh) {
                if (($wh['WarehouseCode'] ?? '') === $this->warehouse) {
                    $in_stock = (float) ($wh['InStock'] ?? 0);
                    $committed = (float) ($wh['Committed'] ?? 0);
                    return [
                        'available' => max(0, (int) floor($in_stock - $committed)),
                        'in_stock' => (int) floor($in_stock),
                        'committed' => (int) floor($committed),
                    ];
                }
            }
        }
        return ['available' => 0, 'in_stock' => 0, 'committed' => 0];
    }

    /**
     * Get WC products not yet mapped to SAP.
     */
    private function get_unmapped_products(): array
    {
        global $wpdb;

        return $wpdb->get_results("
            SELECT p.ID, p.post_title, pm.meta_value as sku, pm_bc.meta_value as sap_barcode
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->prefix}" . Config::TABLE_PRODUCT_MAP . " m ON p.ID = m.wc_product_id
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sku'
            LEFT JOIN {$wpdb->postmeta} pm_bc ON p.ID = pm_bc.post_id AND pm_bc.meta_key = '_sap_barcode'
            WHERE p.post_type = 'product' AND p.post_status IN ('publish', 'draft', 'private')
            AND m.id IS NULL
            ORDER BY p.ID ASC
        ");
    }

    /**
     * Find best SAP match for a WC product, returning match type.
     *
     * @return array|null ['item' => array, 'type' => string] or null
     */
    private function find_match_with_type(object $wc, array $by_code, array $by_barcode, array $by_name, array $mapped): ?array
    {
        $sku = trim($wc->sku ?? '');

        // Strategy 1: SKU matches SAP ItemCode
        if (!empty($sku) && isset($by_code[$sku]) && !isset($mapped[$sku])) {
            return ['item' => $by_code[$sku], 'type' => 'sku_matched'];
        }

        // Strategy 2: WC barcode (SKU or _sap_barcode meta) matches SAP Barcode
        $wc_barcode = trim($wc->sap_barcode ?? '');
        $barcode_candidates = array_filter(array_unique([$sku, $wc_barcode]));
        foreach ($barcode_candidates as $candidate) {
            if (!empty($candidate) && isset($by_barcode[$candidate])) {
                $match = $by_barcode[$candidate];
                if (!isset($mapped[$match['ItemCode']])) {
                    return ['item' => $match, 'type' => 'barcode_matched'];
                }
            }
        }

        // Strategy 3: Exact title match
        $norm_title = self::normalize_name($wc->post_title);
        if (!empty($norm_title) && isset($by_name[$norm_title])) {
            $match = $by_name[$norm_title];
            if (!isset($mapped[$match['ItemCode']])) {
                return ['item' => $match, 'type' => 'exact_matched'];
            }
        }

        // Strategy 4: Fuzzy title match (with length pre-filter)
        $norm_len = strlen($norm_title);
        if ($norm_len >= 3) {
            // For 85% similarity, lengths can't differ by more than ~30%
            $min_len = (int) ($norm_len * 0.7);
            $max_len = (int) ($norm_len * 1.43);

            foreach ($by_name as $sap_norm => $sap_item) {
                if (isset($mapped[$sap_item['ItemCode']])) {
                    continue;
                }
                // Skip strings with incompatible lengths
                $sap_len = strlen($sap_norm);
                if ($sap_len < $min_len || $sap_len > $max_len) {
                    continue;
                }
                similar_text($norm_title, $sap_norm, $percent);
                if ($percent >= Config::FUZZY_MATCH_THRESHOLD) {
                    return ['item' => $sap_item, 'type' => 'fuzzy_matched'];
                }
            }
        }

        return null;
    }

    /**
     * Count total unmapped WC products.
     */
    private function count_unmapped_products(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var("
            SELECT COUNT(*)
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->prefix}" . Config::TABLE_PRODUCT_MAP . " m ON p.ID = m.wc_product_id
            WHERE p.post_type = 'product' AND p.post_status IN ('publish', 'draft', 'private')
            AND m.id IS NULL
        ");
    }

    /**
     * Get a paginated chunk of unmapped WC products.
     */
    private function get_unmapped_products_chunk(int $offset, int $limit): array
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare("
            SELECT p.ID, p.post_title, pm.meta_value as sku, pm_bc.meta_value as sap_barcode
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->prefix}" . Config::TABLE_PRODUCT_MAP . " m ON p.ID = m.wc_product_id
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sku'
            LEFT JOIN {$wpdb->postmeta} pm_bc ON p.ID = pm_bc.post_id AND pm_bc.meta_key = '_sap_barcode'
            WHERE p.post_type = 'product' AND p.post_status IN ('publish', 'draft', 'private')
            AND m.id IS NULL
            ORDER BY p.ID ASC
            LIMIT %d OFFSET %d
        ", $limit, $offset));
    }

    /**
     * Create product mapping and update WC product.
     * Uses WC API (not direct post meta).
     */
    private function create_mapping(object $wc, array $sap_item): void
    {
        $product = wc_get_product($wc->ID);
        if (!$product) {
            return;
        }

        // SKU = SAP ItemCode (always). Barcode stored separately in meta.
        Product_Helper::update_sku($product, $sap_item['ItemCode'], false);
        Product_Helper::set_sap_item_code($product, $sap_item['ItemCode'], false);
        if (!empty($sap_item['Barcode'])) {
            Product_Helper::set_sap_barcode($product, $sap_item['Barcode'], false);
        }

        // Update stock if available
        if (!empty($sap_item['stock'])) {
            Product_Helper::update_stock($product, $sap_item['stock']['available'], false);
        }

        $product->save();

        // Create mapping record
        $this->product_repo->upsert_by_wc_id($wc->ID, [
            'sap_item_code' => $sap_item['ItemCode'],
            'sap_barcode' => $sap_item['Barcode'],
            'sap_stock' => $sap_item['stock']['available'] ?? 0,
            'sap_in_stock' => $sap_item['stock']['in_stock'] ?? 0,
            'sap_committed' => $sap_item['stock']['committed'] ?? 0,
            'sync_status' => Sync_Status::SYNCED,
            'last_sync_at' => current_time('mysql'),
        ]);
    }

    /**
     * Normalize product name for comparison.
     */
    public static function normalize_name(string $name): string
    {
        return strtolower(trim(preg_replace('/[^a-z0-9\s]/i', '', $name)));
    }
}
