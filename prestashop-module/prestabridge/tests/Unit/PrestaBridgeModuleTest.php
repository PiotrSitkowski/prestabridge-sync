<?php

declare(strict_types = 1)
;

namespace PrestaBridge\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Configuration;
use Tools;
use Context;
use Db;

// Load the module class from global namespace before test class definition.
// prestabridge.php guards with defined('_PS_VERSION_') so safe to require.
if (!class_exists('PrestaBridge', false)) {
    require_once __DIR__ . '/../../prestabridge.php';
}

/**
 * Tests for PrestaBridge module admin UI logic (Etap 7).
 *
 * Scenarios: M-A1 ... M-A9
 */
class PrestaBridgeModuleTest extends TestCase
{
    /** @var object|\PrestaBridge */
    private object $module;

    protected function setUp(): void
    {
        // Reset all shared state
        Configuration::reset();
        Tools::reset();
        Db::reset();
        Context::reset();

        // Seed required PS config entries
        Configuration::seed([
            'PS_LANG_DEFAULT' => '1',
            'PS_BO_ALLOW_EMPLOYEE_FORM_LANG' => '0',
            'PRESTABRIDGE_AUTH_SECRET' => 'existing-auth-secret-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
            'PRESTABRIDGE_CRON_TOKEN' => 'existing-cron-token-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
            'PRESTABRIDGE_IMPORT_CATEGORY' => '2',
            'PRESTABRIDGE_WORKER_ENDPOINT' => 'https://worker.example.com/import',
            'PRESTABRIDGE_OVERWRITE_DUPLICATES' => '0',
            'PRESTABRIDGE_IMAGES_PER_CRON' => '10',
            'PRESTABRIDGE_IMAGE_TIMEOUT' => '30',
            'PRESTABRIDGE_DEFAULT_ACTIVE' => '0',
        ]);

        $this->module = new \PrestaBridge();
    }

    // ------------------------------------------------------------------
    // M-A1: postProcess — all 8 fields saved correctly
    // ------------------------------------------------------------------

    public function testPostProcessSavesAllConfigFields(): void
    {
        Tools::seedPost([
            'submitPrestaBridgeConfig' => '1',
            'auth_secret' => 'new-auth-secret-value',
            'import_category' => '5',
            'worker_endpoint' => 'https://new-worker.example.com/import',
            'overwrite_duplicates' => '1',
            'cron_token' => 'new-cron-token-value',
            'images_per_cron' => '15',
            'image_timeout' => '45',
            'default_active' => '1',
        ]);

        $output = $this->module->getContent();

        $this->assertSame('new-auth-secret-value', Configuration::get('PRESTABRIDGE_AUTH_SECRET'));
        $this->assertSame(5, (int)Configuration::get('PRESTABRIDGE_IMPORT_CATEGORY'));
        $this->assertSame('https://new-worker.example.com/import', Configuration::get('PRESTABRIDGE_WORKER_ENDPOINT'));
        $this->assertSame(1, (int)Configuration::get('PRESTABRIDGE_OVERWRITE_DUPLICATES'));
        $this->assertSame('new-cron-token-value', Configuration::get('PRESTABRIDGE_CRON_TOKEN'));
        $this->assertSame(15, (int)Configuration::get('PRESTABRIDGE_IMAGES_PER_CRON'));
        $this->assertSame(45, (int)Configuration::get('PRESTABRIDGE_IMAGE_TIMEOUT'));
        $this->assertSame(1, (int)Configuration::get('PRESTABRIDGE_DEFAULT_ACTIVE'));

        // Confirmation message must be present in output
        $this->assertStringContainsString('alert-success', $output);
    }

    // ------------------------------------------------------------------
    // M-A2: postProcess — empty auth_secret triggers regeneration
    // ------------------------------------------------------------------

    public function testPostProcessRegeneratesAuthSecretWhenEmpty(): void
    {
        Tools::seedPost([
            'submitPrestaBridgeConfig' => '1',
            'auth_secret' => '', // empty -> regenerate
            'import_category' => '2',
            'worker_endpoint' => '',
            'overwrite_duplicates' => '0',
            'cron_token' => 'keep-this-token',
            'images_per_cron' => '10',
            'image_timeout' => '30',
            'default_active' => '0',
        ]);

        $this->module->getContent();

        $newSecret = (string)Configuration::get('PRESTABRIDGE_AUTH_SECRET');
        $this->assertNotEmpty($newSecret);
        $this->assertGreaterThanOrEqual(64, strlen($newSecret));
        $this->assertMatchesRegularExpression('/^[0-9a-f]+$/i', $newSecret);
    }

    // ------------------------------------------------------------------
    // M-A3: postProcess — empty cron_token triggers regeneration
    // ------------------------------------------------------------------

    public function testPostProcessRegeneratesCronTokenWhenEmpty(): void
    {
        Tools::seedPost([
            'submitPrestaBridgeConfig' => '1',
            'auth_secret' => 'keep-auth-secret',
            'import_category' => '2',
            'worker_endpoint' => '',
            'overwrite_duplicates' => '0',
            'cron_token' => '', // empty -> regenerate
            'images_per_cron' => '10',
            'image_timeout' => '30',
            'default_active' => '0',
        ]);

        $this->module->getContent();

        $newToken = (string)Configuration::get('PRESTABRIDGE_CRON_TOKEN');
        $this->assertNotEmpty($newToken);
        $this->assertGreaterThanOrEqual(32, strlen($newToken));
        $this->assertMatchesRegularExpression('/^[0-9a-f]+$/i', $newToken);
    }

