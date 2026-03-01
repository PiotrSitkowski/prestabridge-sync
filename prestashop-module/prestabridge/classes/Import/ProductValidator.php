<?php

declare(strict_types = 1)
;

namespace PrestaBridge\Import;

/**
 * Validates product payload data before import.
 *
 * Returns an associative array with 'valid' (bool) and 'errors' (string[]).
 */
class ProductValidator
{
    private const MAX_SKU_LENGTH = 64;
    private const MAX_NAME_LENGTH = 128;

    /**
     * Validate a product payload.
     *
     * @param array<string, mixed> $product Product data
     * @return array{valid: bool, errors: string[]}
     */
    public static function validate(array $product): array
    {
        $errors = [];

        // Required fields
        if (empty($product['sku'])) {
            $errors[] = 'sku is required';
        } elseif (strlen((string)$product['sku']) > self::MAX_SKU_LENGTH) {
            $errors[] = 'sku must not exceed ' . self::MAX_SKU_LENGTH . ' characters';
        }

        if (empty($product['name'])) {
            $errors[] = 'name is required';
        } elseif (strlen((string)$product['name']) > self::MAX_NAME_LENGTH) {
            $errors[] = 'name must not exceed ' . self::MAX_NAME_LENGTH . ' characters';
        }

        if (!isset($product['price'])) {
            $errors[] = 'price is required';
        } elseif (!is_numeric($product['price']) || (float)$product['price'] <= 0) {
            $errors[] = 'price must be a positive number';
        }

        // Optional: images
        if (array_key_exists('images', $product)) {
            if (!is_array($product['images'])) {
                $errors[] = 'images must be an array';
            } else {
                foreach ($product['images'] as $i => $url) {
                    if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
                        $errors[] = 'images[' . $i . '] is not a valid HTTP(S) URL';
                    }
                }
            }
        }

        // Optional: quantity
        if (array_key_exists('quantity', $product)) {
            if (!is_numeric($product['quantity'])) {
                $errors[] = 'quantity must be numeric';
            } elseif ((int)$product['quantity'] < 0) {
                $errors[] = 'quantity must not be negative';
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }
}
