<?php

declare(strict_types = 1)
;

namespace PrestaBridge\Export;

/**
 * Interface for entities that can be exported from PrestaShop to CloudFlare.
 * 
 * TODO: FUTURE - Implement when building PaaS export functionality.
 * This interface will be implemented by exporters for Product, Customer, Order, etc.
 */
interface ExportableInterface
{
    /**
     * Export entity data to a CloudFlare-compatible format.
     *
     * @return array<string, mixed>
     */
    public function toExportPayload(): array;

    /**
     * Return the unique identifier for this entity.
     */
    public function getExportId(): int;

    /**
     * Return the entity type identifier (e.g., 'product', 'customer').
     */
    public function getExportType(): string;
}
