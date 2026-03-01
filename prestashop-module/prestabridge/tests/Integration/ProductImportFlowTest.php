<?php

declare(strict_types = 1)
;

namespace PrestaBridge\Tests\Integration;

use PHPUnit\Framework\TestCase;
use PrestaBridge\Auth\HmacAuthenticator;
use PrestaBridge\Import\ProductValidator;
use PrestaBridge\Import\ProductImporter;
use PrestaBridge\Import\DuplicateChecker;
use PrestaBridge\Image\ImageQueueManager;

/**
 * Integration test: Full product import flow
 * Simulates what api.php controller does end-to-end.
 * Uses mocked PS classes from tests/bootstrap.php.
 *
 * TESTING-STRATEGY.md section 4.2 — PS Module Integration
 */
class ProductImportFlowTest extends TestCase
{
    private const TEST_SECRET = 'test-secret-key-for-hmac-256-minimum-32chars!!';

    /** @var string Raw JSON body for HMAC generation */
    private string $rawBody;

    protected function setUp(): void
    {
        // Reset all mocks to clean state
        \Db::reset();
        \Product::reset();
        \StockAvailable::reset();
        \Configuration::reset();
        \Configuration::seed([
            'PRESTABRIDGE_AUTH_SECRET' => self::TEST_SECRET,
            'PRESTABRIDGE_IMPORT_CATEGORY' => 2,
            'PRESTABRIDGE_OVERWRITE_DUPLICATES' => 0,
            'PS_LANG_DEFAULT' => 1,
        ]);
    }

    // =========================================================
    // Helper: generate valid HMAC auth header
    // =========================================================

    private function generateValidHeader(string $body, ?int $timestamp = null): string
    {
        $ts = $timestamp ?? time();
        $signature = hash_hmac('sha256', $ts . '.' . $body, self::TEST_SECRET);
        return $ts . '.' . $signature;
    }

    // =========================================================
    // Helper: perform the import flow (mirrors api.php logic)
    // =========================================================

