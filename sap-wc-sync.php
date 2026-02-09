<?php
/**
 * Plugin Name: SAP WooCommerce Sync
 * Plugin URI: https://github.com/rasandilikshana/sap-woocommerce-sync
 * Description: Enterprise-grade, event-driven synchronization between SAP Business One and WooCommerce with queue-based guaranteed delivery, circuit breaker pattern, and comprehensive admin dashboard.
 * Version: 2.0.3
 * Author: Rasandi Likshana
 * Author URI: https://github.com/rasandilikshana
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: sap-wc-sync
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 9.0
 *
 * @package SAP_WooCommerce_Sync
 */

defined('ABSPATH') || exit;

// Plugin constants
define('SAP_WC_VERSION', '2.0.3');
define('SAP_WC_PLUGIN_FILE', __FILE__);
define('SAP_WC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SAP_WC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SAP_WC_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * PSR-4 style autoloader for plugin classes.
 *
 * Maps SAPWCSync\Sub\Class_Name to includes/sub/class-class-name.php
 */
spl_autoload_register(function ($class) {
    $prefix = 'SAPWCSync\\';
    $base_dir = SAP_WC_PLUGIN_DIR . 'includes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $relative_class = strtolower($relative_class);
    $relative_class = str_replace('_', '-', $relative_class);

    $parts = explode('\\', $relative_class);
    $class_name = array_pop($parts);
    $subdir = implode('/', $parts);

    $file = $base_dir;
    if (!empty($subdir)) {
        $file .= $subdir . '/';
    }

    // Try interface first, then class
    $interface_file = $file . 'interface-' . $class_name . '.php';
    $class_file = $file . 'class-' . $class_name . '.php';

    if (file_exists($interface_file)) {
        require $interface_file;
    } elseif (file_exists($class_file)) {
        require $class_file;
    }
});

/**
 * Check WooCommerce dependency.
 */
function sap_wc_check_woocommerce(): bool
{
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="error"><p>';
            esc_html_e('SAP WooCommerce Sync requires WooCommerce to be installed and active.', 'sap-wc-sync');
            echo '</p></div>';
        });
        return false;
    }
    return true;
}

/**
 * Plugin activation.
 */
register_activation_hook(__FILE__, function () {
    require_once SAP_WC_PLUGIN_DIR . 'includes/class-activator.php';
    SAPWCSync\Activator::activate();
});

/**
 * Plugin deactivation.
 */
register_deactivation_hook(__FILE__, function () {
    require_once SAP_WC_PLUGIN_DIR . 'includes/class-deactivator.php';
    SAPWCSync\Deactivator::deactivate();
});

/**
 * Initialize plugin after all plugins loaded.
 */
add_action('plugins_loaded', function () {
    if (!sap_wc_check_woocommerce()) {
        return;
    }

    load_plugin_textdomain('sap-wc-sync', false, dirname(SAP_WC_PLUGIN_BASENAME) . '/languages');

    SAPWCSync\Plugin::instance();
});

/**
 * Load WP-CLI commands.
 */
if (defined('WP_CLI') && WP_CLI) {
    add_action('plugins_loaded', function () {
        if (class_exists('SAPWCSync\CLI\Map_Products_Command')) {
            $cmd = new SAPWCSync\CLI\Map_Products_Command();
            \WP_CLI::add_command('sap-sync map-products', [$cmd, 'map_products']);
            \WP_CLI::add_command('sap-sync sync-inventory', [$cmd, 'sync_inventory']);
            \WP_CLI::add_command('sap-sync process-queue', [$cmd, 'process_queue']);
            \WP_CLI::add_command('sap-sync status', [$cmd, 'status']);
            \WP_CLI::add_command('sap-sync retry-dead-letters', [$cmd, 'retry_dead_letters']);
        }
    }, 20);
}

/**
 * HPOS compatibility declaration.
 */
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});
