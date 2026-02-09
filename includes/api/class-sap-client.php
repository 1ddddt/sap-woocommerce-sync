<?php
/**
 * SAP Service Layer HTTP Client
 *
 * Handles authentication, session management, and all HTTP communication
 * with SAP Business One Service Layer. Integrates with Circuit Breaker
 * for fault tolerance.
 *
 * @package SAP_WooCommerce_Sync
 * @since 2.0.0
 */

namespace SAPWCSync\API;

use SAPWCSync\Constants\Config;
use SAPWCSync\Interfaces\SAP_Client_Interface;
use SAPWCSync\Exceptions\SAP_API_Exception;
use SAPWCSync\Exceptions\SAP_Auth_Exception;
use SAPWCSync\Helpers\API_Cache;
use SAPWCSync\Helpers\Logger;

defined('ABSPATH') || exit;

class SAP_Client implements SAP_Client_Interface
{
    private $base_url;
    private $company_db;
    private $username;
    private $password;
    private $session_id;
    private $route_id;
    private $version = '';
    private $session_timeout = 30;
    private $last_login_time;
    private $last_error;
    private $logger;
    private $retry_on_401 = true;

    public function __construct(array $config)
    {
        $this->base_url = rtrim($config['base_url'] ?? '', '/');
        if (!empty($this->base_url) && substr($this->base_url, -1) !== '/') {
            $this->base_url .= '/';
        }
        $this->company_db = $config['company_db'] ?? '';
        $this->username = $config['username'] ?? '';
        $this->logger = new Logger();

        // Decrypt password if encrypted
        $password = $config['password'] ?? '';
        if (!empty($password)) {
            try {
                $this->password = \SAPWCSync\Security\Encryption::decrypt($password);
            } catch (\Exception $e) {
                // Fallback to plain text during migration
                $this->password = $password;
            }
        } else {
            $this->password = '';
        }
    }

