<?php
/**
 * Admin Settings Page
 *
 * @package SAP_WooCommerce_Sync
 * @since 2.0.0
 */

namespace SAPWCSync\Admin;

use SAPWCSync\Constants\Config;
use SAPWCSync\Security\Encryption;
use SAPWCSync\Queue\Queue_Manager;
use SAPWCSync\Queue\Circuit_Breaker;

defined('ABSPATH') || exit;

class Settings_Page
{
    public function render(): void
    {
        if (isset($_POST['sap_wc_save_settings']) && check_admin_referer('sap_wc_settings')) {
            $this->save_settings();
        }

        $base_url            = Config::get(Config::OPT_BASE_URL, '');
        $company_db          = Config::get(Config::OPT_COMPANY_DB, '');
        $username            = Config::get(Config::OPT_USERNAME, '');
        $default_warehouse   = Config::get(Config::OPT_DEFAULT_WAREHOUSE, Config::DEFAULT_WAREHOUSE);
        $sync_interval       = Config::get(Config::OPT_SYNC_INTERVAL, 5);
        $enable_logging      = Config::get(Config::OPT_ENABLE_LOGGING, 'yes');
        $enable_inventory    = Config::get(Config::OPT_ENABLE_INVENTORY, 'yes');
        $enable_order_sync   = Config::get(Config::OPT_ENABLE_ORDER_SYNC, 'yes');
        $enable_webhooks     = Config::get(Config::OPT_ENABLE_WEBHOOKS, 'no');
        $webhook_secret      = Config::get(Config::OPT_WEBHOOK_SECRET, '');
        $log_retention       = Config::get(Config::OPT_LOG_RETENTION_DAYS, Config::DEFAULT_LOG_RETENTION);
        $freight_expense_code = Config::get(Config::OPT_FREIGHT_EXPENSE_CODE, Config::DEFAULT_FREIGHT_EXPENSE_CODE);
        $shipping_tax_code   = Config::get(Config::OPT_SHIPPING_TAX_CODE, Config::DEFAULT_TAX_CODE);
        $shipping_item_code  = Config::get(Config::OPT_SHIPPING_ITEM_CODE, Config::DEFAULT_SHIPPING_ITEM_CODE);
        $immediate_sync      = Config::get(Config::OPT_IMMEDIATE_SYNC, 'no');

        global $wpdb;
        $mapped_products = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}" . Config::TABLE_PRODUCT_MAP);
        $last_sync       = $wpdb->get_var("SELECT MAX(last_sync_at) FROM {$wpdb->prefix}" . Config::TABLE_PRODUCT_MAP);
        $next_sync       = wp_next_scheduled(Config::CRON_INVENTORY_SYNC);

        $queue          = new Queue_Manager();
        $queue_depth    = $queue->get_queue_depth();
        $dead_letters   = $queue->get_dead_letter_count();
        $circuit_status = Circuit_Breaker::get_status();
        $key_valid      = Encryption::is_key_configured();

        settings_errors('sap_wc_sync');
        ?>
        <div class="wrap sap-wc-sync-settings">
            <h1><?php esc_html_e('SAP WooCommerce Sync', 'sap-wc-sync'); ?></h1>

