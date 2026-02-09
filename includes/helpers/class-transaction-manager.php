<?php
/**
 * Database Transaction Manager with savepoint support
 *
 * @package SAP_WooCommerce_Sync
 * @since 2.0.0
 */

namespace SAPWCSync\Helpers;

defined('ABSPATH') || exit;

class Transaction_Manager
{
    private $depth = 0;

    /**
     * Execute callback within a database transaction.
     *
     * Supports nested transactions via savepoints.
     *
     * @param callable $callback Function to execute
     * @return mixed Return value of callback
     * @throws \Exception Re-throws exception after rollback
     */
    public function execute(callable $callback)
    {
        global $wpdb;

        $this->depth++;

        if ($this->depth === 1) {
            $wpdb->query('START TRANSACTION');
        } else {
            $savepoint = 'sp_' . $this->depth;
            $wpdb->query("SAVEPOINT {$savepoint}");
        }

        try {
            $result = $callback();

            if ($this->depth === 1) {
                $wpdb->query('COMMIT');
            }

            $this->depth--;
            return $result;

        } catch (\Exception $e) {
            if ($this->depth === 1) {
                $wpdb->query('ROLLBACK');
            } else {
                $savepoint = 'sp_' . $this->depth;
                $wpdb->query("ROLLBACK TO SAVEPOINT {$savepoint}");
            }

            $this->depth--;
            throw $e;
        }
    }
}
