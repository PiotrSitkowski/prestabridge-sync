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
            StockAvailable::setQuantity(
                (int)$product->id,
                0,
                (int)($payload['quantity'] ?? 0)
            );

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
