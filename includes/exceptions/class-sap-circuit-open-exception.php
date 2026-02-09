<?php
/**
 * Circuit breaker is open - SAP unavailable.
 *
 * @package SAP_WooCommerce_Sync
 * @since 2.0.0
 */

namespace SAPWCSync\Exceptions;

defined('ABSPATH') || exit;

class SAP_Circuit_Open_Exception extends SAP_Sync_Exception {}
