<?php
/**
 * WP-CLI Commands for SAP-WooCommerce Sync
 *
 * Provides CLI access to all sync operations:
 * - map-products: Bulk product mapping with unified matcher
 * - sync-inventory: Manual inventory sync
 * - process-queue: Manually process event queue
 * - status: System health overview
 * - retry-dead-letters: Re-enqueue dead letter events
 *
 * @package SAP_WooCommerce_Sync
 * @since 2.0.0
 */

namespace SAPWCSync\CLI;

use SAPWCSync\API\SAP_Client;
use SAPWCSync\Constants\Config;
use SAPWCSync\Constants\Sync_Status;
use SAPWCSync\Helpers\Product_Helper;
use SAPWCSync\Queue\Queue_Manager;
use SAPWCSync\Queue\Queue_Worker;
use SAPWCSync\Queue\Circuit_Breaker;
use SAPWCSync\Repositories\Product_Map_Repository;
use SAPWCSync\Repositories\Order_Map_Repository;

defined('ABSPATH') || exit;

if (!class_exists('WP_CLI')) {
    return;
}

class Map_Products_Command
{
    private $sap_client;

    private function get_client(): SAP_Client
    {
        if (!$this->sap_client) {
            $this->sap_client = new SAP_Client([
                'base_url'   => Config::get(Config::OPT_BASE_URL),
                'company_db' => Config::get(Config::OPT_COMPANY_DB),
                'username'   => Config::get(Config::OPT_USERNAME),
                'password'   => Config::get(Config::OPT_PASSWORD),
            ]);
        }
        return $this->sap_client;
    }

