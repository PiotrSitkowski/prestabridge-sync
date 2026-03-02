<?php

declare(strict_types = 1)
;

namespace PrestaBridge\Image;

use Db;

/**
 * Manages the image download queue with pessimistic locking.
 *
 * Images are enqueued during product import and processed asynchronously
 * by CRON. Lock tokens prevent concurrent CRON executions from processing
 * the same images.
 */
class ImageQueueManager
{
    /**
     * Adds image URLs to the download queue.
     *
     * @param int $productId PrestaShop product ID
     * @param string $sku Product SKU for tracking
     * @param array<int, string> $imageUrls Image URLs indexed by position
     * @return int Number of images enqueued
     */
    public static function enqueue(int $productId, string $sku, array $imageUrls): int
    {
        if (empty($imageUrls)) {
            return 0;
        }

        $count = 0;
        foreach ($imageUrls as $position => $url) {
            $isCover = ($position === 0) ? 1 : 0;

            Db::getInstance()->insert('prestabridge_image_queue', [
                'id_product' => $productId,
                'sku' => pSQL($sku),
                'image_url' => pSQL($url),
                'position' => (int)$position,
                'is_cover' => (int)$isCover,
                'status' => 'pending',
                'attempts' => 0,
                'max_attempts' => 3,
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Removes all non-completed image queue entries for a product.
     * Call before re-enqueueing images on product update (overwrite mode).
     *
     * Removes: pending, processing, failed entries.
     * Keeps: completed entries (historical record).
     *
     * @param int $productId PrestaShop product ID
     * @return int Number of entries removed
     */
    public static function clearForProduct(int $productId): int
    {
        Db::getInstance()->execute(
            'DELETE FROM ' . _DB_PREFIX_ . 'prestabridge_image_queue
             WHERE id_product = ' . (int)$productId . '
             AND status IN ("pending", "processing", "failed")'
        );

        return (int)Db::getInstance()->getValue('SELECT ROW_COUNT()');
    }

    /**
     * Acquires a batch of pending images with pessimistic locking.
     *
     * Uses a unique lock_token to prevent concurrent CRON executions
     * from processing the same images. Releases expired locks first
     * as a safety net for crashed CRON processes.
     *
     * @param int $limit Maximum number of images to acquire
     * @return array<int, array<string, mixed>> Locked image records
     */
    public static function acquireBatch(int $limit): array
    {
        $lockToken = bin2hex(random_bytes(16));
        $lockTimeout = date('Y-m-d H:i:s', strtotime('-10 minutes'));

        // Safety net: release stale locks from crashed CRONs
        Db::getInstance()->execute(
            'UPDATE ' . _DB_PREFIX_ . 'prestabridge_image_queue
             SET lock_token = NULL, locked_at = NULL, status = "pending"
             WHERE status = "processing"
             AND locked_at < "' . pSQL($lockTimeout) . '"'
        );

        // Atomically lock a batch of pending images
        Db::getInstance()->execute(
            'UPDATE ' . _DB_PREFIX_ . 'prestabridge_image_queue
             SET lock_token = "' . pSQL($lockToken) . '",
                 locked_at = NOW(),
                 status = "processing"
             WHERE status = "pending"
             AND attempts < max_attempts
             ORDER BY created_at ASC
             LIMIT ' . (int)$limit
        );

        // Fetch only records locked by this CRON instance
        return Db::getInstance()->executeS(
            'SELECT * FROM ' . _DB_PREFIX_ . 'prestabridge_image_queue
             WHERE lock_token = "' . pSQL($lockToken) . '"'
        );
    }

    /**
     * Marks an image as completed and releases the lock.
     *
     * @param int $id Image queue record ID
     */
    public static function markCompleted(int $id): void
    {
        Db::getInstance()->update('prestabridge_image_queue', [
            'status' => 'completed',
            'lock_token' => null,
            'locked_at' => null,
        ], 'id_image_queue = ' . (int)$id);
    }

    /**
     * Marks an image as failed or returns to pending for retry.
     *
     * Uses a CASE expression: if attempts+1 >= max_attempts → 'failed',
     * otherwise → 'pending' (allows retry on next CRON run).
     *
     * @param int $id Image queue record ID
     * @param string $error Error message
     */
    public static function markFailed(int $id, string $error): void
    {
        Db::getInstance()->execute(
            'UPDATE ' . _DB_PREFIX_ . 'prestabridge_image_queue
             SET status = CASE WHEN attempts + 1 >= max_attempts THEN "failed" ELSE "pending" END,
                 attempts = attempts + 1,
                 error_message = "' . pSQL($error) . '",
                 lock_token = NULL,
                 locked_at = NULL
             WHERE id_image_queue = ' . (int)$id
        );
    }
}
