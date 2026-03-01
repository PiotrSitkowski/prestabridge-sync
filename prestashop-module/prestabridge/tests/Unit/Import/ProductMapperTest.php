<?php

declare(strict_types = 1)
;

namespace PrestaBridge\Tests\Unit\Import;

use PHPUnit\Framework\TestCase;
use PrestaBridge\Import\ProductMapper;
use Configuration;
use Context;

class ProductMapperTest extends TestCase
{
    private array $validMinimal;
    private array $validFull;

    protected function setUp(): void
    {
        Configuration::reset();
        Configuration::seed([
            'PS_LANG_DEFAULT' => 1,
            'PRESTABRIDGE_IMPORT_CATEGORY' => 5,
            'PRESTABRIDGE_DEFAULT_ACTIVE' => 0,
        ]);
        Context::reset();

        $fixtures = json_decode(
            file_get_contents(__DIR__ . '/../../../../../shared/fixtures/valid-products.json'),
            true
        );
        $this->validMinimal = $fixtures['minimal'][0];
        $this->validFull = $fixtures['full'][0];
    }

    public function testMapsSkuToReference(): void
    {
        $product = ProductMapper::mapToProduct($this->validMinimal);
        $this->assertSame('MIN-001', $product->reference);
    }

    public function testMapsNameToLangArray(): void
    {
        $product = ProductMapper::mapToProduct($this->validMinimal);
        $this->assertSame('Minimal Product 1', $product->name[1]);
    }

    public function testMapsPriceCorrectly(): void
    {
        $product = ProductMapper::mapToProduct($this->validMinimal);
        $this->assertEqualsWithDelta(9.99, $product->price, 0.001);
    }

    public function testMapsImportCategory(): void
    {
        $product = ProductMapper::mapToProduct($this->validMinimal);
        $this->assertSame(5, $product->id_category_default);
    }

    public function testMapsShopId(): void
    {
        $product = ProductMapper::mapToProduct($this->validMinimal);
        $this->assertSame(1, $product->id_shop_default);
    }

    public function testMapsOptionalFieldsFromFullPayload(): void
    {
        $product = ProductMapper::mapToProduct($this->validFull);
        $this->assertSame(150, $product->quantity);
        $this->assertSame('5901234123457', $product->ean13);
        $this->assertEqualsWithDelta(1.25, $product->weight, 0.001);
        $this->assertTrue($product->active);
        $this->assertSame('Full Product - Best Quality', $product->meta_title[1]);
    }

    public function testDefaultsActiveToFalseWhenNotProvided(): void
    {
        $product = ProductMapper::mapToProduct($this->validMinimal);
        $this->assertFalse($product->active);
    }

    public function testUsesExistingProductForUpdate(): void
    {
        $existing = new \Product(42);
        $product = ProductMapper::mapToProduct($this->validMinimal, $existing);
        $this->assertSame(42, $product->id);
        $this->assertSame('MIN-001', $product->reference);
    }
}