    /**
     * Map WooCommerce products to SAP items using unified matcher.
     *
     * ## OPTIONS
     *
     * [--execute]
     * : Apply mappings (default is analyze only)
     *
     * [--batch-size=<size>]
     * : SAP items per batch (default: 20, max: 20)
     *
     * [--threshold=<percent>]
     * : Fuzzy match threshold (default: 85)
     *
     * ## EXAMPLES
     *
     *     wp sap-sync map-products
     *     wp sap-sync map-products --execute
     *     wp sap-sync map-products --execute --threshold=90
     *
     * @when after_wp_load
     */
    public function map_products($args, $assoc_args)
    {
        $execute   = isset($assoc_args['execute']);
        $batch_size = min(absint($assoc_args['batch-size'] ?? Config::SAP_BATCH_SIZE), Config::SAP_PAGE_SIZE);
        $threshold = absint($assoc_args['threshold'] ?? Config::FUZZY_MATCH_THRESHOLD);

        \WP_CLI::log("=== SAP-WooCommerce Product Mapping (v2.0) ===");
        \WP_CLI::log("Mode: " . ($execute ? 'EXECUTE' : 'ANALYZE'));
        \WP_CLI::log("Batch size: {$batch_size} | Fuzzy threshold: {$threshold}%\n");

        global $wpdb;
        $product_repo = new Product_Map_Repository();
        $client = $this->get_client();

        // Step 1: Fetch SAP items
        \WP_CLI::log("Step 1: Fetching SAP items...");
        $sap_items = [];
        $sap_by_barcode = [];
        $sap_by_name = [];
        $skip = 0;
        $stats = ['sap_items' => 0, 'sap_with_barcode' => 0, 'barcode' => 0, 'exact' => 0, 'fuzzy' => 0, 'no_match' => 0, 'mapped' => 0];

        while ($skip < 15000) {
            $response = $client->get('Items', [
                '$select' => 'ItemCode,ItemName,BarCode,ItemBarCodeCollection',
                '$top' => $batch_size,
                '$skip' => $skip,
            ]);

            $batch = $response['value'] ?? [];
            if (empty($batch)) break;

            foreach ($batch as $item) {
                $barcode = null;
                if (!empty($item['ItemBarCodeCollection'])) {
                    foreach ($item['ItemBarCodeCollection'] as $bc) {
                        if (!empty($bc['Barcode'])) { $barcode = $bc['Barcode']; break; }
                    }
                }
                if (!$barcode && !empty($item['BarCode'])) { $barcode = $item['BarCode']; }

                $sap_item = ['ItemCode' => $item['ItemCode'], 'ItemName' => $item['ItemName'], 'Barcode' => $barcode];
                $sap_items[] = $sap_item;
                $stats['sap_items']++;

                if ($barcode) { $sap_by_barcode[$barcode] = $sap_item; $stats['sap_with_barcode']++; }
                $norm = strtolower(trim(preg_replace('/[^a-z0-9\s]/i', '', $item['ItemName'])));
                $sap_by_name[$norm] = $sap_item;
            }

            $skip += count($batch);
            if (count($batch) < $batch_size) break;
        }

        \WP_CLI::success("Loaded {$stats['sap_items']} SAP items ({$stats['sap_with_barcode']} with barcodes)");

        // Step 2: Get unmapped WC products
        $mapped_codes = $product_repo->get_mapped_codes();
        $mapped_lookup = array_flip($mapped_codes);
        \WP_CLI::log("Already mapped: " . count($mapped_codes));

        $unmapped = $wpdb->get_results("
            SELECT p.ID, p.post_title, pm.meta_value as sku, pm_bc.meta_value as sap_barcode
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->prefix}" . Config::TABLE_PRODUCT_MAP . " m ON p.ID = m.wc_product_id
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sku'
            LEFT JOIN {$wpdb->postmeta} pm_bc ON p.ID = pm_bc.post_id AND pm_bc.meta_key = '_sap_barcode'
            WHERE p.post_type = 'product' AND p.post_status IN ('publish', 'draft', 'private') AND m.id IS NULL
        ");

        \WP_CLI::log("Unmapped WC products: " . count($unmapped));

        // Step 3: Match
        \WP_CLI::log("\nStep 2: Matching products...");
        $matches = [];
        $sap_by_code = array_column($sap_items, null, 'ItemCode');

        foreach ($unmapped as $wc) {
            $match = null;
            $type = null;
            $sku = trim($wc->sku ?? '');

            // Strategy 1: SKU matches SAP ItemCode directly
            if (!empty($sku) && isset($sap_by_code[$sku]) && !isset($mapped_lookup[$sku])) {
                $match = $sap_by_code[$sku];
                $type = 'sku_itemcode';
                $stats['barcode']++; // reuse counter for direct matches
            }

            // Strategy 1b: WC barcode (SKU or _sap_barcode meta) matches SAP Barcode
            if (!$match) {
                $wc_barcode = trim($wc->sap_barcode ?? '');
                $barcode_candidates = array_filter(array_unique([$sku, $wc_barcode]));
                foreach ($barcode_candidates as $candidate) {
                    if (!empty($candidate) && isset($sap_by_barcode[$candidate]) && !isset($mapped_lookup[$sap_by_barcode[$candidate]['ItemCode']])) {
                        $match = $sap_by_barcode[$candidate];
                        $type = 'barcode';
                        $stats['barcode']++;
                        break;
                    }
                }
            }

            // Strategy 2: Exact title
            if (!$match) {
                $norm = strtolower(trim(preg_replace('/[^a-z0-9\s]/i', '', $wc->post_title)));
                if (isset($sap_by_name[$norm]) && !isset($mapped_lookup[$sap_by_name[$norm]['ItemCode']])) {
                    $match = $sap_by_name[$norm];
                    $type = 'exact_name';
                    $stats['exact']++;
                }
            }

            // Strategy 3: Fuzzy title
            if (!$match) {
                $norm_wc = strtolower(trim(preg_replace('/[^a-z0-9\s]/i', '', $wc->post_title)));
                $best = null;
                $best_score = 0;

                foreach ($sap_items as $si) {
                    if (isset($mapped_lookup[$si['ItemCode']])) continue;
                    $norm_sap = strtolower(trim(preg_replace('/[^a-z0-9\s]/i', '', $si['ItemName'])));
                    similar_text($norm_wc, $norm_sap, $pct);
                    if ($pct >= $threshold && $pct > $best_score) {
                        $best_score = $pct;
                        $best = $si;
                    }
                }

                if ($best) {
                    $match = $best;
                    $type = 'fuzzy_' . round($best_score) . '%';
                    $stats['fuzzy']++;
                }
            }

            if ($match) {
                $matches[] = ['wc_id' => $wc->ID, 'wc_title' => $wc->post_title, 'wc_sku' => $sku, 'sap' => $match, 'type' => $type];
                $mapped_lookup[$match['ItemCode']] = true;
            } else {
                $stats['no_match']++;
            }
        }

        // Show samples
        \WP_CLI::log("\n=== Sample Matches ===");
        foreach (array_slice($matches, 0, 10) as $m) {
            \WP_CLI::log(sprintf("[%s] %s -> %s", $m['type'], substr($m['wc_title'], 0, 40), $m['sap']['ItemCode']));
        }
        if (count($matches) > 10) \WP_CLI::log("... and " . (count($matches) - 10) . " more");

        // Execute
        if ($execute && !empty($matches)) {
            \WP_CLI::log("\n=== Executing Mappings ===");
            $progress = \WP_CLI\Utils\make_progress_bar('Mapping', count($matches));

            foreach ($matches as $m) {
                $product = wc_get_product($m['wc_id']);
                if (!$product) { $progress->tick(); continue; }

                // SKU = SAP ItemCode (always). Barcode stored separately in meta.
                Product_Helper::update_sku($product, $m['sap']['ItemCode'], false);
                Product_Helper::set_sap_item_code($product, $m['sap']['ItemCode'], false);
                if (!empty($m['sap']['Barcode'])) {
                    Product_Helper::set_sap_barcode($product, $m['sap']['Barcode'], false);
                }
                $product->save();

                $product_repo->upsert_by_wc_id($m['wc_id'], [
                    'sap_item_code' => $m['sap']['ItemCode'],
                    'sap_barcode' => $m['sap']['Barcode'],
                    'sync_status' => Sync_Status::PENDING,
                ]);

                $stats['mapped']++;
                $progress->tick();
            }
            $progress->finish();
            \WP_CLI::success("Mapped {$stats['mapped']} products!");
        }

        // Summary
        \WP_CLI::log("\n=== SUMMARY ===");
        \WP_CLI::log("SAP Items: {$stats['sap_items']} ({$stats['sap_with_barcode']} with barcodes)");
        \WP_CLI::log("Matched by Barcode: {$stats['barcode']}");
        \WP_CLI::log("Matched by Exact Name: {$stats['exact']}");
        \WP_CLI::log("Matched by Fuzzy Name: {$stats['fuzzy']}");
        \WP_CLI::log("No Match: {$stats['no_match']}");
        if ($execute) \WP_CLI::log("Successfully Mapped: {$stats['mapped']}");
        else \WP_CLI::log("\nRun with --execute to apply mappings");
    }

