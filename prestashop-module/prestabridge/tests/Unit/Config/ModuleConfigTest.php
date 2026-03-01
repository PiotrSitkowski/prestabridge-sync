<?php

declare(strict_types = 1)
;

namespace PrestaBridge\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use PrestaBridge\Config\ModuleConfig;
use Configuration;

class ModuleConfigTest extends TestCase
{
    protected function setUp(): void
    {
        Configuration::reset();
    }

    public function testGetAuthSecretReturnsConfigValue(): void
    {
        // Arrange
        Configuration::seed(['PRESTABRIDGE_AUTH_SECRET' => 'my-secret-key']);
        // Act
        $result = ModuleConfig::getAuthSecret();
        // Assert
        $this->assertSame('my-secret-key', $result);
    }

    public function testGetImportCategoryReturnsInt(): void
    {
        Configuration::seed(['PRESTABRIDGE_IMPORT_CATEGORY' => '5']);
        $this->assertSame(5, ModuleConfig::getImportCategory());
    }

    public function testGetImagesPerCronDefaultsTen(): void
    {
        // No value set → defaults to 10
        $this->assertSame(10, ModuleConfig::getImagesPerCron());
    }

    public function testGetImageTimeoutDefaultsThirty(): void
    {
        $this->assertSame(30, ModuleConfig::getImageTimeout());
    }

    public function testInstallDefaultsSetsAllKeys(): void
    {
        ModuleConfig::installDefaults();
        $this->assertTrue(Configuration::hasKey('PRESTABRIDGE_AUTH_SECRET'));
        $this->assertTrue(Configuration::hasKey('PRESTABRIDGE_IMPORT_CATEGORY'));
        $this->assertTrue(Configuration::hasKey('PRESTABRIDGE_CRON_TOKEN'));
    }

    public function testInstallDefaultsDoesNotOverwriteExisting(): void
    {
        Configuration::seed(['PRESTABRIDGE_AUTH_SECRET' => 'keep-this']);
        ModuleConfig::installDefaults();
        $this->assertSame('keep-this', ModuleConfig::getAuthSecret());
    }

    public function testUninstallAllRemovesAllKeys(): void
    {
        ModuleConfig::installDefaults();
        ModuleConfig::uninstallAll();
        $this->assertFalse(Configuration::hasKey('PRESTABRIDGE_AUTH_SECRET'));
        $this->assertFalse(Configuration::hasKey('PRESTABRIDGE_IMPORT_CATEGORY'));
    }
}
