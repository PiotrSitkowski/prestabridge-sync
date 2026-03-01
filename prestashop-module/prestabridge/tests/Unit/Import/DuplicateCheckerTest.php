<?php

declare(strict_types = 1)
;

namespace PrestaBridge\Tests\Unit\Import;

use PHPUnit\Framework\TestCase;
use PrestaBridge\Import\DuplicateChecker;
use Db;

class DuplicateCheckerTest extends TestCase
{
    private Db $db;

    protected function setUp(): void
    {
        Db::reset();
        $this->db = Db::getInstance();
    }

    // TEST P-D1: returns null when no product with sku
    public function testReturnsNullWhenNoProductWithSku(): void
    {
        // Arrange
        $this->db->setReturnValue(false);

        // Act
        $result = DuplicateChecker::getProductIdBySku('NONEXIST');

        // Assert
        $this->assertNull($result);
    }

    // TEST P-D2: returns product id when sku exists
    public function testReturnsProductIdWhenSkuExists(): void
    {
        // Arrange
        $this->db->setReturnValue('42');

        // Act
        $result = DuplicateChecker::getProductIdBySku('EXIST');

        // Assert
        $this->assertSame(42, $result);
    }

    // TEST P-D3: exists() returns true for existing sku
    public function testExistsReturnsTrueForExistingSku(): void
    {
        // Arrange
        $this->db->setReturnValue('42');

        // Act & Assert
        $this->assertTrue(DuplicateChecker::exists('EXIST'));
    }

    // TEST P-D4: exists() returns false for missing sku
    public function testExistsReturnsFalseForMissingSku(): void
    {
        // Arrange
        $this->db->setReturnValue(false);

        // Act & Assert
        $this->assertFalse(DuplicateChecker::exists('NONEXIST'));
    }

    // TEST P-D5: escapes SQL injection in sku
    public function testEscapesSqlInjectionInSku(): void
    {
        // Arrange — dangerous SKU
        $this->db->setReturnValue(false);
        $dangerousSku = "'; DROP TABLE products; --";

        // Act
        DuplicateChecker::getProductIdBySku($dangerousSku);

        // Assert — query must NOT contain the raw dangerous string
        $query = $this->db->lastQuery ?? '';
        $this->assertStringNotContainsString("'; DROP TABLE products; --", $query);
        // pSQL() should have escaped the single quote
        $this->assertStringContainsString('ps_product', $query);
    }
}
