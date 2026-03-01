<?php

declare(strict_types = 1)
;

namespace PrestaBridge\Tracking;

use Db;

/**
 * Tracks the import status of products (per request).
 * Writes to ps_prestabridge_import_tracking table.
 */
class ImportTracker
{
    /**
     * Create or update a tracking record for an imported product.
     *
     * @param string $requestId    Request UUID from CF Worker
     * @param int    $productId    PrestaShop product ID
     * @param array<string, mixed> $payload Product payload (for images count)
     */
    public static function track(string $requestId, int $productId, array $payload): void
    {
        $sku = $payload['sku'] ?? '';
        $imagesTotal = count($payload['images'] ?? []);
        $status = $imagesTotal > 0 ? 'images_pending' : 'imported';

        Db::getInstance()->insert('prestabridge_import_tracking', [
            'request_id' => pSQL($requestId),
            'id_product' => (int)$productId,
            'sku' => pSQL($sku),
            'status' => pSQL($status),
            'images_total' => (int)$imagesTotal,
            'images_completed' => 0,
            'images_failed' => 0,
        ]);
    }

    /**
     * Update the image processing status for a tracked product.
     *
     * @param int $productId    PS product ID
     * @param bool $completed   Whether an image completed (true) or failed (false)
     */
    public static function updateImageStatus(int $productId, bool $completed = true): void
    {
        if ($completed) {
            Db::getInstance()->execute(
                'UPDATE ' . _DB_PREFIX_ . 'prestabridge_import_tracking
                 SET images_completed = images_completed + 1,
                     status = CASE
                         WHEN images_completed + 1 >= images_total THEN "completed"
                         ELSE "images_partial"
                     END,
                     updated_at = NOW()
                 WHERE id_product = ' . (int)$productId
            );
        }
        else {
            Db::getInstance()->execute(
                'UPDATE ' . _DB_PREFIX_ . 'prestabridge_import_tracking
                 SET images_failed = images_failed + 1,
                     updated_at = NOW()
                 WHERE id_product = ' . (int)$productId
            );
        }
    }
}
