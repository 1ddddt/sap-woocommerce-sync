<?php
/**
 * Sync logs page template
 *
 * @package SAP_WooCommerce_Sync
 * @since 2.0.0
 */

defined('ABSPATH') || exit;

use SAPWCSync\Constants\Config;
use SAPWCSync\Helpers\Logger;

$page        = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
$entity_type = isset($_GET['entity_type']) ? sanitize_text_field($_GET['entity_type']) : '';
$status      = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
$per_page    = 50;

$args = [
    'page'        => $page,
    'per_page'    => $per_page,
    'entity_type' => $entity_type,
    'status'      => $status,
];

$logs        = Logger::get_logs($args);
$total       = Logger::get_log_count($args);
$total_pages = ceil($total / $per_page);

// Dead letter stats
global $wpdb;
$dead_letters = $wpdb->get_results(
    "SELECT * FROM {$wpdb->prefix}" . Config::TABLE_DEAD_LETTER . " WHERE resolved = 0 ORDER BY created_at DESC LIMIT 20"
);
?>
<div class="wrap sap-wc-sync-logs">
    <h1><?php esc_html_e('SAP Sync Logs', 'sap-wc-sync'); ?></h1>

    <!-- Filters -->
    <form method="get" class="sap-wc-filters" style="margin-bottom: 15px;">
        <input type="hidden" name="page" value="sap-wc-sync-logs">
        <select name="entity_type">
            <option value=""><?php esc_html_e('All Types', 'sap-wc-sync'); ?></option>
            <option value="product" <?php selected($entity_type, 'product'); ?>>Product</option>
            <option value="inventory" <?php selected($entity_type, 'inventory'); ?>>Inventory</option>
            <option value="order" <?php selected($entity_type, 'order'); ?>>Order</option>
            <option value="api" <?php selected($entity_type, 'api'); ?>>API</option>
            <option value="queue" <?php selected($entity_type, 'queue'); ?>>Queue</option>
            <option value="system" <?php selected($entity_type, 'system'); ?>>System</option>
        </select>
        <select name="status">
            <option value=""><?php esc_html_e('All Status', 'sap-wc-sync'); ?></option>
            <option value="success" <?php selected($status, 'success'); ?>>Success</option>
            <option value="error" <?php selected($status, 'error'); ?>>Error</option>
            <option value="warning" <?php selected($status, 'warning'); ?>>Warning</option>
            <option value="info" <?php selected($status, 'info'); ?>>Info</option>
        </select>
        <button type="submit" class="button"><?php esc_html_e('Filter', 'sap-wc-sync'); ?></button>
        <a href="<?php echo esc_url(admin_url('admin.php?page=sap-wc-sync-logs')); ?>" class="button"><?php esc_html_e('Clear', 'sap-wc-sync'); ?></a>
    </form>

    <p><?php printf(esc_html__('Showing %d logs', 'sap-wc-sync'), $total); ?></p>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width:50px;">ID</th>
                <th style="width:150px;">Date</th>
                <th style="width:80px;">Type</th>
                <th style="width:80px;">Status</th>
                <th>Message</th>
                <th style="width:80px;">Entity ID</th>
                <th style="width:80px;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($logs)): ?>
                <tr><td colspan="7"><?php esc_html_e('No logs found.', 'sap-wc-sync'); ?></td></tr>
            <?php else: ?>
                <?php foreach ($logs as $log): ?>
                <tr>
                    <td><?php echo esc_html($log->id); ?></td>
                    <td><?php echo esc_html($log->created_at); ?></td>
                    <td><code><?php echo esc_html($log->entity_type); ?></code></td>
                    <td><span class="sap-wc-status sap-wc-status-<?php echo esc_attr($log->status); ?>"><?php echo esc_html($log->status); ?></span></td>
                    <td><?php echo esc_html($log->message); ?></td>
                    <td><?php echo $log->entity_id ? esc_html($log->entity_id) : '—'; ?></td>
                    <td>
                        <?php if ($log->status === 'error' && $log->entity_type === 'order' && $log->entity_id): ?>
                            <button type="button" class="button button-small sap-wc-retry-order" data-order-id="<?php echo esc_attr($log->entity_id); ?>">Retry</button>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if ($total_pages > 1): ?>
    <div class="tablenav bottom">
        <div class="tablenav-pages">
            <?php echo paginate_links(['base' => add_query_arg('paged', '%#%'), 'total' => $total_pages, 'current' => $page]); ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Dead Letter Queue -->
    <?php if (!empty($dead_letters)): ?>
    <h2 style="margin-top:30px;"><?php esc_html_e('Dead Letter Queue', 'sap-wc-sync'); ?> <span style="color:#dc3232;">(<?php echo count($dead_letters); ?> unresolved)</span></h2>
    <p class="description"><?php esc_html_e('Events that failed after maximum retry attempts. Review and re-enqueue if the issue is resolved.', 'sap-wc-sync'); ?></p>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width:50px;">ID</th>
                <th style="width:150px;">Date</th>
                <th style="width:120px;">Event Type</th>
                <th style="width:60px;">Attempts</th>
                <th>Last Error</th>
                <th style="width:100px;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($dead_letters as $dl):
                $errors = json_decode($dl->error_history, true) ?: [];
                $last_error = !empty($errors) ? end($errors) : 'Unknown';
            ?>
            <tr>
                <td><?php echo esc_html($dl->id); ?></td>
                <td><?php echo esc_html($dl->created_at); ?></td>
                <td><code><?php echo esc_html($dl->event_type); ?></code></td>
                <td><?php echo esc_html($dl->total_attempts); ?></td>
                <td><?php echo esc_html(wp_trim_words($last_error, 15)); ?></td>
                <td>
                    <button type="button" class="button button-small sap-wc-retry-dead-letter" data-dead-letter-id="<?php echo esc_attr($dl->id); ?>">Re-enqueue</button>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
