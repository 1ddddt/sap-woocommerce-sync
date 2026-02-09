<?php
/**
 * Base Repository - Abstract database access layer
 *
 * @package SAP_WooCommerce_Sync
 * @since 2.0.0
 */

namespace SAPWCSync\Repositories;

use SAPWCSync\Interfaces\Repository_Interface;

defined('ABSPATH') || exit;

abstract class Base_Repository implements Repository_Interface
{
    protected $wpdb;
    protected $table;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . $this->get_table_name();
    }

    /**
     * Subclasses must define their table name (without prefix).
     */
    abstract protected function get_table_name(): string;

    public function find(int $id): ?object
    {
        return $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d",
            $id
        ));
    }

    public function create(array $data): int
    {
        $data['created_at'] = $data['created_at'] ?? current_time('mysql');
        $this->wpdb->insert($this->table, $data);
        return (int) $this->wpdb->insert_id;
    }

    public function update(int $id, array $data): bool
    {
        $data['updated_at'] = current_time('mysql');
        return $this->wpdb->update($this->table, $data, ['id' => $id]) !== false;
    }

    public function delete(int $id): bool
    {
        return $this->wpdb->delete($this->table, ['id' => $id]) !== false;
    }

    public function find_all(array $conditions = [], int $limit = 0, int $offset = 0): array
    {
        $where = '1=1';
        $values = [];

        foreach ($conditions as $field => $value) {
            $where .= " AND {$field} = %s";
            $values[] = $value;
        }

        $sql = "SELECT * FROM {$this->table} WHERE {$where} ORDER BY id DESC";

        if ($limit > 0) {
            $sql .= " LIMIT %d OFFSET %d";
            $values[] = $limit;
            $values[] = $offset;
        }

        if (!empty($values)) {
            return $this->wpdb->get_results($this->wpdb->prepare($sql, $values));
        }

        return $this->wpdb->get_results($sql);
    }

    public function count(array $conditions = []): int
    {
        $where = '1=1';
        $values = [];

        foreach ($conditions as $field => $value) {
            $where .= " AND {$field} = %s";
            $values[] = $value;
        }

        if (!empty($values)) {
            return (int) $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table} WHERE {$where}",
                $values
            ));
        }

        return (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->table}");
    }
}
