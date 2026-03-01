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

            // Detect image type and extension
            $mimeType = \mime_content_type($tmpFilePath);
            $extension = \image_type_to_extension(\exif_imagetype($tmpFilePath), false);

            if (!copy($tmpFilePath, $newPath . '.' . $extension)) {
                $image->delete();
                throw new \RuntimeException('Failed to copy image file');
            }

            // Generate thumbnails for all product image types
            $types = ImageType::getImagesTypes('products');
            foreach ($types as $type) {
                ImageManager::resize(
                    $newPath . '.' . $extension,
                    $newPath . '-' . stripslashes($type['name']) . '.' . $extension,
                    (int)$type['width'],
                    (int)$type['height'],
                    $extension
                );
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
