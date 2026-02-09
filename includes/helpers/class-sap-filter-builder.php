<?php
/**
 * OData Filter Builder for SAP Service Layer queries
 *
 * @package SAP_WooCommerce_Sync
 * @since 2.0.0
 */

namespace SAPWCSync\Helpers;

defined('ABSPATH') || exit;

class SAP_Filter_Builder
{
    /**
     * Build OR filter for matching field against multiple values.
     * e.g., "ItemCode eq 'A' or ItemCode eq 'B'"
     */
    public static function or_equals(array $values, string $field): string
    {
        $parts = [];
        foreach ($values as $value) {
            $escaped = self::escape($value);
            $parts[] = "{$field} eq '{$escaped}'";
        }
        return implode(' or ', $parts);
    }

    /**
     * Build single equals filter.
     */
    public static function equals(string $field, string $value): string
    {
        return "{$field} eq '" . self::escape($value) . "'";
    }

    /**
     * Build AND filter from multiple conditions.
     */
    public static function and_filter(array $conditions): string
    {
        return implode(' and ', $conditions);
    }

    /**
     * Build OR filter from multiple conditions.
     */
    public static function or_filter(array $conditions): string
    {
        return '(' . implode(' or ', $conditions) . ')';
    }

    /**
     * Build contains filter (substring match).
     */
    public static function contains(string $field, string $value): string
    {
        return "contains({$field}, '" . self::escape($value) . "')";
    }

    /**
     * Escape single quotes for OData string values.
     */
    private static function escape(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}
