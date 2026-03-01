<?php

declare(strict_types = 1)
;

use PrestaBridge\Config\ModuleConfig;
use PrestaBridge\Logging\BridgeLogger;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * PrestaBridge — Product synchronization bridge between CloudFlare and PrestaShop.
 *
 * @version 1.0.0
 * @author  Universal MEDIA Piotr Sitkowski
 */
class PrestaBridge extends Module
{
    public function __construct()
    {
        $this->name = 'prestabridge';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'Universal MEDIA Piotr Sitkowski';
        $this->author_uri = 'https://universalmedia.pl';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('MeriBridge');
        $this->description = $this->l('Product synchronization bridge between CloudFlare and PrestaShop.');
    }

    /**
     * Install the module: create tables, register hooks, set default config.
     */
    public function install(): bool
    {
        return parent::install()
            && $this->installSql()
            && $this->installDefaults();
    }

    /**
     * Uninstall the module: drop tables, remove config.
     */
    public function uninstall(): bool
    {
        return parent::uninstall()
            && $this->uninstallSql()
            && $this->uninstallConfig();
    }

    /**
     * Module configuration page — Admin UI (Etap 7).
     *
     * @return string HTML content
     */
    public function getContent(): string
    {
        $output = $this->postProcess();

        $configFormHtml = $this->renderConfigForm();
        $logsHtml = $this->renderLogTable();

        $this->context->smarty->assign([
            'config_form' => $configFormHtml,
            'logs_section' => $logsHtml,
            'module_version' => $this->version,
        ]);

        return $output . $this->display(__FILE__, 'views/templates/admin/configure.tpl');
    }

    // ============================================================
    // Future hooks (registered but empty — planned for future PaaS)
    // ============================================================
    // public function hookActionProductUpdate(array $params): void {}
    // public function hookActionProductAdd(array $params): void {}
    // public function hookActionProductDelete(array $params): void {}

    // ============================================================
    // Private — Admin UI logic
    // ============================================================

    /**
     * Handles form submissions from the configuration page.
     * Returns confirmation / error HTML string (or empty string).
     */
    private function postProcess(): string
    {
        $output = '';

        if (Tools::isSubmit('submitPrestaBridgeConfig')) {
            // --- Auth Secret ---
            $authSecret = trim((string)Tools::getValue('auth_secret'));
            if ($authSecret === '') {
                // User cleared the field → regenerate a secure token
                $authSecret = bin2hex(random_bytes(32));
            }
            Configuration::updateValue('PRESTABRIDGE_AUTH_SECRET', $authSecret);

            // --- Import Category ---
            Configuration::updateValue(
                'PRESTABRIDGE_IMPORT_CATEGORY',
                (int)Tools::getValue('import_category', 2)
            );

            // --- Worker Endpoint ---
            Configuration::updateValue(
                'PRESTABRIDGE_WORKER_ENDPOINT',
                trim((string)Tools::getValue('worker_endpoint'))
            );

            // --- Overwrite Duplicates (switch → int 0/1) ---
            Configuration::updateValue(
                'PRESTABRIDGE_OVERWRITE_DUPLICATES',
                (int)Tools::getValue('overwrite_duplicates')
            );

            // --- CRON Token ---
            $cronToken = trim((string)Tools::getValue('cron_token'));
            if ($cronToken === '') {
                // User cleared the field → regenerate
                $cronToken = bin2hex(random_bytes(16));
            }
            Configuration::updateValue('PRESTABRIDGE_CRON_TOKEN', $cronToken);

            // --- Images Per CRON ---
            $imagesPerCron = (int)Tools::getValue('images_per_cron', 10);
            if ($imagesPerCron < 1) {
                $imagesPerCron = 1;
            }
            Configuration::updateValue('PRESTABRIDGE_IMAGES_PER_CRON', $imagesPerCron);

            // --- Image Timeout ---
            $imageTimeout = (int)Tools::getValue('image_timeout', 30);
            if ($imageTimeout < 5) {
                $imageTimeout = 5;
            }
            Configuration::updateValue('PRESTABRIDGE_IMAGE_TIMEOUT', $imageTimeout);

            // --- Default Active (switch → int 0/1) ---
            Configuration::updateValue(
                'PRESTABRIDGE_DEFAULT_ACTIVE',
                (int)Tools::getValue('default_active')
            );

            BridgeLogger::info('Configuration updated via admin panel', [], 'config');

            $output .= $this->displayConfirmation($this->l('Settings updated successfully.'));
        }

        if (Tools::isSubmit('clearLogs')) {
            BridgeLogger::clearLogs();
            $output .= $this->displayConfirmation($this->l('Logs cleared successfully.'));
        }

        return $output;
    }

