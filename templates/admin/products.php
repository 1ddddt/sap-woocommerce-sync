<?php
/**
 * Products monitoring page template
 *
 * @package SAP_WooCommerce_Sync
 * @since 2.0.0
 */

defined('ABSPATH') || exit;

use SAPWCSync\Constants\Config;

global $wpdb;

$product_table = $wpdb->prefix . Config::TABLE_PRODUCT_MAP;

$page     = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
$per_page = 50;
$offset   = ($page - 1) * $per_page;
$filter   = isset($_GET['filter']) ? sanitize_text_field($_GET['filter']) : 'all';
$search   = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
$orderby  = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'last_sync';
$order    = isset($_GET['order']) ? strtoupper(sanitize_text_field($_GET['order'])) : 'DESC';
$order    = in_array($order, ['ASC', 'DESC']) ? $order : 'DESC';

$sortable_columns = [
    'product'       => 'p.post_title',
    'sku'           => 'pm_sku.meta_value',
    'sap_item_code' => 'm.sap_item_code',
    'wc_stock'      => 'CAST(IFNULL(pm_stock.meta_value, 0) AS SIGNED)',
    'sap_instock'   => 'CAST(IFNULL(m.sap_in_stock, 0) AS SIGNED)',
    'sap_committed' => 'CAST(IFNULL(m.sap_committed, 0) AS SIGNED)',
    'sap_stock'     => 'CAST(IFNULL(m.sap_stock, 0) AS SIGNED)',
    'status'        => 'm.sync_status',
    'last_sync'     => 'm.last_sync_at',
];

$order_column = $sortable_columns[$orderby] ?? 'm.last_sync_at';
$order_clause = "{$order_column} {$order}";
if ($orderby !== 'product') {
    $order_clause .= ", p.post_title ASC";
}

$where  = ["p.post_type = 'product'", "p.post_status IN ('publish', 'draft', 'private')"];
$values = [];

if ($filter === 'mapped')     { $where[] = 'm.id IS NOT NULL'; }
elseif ($filter === 'unmapped') { $where[] = 'm.id IS NULL'; }
elseif ($filter === 'synced')   { $where[] = "m.sync_status = 'synced'"; }
elseif ($filter === 'mismatched') { $where[] = 'm.id IS NOT NULL AND m.sap_stock IS NOT NULL AND CAST(pm_stock.meta_value AS SIGNED) != m.sap_stock'; }

if (!empty($search)) {
    $where[] = '(p.post_title LIKE %s OR m.sap_item_code LIKE %s OR pm_sku.meta_value LIKE %s)';
    $like = '%' . $wpdb->esc_like($search) . '%';
    $values = array_merge($values, [$like, $like, $like]);
}

$where_clause = implode(' AND ', $where);

$count_all = (int) $wpdb->get_var(
    "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p LEFT JOIN {$product_table} m ON p.ID = m.wc_product_id WHERE p.post_type = 'product' AND p.post_status IN ('publish', 'draft', 'private')"
);
$count_mapped = (int) $wpdb->get_var(
    "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p INNER JOIN {$product_table} m ON p.ID = m.wc_product_id WHERE p.post_type = 'product' AND p.post_status IN ('publish', 'draft', 'private')"
);
$count_unmapped   = $count_all - $count_mapped;
$count_mismatched = (int) $wpdb->get_var(
    "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p INNER JOIN {$product_table} m ON p.ID = m.wc_product_id LEFT JOIN {$wpdb->postmeta} pm_stock ON p.ID = pm_stock.post_id AND pm_stock.meta_key = '_stock' WHERE p.post_type = 'product' AND p.post_status IN ('publish', 'draft', 'private') AND m.sap_stock IS NOT NULL AND CAST(IFNULL(pm_stock.meta_value, 0) AS SIGNED) != m.sap_stock"
);

$count_query = "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p LEFT JOIN {$product_table} m ON p.ID = m.wc_product_id LEFT JOIN {$wpdb->postmeta} pm_sku ON p.ID = pm_sku.post_id AND pm_sku.meta_key = '_sku' LEFT JOIN {$wpdb->postmeta} pm_stock ON p.ID = pm_stock.post_id AND pm_stock.meta_key = '_stock' WHERE {$where_clause}";
$total = !empty($values) ? (int) $wpdb->get_var($wpdb->prepare($count_query, $values)) : (int) $wpdb->get_var($count_query);
$total_pages = ceil($total / $per_page);