    /**
     * Login to SAP Service Layer.
     */
    public function login(): bool
    {
        if (empty($this->base_url) || empty($this->company_db) || empty($this->username)) {
            throw new SAP_Auth_Exception('SAP configuration incomplete');
        }

        $response = wp_remote_post($this->base_url . 'Login', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode([
                'CompanyDB' => $this->company_db,
                'UserName'  => $this->username,
                'Password'  => $this->password,
            ]),
            'sslverify' => $this->get_ssl_verify(),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            throw new SAP_API_Exception('Connection failed: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $error_msg = $body['error']['message'] ?? 'Login failed';
            throw new SAP_Auth_Exception("SAP Login Error: {$error_msg}");
        }

        // Extract session cookies
        foreach (wp_remote_retrieve_cookies($response) as $cookie) {
            if ($cookie->name === 'B1SESSION') {
                $this->session_id = $cookie->value;
            }
            if ($cookie->name === 'ROUTEID') {
                $this->route_id = $cookie->value;
            }
        }

        $this->version = $body['Version'] ?? '';
        $this->session_timeout = $body['SessionTimeout'] ?? 30;
        $this->last_login_time = time();

        return !empty($this->session_id);
    }

    /**
     * Logout from SAP Service Layer.
     */
    public function logout(): bool
    {
        if (empty($this->session_id)) {
            return false;
        }

        wp_remote_post($this->base_url . 'Logout', [
            'headers' => $this->get_auth_headers(),
            'sslverify' => $this->get_ssl_verify(),
            'timeout' => 10,
        ]);

        $this->session_id = null;
        $this->route_id = null;
        return true;
    }

    public function get(string $endpoint, array $params = []): array
    {
        return $this->request('GET', $endpoint, $params);
    }

    public function post(string $endpoint, array $data = []): array
    {
        return $this->request('POST', $endpoint, [], $data);
    }

    public function patch(string $endpoint, array $data = []): array
    {
        return $this->request('PATCH', $endpoint, [], $data);
    }

    public function delete(string $endpoint): bool
    {
        $this->ensure_authenticated();

        $response = wp_remote_request($this->base_url . $endpoint, [
            'method' => 'DELETE',
            'headers' => $this->get_auth_headers(),
            'sslverify' => $this->get_ssl_verify(),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            $this->last_error = $response->get_error_message();
            throw new SAP_API_Exception($this->last_error);
        }

        $code = wp_remote_retrieve_response_code($response);
        return $code >= 200 && $code < 300;
    }

    public function get_version(): string
    {
        return $this->version;
    }

    public function get_last_error(): ?string
    {
        return $this->last_error;
    }

    public function is_authenticated(): bool
    {
        return !empty($this->session_id);
    }

    public function test_connection(): array
    {
        try {
            $this->login();
            $this->logout();
            return ['success' => true, 'message' => 'Connected successfully', 'version' => $this->version];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Internal request method with caching and session management.
     */
    private function request(string $method, string $endpoint, array $params = [], array $data = []): array
    {
        // Check cache for GET requests
        $use_cache = API_Cache::should_cache($method, $endpoint);
        if ($use_cache) {
            $cached = API_Cache::get($endpoint, $params);
            if ($cached !== false) {
                return $cached;
            }
        }

        $this->ensure_authenticated();

        $url = $this->base_url . $endpoint;

        if (!empty($params)) {
            $query_parts = [];
            foreach ($params as $key => $value) {
                $query_parts[] = $key . '=' . rawurlencode($value);
            }
            $url .= '?' . implode('&', $query_parts);
        }

        $args = [
            'method' => $method,
            'headers' => $this->get_auth_headers(),
            'sslverify' => $this->get_ssl_verify(),
            'timeout' => 60,
        ];

        if (in_array($method, ['POST', 'PATCH']) && !empty($data)) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($data);
        }

        $response = wp_remote_request($url, $args);

        $result = $this->handle_response($response, $method, $endpoint, $params, $data);

        // Cache successful GET responses
        if ($use_cache && $result !== null) {
            API_Cache::set($endpoint, $params, $result);
        }

        return $result;
    }

    /**
     * Handle API response with session-expired retry.
     */
    private function handle_response($response, string $method, string $endpoint, array $params, array $data): array
    {
        if (is_wp_error($response)) {
            $this->last_error = $response->get_error_message();
            throw new SAP_API_Exception('Request failed: ' . $this->last_error);
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        // Log errors to DB
        if ($code >= 400) {
            $this->logger->log(
                'api', null, 'request', 'error',
                "{$method} {$endpoint} - HTTP {$code}",
                ['method' => $method, 'endpoint' => $endpoint, 'params' => $params, 'data' => $data],
                $body
            );
        }

        // Handle session timeout - re-authenticate once
        if ($code === 401 && $this->retry_on_401) {
            $this->session_id = null;
            $this->retry_on_401 = false;
            $this->login();
            $this->retry_on_401 = true;
            return $this->request($method, $endpoint, $params, $data);
        }

        if ($code >= 400) {
            $error_msg = $body['error']['message'] ?? 'Unknown error';
            $this->last_error = $error_msg;
            throw new SAP_API_Exception("SAP Error ({$code}): {$error_msg}", $code);
        }

        return is_array($body) ? $body : [];
    }

    /**
     * Ensure session is valid, re-authenticate if needed.
     */
    private function ensure_authenticated(): void
    {
        if (empty($this->session_id)) {
            $this->login();
            return;
        }

        // Refresh session at 80% of timeout
        $timeout_seconds = ($this->session_timeout * 60) * Config::SAP_SESSION_REFRESH_PCT;
        if ($this->last_login_time && (time() - $this->last_login_time) > $timeout_seconds) {
            $this->login();
        }
    }

    private function get_auth_headers(): array
    {
        return [
            'Cookie' => "B1SESSION={$this->session_id}; ROUTEID={$this->route_id}",
        ];
    }

    private function get_ssl_verify(): bool
    {
        return apply_filters('sap_wc_ssl_verify', defined('SAP_WC_SSL_VERIFY') ? SAP_WC_SSL_VERIFY : true);
    }
}
