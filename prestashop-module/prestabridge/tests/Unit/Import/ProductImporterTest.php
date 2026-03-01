<?php

declare(strict_types = 1)
;

namespace PrestaBridge\Tests\Unit\Import;

use PHPUnit\Framework\TestCase;
use PrestaBridge\Import\ProductImporter;
use Configuration;
use Context;
use Db;
use Product;
use StockAvailable;

class ProductImporterTest extends TestCase
{
    private array $validPayload;

    protected function setUp(): void
    {
        Db::reset();
        Product::reset();
        StockAvailable::reset();
        Configuration::reset();
        Configuration::seed([
            'PS_LANG_DEFAULT' => 1,
            'PRESTABRIDGE_IMPORT_CATEGORY' => 5,
            'PRESTABRIDGE_DEFAULT_ACTIVE' => 0,
        ]);
        Context::reset();

        $fixtures = json_decode(
            file_get_contents(__DIR__ . '/../../../../shared/fixtures/valid-products.json'),
            true
        );
        $this->validPayload = $fixtures['minimal'][0]; // {sku: 'MIN-001', name: 'Minimal Product 1', price: 9.99}
    }

    // TEST P-I1: creates new product successfully
    public function testCreatesNewProductSuccessfully(): void
    {
        // Arrange
        Product::setAddResult(true);

        // Act
        $result = ProductImporter::import($this->validPayload);

        // Assert
        $this->assertTrue($result['success']);
        $this->assertSame('created', $result['status']);
        $this->assertSame(42, $result['productId']); // mock Product assigns id=42 on add
    }

    // TEST P-I2: updates existing product when updateMode=true
    public function testUpdatesExistingProductWhenUpdateMode(): void
    {
        // Arrange — DuplicateChecker uses Db mock
        Db::getInstance()->setReturnValue('42');
        Product::setUpdateResult(true);

        // Act
        $result = ProductImporter::import($this->validPayload, true);

        // Assert
        $this->assertSame('updated', $result['status']);
    }

    // TEST P-I3: returns error when Product::add fails
    public function testReturnsErrorWhenProductAddFails(): void
    {
        // Arrange
        Product::setAddResult(false);

        // Act
        $result = ProductImporter::import($this->validPayload);

        // Assert
        $this->assertFalse($result['success']);
        $this->assertSame('error', $result['status']);
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('save failed', $result['error']);
    }

    // TEST P-I4: sets stock via StockAvailable
    public function testSetsStockViaStockAvailable(): void
    {
        // Arrange
        $payload = array_merge($this->validPayload, ['quantity' => 50]);
        Product::setAddResult(true);

        // Act
        ProductImporter::import($payload);

        // Assert
        $calls = StockAvailable::getCalls();
        $this->assertCount(1, $calls);
        $this->assertSame(42, $calls[0][0]); // product id
        $this->assertSame(0, $calls[0][1]); // attribute id
        $this->assertSame(50, $calls[0][2]); // quantity
    }

    // TEST P-I5: assigns import category
    public function testAssignsImportCategory(): void
    {
        // Arrange
        Product::setAddResult(true);

        // Act
        ProductImporter::import($this->validPayload);

        // Assert
        $updated = Product::getUpdatedCategories();
        $this->assertContains(5, $updated); // PRESTABRIDGE_IMPORT_CATEGORY = 5
    }

    // TEST P-I6: logs successful import
    public function testLogsSuccessfulImport(): void
    {
        // Arrange
        Db::reset();
        $db = Db::getInstance();
        Product::setAddResult(true);

        // Act
        ProductImporter::import($this->validPayload);

        // Assert — BridgeLogger::info calls Db::insert
        $insertCalls = $db->insertCalls;
        $logCall = null;
        foreach ($insertCalls as $call) {
            if ($call['table'] === 'prestabridge_log') {
                $logCall = $call;
                break;
            }
        }
        $this->assertNotNull($logCall, 'Expected a log insert call');
        $this->assertSame('info', $logCall['data']['level']);
        $this->assertStringContainsString('MIN-001', (string)$logCall['data']['sku']);
    }

    // TEST P-I7: logs failed import
    public function testLogsFailedImport(): void
    {
        // Arrange
        Db::reset();
        $db = Db::getInstance();
        Product::setAddResult(false);

        // Act
        ProductImporter::import($this->validPayload);

        // Assert — BridgeLogger::error call
        $insertCalls = $db->insertCalls;
        $logCall = null;
        foreach ($insertCalls as $call) {
            if ($call['table'] === 'prestabridge_log') {
                $logCall = $call;
                break;
            }
        }
        $this->assertNotNull($logCall, 'Expected a log insert call');
        $this->assertSame('error', $logCall['data']['level']);
    }
}
