<?php
/**
 * SAP Client Interface
 *
 * Contract for SAP Business One Service Layer communication.
 *
 * @package SAP_WooCommerce_Sync
 * @since 2.0.0
 */

namespace SAPWCSync\Interfaces;

defined('ABSPATH') || exit;

interface SAP_Client_Interface
{
    public function login(): bool;
    public function logout(): bool;
    public function get(string $endpoint, array $params = []): array;
    public function post(string $endpoint, array $data = []): array;
    public function patch(string $endpoint, array $data = []): array;
    public function delete(string $endpoint): bool;
    public function get_version(): string;
    public function get_last_error(): ?string;
    public function is_authenticated(): bool;
    public function test_connection(): array;
}
