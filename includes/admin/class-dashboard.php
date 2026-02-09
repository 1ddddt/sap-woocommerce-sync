<?php
/**
 * Dashboard Widget - SAP sync status overview
 *
 * @package SAP_WooCommerce_Sync
 * @since 2.0.0
 */

namespace SAPWCSync\Admin;

use SAPWCSync\Constants\Config;
use SAPWCSync\Queue\Queue_Manager;
use SAPWCSync\Queue\Circuit_Breaker;
use SAPWCSync\Repositories\Order_Map_Repository;

defined('ABSPATH') || exit;

class Dashboard
{
    public function __construct()
    {
        add_action('wp_dashboard_setup', [$this, 'add_dashboard_widget']);
    }

    public function add_dashboard_widget(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        wp_add_dashboard_widget(
            'sap_wc_sync_status',
            'SAP-WooCommerce Sync Status',
            [$this, 'render']
        );
    }

    public function render(): void
    {
        global $wpdb;

        $order_repo = new Order_Map_Repository();
        $failed_orders  = $order_repo->get_failed_count_24h();
        $pending_orders = $order_repo->get_pending_count();

        $synced_products = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}" . Config::TABLE_PRODUCT_MAP . " WHERE sap_item_code IS NOT NULL"
        );

        $last_inventory_sync = Config::get(Config::OPT_LAST_INVENTORY_SYNC);
        $last_sync_text = $last_inventory_sync
            ? human_time_diff(strtotime($last_inventory_sync), current_time('timestamp')) . ' ago'
            : 'Never';

        $queue = new Queue_Manager();
        $queue_depth  = $queue->get_queue_depth();
        $dead_letters = $queue->get_dead_letter_count();

        $circuit = Circuit_Breaker::get_status();
        $circuit_state = $circuit['state'] ?? 'closed';

        // Determine health
        $health = 'good';
        $health_msg = 'All systems operational';
        if ($circuit_state === 'open') {
            $health = 'critical';
            $health_msg = 'Circuit breaker OPEN - SAP unreachable';
        } elseif ($failed_orders > 5 || $dead_letters > 0) {
            $health = 'critical';
            $health_msg = 'Critical: failures detected';
        } elseif ($failed_orders > 0 || $circuit_state === 'half_open') {
            $health = 'warning';
            $health_msg = 'Warning: some issues detected';
        }

        $recent_logs = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}" . Config::TABLE_SYNC_LOG . " ORDER BY created_at DESC LIMIT 5"
        );
        ?>
        <style>
            .sapwc-widget{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
            .sapwc-status-bar{display:flex;align-items:center;padding:12px;background:#f8f9fa;border-radius:4px;margin-bottom:16px}
            .sapwc-status-dot{width:10px;height:10px;border-radius:50%;margin-right:10px;flex-shrink:0}
            .sapwc-status-dot.good{background:#46b450}.sapwc-status-dot.warning{background:#ffb900}.sapwc-status-dot.critical{background:#dc3232}
            .sapwc-metrics{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px}
            .sapwc-metric{flex:1;min-width:calc(33% - 6px);background:#fff;border:1px solid #e0e0e0;border-radius:4px;padding:12px;box-sizing:border-box}
            .sapwc-metric-value{font-size:20px;font-weight:600;line-height:1;margin-bottom:4px;color:#2271b1}
            .sapwc-metric-value.red{color:#dc3232}.sapwc-metric-value.orange{color:#ffb900}
            .sapwc-metric-label{font-size:10px;color:#646970;text-transform:uppercase;letter-spacing:0.5px}
            .sapwc-logs-title{font-size:12px;font-weight:600;margin:0 0 8px;text-transform:uppercase;letter-spacing:0.5px}
            .sapwc-log-item{padding:6px 0;border-bottom:1px solid #f0f0f1;font-size:12px}
            .sapwc-log-item:last-child{border-bottom:none}
            .sapwc-log-badge{display:inline-block;padding:2px 6px;border-radius:3px;font-size:10px;font-weight:600;text-transform:uppercase;margin-right:4px}
            .sapwc-log-badge.error{background:#fee;color:#b32d2e}.sapwc-log-badge.warning{background:#fff4cc;color:#8a6d3b}
            .sapwc-log-badge.success{background:#e5fae5;color:#1e7e34}.sapwc-log-badge.info{background:#e5f5fa;color:#00a0d2}
            .sapwc-actions{display:flex;gap:8px;padding-top:12px;border-top:1px solid #e0e0e0}
            .sapwc-btn{flex:1;text-align:center;padding:8px;border-radius:4px;text-decoration:none;font-size:13px;font-weight:500}
            .sapwc-btn-primary{background:#2271b1;color:#fff}.sapwc-btn-primary:hover{background:#135e96;color:#fff}
            .sapwc-btn-secondary{background:#f6f7f7;color:#2c3338;border:1px solid #c3c4c7}.sapwc-btn-secondary:hover{background:#f0f0f1;color:#2c3338}
        </style>

        <div class="sapwc-widget">
            <div class="sapwc-status-bar">
                <span class="sapwc-status-dot <?php echo esc_attr($health); ?>"></span>
                <span style="font-size:13px;font-weight:500;"><?php echo esc_html($health_msg); ?></span>
            </div>

            <div class="sapwc-metrics">
                <div class="sapwc-metric">
                    <div class="sapwc-metric-value red"><?php echo esc_html($failed_orders); ?></div>
                    <div class="sapwc-metric-label">Failed (24h)</div>
                </div>
                <div class="sapwc-metric">
                    <div class="sapwc-metric-value <?php echo $pending_orders > 0 ? 'orange' : ''; ?>"><?php echo esc_html($pending_orders); ?></div>
                    <div class="sapwc-metric-label">Pending Orders</div>
                </div>
                <div class="sapwc-metric">
                    <div class="sapwc-metric-value"><?php echo esc_html($synced_products); ?></div>
                    <div class="sapwc-metric-label">Products</div>
                </div>
                <div class="sapwc-metric">
                    <div class="sapwc-metric-value <?php echo $queue_depth > 10 ? 'orange' : ''; ?>"><?php echo esc_html($queue_depth); ?></div>
                    <div class="sapwc-metric-label">Queue</div>
                </div>
                <div class="sapwc-metric">
                    <div class="sapwc-metric-value <?php echo $dead_letters > 0 ? 'red' : ''; ?>"><?php echo esc_html($dead_letters); ?></div>
                    <div class="sapwc-metric-label">Dead Letters</div>
                </div>
                <div class="sapwc-metric">
                    <div class="sapwc-metric-value" style="font-size:12px;"><?php echo esc_html($last_sync_text); ?></div>
                    <div class="sapwc-metric-label">Last Sync</div>
                </div>
            </div>

            <?php if (!empty($recent_logs)): ?>
            <div>
                <h4 class="sapwc-logs-title">Recent Activity</h4>
                <?php foreach ($recent_logs as $log): ?>
                <div class="sapwc-log-item">
                    <span class="sapwc-log-badge <?php echo esc_attr($log->status); ?>"><?php echo esc_html($log->status); ?></span>
                    <span style="color:#50575e;"><?php echo esc_html(wp_trim_words($log->message, 8)); ?></span>
                    <span style="color:#a0a5aa;font-size:11px;margin-left:4px;"><?php echo esc_html(human_time_diff(strtotime($log->created_at), current_time('timestamp'))); ?> ago</span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="sapwc-actions">
                <a href="<?php echo esc_url(admin_url('admin.php?page=sap-wc-products')); ?>" class="sapwc-btn sapwc-btn-primary">Products</a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=sap-wc-sync-logs')); ?>" class="sapwc-btn sapwc-btn-secondary">Logs</a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=sap-wc-sync')); ?>" class="sapwc-btn sapwc-btn-secondary">Settings</a>
            </div>
        </div>
        <?php
    }
}
