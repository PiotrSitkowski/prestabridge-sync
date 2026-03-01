<?php

declare(strict_types = 1)
;

namespace PrestaBridge\Config;

use Configuration;

/**
 * Module configuration abstraction.
 * All config values are read from PrestaShop Configuration (ps_configuration table).
 */
class ModuleConfig
{
    public static function getAuthSecret(): string
    {
        return (string)Configuration::get('PRESTABRIDGE_AUTH_SECRET');
    }

    public static function getImportCategory(): int
    {
        return (int)Configuration::get('PRESTABRIDGE_IMPORT_CATEGORY');
    }

    public static function getWorkerEndpoint(): string
    {
        return (string)Configuration::get('PRESTABRIDGE_WORKER_ENDPOINT');
    }

    public static function getOverwriteDuplicates(): bool
    {
        return (bool)Configuration::get('PRESTABRIDGE_OVERWRITE_DUPLICATES');
    }

    public static function getCronToken(): string
    {
        return (string)Configuration::get('PRESTABRIDGE_CRON_TOKEN');
    }

    public static function getImagesPerCron(): int
    {
        return (int)Configuration::get('PRESTABRIDGE_IMAGES_PER_CRON') ?: 10;
    }

    public static function getImageTimeout(): int
    {
        return (int)Configuration::get('PRESTABRIDGE_IMAGE_TIMEOUT') ?: 30;
    }

    public static function getDefaultActive(): bool
    {
        return (bool)Configuration::get('PRESTABRIDGE_DEFAULT_ACTIVE');
    }

    /**
     * Install default configuration values (only if not already set).
     */
    public static function installDefaults(): void
    {
        $defaults = [
            'PRESTABRIDGE_AUTH_SECRET' => bin2hex(random_bytes(32)),
            'PRESTABRIDGE_IMPORT_CATEGORY' => 2,
            'PRESTABRIDGE_WORKER_ENDPOINT' => '',
            'PRESTABRIDGE_OVERWRITE_DUPLICATES' => 0,
            'PRESTABRIDGE_CRON_TOKEN' => bin2hex(random_bytes(16)),
            'PRESTABRIDGE_IMAGES_PER_CRON' => 10,
            'PRESTABRIDGE_IMAGE_TIMEOUT' => 30,
            'PRESTABRIDGE_DEFAULT_ACTIVE' => 0,
        ];

        foreach ($defaults as $key => $value) {
            if (!Configuration::hasKey($key)) {
                Configuration::updateValue($key, $value);
            }
        }
    }

    /**
     * Remove all module configuration keys.
     */
    public static function uninstallAll(): void
    {
        $keys = [
            'PRESTABRIDGE_AUTH_SECRET',
            'PRESTABRIDGE_IMPORT_CATEGORY',
            'PRESTABRIDGE_WORKER_ENDPOINT',
            'PRESTABRIDGE_OVERWRITE_DUPLICATES',
            'PRESTABRIDGE_CRON_TOKEN',
            'PRESTABRIDGE_IMAGES_PER_CRON',
            'PRESTABRIDGE_IMAGE_TIMEOUT',
            'PRESTABRIDGE_DEFAULT_ACTIVE',
        ];
        foreach ($keys as $key) {
            Configuration::deleteByName($key);
        }
    }
}
