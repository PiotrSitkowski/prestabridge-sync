<?php

declare(strict_types = 1)
;

namespace PrestaBridge\Image;

use PrestaBridge\Config\ModuleConfig;

/**
 * Downloads images from URLs to temporary files.
 *
 * Uses stream context with configurable timeout from ModuleConfig.
 * Validates MIME type to ensure only images are accepted.
 */
class ImageDownloader
{
    /**
     * Downloads an image from URL to a temporary file.
     *
     * @param string $url Image URL to download
     * @return array{success: bool, tmpPath?: string, mimeType?: string, error?: string}
     */
    public static function download(string $url): array
    {
        $timeout = ModuleConfig::getImageTimeout();

        $context = stream_context_create([
            'http' => [
                'timeout' => $timeout,
                'method' => 'GET',
                'header' => 'User-Agent: PrestaBridge/1.0',
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $tmpPath = tempnam(sys_get_temp_dir(), 'pb_img_');

        try {
            $content = @file_get_contents($url, false, $context);
            if ($content === false) {
                throw new \RuntimeException('Failed to download image from: ' . $url);
            }

            file_put_contents($tmpPath, $content);

            // Verify MIME type
            $finfo = new \finfo(\FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($tmpPath);
            $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

            if (!in_array($mimeType, $allowedMimes)) {
                unlink($tmpPath);
                throw new \RuntimeException('Invalid image MIME type: ' . $mimeType);
            }

            return [
                'success' => true,
                'tmpPath' => $tmpPath,
                'mimeType' => $mimeType,
            ];
        }
        catch (\Exception $e) {
            if (file_exists($tmpPath)) {
                unlink($tmpPath);
            }
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
