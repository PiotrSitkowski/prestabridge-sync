<?php

declare(strict_types = 1)
;

namespace PrestaBridge\Tests\Unit\Import;

use PHPUnit\Framework\TestCase;
use PrestaBridge\Import\ProductValidator;

class ProductValidatorTest extends TestCase
{
    // Fixtures loaded from shared source of truth
    private array $validMinimal;
    private array $validFull;

    protected function setUp(): void
    {
        $fixtures = json_decode(
            file_get_contents(__DIR__ . '/../../../../shared/fixtures/valid-products.json'),
            true
        );
        $this->validMinimal = $fixtures['minimal'][0]; // {sku: 'MIN-001', name: 'Minimal Product 1', price: 9.99}
        $this->validFull = $fixtures['full'][0]; // full product with all fields
    }

    // TEST P-V1: validates minimal product
    public function testValidatesMinimalProduct(): void
    {
        $result = ProductValidator::validate($this->validMinimal);
        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['errors']);
    }

    // TEST P-V2: validates full product
    public function testValidatesFullProduct(): void
    {
        $result = ProductValidator::validate($this->validFull);
        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['errors']);
    }

    // TEST P-V3: rejects missing sku
    public function testRejectsMissingSku(): void
    {
        $product = ['name' => 'X', 'price' => 10];
        $result = ProductValidator::validate($product);
        $this->assertFalse($result['valid']);
        $this->assertContains('sku is required', $result['errors']);
    }

    // TEST P-V4: rejects missing name
    public function testRejectsMissingName(): void
    {
        $product = ['sku' => 'X', 'price' => 10];
        $result = ProductValidator::validate($product);
        $this->assertFalse($result['valid']);
        $this->assertContains('name is required', $result['errors']);
    }

    // TEST P-V5: rejects missing price
    public function testRejectsMissingPrice(): void
    {
        $product = ['sku' => 'X', 'name' => 'Y'];
        $result = ProductValidator::validate($product);
        $this->assertFalse($result['valid']);
        $this->assertContains('price is required', $result['errors']);
    }

    // TEST P-V6: rejects zero price
    public function testRejectsZeroPrice(): void
    {
        $product = ['sku' => 'X', 'name' => 'Y', 'price' => 0];
        $result = ProductValidator::validate($product);
        $this->assertFalse($result['valid']);
    }

    // TEST P-V7: rejects negative price
    public function testRejectsNegativePrice(): void
    {
        $product = ['sku' => 'X', 'name' => 'Y', 'price' => -10];
        $result = ProductValidator::validate($product);
        $this->assertFalse($result['valid']);
    }

    // TEST P-V8: rejects sku longer than 64
    public function testRejectsSkuLongerThan64(): void
    {
        $product = ['sku' => str_repeat('X', 65), 'name' => 'Y', 'price' => 9.99];
        $result = ProductValidator::validate($product);
        $this->assertFalse($result['valid']);
    }

    // TEST P-V9: rejects name longer than 128
    public function testRejectsNameLongerThan128(): void
    {
        $product = ['sku' => 'X', 'name' => str_repeat('X', 129), 'price' => 9.99];
        $result = ProductValidator::validate($product);
        $this->assertFalse($result['valid']);
    }

    // TEST P-V10: rejects non-array images
    public function testRejectsNonArrayImages(): void
    {
        $product = array_merge($this->validMinimal, ['images' => 'string']);
        $result = ProductValidator::validate($product);
        $this->assertFalse($result['valid']);
    }

    // TEST P-V11: rejects invalid image URL
    public function testRejectsInvalidImageUrl(): void
    {
        $product = array_merge($this->validMinimal, ['images' => ['ftp://bad.com/img.jpg']]);
        $result = ProductValidator::validate($product);
        $this->assertFalse($result['valid']);
    }

    // TEST P-V12: accepts empty images array
    public function testAcceptsEmptyImagesArray(): void
    {
        $product = array_merge($this->validMinimal, ['images' => []]);
        $result = ProductValidator::validate($product);
        $this->assertTrue($result['valid']);
    }

    // TEST P-V13: rejects non-numeric quantity
    public function testRejectsNonNumericQuantity(): void
    {
        $product = array_merge($this->validMinimal, ['quantity' => 'abc']);
        $result = ProductValidator::validate($product);
        $this->assertFalse($result['valid']);
    }

    // TEST P-V14: rejects negative quantity
    public function testRejectsNegativeQuantity(): void
    {
        $product = array_merge($this->validMinimal, ['quantity' => -1]);
        $result = ProductValidator::validate($product);
        $this->assertFalse($result['valid']);
    }

    // TEST P-V15: accumulates multiple errors
    public function testAccumulatesMultipleErrors(): void
    {
        $product = ['sku' => '', 'name' => '', 'price' => -1];
        $result = ProductValidator::validate($product);
        $this->assertFalse($result['valid']);
        $this->assertGreaterThanOrEqual(3, count($result['errors']));
    }
}
