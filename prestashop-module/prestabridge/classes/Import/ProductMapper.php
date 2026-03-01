<?php

declare(strict_types = 1)
;

namespace PrestaBridge\Import;

use Configuration;
use Context;
use Product;

/**
 * Maps a validated product payload to a PrestaShop Product ObjectModel.
 */
class ProductMapper
{
    /**
     * Map payload data onto a Product object.
     *
     * @param array<string, mixed> $payload  Validated product data
     * @param Product|null         $existing Existing product for update mode
     *
     * @return Product Populated Product object (not yet persisted)
     */
    public static function mapToProduct(array $payload, ?Product $existing = null): Product
    {
        $product = $existing ?? new Product();

        $langId = (int)Configuration::get('PS_LANG_DEFAULT');
        $shopId = (int)(Context::getContext()->shop->id ?? 1);

        // Core fields
        $product->reference = $payload['sku'];
        $product->name[$langId] = $payload['name'];
        $product->price = (float)$payload['price'];

        // Category
        $product->id_category_default = (int)Configuration::get('PRESTABRIDGE_IMPORT_CATEGORY');
        $product->id_shop_default = $shopId;

        // Optional fields
        if (isset($payload['description'])) {
            $product->description[$langId] = $payload['description'];
        }

        if (isset($payload['description_short'])) {
            $product->description_short[$langId] = $payload['description_short'];
        }

        if (isset($payload['quantity'])) {
            $product->quantity = (int)$payload['quantity'];
        }

        if (isset($payload['ean13'])) {
            $product->ean13 = $payload['ean13'];
        }

        if (isset($payload['weight'])) {
            $product->weight = (float)$payload['weight'];
        }

        // Active status
        if (isset($payload['active'])) {
            $product->active = (bool)$payload['active'];
        } else {
            $product->active = (bool)Configuration::get('PRESTABRIDGE_DEFAULT_ACTIVE');
        }

        // SEO meta fields
        if (isset($payload['meta_title'])) {
            $product->meta_title[$langId] = $payload['meta_title'];
        }

        if (isset($payload['meta_description'])) {
            $product->meta_description[$langId] = $payload['meta_description'];
        }

        // Link rewrite (required by PrestaShop)
        $product->link_rewrite[$langId] = self::generateLinkRewrite($payload['name']);

        return $product;
    }

    /**
     * Generate a URL-friendly slug from a product name.
     */
    private static function generateLinkRewrite(string $name): string
    {
        $slug = mb_strtolower($name, 'UTF-8');
        $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug) ?? '';
        $slug = preg_replace('/[\s-]+/', '-', $slug) ?? '';

        return trim($slug, '-') ?: 'product';
    }
}