    /**
     * Builds and returns the HelperForm configuration form HTML.
     */
    private function renderConfigForm(): string
    {
        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = 'submitPrestaBridgeConfig';
        $helper->default_form_language = (int)Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = (int)Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->title = $this->l('PrestaBridge Configuration');
        $helper->show_toolbar = false;

        // Lazy-create tokens if missing (defence against accidental deletions from DB)
        $authSecret = $this->getOrCreateToken('PRESTABRIDGE_AUTH_SECRET', 32);
        $cronToken = $this->getOrCreateToken('PRESTABRIDGE_CRON_TOKEN', 16);

        $helper->fields_value = [
            'auth_secret' => $authSecret,
            'import_category' => ModuleConfig::getImportCategory(),
            'worker_endpoint' => ModuleConfig::getWorkerEndpoint(),
            'overwrite_duplicates' => (int)ModuleConfig::getOverwriteDuplicates(),
            'cron_token' => $cronToken,
            'images_per_cron' => ModuleConfig::getImagesPerCron(),
            'image_timeout' => ModuleConfig::getImageTimeout(),
            'default_active' => (int)ModuleConfig::getDefaultActive(),
        ];

        $moduleLinkBase = AdminController::$currentIndex . '&configure=' . $this->name
            . '&token=' . Tools::getAdminTokenLite('AdminModules');

        $cronUrl = $this->context->link->getModuleLink(
            $this->name,
            'cron',
        ['token' => $cronToken]
        );

        $fields_form = [
            'form' => [
                'legend' => [
                    'title' => $this->l('API & Security'),
                    'icon' => 'icon-lock',
                ],
                'input' => [
                    // --- Auth Secret ---
                    [
                        'type' => 'text',
                        'label' => $this->l('Auth Secret (HMAC)'),
                        'name' => 'auth_secret',
                        'required' => true,
                        'readonly' => true,
                        'class' => 'fixed-width-xxl',
                        'desc' => $this->l('HMAC-SHA256 shared secret used to authenticate CF Worker requests. Leave empty to auto-regenerate on save.'),
                    ],
                    // --- Worker Endpoint ---
                    [
                        'type' => 'text',
                        'label' => $this->l('CF Worker Endpoint URL'),
                        'name' => 'worker_endpoint',
                        'class' => 'fixed-width-xxl',
                        'desc' => $this->l('Full URL of the CloudFlare Router Worker (e.g. https://prestabridge-router.workers.dev/import).'),
                    ],
                ],
                'submit' => ['title' => $this->l('Save'), 'class' => 'btn btn-default pull-right'],
            ],
        ];

        $fields_form2 = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Import Settings'),
                    'icon' => 'icon-import',
                ],
                'input' => [
                    // --- Import Category ---
                    [
                        'type' => 'select',
                        'label' => $this->l('Import Category'),
                        'name' => 'import_category',
                        'required' => true,
                        'options' => [
                            'query' => $this->getCategoryOptions(),
                            'id' => 'id_category',
                            'name' => 'name',
                        ],
                        'desc' => $this->l('Default category for imported products.'),
                    ],
                    // --- Overwrite Duplicates ---
                    [
                        'type' => 'switch',
                        'label' => $this->l('Overwrite Duplicates'),
                        'name' => 'overwrite_duplicates',
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'overwrite_duplicates_on', 'value' => 1, 'label' => $this->l('Yes')],
                            ['id' => 'overwrite_duplicates_off', 'value' => 0, 'label' => $this->l('No')],
                        ],
                        'desc' => $this->l('If enabled, existing products with the same SKU will be updated instead of skipped.'),
                    ],
                    // --- Default Active ---
                    [
                        'type' => 'switch',
                        'label' => $this->l('Default Product Active State'),
                        'name' => 'default_active',
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'default_active_on', 'value' => 1, 'label' => $this->l('Yes')],
                            ['id' => 'default_active_off', 'value' => 0, 'label' => $this->l('No')],
                        ],
                        'desc' => $this->l('Default active flag for imported products (recommended: No — activate after images are downloaded).'),
                    ],
                ],
                'submit' => ['title' => $this->l('Save'), 'class' => 'btn btn-default pull-right'],
            ],
        ];

        $fields_form3 = [
            'form' => [
                'legend' => [
                    'title' => $this->l('CRON Settings'),
                    'icon' => 'icon-time',
                ],
                'input' => [
                    // --- CRON Token ---
                    [
                        'type' => 'text',
                        'label' => $this->l('CRON Token'),
                        'name' => 'cron_token',
                        'readonly' => true,
                        'class' => 'fixed-width-xxl',
                        'desc' => sprintf(
                        $this->l('Token for CRON endpoint authentication. CRON URL: %s'),
                        '<code>' . htmlspecialchars($cronUrl) . '</code>'
                    ),
                    ],
                    // --- Images Per CRON ---
                    [
                        'type' => 'text',
                        'label' => $this->l('Images Per CRON Run'),
                        'name' => 'images_per_cron',
                        'class' => 'fixed-width-sm',
                        'desc' => $this->l('How many images to download per single CRON invocation (default: 10).'),
                    ],
                    // --- Image Timeout ---
                    [
                        'type' => 'text',
                        'label' => $this->l('Image Download Timeout (seconds)'),
                        'name' => 'image_timeout',
                        'class' => 'fixed-width-sm',
                        'suffix' => $this->l('s'),
                        'desc' => $this->l('HTTP timeout for downloading product images (default: 30).'),
                    ],
                ],
                'submit' => ['title' => $this->l('Save'), 'class' => 'btn btn-default pull-right'],
            ],
        ];

        return $helper->generateForm([$fields_form, $fields_form2, $fields_form3]);
    }

    /**
     * Builds and returns the log table section HTML via Smarty.
     */
    private function renderLogTable(): string
    {
        $page = max(1, (int)Tools::getValue('log_page', 1));
        $level = Tools::getValue('log_level', null);
        // Sanitize level — only allow valid ENUM values or null
        $validLevels = ['debug', 'info', 'warning', 'error', 'critical'];
        if (!in_array($level, $validLevels, true)) {
            $level = null;
        }

        $logs = BridgeLogger::getLogs($page, 50, $level);

        $moduleLink = AdminController::$currentIndex
            . '&configure=' . $this->name
            . '&token=' . Tools::getAdminTokenLite('AdminModules');

        $this->context->smarty->assign([
            'pb_logs' => $logs['logs'],
            'pb_total' => $logs['total'],
            'pb_page' => $logs['page'],
            'pb_totalPages' => $logs['totalPages'],
            'pb_currentLevel' => $level,
            'pb_moduleLink' => $moduleLink,
            'pb_levels' => ['debug', 'info', 'warning', 'error', 'critical'],
        ]);

        return $this->display(__FILE__, 'views/templates/admin/logs.tpl');
    }

    /**
     * Returns a flat list of categories for HelperForm select.
     *
     * @return array<int, array{id_category: int, name: string}>
     */
    private function getCategoryOptions(): array
    {
        $id_lang = (int)Configuration::get('PS_LANG_DEFAULT');
        $categories = Category::getSimpleCategories($id_lang);

        if (empty($categories)) {
            // Fallback: return at least "Home" category
            return [
                ['id_category' => 2, 'name' => $this->l('Home')],
            ];
        }

        return $categories;
    }

    /**
     * Returns the stored token for $key, generating and persisting a new one if absent.
     *
     * @param string $key   Configuration key
     * @param int    $bytes Number of random bytes (hex-encoded, so final length = bytes * 2)
     */
    private function getOrCreateToken(string $key, int $bytes): string
    {
        $value = (string)Configuration::get($key);
        if ($value === '' || $value === false) {
            $value = bin2hex(random_bytes($bytes));
            Configuration::updateValue($key, $value);
        }
        return $value;
    }

    // ============================================================
    // Private — install helpers
    // ============================================================

    private function installSql(): bool
    {
        $sqlFile = __DIR__ . '/sql/install.sql';
        if (!file_exists($sqlFile)) {
            return false;
        }

        $sql = file_get_contents($sqlFile);
        if ($sql === false) {
            return false;
        }

        $sql = str_replace('PREFIX_', _DB_PREFIX_, $sql);

        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            if ($statement !== '' && !Db::getInstance()->execute($statement)) {
                return false;
            }
        }

        return true;
    }

    private function uninstallSql(): bool
    {
        $sqlFile = __DIR__ . '/sql/uninstall.sql';
        if (!file_exists($sqlFile)) {
            return true;
        }

        $sql = file_get_contents($sqlFile);
        if ($sql === false) {
            return true;
        }

        $sql = str_replace('PREFIX_', _DB_PREFIX_, $sql);

        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            if ($statement !== '') {
                Db::getInstance()->execute($statement);
            }
        }

        return true;
    }

    private function installDefaults(): bool
    {
        ModuleConfig::installDefaults();
        return true;
    }

    private function uninstallConfig(): bool
    {
        ModuleConfig::uninstallAll();
        return true;
    }
}