$query = "SELECT p.ID as product_id, p.post_title, m.id as map_id, m.sap_item_code, m.sap_barcode, m.sap_stock, m.sap_in_stock, m.sap_committed, m.sync_status, m.last_sync_at, m.error_message, pm_sku.meta_value as sku, pm_stock.meta_value as wc_stock FROM {$wpdb->posts} p LEFT JOIN {$product_table} m ON p.ID = m.wc_product_id LEFT JOIN {$wpdb->postmeta} pm_sku ON p.ID = pm_sku.post_id AND pm_sku.meta_key = '_sku' LEFT JOIN {$wpdb->postmeta} pm_stock ON p.ID = pm_stock.post_id AND pm_stock.meta_key = '_stock' WHERE {$where_clause} GROUP BY p.ID ORDER BY {$order_clause} LIMIT %d OFFSET %d";
$products = $wpdb->get_results($wpdb->prepare($query, array_merge($values, [$per_page, $offset])));

function sap_wc_sort_url($col, $cur_orderby, $cur_order, $filter, $search) {
    $url = admin_url('admin.php?page=sap-wc-products');
    if ($filter !== 'all') { $url = add_query_arg('filter', $filter, $url); }
    if (!empty($search))   { $url = add_query_arg('s', $search, $url); }
    $url = add_query_arg('orderby', $col, $url);
    $url = add_query_arg('order', ($cur_orderby === $col && $cur_order === 'ASC') ? 'DESC' : 'ASC', $url);
    return $url;
}

