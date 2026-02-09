<?php
/**
 * Repository Interface
 *
 * @package SAP_WooCommerce_Sync
 * @since 2.0.0
 */

namespace SAPWCSync\Interfaces;

defined('ABSPATH') || exit;

interface Repository_Interface
{
    public function find(int $id): ?object;
    public function create(array $data): int;
    public function update(int $id, array $data): bool;
    public function delete(int $id): bool;
    public function find_all(array $conditions = [], int $limit = 0, int $offset = 0): array;
    public function count(array $conditions = []): int;
}
