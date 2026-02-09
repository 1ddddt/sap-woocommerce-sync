<?php
/**
 * Logger Interface
 *
 * @package SAP_WooCommerce_Sync
 * @since 2.0.0
 */

namespace SAPWCSync\Interfaces;

defined('ABSPATH') || exit;

interface Logger_Interface
{
    public function log(string $entity_type, ?int $entity_id, string $direction, string $status, string $message, $request = null, $response = null): void;
    public function info(string $message, array $context = []): void;
    public function error(string $message, array $context = []): void;
    public function warning(string $message, array $context = []): void;
    public function success(string $message, array $context = []): void;
    public function get_recent_logs(array $filters = [], int $limit = 50, int $offset = 0): array;
    public function cleanup_old_logs(int $days = 30): int;
}
