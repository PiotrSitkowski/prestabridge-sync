<?php

declare(strict_types = 1)
;

use PrestaBridge\Auth\HmacAuthenticator;
use PrestaBridge\Import\DuplicateChecker;
use PrestaBridge\Import\ProductImporter;
use PrestaBridge\Import\ProductValidator;
use PrestaBridge\Image\ImageQueueManager;
use PrestaBridge\Logging\BridgeLogger;
use PrestaBridge\Config\ModuleConfig;
use PrestaBridge\Tracking\ImportTracker;

/**
 * API endpoint for PrestaShop module.
 * URL: /module/prestabridge/api
 * Method: POST only
 * Auth: X-PrestaBridge-Auth: timestamp.hmac_signature
 */
class PrestabridgeApiModuleFrontController extends ModuleFrontController
{
    /**
     * @var bool Disable PS layout — return raw JSON output
     */
    public $ajax = true;

    /**
     * Main entry point for ModuleFrontController.
     * Called by PrestaShop routing after module load.
     */
    public function initContent(): void
    {
        parent::initContent();

        header('Content-Type: application/json');

        // --------------------------------------------------------
        // Step 1: Only POST allowed
        // --------------------------------------------------------
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            die(json_encode([
                'success' => false,
                'error' => 'Method not allowed. Use POST.',
            ]));
        }

        // --------------------------------------------------------
        // Step 2: Read raw body ONCE — reused for HMAC and JSON decode
        // php://input can only be read once.
        // --------------------------------------------------------
        $rawBody = (string)file_get_contents('php://input');

        // --------------------------------------------------------
        // Step 3: HMAC authentication
        // --------------------------------------------------------
        $authHeader = $_SERVER['HTTP_X_PRESTABRIDGE_AUTH'] ?? '';
        $authSecret = ModuleConfig::getAuthSecret();

        if (!HmacAuthenticator::verify($authHeader, $rawBody, $authSecret)) {
            BridgeLogger::warning(
                'API: Authentication failed',
            ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'],
                'api'
            );
            http_response_code(401);
            die(json_encode([
                'success' => false,
                'error' => 'Unauthorized. Invalid or missing authentication.',
            ]));
        }

        // --------------------------------------------------------
        // Step 4: Parse JSON body
        // --------------------------------------------------------
        $body = json_decode($rawBody, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            die(json_encode([
                'success' => false,
                'error' => 'Invalid JSON: ' . json_last_error_msg(),
            ]));
        }

        // --------------------------------------------------------
        // Step 5: Validate request structure
        // --------------------------------------------------------
        if (!isset($body['products']) || !is_array($body['products'])) {
            http_response_code(400);
            die(json_encode([
                'success' => false,
                'error' => 'Request body must contain a "products" array.',
            ]));
        }

        if (empty($body['products'])) {
            http_response_code(400);
            die(json_encode([
                'success' => false,
                'error' => 'Products array must not be empty.',
            ]));
        }

        $requestId = $body['requestId'] ?? bin2hex(random_bytes(16));
        $overwrite = ModuleConfig::getOverwriteDuplicates();
        $products = $body['products'];

        // --------------------------------------------------------
        // Step 6: Process each product
        // --------------------------------------------------------
        $results = [];
        $rejected = [];

        foreach ($products as $index => $product) {
            // 6a. Validate product fields
            $validation = ProductValidator::validate($product);
            if (!$validation['valid']) {
                $rejected[] = [
                    'index' => $index,
                    'sku' => $product['sku'] ?? '',
                    'errors' => $validation['errors'],
                ];
                BridgeLogger::warning(
                    'API: Product validation failed',
                ['index' => $index, 'errors' => $validation['errors']],
                    'api',
                    $product['sku'] ?? null,
                    null
                );
                continue;
            }

            // 6b. Duplicate check
            $updateMode = false;
            $existingId = DuplicateChecker::getProductIdBySku($product['sku']);

            if ($existingId !== null) {
                if (!$overwrite) {
                    // Skip — no overwrite configured
                    BridgeLogger::info(
                        'API: Duplicate SKU skipped (overwrite=false)',
                    ['sku' => $product['sku'], 'existingId' => $existingId],
                        'api',
                        $product['sku']
                    );
                    $results[] = [
                        'sku' => $product['sku'],
                        'status' => 'skipped',
                        'productId' => $existingId,
                        'imagesQueued' => 0,
                    ];
                    continue;
                }
                $updateMode = true;
            }

            // 6c. Import product (create or update via ObjectModel)
            try {
                $importResult = ProductImporter::import($product, $updateMode);
            }
            catch (\Exception $e) {
                BridgeLogger::error(
                    'API: ProductImporter threw exception: ' . $e->getMessage(),
                ['sku' => $product['sku'], 'trace' => $e->getTraceAsString()],
                    'api',
                    $product['sku']
                );
                $rejected[] = [
                    'index' => $index,
                    'sku' => $product['sku'],
                    'errors' => [$e->getMessage()],
                ];
                continue;
            }

            if (!$importResult['success']) {
                // Import failed — add to rejected (not results)
                $rejected[] = [
                    'index' => $index,
                    'sku' => $product['sku'],
                    'errors' => [$importResult['error'] ?? 'Import failed'],
                ];
                continue;
            }

            $productId = $importResult['productId'];

            // 6d. Enqueue images for async CRON processing
            $imagesQueued = 0;
            if (!empty($product['images']) && is_array($product['images'])) {
                $imagesQueued = ImageQueueManager::enqueue($productId, $product['sku'], $product['images']);
            }

            // 6e. Track import status
            try {
                ImportTracker::track($requestId, $productId, $product);
            }
            catch (\Exception $e) {
                // Non-fatal — log and continue
                BridgeLogger::warning(
                    'API: ImportTracker failed (non-fatal): ' . $e->getMessage(),
                ['sku' => $product['sku']],
                    'api',
                    $product['sku'],
                    $productId
                );
            }

            // 6f. Collect result for PSResponse
            $results[] = [
                'sku' => $product['sku'],
                'status' => $importResult['status'], // 'created' | 'updated'
                'productId' => $productId,
                'imagesQueued' => $imagesQueued,
            ];
        }

        // --------------------------------------------------------
        // Step 7: Build and return PSResponse (section 4.5 CLAUDE.md)
        // Partial success: always success=true if auth passed.
        // CF Consumer acks the message; PS logs per-product errors.
        // --------------------------------------------------------
        BridgeLogger::info(
            'API: Import batch processed',
        [
            'requestId' => $requestId,
            'accepted' => count($results),
            'rejected' => count($rejected),
        ],
            'api',
            null,
            null
        );

        die(json_encode([
            'success' => true,
            'results' => $results,
            'rejected' => $rejected,
        ]));
    }
}
