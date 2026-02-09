<?php
/**
 * WP-CLI runner for background inventory sync.
 *
 * Called via: wp eval-file includes/cli/run-inventory-sync.php --path=/var/www/html
 * Spawned by Plugin::spawn_cli_sync() for non-blocking execution.
 *
 * @package SAP_WooCommerce_Sync
 * @since 2.0.1
 */

defined('ABSPATH') || exit;

$client = new \SAPWCSync\API\SAP_Client([
    'base_url'   => \SAPWCSync\Constants\Config::get(\SAPWCSync\Constants\Config::OPT_BASE_URL),
    'company_db' => \SAPWCSync\Constants\Config::get(\SAPWCSync\Constants\Config::OPT_COMPANY_DB),
    'username'   => \SAPWCSync\Constants\Config::get(\SAPWCSync\Constants\Config::OPT_USERNAME),
    'password'   => \SAPWCSync\Constants\Config::get(\SAPWCSync\Constants\Config::OPT_PASSWORD),
]);

$sync = new \SAPWCSync\Sync\Inventory_Sync($client);

try {
    $result = $sync->sync_stock_levels();
    set_transient('sap_wc_last_sync_result', $result, 3600);
} catch (\Exception $e) {
    set_transient('sap_wc_last_sync_result', [
        'error'   => $e->getMessage(),
        'updated' => 0,
        'skipped' => 0,
        'errors'  => [['error' => $e->getMessage()]],
    ], 3600);
}
