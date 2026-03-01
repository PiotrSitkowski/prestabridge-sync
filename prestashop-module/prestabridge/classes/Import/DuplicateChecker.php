<?php

declare(strict_types = 1)
;

namespace PrestaBridge\Import;

use Db;

/**
 * Checks for duplicate products by SKU in the PrestaShop database.
 */
class DuplicateChecker
{
    /**
     * Check if a product with the given SKU exists.
     *
     * @param string $sku Product reference/SKU
     */
    public static function exists(string $sku): bool
    {
        return self::getProductIdBySku($sku) !== null;
    }

    /**
     * Return the product ID for a given SKU, or null if not found.
     *
     * @param string $sku Product reference/SKU
     * @return int|null Product ID or null
     */
    public static function getProductIdBySku(string $sku): ?int
    {
        $result = Db::getInstance()->getValue(
            'SELECT id_product FROM ' . _DB_PREFIX_ . 'product
             WHERE reference = \'' . pSQL($sku) . '\'
             LIMIT 1'
        );

        return $result ? (int)$result : null;
    }
}
