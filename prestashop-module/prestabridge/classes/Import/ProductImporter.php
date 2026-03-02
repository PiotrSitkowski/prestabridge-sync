<?php

declare(strict_types = 1)
;

namespace PrestaBridge\Import;

use Product;
use StockAvailable;
use PrestaBridge\Logging\BridgeLogger;

/**
 * Imports (creates or updates) products using the PrestaShop ObjectModel.
 */
class ProductImporter
{
    /**
     * Import a single product.
     *
     * @param array<string, mixed> $payload     Product payload data
     * @param bool                 $updateMode  If true, updates existing product found by SKU
     *
     * @return array{success: bool, productId: int, status: string, error?: string}
     */
    public static function import(array $payload, bool $updateMode = false): array
    {
        try {
            $existing = null;
            if ($updateMode) {
                $existingId = DuplicateChecker::getProductIdBySku($payload['sku']);
                if ($existingId !== null) {
                    $existing = new Product($existingId);
                }
            }

            $product = ProductMapper::mapToProduct($payload, $existing);

            if ($updateMode && $existing !== null) {
                $result = $product->update();
                $status = 'updated';
            }
            else {
                $result = $product->add();
                $status = 'created';
            }

            if (!$result) {
                throw new \RuntimeException('Product save failed for SKU: ' . $payload['sku']);
            }

            // Assign import category
            $product->updateCategories([(int)\PrestaBridge\Config\ModuleConfig::getImportCategory()]);

            // Set stock availability
            try {
                StockAvailable::setQuantity(
                    (int)$product->id,
                    0,
                    (int)($payload['quantity'] ?? 0),
                    (int)$product->id_shop_default
                );
            }
            catch (\Exception $e) {
                // PrestaShop czasami po ->add() od razu zakłada wpis w stock_available przez własne hooki (bug w niektórych wersjach).
                // Kolejne wywołanie setQuantity wyrzuca próbę wstawienia Duplicate Entry (1062).
                if (strpos($e->getMessage(), '1062') !== false || strpos($e->getMessage(), 'Duplicate') !== false) {
                    try {
                        \Db::getInstance()->execute(
                            'UPDATE `' . _DB_PREFIX_ . 'stock_available` 
                             SET `quantity` = ' . (int)($payload['quantity'] ?? 0) . ' 
                             WHERE `id_product` = ' . (int)$product->id . ' AND `id_product_attribute` = 0'
                        );
                    }
                    catch (\Exception $e2) {
                        BridgeLogger::error('Stock update manual bypass failed: ' . $e2->getMessage(), [], 'import');
                    }
                }
                else {
                    BridgeLogger::error('Stock set error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()], 'import');
                }
            }

            BridgeLogger::info(
                'Product ' . $status,
            ['sku' => $payload['sku'], 'productId' => $product->id],
                'import',
                $payload['sku'],
                (int)$product->id
            );

            return [
                'success' => true,
                'productId' => (int)$product->id,
                'status' => $status,
            ];
        }
        catch (\Exception $e) {
            BridgeLogger::error(
                'Product import failed: ' . $e->getMessage(),
            ['sku' => $payload['sku'] ?? 'unknown', 'trace' => $e->getTraceAsString()],
                'import',
                $payload['sku'] ?? null
            );

            return [
                'success' => false,
                'productId' => 0,
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }
}