            <!-- Status Cards -->
            <div class="sap-wc-status-cards">
                <div class="sap-wc-card">
                    <h3><?php esc_html_e('Mapped Products', 'sap-wc-sync'); ?></h3>
                    <p class="sap-wc-stat"><?php echo esc_html($mapped_products); ?></p>
                </div>
                <div class="sap-wc-card">
                    <h3><?php esc_html_e('Last Sync', 'sap-wc-sync'); ?></h3>
                    <p class="sap-wc-stat"><?php echo $last_sync ? esc_html(human_time_diff(strtotime($last_sync), current_time('timestamp')) . ' ago') : '—'; ?></p>
                </div>
                <div class="sap-wc-card">
                    <h3><?php esc_html_e('Next Sync', 'sap-wc-sync'); ?></h3>
                    <p class="sap-wc-stat"><?php echo $next_sync ? esc_html(human_time_diff($next_sync) . ' from now') : '—'; ?></p>
                </div>
                <div class="sap-wc-card">
                    <h3><?php esc_html_e('Encryption', 'sap-wc-sync'); ?></h3>
                    <p class="sap-wc-stat">
                        <?php if ($key_valid): ?>
                            <span style="color: #46b450;">&#10003; Configured</span>
                        <?php else: ?>
                            <span style="color: #dc3232;">&#10007; Not Configured</span>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="sap-wc-card">
                    <h3><?php esc_html_e('Queue Depth', 'sap-wc-sync'); ?></h3>
                    <p class="sap-wc-stat"><?php echo esc_html($queue_depth); ?></p>
                </div>
                <div class="sap-wc-card">
                    <h3><?php esc_html_e('Dead Letters', 'sap-wc-sync'); ?></h3>
                    <p class="sap-wc-stat" style="<?php echo $dead_letters > 0 ? 'color:#dc3232;' : ''; ?>"><?php echo esc_html($dead_letters); ?></p>
                </div>
                <div class="sap-wc-card">
                    <h3><?php esc_html_e('Circuit Breaker', 'sap-wc-sync'); ?></h3>
                    <p class="sap-wc-stat">
                        <?php
                        $state = $circuit_status['state'] ?? 'closed';
                        $color = $state === 'closed' ? '#46b450' : ($state === 'half_open' ? '#ffb900' : '#dc3232');
                        echo '<span style="color:' . esc_attr($color) . ';">' . esc_html(ucfirst(str_replace('_', ' ', $state))) . '</span>';
                        ?>
                    </p>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="sap-wc-actions">
                <h2><?php esc_html_e('Quick Actions', 'sap-wc-sync'); ?></h2>
                <button type="button" id="sap-wc-test-connection" class="button button-secondary">
                    <?php esc_html_e('Test Connection', 'sap-wc-sync'); ?>
                </button>
                <button type="button" id="sap-wc-sync-inventory" class="button button-primary">
                    <?php esc_html_e('Sync Inventory Now', 'sap-wc-sync'); ?>
                </button>
                <button type="button" id="sap-wc-sync-products" class="button button-secondary">
                    <?php esc_html_e('Map Products from SAP', 'sap-wc-sync'); ?>
                </button>
                <div id="sap-wc-action-result" class="sap-wc-result"></div>
            </div>

            <!-- Settings Form -->
            <form method="post" action="">
                <?php wp_nonce_field('sap_wc_settings'); ?>