    /**
     * Manually trigger inventory sync.
     *
     * ## EXAMPLES
     *
     *     wp sap-sync sync-inventory
     *
     * @when after_wp_load
     */
    public function sync_inventory($args, $assoc_args)
    {
        \WP_CLI::log("Running inventory sync...");
        $sync = new \SAPWCSync\Sync\Inventory_Sync($this->get_client());
        $result = $sync->sync_stock_levels();

        \WP_CLI::success(sprintf(
            "Inventory sync complete. Updated: %d, Errors: %d",
            $result['updated'] ?? 0,
            count($result['errors'] ?? [])
        ));
    }

    /**
     * Process event queue manually.
     *
     * ## OPTIONS
     *
     * [--batch=<size>]
     * : Events to process (default: 10)
     *
     * ## EXAMPLES
     *
     *     wp sap-sync process-queue
     *     wp sap-sync process-queue --batch=50
     *
     * @when after_wp_load
     */
    public function process_queue($args, $assoc_args)
    {
        $batch = absint($assoc_args['batch'] ?? Config::QUEUE_BATCH_SIZE);
        \WP_CLI::log("Processing event queue (batch: {$batch})...");

        $worker = new Queue_Worker($this->get_client());
        $result = $worker->process_batch($batch);

        \WP_CLI::success(sprintf(
            "Queue processed. Success: %d, Failed: %d",
            $result['processed'] ?? 0,
            $result['failed'] ?? 0
        ));
    }