    // ------------------------------------------------------------------
    // M-A4: postProcess — switch fields saved as int 0/1
    // ------------------------------------------------------------------

    public function testPostProcessSavesSwitchFieldsAsInt(): void
    {
        // Both switches ON
        Tools::seedPost([
            'submitPrestaBridgeConfig' => '1',
            'auth_secret' => 'any-secret',
            'import_category' => '2',
            'worker_endpoint' => '',
            'overwrite_duplicates' => '1',
            'cron_token' => 'any-token',
            'images_per_cron' => '10',
            'image_timeout' => '30',
            'default_active' => '1',
        ]);

        $this->module->getContent();

        $this->assertSame(1, (int)Configuration::get('PRESTABRIDGE_OVERWRITE_DUPLICATES'));
        $this->assertSame(1, (int)Configuration::get('PRESTABRIDGE_DEFAULT_ACTIVE'));

        // Both switches OFF
        Tools::reset();
        Tools::seedPost([
            'submitPrestaBridgeConfig' => '1',
            'auth_secret' => 'any-secret',
            'import_category' => '2',
            'worker_endpoint' => '',
            'overwrite_duplicates' => '0',
            'cron_token' => 'any-token',
            'images_per_cron' => '10',
            'image_timeout' => '30',
            'default_active' => '0',
        ]);

        $this->module->getContent();

        $this->assertSame(0, (int)Configuration::get('PRESTABRIDGE_OVERWRITE_DUPLICATES'));
        $this->assertSame(0, (int)Configuration::get('PRESTABRIDGE_DEFAULT_ACTIVE'));
    }

    // ------------------------------------------------------------------
    // M-A5: getOrCreateToken — existing token is returned unchanged
    // ------------------------------------------------------------------

    public function testGetOrCreateTokenReturnsExistingValue(): void
    {
        $existingSecret = 'existing-auth-secret-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';

        // No submit — just render (triggers renderConfigForm -> getOrCreateToken)
        $this->module->getContent();

        $this->assertSame($existingSecret, Configuration::get('PRESTABRIDGE_AUTH_SECRET'));
    }

    // ------------------------------------------------------------------
    // M-A6: getOrCreateToken — missing token is auto-generated and saved
    // ------------------------------------------------------------------

    public function testGetOrCreateTokenGeneratesAndPersistsWhenMissing(): void
    {
        Configuration::reset();
        Configuration::seed([
            'PS_LANG_DEFAULT' => '1',
            'PS_BO_ALLOW_EMPLOYEE_FORM_LANG' => '0',
            // PRESTABRIDGE_AUTH_SECRET intentionally missing
            'PRESTABRIDGE_CRON_TOKEN' => '',
            'PRESTABRIDGE_IMPORT_CATEGORY' => '2',
            'PRESTABRIDGE_WORKER_ENDPOINT' => '',
            'PRESTABRIDGE_OVERWRITE_DUPLICATES' => '0',
            'PRESTABRIDGE_IMAGES_PER_CRON' => '10',
            'PRESTABRIDGE_IMAGE_TIMEOUT' => '30',
            'PRESTABRIDGE_DEFAULT_ACTIVE' => '0',
        ]);

        // Render triggers getOrCreateToken for both tokens
        $this->module->getContent();

        $generatedSecret = (string)Configuration::get('PRESTABRIDGE_AUTH_SECRET');
        $this->assertNotEmpty($generatedSecret, 'Auth secret should be auto-generated when missing');
        $this->assertGreaterThanOrEqual(64, strlen($generatedSecret));

        $generatedCronToken = (string)Configuration::get('PRESTABRIDGE_CRON_TOKEN');
        $this->assertNotEmpty($generatedCronToken, 'CRON token should be auto-generated when empty');
        $this->assertGreaterThanOrEqual(32, strlen($generatedCronToken));
    }

    // ------------------------------------------------------------------
    // M-A7: getContent — returns non-empty HTML string
    // ------------------------------------------------------------------

    public function testGetContentReturnsHtmlString(): void
    {
        $output = $this->module->getContent();

        $this->assertIsString($output);
        $this->assertNotEmpty($output);
    }

    // ------------------------------------------------------------------
    // M-A8: images_per_cron below minimum -> clamped to 1
    // ------------------------------------------------------------------

    public function testPostProcessClampsImagesPerCronToMinimumOne(): void
    {
        Tools::seedPost([
            'submitPrestaBridgeConfig' => '1',
            'auth_secret' => 'any',
            'import_category' => '2',
            'worker_endpoint' => '',
            'overwrite_duplicates' => '0',
            'cron_token' => 'any',
            'images_per_cron' => '0', // invalid - below minimum
            'image_timeout' => '30',
            'default_active' => '0',
        ]);

        $this->module->getContent();

        $this->assertSame(1, (int)Configuration::get('PRESTABRIDGE_IMAGES_PER_CRON'));
    }

    // ------------------------------------------------------------------
    // M-A9: image_timeout below minimum -> clamped to 5
    // ------------------------------------------------------------------

    public function testPostProcessClampsImageTimeoutToMinimumFive(): void
    {
        Tools::seedPost([
            'submitPrestaBridgeConfig' => '1',
            'auth_secret' => 'any',
            'import_category' => '2',
            'worker_endpoint' => '',
            'overwrite_duplicates' => '0',
            'cron_token' => 'any',
            'images_per_cron' => '10',
            'image_timeout' => '2', // invalid - below minimum
            'default_active' => '0',
        ]);

        $this->module->getContent();

        $this->assertSame(5, (int)Configuration::get('PRESTABRIDGE_IMAGE_TIMEOUT'));
    }
}
