---
name: prestashop-admin-ui
description: Użyj tego skilla gdy zadanie dotyczy plików w /views/templates/admin/ lub metody getContent() — zawiera wzorce HelperForm, tabeli logów Smarty, Bootstrap 4 PS BO oraz zakazy generowania HTML w PHP.
---

# SKILL: prestashop-admin-ui

### Kiedy aktywować
Zadanie dotyczy plików w `/views/templates/admin/` lub metody `getContent()` w prestabridge.php.

### Kontekst
- PrestaShop 8.1 używa Smarty templates (.tpl) dla modułów BO
- HelperForm generuje formularze automatycznie
- Bootstrap 4 jest dostępny w BO PS

### Wzorzec HelperForm:
```php
public function getContent()
{
    $output = '';

    // Obsługa submit
    if (Tools::isSubmit('submitPrestaBridgeConfig')) {
        // Walidacja i zapis
        Configuration::updateValue('PRESTABRIDGE_AUTH_SECRET', Tools::getValue('auth_secret'));
        // ...
        $output .= $this->displayConfirmation($this->l('Settings updated'));
    }

    if (Tools::isSubmit('clearLogs')) {
        BridgeLogger::clearLogs();
        $output .= $this->displayConfirmation($this->l('Logs cleared'));
    }

    // Formularz konfiguracji
    $output .= $this->renderConfigForm();

    // Tabela logów
    $output .= $this->renderLogTable();

    return $output;
}

private function renderConfigForm(): string
{
    $helper = new HelperForm();
    $helper->module = $this;
    $helper->name_controller = $this->name;
    $helper->token = Tools::getAdminTokenLite('AdminModules');
    $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
    $helper->submit_action = 'submitPrestaBridgeConfig';

    $helper->fields_value = [
        'auth_secret' => Configuration::get('PRESTABRIDGE_AUTH_SECRET'),
        // ... wszystkie pola
    ];

    $fields_form = [
        'form' => [
            'legend' => ['title' => $this->l('Configuration')],
            'input' => [
                [
                    'type' => 'text',
                    'label' => $this->l('Auth Secret'),
                    'name' => 'auth_secret',
                    'required' => true,
                    'desc' => $this->l('HMAC-SHA256 shared secret'),
                ],
                // ... kolejne pola z CLAUDE.md sekcja 9.11
            ],
            'submit' => ['title' => $this->l('Save')],
        ],
    ];

    return $helper->generateForm([$fields_form]);
}
```

### Tabela logów:
```php
private function renderLogTable(): string
{
    $page = (int) Tools::getValue('log_page', 1);
    $level = Tools::getValue('log_level', null);
    $logs = BridgeLogger::getLogs($page, 50, $level);

    $this->context->smarty->assign([
        'logs' => $logs['logs'],
        'total' => $logs['total'],
        'page' => $logs['page'],
        'totalPages' => $logs['totalPages'],
        'currentLevel' => $level,
        'moduleLink' => $this->context->link->getAdminLink('AdminModules') .
                         '&configure=' . $this->name,
    ]);

    return $this->display(__FILE__, 'views/templates/admin/logs.tpl');
}
```

### Zakazy
- NIE generuj HTML w PHP (używaj .tpl Smarty)
- NIE buduj własnego CSS (użyj Bootstrap 4 z PS BO)
- NIE używaj AJAX do zapisu konfiguracji (standardowy form submit)
- NIE używaj JavaScript frameworks (React, Vue) — PS BO ich nie obsługuje w modułach