                <h2><?php esc_html_e('SAP Connection Settings', 'sap-wc-sync'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="sap_wc_base_url"><?php esc_html_e('SAP Service Layer URL', 'sap-wc-sync'); ?></label></th>
                        <td>
                            <input type="url" id="sap_wc_base_url" name="sap_wc_base_url"
                                   value="<?php echo esc_attr($base_url); ?>" class="regular-text"
                                   placeholder="https://sap-server:50000/b1s/v2/">
                            <p class="description"><?php esc_html_e('Include /b1s/v2/ at the end', 'sap-wc-sync'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sap_wc_company_db"><?php esc_html_e('Company Database', 'sap-wc-sync'); ?></label></th>
                        <td><input type="text" id="sap_wc_company_db" name="sap_wc_company_db" value="<?php echo esc_attr($company_db); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sap_wc_username"><?php esc_html_e('Username', 'sap-wc-sync'); ?></label></th>
                        <td><input type="text" id="sap_wc_username" name="sap_wc_username" value="<?php echo esc_attr($username); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sap_wc_password"><?php esc_html_e('Password', 'sap-wc-sync'); ?></label></th>
                        <td><input type="password" id="sap_wc_password" name="sap_wc_password" value="" class="regular-text" placeholder="<?php esc_attr_e('Leave empty to keep current', 'sap-wc-sync'); ?>"></td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Sync Settings', 'sap-wc-sync'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="sap_wc_default_warehouse"><?php esc_html_e('Default Warehouse', 'sap-wc-sync'); ?></label></th>
                        <td><input type="text" id="sap_wc_default_warehouse" name="sap_wc_default_warehouse" value="<?php echo esc_attr($default_warehouse); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sap_wc_shipping_item_code"><?php esc_html_e('Shipping Item Code', 'sap-wc-sync'); ?></label></th>
                        <td>
                            <input type="text" id="sap_wc_shipping_item_code" name="sap_wc_shipping_item_code" value="<?php echo esc_attr($shipping_item_code); ?>" class="regular-text" placeholder="SHIPPING-SVC">
                            <p class="description"><?php esc_html_e('SAP Item Code for shipping/freight service (e.g., SHIPPING-SVC). Must be a Labor/Service type item in SAP.', 'sap-wc-sync'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sap_wc_shipping_tax_code"><?php esc_html_e('Shipping Tax Code', 'sap-wc-sync'); ?></label></th>
                        <td>
                            <input type="text" id="sap_wc_shipping_tax_code" name="sap_wc_shipping_tax_code" value="<?php echo esc_attr($shipping_tax_code); ?>" class="regular-text" placeholder="VAT@18">
                            <p class="description"><?php esc_html_e('SAP Tax Code for shipping charges. Leave empty to use the default product tax code.', 'sap-wc-sync'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sap_wc_log_retention_days"><?php esc_html_e('Log Retention (days)', 'sap-wc-sync'); ?></label></th>
                        <td><input type="number" id="sap_wc_log_retention_days" name="sap_wc_log_retention_days" value="<?php echo esc_attr($log_retention); ?>" min="7" max="365" class="small-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable Features', 'sap-wc-sync'); ?></th>
                        <td>
                            <fieldset>
                                <label><input type="checkbox" name="sap_wc_enable_inventory_sync" value="yes" <?php checked($enable_inventory, 'yes'); ?>> <?php esc_html_e('Inventory Sync (SAP to WooCommerce)', 'sap-wc-sync'); ?></label><br>
                                <label><input type="checkbox" name="sap_wc_enable_order_sync" value="yes" <?php checked($enable_order_sync, 'yes'); ?>> <?php esc_html_e('Order Sync (WooCommerce to SAP)', 'sap-wc-sync'); ?></label><br>
                                <label><input type="checkbox" name="sap_wc_immediate_sync" value="yes" <?php checked($immediate_sync, 'yes'); ?>> <?php esc_html_e('Immediate Order Sync (Process immediately instead of waiting for cron)', 'sap-wc-sync'); ?></label><br>
                                <label><input type="checkbox" name="sap_wc_enable_logging" value="yes" <?php checked($enable_logging, 'yes'); ?>> <?php esc_html_e('Enable Detailed Logging', 'sap-wc-sync'); ?></label>
                            </fieldset>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Webhook Settings (Event-Driven)', 'sap-wc-sync'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable Webhooks', 'sap-wc-sync'); ?></th>
                        <td>
                            <label><input type="checkbox" name="sap_wc_enable_webhooks" value="yes" <?php checked($enable_webhooks, 'yes'); ?>> <?php esc_html_e('Receive SAP events via webhook', 'sap-wc-sync'); ?></label>
                            <p class="description">
                                <?php esc_html_e('When enabled, SAP can push events to your site for real-time sync.', 'sap-wc-sync'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sap_wc_webhook_url"><?php esc_html_e('Webhook URL', 'sap-wc-sync'); ?></label></th>
                        <td>
                            <input type="text" readonly class="regular-text" value="<?php echo esc_attr(rest_url('sap-wc/v1/webhook')); ?>">
                            <p class="description"><?php esc_html_e('Configure this URL in your SAP middleware.', 'sap-wc-sync'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sap_wc_webhook_secret"><?php esc_html_e('Webhook Secret', 'sap-wc-sync'); ?></label></th>
                        <td>
                            <input type="text" id="sap_wc_webhook_secret" name="sap_wc_webhook_secret"
                                   value="<?php echo esc_attr($webhook_secret); ?>" class="regular-text">
                            <p class="description"><?php esc_html_e('HMAC-SHA256 secret for signature verification. Auto-generated on activation.', 'sap-wc-sync'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Health Check', 'sap-wc-sync'); ?></th>
                        <td>
                            <input type="text" readonly class="regular-text" value="<?php echo esc_attr(rest_url('sap-wc/v1/health')); ?>">
                            <p class="description"><?php esc_html_e('Public health endpoint (no authentication required).', 'sap-wc-sync'); ?></p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <input type="submit" name="sap_wc_save_settings" class="button button-primary"
                           value="<?php esc_attr_e('Save Settings', 'sap-wc-sync'); ?>">
                </p>
            </form>
        </div>
        <?php
    }

    private function save_settings(): void
    {
        $fields = [
            Config::OPT_BASE_URL          => 'sanitize_text_field',
            Config::OPT_COMPANY_DB        => 'sanitize_text_field',
            Config::OPT_USERNAME          => 'sanitize_text_field',
            Config::OPT_DEFAULT_WAREHOUSE => 'sanitize_text_field',
            Config::OPT_LOG_RETENTION_DAYS => 'absint',
            Config::OPT_WEBHOOK_SECRET    => 'sanitize_text_field',
            Config::OPT_SHIPPING_ITEM_CODE => 'sanitize_text_field',
            Config::OPT_SHIPPING_TAX_CODE => 'sanitize_text_field',
        ];

        foreach ($fields as $field => $sanitizer) {
            if (isset($_POST[$field])) {
                update_option($field, call_user_func($sanitizer, wp_unslash($_POST[$field])));
            }
        }

        // Password: only update if provided
        if (!empty($_POST[Config::OPT_PASSWORD])) {
            $validation = Encryption::validate_key();
            if (!$validation['valid']) {
                add_settings_error('sap_wc_sync', 'encryption_key_invalid',
                    sprintf(__('Cannot save password: %s', 'sap-wc-sync'), $validation['message']), 'error');
            } else {
                try {
                    $encrypted = Encryption::encrypt(wp_unslash($_POST[Config::OPT_PASSWORD]));
                    update_option(Config::OPT_PASSWORD, $encrypted);
                    add_settings_error('sap_wc_sync', 'password_saved',
                        __('Password updated and encrypted.', 'sap-wc-sync'), 'success');
                } catch (\Exception $e) {
                    add_settings_error('sap_wc_sync', 'encryption_error',
                        __('Password encryption failed: ', 'sap-wc-sync') . $e->getMessage(), 'error');
                }
            }
        }

        // Checkboxes
        $checkboxes = [
            Config::OPT_ENABLE_INVENTORY,
            Config::OPT_ENABLE_ORDER_SYNC,
            Config::OPT_IMMEDIATE_SYNC,
            Config::OPT_ENABLE_LOGGING,
            Config::OPT_ENABLE_WEBHOOKS,
        ];
        foreach ($checkboxes as $checkbox) {
            update_option($checkbox, isset($_POST[$checkbox]) ? 'yes' : 'no');
        }

        // Re-schedule crons (5-minute interval, not 30-second)
        wp_clear_scheduled_hook(Config::CRON_INVENTORY_SYNC);
        if (Config::get(Config::OPT_ENABLE_INVENTORY) === 'yes') {
            wp_schedule_event(time(), Config::SCHEDULE_5MIN, Config::CRON_INVENTORY_SYNC);
        }

        add_settings_error('sap_wc_sync', 'settings_saved', __('Settings saved.', 'sap-wc-sync'), 'success');
    }
}