    /**
     * Show system health status.
     *
     * ## EXAMPLES
     *
     *     wp sap-sync status
     *
     * @when after_wp_load
     */
    public function status($args, $assoc_args)
    {
        global $wpdb;

        $product_repo = new Product_Map_Repository();
        $order_repo = new Order_Map_Repository();
        $queue = new Queue_Manager();
        $circuit = Circuit_Breaker::get_status();

        $mapped = count($product_repo->get_mapped_codes());
        $pending_orders = $order_repo->get_pending_count();
        $failed_orders = $order_repo->get_failed_count_24h();
        $queue_depth = $queue->get_queue_depth();
        $dead_letters = $queue->get_dead_letter_count();

        $last_sync = Config::get(Config::OPT_LAST_INVENTORY_SYNC);
        $last_sync_text = $last_sync ? human_time_diff(strtotime($last_sync)) . ' ago' : 'Never';

        \WP_CLI::log("=== SAP-WooCommerce Sync Status (v" . Config::VERSION . ") ===\n");
        \WP_CLI::log("Circuit Breaker: " . ($circuit['state'] ?? 'unknown'));
        \WP_CLI::log("Mapped Products: {$mapped}");
        \WP_CLI::log("Pending Orders: {$pending_orders}");
        \WP_CLI::log("Failed Orders (24h): {$failed_orders}");
        \WP_CLI::log("Queue Depth: {$queue_depth}");
        \WP_CLI::log("Dead Letters: {$dead_letters}");
        \WP_CLI::log("Last Inventory Sync: {$last_sync_text}");
        \WP_CLI::log("Webhooks: " . (Config::get(Config::OPT_ENABLE_WEBHOOKS) === 'yes' ? 'Enabled' : 'Disabled'));

        if ($dead_letters > 0 || $failed_orders > 5) {
            \WP_CLI::warning("System needs attention!");
        } else {
            \WP_CLI::success("All systems operational");
        }
    }

    /**
     * Re-enqueue dead letter events.
     *
     * ## OPTIONS
     *
     * [--all]
     * : Re-enqueue all unresolved dead letters
     *
     * [--id=<id>]
     * : Re-enqueue a specific dead letter by ID
     *
     * ## EXAMPLES
     *
     *     wp sap-sync retry-dead-letters --all
     *     wp sap-sync retry-dead-letters --id=42
     *
     * @when after_wp_load
     */
    public function retry_dead_letters($args, $assoc_args)
    {
        global $wpdb;
        $dead_table = $wpdb->prefix . Config::TABLE_DEAD_LETTER;
        $queue = new Queue_Manager();
        $count = 0;

        if (!empty($assoc_args['id'])) {
            $id = absint($assoc_args['id']);
            $dl = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$dead_table} WHERE id = %d AND resolved = 0", $id
            ));
            if (!$dl) {
                \WP_CLI::error("Dead letter #{$id} not found or already resolved");
                return;
            }
            $dead_letters = [$dl];
        } elseif (isset($assoc_args['all'])) {
            $dead_letters = $wpdb->get_results("SELECT * FROM {$dead_table} WHERE resolved = 0");
        } else {
            \WP_CLI::error("Specify --all or --id=<id>");
            return;
        }

        foreach ($dead_letters as $dl) {
            $new_id = $queue->enqueue(
                $dl->event_type,
                $dl->event_source,
                json_decode($dl->payload, true),
                1
            );

            $wpdb->update($dead_table, [
                'resolved' => 1,
                'resolved_at' => current_time('mysql'),
                'resolution_note' => "CLI re-enqueued as event #{$new_id}",
            ], ['id' => $dl->id]);

            $count++;
            \WP_CLI::log("Dead letter #{$dl->id} -> event #{$new_id}");
        }

        \WP_CLI::success("Re-enqueued {$count} dead letters");
    }
}
