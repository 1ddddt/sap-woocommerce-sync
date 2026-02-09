<?php
/**
 * AES-256-CBC Encryption for SAP credentials
 *
 * @package SAP_WooCommerce_Sync
 * @since 2.0.0
 */

namespace SAPWCSync\Security;

defined('ABSPATH') || exit;

class Encryption
{
    const CIPHER = 'aes-256-cbc';

    /**
     * Encrypt plaintext using AES-256-CBC.
     */
    public static function encrypt(string $plaintext): string
    {
        $key = self::get_key();

        $iv_length = openssl_cipher_iv_length(self::CIPHER);
        $iv = openssl_random_pseudo_bytes($iv_length);

        $encrypted = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);

        if ($encrypted === false) {
            throw new \Exception('Encryption failed');
        }

        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt ciphertext.
     */
    public static function decrypt(string $ciphertext): string
    {
        $key = self::get_key();

        $data = base64_decode($ciphertext, true);
        if ($data === false) {
            throw new \Exception('Invalid encrypted data format');
        }

        $iv_length = openssl_cipher_iv_length(self::CIPHER);

        if (strlen($data) < $iv_length) {
            throw new \Exception('Encrypted data too short');
        }

        $iv = substr($data, 0, $iv_length);
        $encrypted = substr($data, $iv_length);

        $decrypted = openssl_decrypt($encrypted, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);

        if ($decrypted === false) {
            throw new \Exception('Decryption failed - invalid key or corrupted data');
        }

        return $decrypted;
    }

    /**
     * Validate encryption key configuration.
     */
    public static function validate_key(): array
    {
        if (!defined('SAP_WC_ENCRYPTION_KEY')) {
            return [
                'valid' => false,
                'message' => 'SAP_WC_ENCRYPTION_KEY constant is not defined in wp-config.php',
            ];
        }

        $key = SAP_WC_ENCRYPTION_KEY;

        if (empty($key)) {
            return [
                'valid' => false,
                'message' => 'SAP_WC_ENCRYPTION_KEY is empty',
            ];
        }

        if (strlen($key) !== 64 || !ctype_xdigit($key)) {
            return [
                'valid' => false,
                'message' => 'SAP_WC_ENCRYPTION_KEY must be a 64-character hex string (32 bytes). Generate with: openssl rand -hex 32',
            ];
        }

        return ['valid' => true, 'message' => 'Encryption key is properly configured'];
    }

    /**
     * Check if encryption key is configured.
     */
    public static function is_key_configured(): bool
    {
        return self::validate_key()['valid'];
    }

    /**
     * Get the binary encryption key.
     */
    private static function get_key(): string
    {
        if (!defined('SAP_WC_ENCRYPTION_KEY') || empty(SAP_WC_ENCRYPTION_KEY)) {
            throw new \Exception('Encryption key not configured. Define SAP_WC_ENCRYPTION_KEY in wp-config.php');
        }

        return hex2bin(SAP_WC_ENCRYPTION_KEY);
    }
}
