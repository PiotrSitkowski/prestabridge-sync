<?php

declare(strict_types = 1)
;

namespace PrestaBridge\Image;

use Image;
use Product;
use ImageManager;
use ImageType;
use PrestaBridge\Logging\BridgeLogger;

/**
 * Assigns downloaded images to PrestaShop products.
 *
 * CRITICAL: Performs race condition safety check via Product::existsInDatabase()
 * before any Image operations (Rule #8).
 */
class ImageAssigner
{
    /**
     * Assigns an image to a product.
     *
     * @param int $productId PrestaShop product ID
     * @param string $tmpFilePath Path to temporary image file
     * @param int $position Image position (0 = first)
     * @param bool $isCover Whether this image should be the product cover
     * @return array{success: bool, imageId?: int, error?: string}
     */
    public static function assign(
        int $productId,
        string $tmpFilePath,
        int $position,
        bool $isCover
        ): array
    {
        // RACE CONDITION CHECK: Product must exist (Rule #8)
        if (!Product::existsInDatabase($productId, 'product')) {
            return [
                'success' => false,
                'error' => "Product ID $productId does not exist in database"
            ];
        }

        try {
            $image = new Image();
            $image->id_product = $productId;
            $image->position = $position;
            $image->cover = $isCover ? 1 : 0;

            // If cover, remove cover flag from other images first
            if ($isCover) {
                Image::deleteCover($productId);
            }

            if (!$image->add()) {
                throw new \RuntimeException('Failed to create Image record');
            }

            // Copy file to correct PS location
            $newPath = $image->getPathForCreation();

            // PrestaShop ALWAYS uses 'jpg' extension for product images internally,
            // regardless of actual format. ImageManager::resize() handles conversion.
            $extension = 'jpg';
            $fullPath = $newPath . '.' . $extension;

            // Ensure the target directory exists
            $targetDir = dirname($newPath);
            if (!is_dir($targetDir)) {
                @mkdir($targetDir, 0775, true);
            }

            // Copy source image to PS image path
            if (!copy($tmpFilePath, $fullPath)) {
                $image->delete();
                throw new \RuntimeException('Failed to copy image file to: ' . $fullPath);
            }

            // CRITICAL: Verify the file actually exists and has content.
            // Without this, PS resolves a dangling Image record to a random product's image.
            if (!file_exists($fullPath) || filesize($fullPath) === 0) {
                @unlink($fullPath);
                $image->delete();
                throw new \RuntimeException('Image file verification failed (missing or empty): ' . $fullPath);
            }

            // Generate thumbnails for all product image types
            $types = ImageType::getImagesTypes('products');
            foreach ($types as $type) {
                $thumbPath = $newPath . '-' . stripslashes($type['name']) . '.' . $extension;
                $resizeResult = ImageManager::resize(
                    $fullPath,
                    $thumbPath,
                    (int)$type['width'],
                    (int)$type['height'],
                    $extension
                );

                // If thumbnail generation fails, log but continue —
                // the main image exists, PS can still display it
                if (!$resizeResult) {
                    BridgeLogger::warning('Thumbnail generation failed', [
                        'productId' => $productId,
                        'type' => $type['name'],
                        'thumbPath' => $thumbPath,
                    ], 'image', null, $productId);
                }
            }

            // Cleanup temporary file
            @unlink($tmpFilePath);

            return [
                'success' => true,
                'imageId' => (int)$image->id,
            ];
        }
        catch (\Exception $e) {
            @unlink($tmpFilePath);
            BridgeLogger::error('Image assign failed', [
                'productId' => $productId,
                'position' => $position,
                'error' => $e->getMessage()
            ], 'image', null, $productId);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
