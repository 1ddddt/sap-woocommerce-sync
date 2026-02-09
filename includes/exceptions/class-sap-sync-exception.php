<?php
/**
 * Custom Exception Classes
 *
 * Typed exceptions for different failure modes. Allows catching
 * specific error types without string-matching on messages.
 *
 * @package SAP_WooCommerce_Sync
 * @since 2.0.0
 */

namespace SAPWCSync\Exceptions;

defined('ABSPATH') || exit;

/**
 * Base exception for all SAP sync errors.
 */
class SAP_Sync_Exception extends \Exception
{
    protected $context = [];

    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null, array $context = [])
    {
        $this->context = $context;
        parent::__construct($message, $code, $previous);
    }

    public function get_context(): array
    {
        return $this->context;
    }
}
