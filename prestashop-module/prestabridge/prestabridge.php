<?php

declare(strict_types = 1)
;

use PrestaBridge\Config\ModuleConfig;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * PrestaBridge — Product synchronization bridge between CloudFlare and PrestaShop.
 *
 * @version 1.0.0
 * @author  PrestaBridge Team
 */
class PrestaBridge extends Module
{
    public function __construct()
    {
        $this->name = 'prestabridge';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'PrestaBridge Team';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('PrestaBridge');
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
     * Module configuration page.
     * Full implementation in Etap 7 (Admin UI).
     *
     * @return string HTML content
     */
    public function getContent(): string
    {
        // TODO: Etap 7 — implement HelperForm configuration + BridgeLogger log table
        return '<p>' . $this->l('PrestaBridge configuration — coming in Etap 7.') . '</p>';
    }

    // ============================================================
    // Future hooks (registered but empty — Etap 7+)
    // ============================================================
    // public function hookActionProductUpdate(array $params): void {}
    // public function hookActionProductAdd(array $params): void {}
    // public function hookActionProductDelete(array $params): void {}

    // ============================================================
    // Private helpers
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

        // Replace PREFIX_ placeholder with actual DB prefix
        $sql = str_replace('PREFIX_', _DB_PREFIX_, $sql);

        // Execute each statement
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
            return true; // Not critical
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