    /**
     * Simulates the api.php controller loop for a list of product payloads.
     * Returns the PSResponse array.
     *
     * @param array<int, array<string, mixed>> $products
     * @param bool   $overwrite
     * @return array{success: bool, results: list<mixed>, rejected: list<mixed>}
     */
    private function runImportFlow(array $products, bool $overwrite = false): array
    {
        $results = [];
        $rejected = [];

        foreach ($products as $index => $product) {
            // Step 1: Validate
            $validation = ProductValidator::validate($product);
            if (!$validation['valid']) {
                $rejected[] = [
                    'index' => $index,
                    'sku' => $product['sku'] ?? '',
                    'errors' => $validation['errors'],
                ];
                continue;
            }

            // Step 2: Duplicate check
            $updateMode = false;
            $existingId = DuplicateChecker::getProductIdBySku($product['sku']);
            if ($existingId !== null) {
                if (!$overwrite) {
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

            // Step 3: Import
            $importResult = ProductImporter::import($product, $updateMode);
            if (!$importResult['success']) {
                $rejected[] = [
                    'index' => $index,
                    'sku' => $product['sku'],
                    'errors' => [$importResult['error'] ?? 'Import failed'],
                ];
                continue;
            }

            // Step 4: Enqueue images
            $imagesQueued = 0;
            if (!empty($product['images'])) {
                $imagesQueued = ImageQueueManager::enqueue(
                    $importResult['productId'],
                    $product['sku'],
                    $product['images']
                );
            }

            $results[] = [
                'sku' => $product['sku'],
                'status' => $importResult['status'],
                'productId' => $importResult['productId'],
                'imagesQueued' => $imagesQueued,
            ];
        }

        return [
            'success' => true,
            'results' => $results,
            'rejected' => $rejected,
        ];
    }

    // =========================================================
    // TEST: Happy path — 3 valid products created
    // =========================================================

    public function testFullImportFlowCreatesThreeProducts(): void
    {
        // Arrange
        $products = [
            ['sku' => 'INT-001', 'name' => 'Product One', 'price' => 10.00],
            ['sku' => 'INT-002', 'name' => 'Product Two', 'price' => 20.00],
            ['sku' => 'INT-003', 'name' => 'Product Three', 'price' => 30.00],
        ];
        \Db::getInstance()->setReturnValue(false); // No duplicates

        // Act
        $response = $this->runImportFlow($products);

        // Assert
        $this->assertTrue($response['success']);
        $this->assertCount(3, $response['results']);
        $this->assertCount(0, $response['rejected']);

        foreach ($response['results'] as $result) {
            $this->assertSame('created', $result['status']);
            $this->assertGreaterThan(0, $result['productId']);
        }
    }

    // =========================================================
    // TEST: 3 products with images — images enqueued
    // =========================================================

    public function testImagesAreEnqueuedForImportedProducts(): void
    {
        // Arrange
        $products = [
            [
                'sku' => 'IMG-001',
                'name' => 'With Images',
                'price' => 15.00,
                'images' => [
                    'https://example.com/img1.jpg',
                    'https://example.com/img2.jpg',
                ],
            ],
        ];
        \Db::getInstance()->setReturnValue(false); // No duplicate

        // Act
        $response = $this->runImportFlow($products);

        // Assert
        $this->assertCount(1, $response['results']);
        $this->assertSame(2, $response['results'][0]['imagesQueued']);

        $db = \Db::getInstance();
        // Two images inserted into image_queue table
        $imageInserts = array_filter(
            $db->insertCalls,
        fn($call) => $call['table'] === 'prestabridge_image_queue'
        );
        $this->assertCount(2, $imageInserts);

        // First image must be cover
        $firstInsert = array_values($imageInserts)[0];
        $this->assertSame(1, $firstInsert['data']['is_cover']);
        $this->assertSame(0, $firstInsert['data']['position']);
    }

    // =========================================================
    // TEST: Mixed — 2 valid + 1 invalid
    // =========================================================

    public function testMixedValidAndInvalidProducts(): void
    {
        // Arrange: 2 valid, 1 invalid (missing price)
        $products = [
            ['sku' => 'VALID-001', 'name' => 'Good Product', 'price' => 25.00],
            ['sku' => 'VALID-002', 'name' => 'Good Two', 'price' => 35.00],
            ['sku' => 'BAD-001', 'name' => 'No Price'], // Missing price
        ];
        \Db::getInstance()->setReturnValue(false);

        // Act
        $response = $this->runImportFlow($products);

        // Assert
        $this->assertTrue($response['success']);
        $this->assertCount(2, $response['results']);
        $this->assertCount(1, $response['rejected']);

        // Rejected entry has index and errors
        $rejected = $response['rejected'][0];
        $this->assertSame(2, $rejected['index']);
        $this->assertSame('BAD-001', $rejected['sku']);
        $this->assertNotEmpty($rejected['errors']);
    }

    // =========================================================
    // TEST: All products invalid
    // =========================================================

    public function testAllInvalidProductsProducesEmptyResults(): void
    {
        // Arrange
        $products = [
            ['name' => 'No SKU', 'price' => 10],
            ['sku' => 'X'], // Missing name and price
            ['sku' => 'Y', 'name' => 'Z', 'price' => 0], // Zero price
        ];

        // Act
        $response = $this->runImportFlow($products);

        // Assert
        $this->assertTrue($response['success']); // Partial success: always true
        $this->assertCount(0, $response['results']);
        $this->assertCount(3, $response['rejected']);

        // Db::insert for product should NOT have been called (no images, no import)
        $db = \Db::getInstance();
        $this->assertEmpty($db->insertCalls);
    }

    // =========================================================
    // TEST: Duplicate SKU, overwrite=false → skipped
    // =========================================================

    public function testDuplicateSkuWithOverwriteFalseIsSkipped(): void
    {
        // Arrange: Db returns existing id=99 for the SKU
        \Db::getInstance()->setReturnValue('99');

        $products = [
            ['sku' => 'EXIST-001', 'name' => 'Existing Product', 'price' => 50.00],
        ];

        // Act
        $response = $this->runImportFlow($products, false);

        // Assert
        $this->assertCount(1, $response['results']);
        $this->assertSame('skipped', $response['results'][0]['status']);
        $this->assertSame(99, $response['results'][0]['productId']);
        $this->assertCount(0, $response['rejected']);
    }

    // =========================================================
    // TEST: Duplicate SKU, overwrite=true → updated
    // =========================================================

    public function testDuplicateSkuWithOverwriteTrueIsUpdated(): void
    {
        // Arrange: Db returns existing id for the SKU
        \Db::getInstance()->setReturnValue('42');
        \Product::setUpdateResult(true);

        $products = [
            ['sku' => 'EXIST-002', 'name' => 'Updated Product', 'price' => 60.00],
        ];

        // Act
        $response = $this->runImportFlow($products, true);

        // Assert
        $this->assertCount(1, $response['results']);
        $this->assertSame('updated', $response['results'][0]['status']);
        $this->assertCount(0, $response['rejected']);
    }

    // =========================================================
    // TEST: HMAC authentication validates correctly
    // =========================================================

    public function testHmacAuthValidationPassesWithCorrectHeader(): void
    {
        // Arrange
        $body = '{"products":[{"sku":"A","name":"B","price":10}]}';
        $header = $this->generateValidHeader($body);

        // Act
        $result = HmacAuthenticator::verify($header, $body, self::TEST_SECRET);

        // Assert
        $this->assertTrue($result);
    }

    public function testHmacAuthValidationFailsWithWrongSecret(): void
    {
        // Arrange
        $body = '{"products":[{"sku":"A","name":"B","price":10}]}';
        $header = $this->generateValidHeader($body);

        // Act — pass a different secret
        $result = HmacAuthenticator::verify($header, $body, 'wrong-secret');

        // Assert
        $this->assertFalse($result);
    }

    public function testHmacAuthValidationFailsForExpiredTimestamp(): void
    {
        // Arrange: 10 minutes ago
        $body = '{"products":[]}';
        $header = $this->generateValidHeader($body, time() - 600);

        // Act
        $result = HmacAuthenticator::verify($header, $body, self::TEST_SECRET);

        // Assert
        $this->assertFalse($result);
    }

    // =========================================================
    // TEST: Import fail (Product::add returns false) → rejected
    // =========================================================

    public function testProductAddFailureMovesToRejected(): void
    {
        // Arrange
        \Db::getInstance()->setReturnValue(false); // No duplicate
        \Product::setAddResult(false); // Simulate DB failure

        $products = [
            ['sku' => 'FAIL-001', 'name' => 'Fail Product', 'price' => 9.99],
        ];

        // Act
        $response = $this->runImportFlow($products);

        // Assert
        $this->assertCount(0, $response['results']);
        $this->assertCount(1, $response['rejected']);
        $this->assertSame('FAIL-001', $response['rejected'][0]['sku']);
    }

    // =========================================================
    // TEST: Stock is set for imported product
    // =========================================================

    public function testStockIsSetAfterSuccessfulImport(): void
    {
        // Arrange
        \Db::getInstance()->setReturnValue(false);
        $products = [
            ['sku' => 'STOCK-001', 'name' => 'Stock Product', 'price' => 5.00, 'quantity' => 77],
        ];

        // Act
        $this->runImportFlow($products);

        // Assert
        $stockCalls = \StockAvailable::getCalls();
        $this->assertCount(1, $stockCalls);
        $this->assertSame(77, $stockCalls[0][2]); // Third arg is quantity
    }

    // =========================================================
    // TEST: Correct PSResponse structure
    // =========================================================

    public function testResponseContainsCorrectPSResponseStructure(): void
    {
        // Arrange
        \Db::getInstance()->setReturnValue(false);
        $products = [
            ['sku' => 'STRUCT-001', 'name' => 'Structure Test', 'price' => 1.00],
        ];

        // Act
        $response = $this->runImportFlow($products);

        // Assert — PSResponse shape (section 4.5 CLAUDE.md)
        $this->assertArrayHasKey('success', $response);
        $this->assertArrayHasKey('results', $response);
        $this->assertArrayHasKey('rejected', $response);

        $result = $response['results'][0];
        $this->assertArrayHasKey('sku', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('productId', $result);
        $this->assertArrayHasKey('imagesQueued', $result);
    }
}