function sap_wc_sort_arrow($col, $cur_orderby, $cur_order) {
    if ($cur_orderby !== $col) return '';
    return '<span style="color:#2271b1;margin-left:3px;">' . ($cur_order === 'ASC' ? '&#9650;' : '&#9660;') . '</span>';
}
?>
<div class="wrap sap-wc-products">
    <h1><?php esc_html_e('Product Sync Monitor', 'sap-wc-sync'); ?></h1>
    <p class="description"><?php printf(esc_html__('Showing all WooCommerce products with SAP mapping status. %d mapped of %d total.', 'sap-wc-sync'), $count_mapped, $count_all); ?></p>

    <ul class="subsubsub">
        <li><a href="<?php echo esc_url(admin_url('admin.php?page=sap-wc-products')); ?>" class="<?php echo $filter === 'all' ? 'current' : ''; ?>">All <span class="count">(<?php echo $count_all; ?>)</span></a> |</li>
        <li><a href="<?php echo esc_url(admin_url('admin.php?page=sap-wc-products&filter=mapped')); ?>" class="<?php echo $filter === 'mapped' ? 'current' : ''; ?>">Mapped <span class="count">(<?php echo $count_mapped; ?>)</span></a> |</li>
        <li><a href="<?php echo esc_url(admin_url('admin.php?page=sap-wc-products&filter=unmapped')); ?>" class="<?php echo $filter === 'unmapped' ? 'current' : ''; ?>">Unmapped <span class="count">(<?php echo $count_unmapped; ?>)</span></a> |</li>
        <li><a href="<?php echo esc_url(admin_url('admin.php?page=sap-wc-products&filter=mismatched')); ?>" class="<?php echo $filter === 'mismatched' ? 'current' : ''; ?>">Mismatched <span class="count">(<?php echo $count_mismatched; ?>)</span></a></li>
    </ul>

    <form method="get">
        <input type="hidden" name="page" value="sap-wc-products">
        <?php if ($filter !== 'all'): ?><input type="hidden" name="filter" value="<?php echo esc_attr($filter); ?>"><?php endif; ?>
        <p class="search-box">
            <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search name, SKU, ItemCode...', 'sap-wc-sync'); ?>">
            <input type="submit" class="button" value="<?php esc_attr_e('Search', 'sap-wc-sync'); ?>">
        </p>
    </form>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><a href="<?php echo esc_url(sap_wc_sort_url('product', $orderby, $order, $filter, $search)); ?>">Product<?php echo sap_wc_sort_arrow('product', $orderby, $order); ?></a></th>
                <th><a href="<?php echo esc_url(sap_wc_sort_url('sku', $orderby, $order, $filter, $search)); ?>">SKU<?php echo sap_wc_sort_arrow('sku', $orderby, $order); ?></a></th>
                <th><a href="<?php echo esc_url(sap_wc_sort_url('sap_item_code', $orderby, $order, $filter, $search)); ?>">SAP ItemCode<?php echo sap_wc_sort_arrow('sap_item_code', $orderby, $order); ?></a></th>
                <th><a href="<?php echo esc_url(sap_wc_sort_url('wc_stock', $orderby, $order, $filter, $search)); ?>">WC Stock<?php echo sap_wc_sort_arrow('wc_stock', $orderby, $order); ?></a></th>
                <th><a href="<?php echo esc_url(sap_wc_sort_url('sap_instock', $orderby, $order, $filter, $search)); ?>">InStock<?php echo sap_wc_sort_arrow('sap_instock', $orderby, $order); ?></a></th>
                <th><a href="<?php echo esc_url(sap_wc_sort_url('sap_committed', $orderby, $order, $filter, $search)); ?>">Committed<?php echo sap_wc_sort_arrow('sap_committed', $orderby, $order); ?></a></th>
                <th><a href="<?php echo esc_url(sap_wc_sort_url('sap_stock', $orderby, $order, $filter, $search)); ?>">Available<?php echo sap_wc_sort_arrow('sap_stock', $orderby, $order); ?></a></th>
                <th>Match</th>
                <th><a href="<?php echo esc_url(sap_wc_sort_url('status', $orderby, $order, $filter, $search)); ?>">Status<?php echo sap_wc_sort_arrow('status', $orderby, $order); ?></a></th>
                <th><a href="<?php echo esc_url(sap_wc_sort_url('last_sync', $orderby, $order, $filter, $search)); ?>">Last Sync<?php echo sap_wc_sort_arrow('last_sync', $orderby, $order); ?></a></th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($products)): ?>
                <tr><td colspan="11"><?php esc_html_e('No products found.', 'sap-wc-sync'); ?></td></tr>
            <?php else: ?>
                <?php foreach ($products as $p):
                    $is_mapped    = !empty($p->sap_item_code);
                    $wc_stock     = (int) ($p->wc_stock ?? 0);
                    $sap_in_stock = isset($p->sap_in_stock) ? (int) $p->sap_in_stock : null;
                    $sap_committed = isset($p->sap_committed) ? (int) $p->sap_committed : null;
                    $sap_stock    = isset($p->sap_stock) ? (int) $p->sap_stock : ($sap_in_stock !== null && $sap_committed !== null ? max(0, $sap_in_stock - $sap_committed) : null);
                    $match        = ($sap_stock === null) ? null : ($wc_stock === $sap_stock);

                    if (!$is_mapped) { $status = 'unmapped'; $label = 'Unmapped'; }
                    elseif ($p->sync_status === 'error') { $status = 'error'; $label = 'Error'; }
                    elseif ($p->sync_status === 'synced') { $status = 'synced'; $label = 'Synced'; }
                    else { $status = 'pending'; $label = 'Pending'; }
                ?>
                <tr data-product-id="<?php echo esc_attr($p->product_id); ?>">
                    <td><strong><a href="<?php echo esc_url(get_edit_post_link($p->product_id)); ?>"><?php echo esc_html(wp_trim_words($p->post_title, 6)); ?></a></strong></td>
                    <td><?php echo $p->sku ? '<code>' . esc_html($p->sku) . '</code>' : '—'; ?></td>
                    <td><?php echo $is_mapped ? '<code>' . esc_html($p->sap_item_code) . '</code>' : '<span style="color:#999;">Not mapped</span>'; ?></td>
                    <td><strong><?php echo esc_html($wc_stock); ?></strong></td>
                    <td><?php echo $sap_in_stock !== null ? esc_html($sap_in_stock) : '—'; ?></td>
                    <td><?php echo $sap_committed !== null ? esc_html($sap_committed) : '—'; ?></td>
                    <td><?php echo $sap_stock !== null ? '<strong>' . esc_html($sap_stock) . '</strong>' : '—'; ?></td>
                    <td><?php echo $match === true ? '&#9989;' : ($match === false ? '&#9888;&#65039;' : '—'); ?></td>
                    <td><span class="sap-wc-status sap-wc-status-<?php echo esc_attr($status); ?>"><?php echo esc_html($label); ?></span></td>
                    <td><?php echo $p->last_sync_at ? esc_html(human_time_diff(strtotime($p->last_sync_at), current_time('timestamp')) . ' ago') : 'Never'; ?></td>
                    <td>
                        <?php if ($is_mapped): ?>
                            <button type="button" class="button button-small sap-wc-sync-single" data-product-id="<?php echo esc_attr($p->product_id); ?>">Sync</button>
                        <?php else: ?>
                            <button type="button" class="button button-small sap-wc-manual-map" data-product-id="<?php echo esc_attr($p->product_id); ?>" data-product-name="<?php echo esc_attr($p->post_title); ?>">Map</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if ($total_pages > 1):
        $base_url = admin_url('admin.php?page=sap-wc-products');
        if ($filter !== 'all') { $base_url = add_query_arg('filter', $filter, $base_url); }
        if (!empty($search))   { $base_url = add_query_arg('s', $search, $base_url); }
        if ($orderby !== 'last_sync') { $base_url = add_query_arg('orderby', $orderby, $base_url); }
        if ($order !== 'DESC') { $base_url = add_query_arg('order', $order, $base_url); }
    ?>
    <div class="tablenav bottom">
        <div class="tablenav-pages">
            <?php echo paginate_links(['base' => add_query_arg('paged', '%#%', $base_url), 'format' => '', 'total' => $total_pages, 'current' => $page]); ?>
        </div>
    </div>
    <?php endif; ?>
</div>
