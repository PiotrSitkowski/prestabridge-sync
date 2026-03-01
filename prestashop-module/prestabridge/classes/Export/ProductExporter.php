<?php

declare(strict_types = 1)
;

namespace PrestaBridge\Export;

/**
 * Abstract base class for product export to CloudFlare.
 *
 * TODO: FUTURE - Implement when building PaaS export functionality.
 * Extend this class to create concrete exporters (e.g., ProductExporter, CategoryExporter).
 */
abstract class ProductExporter
{
    /**
     * Export a batch of products to CloudFlare.
     *
     * TODO: FUTURE - Implement with CF D1 or external API as target.
     *
     * @param array<int> $productIds List of PS product IDs to export
     */
    abstract public function export(array $productIds): void;

    /**
     * Build the export payload for a single product.
     *
     * TODO: FUTURE - Map PS Product fields to CloudFlare schema.
     *
     * @param int $productId PS product ID
     * @return array<string, mixed>
     */
    abstract protected function buildPayload(int $productId): array;
}
