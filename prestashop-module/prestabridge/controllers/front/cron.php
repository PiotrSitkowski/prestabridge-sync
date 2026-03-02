<?php

declare(strict_types = 1)
;

if (file_exists(dirname(__DIR__, 2) . '/vendor/autoload.php')) {
    require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
}

use PrestaBridge\Image\ImageAssigner;
use PrestaBridge\Image\ImageDownloader;
use PrestaBridge\Image\ImageLockManager;
use PrestaBridge\Image\ImageQueueManager;
use PrestaBridge\Logging\BridgeLogger;
use PrestaBridge\Config\ModuleConfig;
use PrestaBridge\Tracking\ImportTracker;

/**
 * CRON endpoint for async image processing.
 * URL: /module/prestabridge/cron?token=CRON_TOKEN[&limit=N]
 * Method: GET
 *
 * Trigger example (crontab):
 *   *\/2 * * * * curl -s "https://shop.example.com/module/prestabridge/cron?token=TOKEN&limit=10"
 */
class PrestabridgeCronModuleFrontController extends ModuleFrontController
{
    /**
     * @var bool Disable PS layout — return raw JSON output
     */
    public $ajax = true;

    /**
     * Main entry point for ModuleFrontController.
     */
    public function initContent(): void
    {
        parent::initContent();

        header('Content-Type: application/json');

        // --------------------------------------------------------
        // Step 1: Validate CRON token (GET ?token=...)
        // Use hash_equals for constant-time comparison (timing-safe)
        // --------------------------------------------------------
        $providedToken = (string)($_GET['token'] ?? '');
        $cronToken = ModuleConfig::getCronToken();

        if (!hash_equals($cronToken, $providedToken)) {
            BridgeLogger::warning(
                'CRON: Invalid or missing token',
            ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'],
                'cron'
            );
            http_response_code(401);
            die(json_encode([
                'success' => false,
                'error' => 'Unauthorized. Invalid CRON token.',
            ]));
        }

        // --------------------------------------------------------
        // Step 2: Determine batch limit
        // Prefer ?limit=N query param, fall back to config value
        // --------------------------------------------------------
        $configLimit = ModuleConfig::getImagesPerCron();
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : $configLimit;

        if ($limit <= 0) {
            $limit = $configLimit;
        }

        // --------------------------------------------------------
        // Step 3: Acquire batch with pessimistic locking
        // ImageQueueManager handles expired lock release + new lock
        // --------------------------------------------------------
        $batch = ImageQueueManager::acquireBatch($limit);

        if (empty($batch)) {
            BridgeLogger::debug('CRON: No pending images in queue', [], 'cron');
            die(json_encode([
                'success' => true,
                'processed' => 0,
                'failed' => 0,
                'message' => 'No pending images.',
            ]));
        }

        // --------------------------------------------------------
        // Step 4: Process each image
        // --------------------------------------------------------
        $processed = 0;
        $failed = 0;
        $errors = [];

        foreach ($batch as $image) {
            $id = (int)$image['id_image_queue'];
            $productId = (int)$image['id_product'];
            $sku = (string)($image['sku'] ?? '');
            $url = (string)$image['image_url'];
            $position = (int)$image['position'];
            $isCover = (bool)$image['is_cover'];

            // 4a. Race condition safety check: product must exist
            // Rule #8 from project-rules.md — ALWAYS check before image ops
            if (!\Product::existsInDatabase($productId, 'product')) {
                $errorMsg = "Product ID $productId (SKU: $sku) not found in database";
                ImageQueueManager::markFailed($id, $errorMsg);
                BridgeLogger::error(
                    'CRON: ' . $errorMsg,
                ['id_image_queue' => $id, 'url' => $url],
                    'cron',
                    $sku,
                    $productId
                );
                $errors[] = $errorMsg;
                $failed++;
                continue;
            }

            // 4b. Download image from URL to temp file
            try {
                $download = ImageDownloader::download($url);
            }
            catch (\Exception $e) {
                $download = ['success' => false, 'error' => $e->getMessage()];
            }

            if (!$download['success']) {
                $errorMsg = $download['error'] ?? 'Download failed for: ' . $url;
                ImageQueueManager::markFailed($id, $errorMsg);
                BridgeLogger::error(
                    'CRON: Image download failed',
                ['url' => $url, 'error' => $errorMsg, 'id_image_queue' => $id],
                    'cron',
                    $sku,
                    $productId
                );
                $errors[] = "[$sku] $errorMsg";
                $failed++;
                continue;
            }

            $tmpFilePath = $download['tmpPath'];

            // 4c. Assign image to product via PS Image ObjectModel
            try {
                $assign = ImageAssigner::assign($productId, $tmpFilePath, $position, $isCover);
            }
            catch (\Exception $e) {
                // Cleanup tmp file in case ImageAssigner threw before handling it
                if (file_exists($tmpFilePath)) {
                    @unlink($tmpFilePath);
                }
                $assign = ['success' => false, 'error' => $e->getMessage()];
            }

            if (!$assign['success']) {
                $errorMsg = $assign['error'] ?? 'Assign failed for product ID ' . $productId;
                ImageQueueManager::markFailed($id, $errorMsg);
                BridgeLogger::error(
                    'CRON: Image assign failed',
                ['productId' => $productId, 'position' => $position, 'error' => $errorMsg],
                    'cron',
                    $sku,
                    $productId
                );
                $errors[] = "[$sku] $errorMsg";
                $failed++;
                continue;
            }

            // 4d. Mark as completed — clear lock, set status = completed
            ImageQueueManager::markCompleted($id);

            BridgeLogger::info(
                'CRON: Image processed successfully',
            [
                'id_image_queue' => $id,
                'productId' => $productId,
                'imageId' => $assign['imageId'] ?? 0,
                'position' => $position,
                'isCover' => $isCover,
            ],
                'cron',
                $sku,
                $productId
            );

            // 4e. Update import tracking status
            try {
                ImportTracker::updateImageStatus($productId);
            }
            catch (\Exception $e) {
                // Non-fatal — tracking failure must not block image processing
                BridgeLogger::warning(
                    'CRON: ImportTracker::updateImageStatus failed (non-fatal): ' . $e->getMessage(),
                ['productId' => $productId],
                    'cron',
                    $sku,
                    $productId
                );
            }

            $processed++;
        }

        // --------------------------------------------------------
        // Step 5: Safety net — release any expired locks
        // Protects against crashed CRON runs leaving records stuck
        // (Rule #8 / race-conditions skill pattern)
        // --------------------------------------------------------
        ImageLockManager::releaseExpiredLocks();

        // --------------------------------------------------------
        // Step 6: Return JSON report
        // --------------------------------------------------------
        BridgeLogger::info(
            'CRON: Batch complete',
        [
            'total' => count($batch),
            'processed' => $processed,
            'failed' => $failed,
        ],
            'cron'
        );

        die(json_encode([
            'success' => true,
            'processed' => $processed,
            'failed' => $failed,
            'total' => count($batch),
            'errors' => $errors,
        ]));
    }
}
