# SKILLS.md — Instrukcje kontekstowe dla agentów AI

> Każda sekcja to osobny "skill" — zestaw instrukcji aktywowany gdy agent pracuje
> nad konkretnym komponentem systemu. Agent powinien przeczytać odpowiedni skill
> PRZED rozpoczęciem pracy.

---

## SKILL: cloudflare-workers

### Kiedy aktywować
Zadanie dotyczy plików w `/workers/router/` lub `/workers/consumer/`.

### Kontekst technologiczny
- CloudFlare Workers Free Tier: **CPU 10ms**, wall time 30s
- Format: **ES Modules** (export default { fetch, queue })
- Runtime: **V8 isolate** — nie Node.js! Brak: fs, path, process, Buffer (użyj Uint8Array)
- Crypto: `crypto.subtle` (Web Crypto API) — JEDYNY dozwolony sposób na HMAC
- Fetch: globalny `fetch()` — z AbortController dla timeoutów
- Brak npm dependencies w production — ZERO. Tylko devDependencies dla testów

### Wzorce obowiązkowe

#### Entry point (Router):
```javascript
export default {
  async fetch(request, env, ctx) {
    // env zawiera: AUTH_SECRET, PRODUCT_QUEUE, ENVIRONMENT, ...
    // ctx zawiera: waitUntil() dla operacji po response
  }
};
```

#### Entry point (Consumer):
```javascript
export default {
  async queue(batch, env, ctx) {
    // batch.messages — tablica wiadomości
    // message.body — deserializowany JSON
    // message.ack() — potwierdź przetworzenie
    // message.retry({ delaySeconds }) — ponów próbę
  }
};
```

#### HMAC (jedyny poprawny sposób):
```javascript
const encoder = new TextEncoder();
const key = await crypto.subtle.importKey(
  'raw', encoder.encode(secret),
  { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']
);
const signature = await crypto.subtle.sign('HMAC', key, encoder.encode(payload));
const hex = [...new Uint8Array(signature)].map(b => b.toString(16).padStart(2, '0')).join('');
```

#### Fetch z timeoutem:
```javascript
const controller = new AbortController();
const timeoutId = setTimeout(() => controller.abort(), parseInt(env.REQUEST_TIMEOUT_MS));
try {
  const response = await fetch(url, { signal: controller.signal, ...options });
} finally {
  clearTimeout(timeoutId);
}
```

#### Response helper:
```javascript
return new Response(JSON.stringify(body), {
  status,
  headers: { 'Content-Type': 'application/json' }
});
```

### Zakazy bezwzględne
- NIE importuj npm packages (ajv, joi, zod, axios, node-fetch)
- NIE używaj require() — tylko import/export
- NIE używaj console.log w produkcji do debug (użyj logger.js)
- NIE przekraczaj 10ms CPU — walidacja ręczna, brak parsowania JSON Schema
- NIE używaj setTimeout/setInterval jako timera biznesowego
- NIE zapisuj stanu między requestami (Workers są stateless)

### Testy
- Framework: **Vitest** + `@cloudflare/vitest-pool-workers`
- Mockowanie Queue: `{ send: vi.fn().mockResolvedValue(undefined) }`
- Mockowanie fetch: `globalThis.fetch = vi.fn()`
- Fixtures: importuj z `/shared/fixtures/`

---

## SKILL: prestashop-module

### Kiedy aktywować
Zadanie dotyczy plików w `/prestashop-module/prestabridge/`.

### Kontekst technologiczny
- PrestaShop **8.1+** na PHP **8.1+**
- Natywne klasy PS: Product, Image, Category, Configuration, Db, Context, StockAvailable, ImageType, ImageManager, Validate, Tools
- Namespace: `PrestaBridge\{Subdir}`
- Autoload: PSR-4 via composer.json
- Standard kodowania: **PSR-12**
- Type hints: **pełne** (return types, param types, property types)

### Wzorce obowiązkowe

#### Klasa modułu:
```php
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
        $this->description = $this->l('Product synchronization bridge');
    }
}
```

#### Front controller (API):
```php
class PrestaBridgeApiModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        parent::initContent();
        $this->ajax = true;
        header('Content-Type: application/json');
        // ... logika
        die(json_encode($response));
    }
}
```

#### SQL bezpieczeństwo — ZAWSZE:
```php
// String:
'WHERE reference = \'' . pSQL($sku) . '\''
// Integer:
'WHERE id_product = ' . (int) $id
// NIGDY:
'WHERE reference = \'' . $sku . '\''  // SQL INJECTION!
```

#### Product ObjectModel:
```php
$product = new Product();
$id_lang = (int) Configuration::get('PS_LANG_DEFAULT');
$product->name[$id_lang] = 'Nazwa';
$product->price = 29.99;  // cena NETTO
$product->reference = 'SKU-001';
$product->id_category_default = $categoryId;
$product->add();
$product->updateCategories([$categoryId]);
StockAvailable::setQuantity((int) $product->id, 0, $quantity);
```

#### Image ObjectModel:
```php
$image = new Image();
$image->id_product = $productId;
$image->position = $position;
$image->cover = $isCover ? 1 : 0;
$image->add();
$newPath = $image->getPathForCreation();
// kopiuj plik + ImageManager::resize() dla thumbnails
```

### Zakazy bezwzględne
- NIE używaj Webservice API do importu (używamy ObjectModel)
- NIE używaj raw SQL INSERT do produktów (używamy Product::add())
- NIE pomijaj pSQL() i (int) casting w zapytaniach
- NIE hardcoduj ścieżek (/var/www/...) — używaj _PS_ROOT_DIR_, _PS_IMG_DIR_
- NIE modyfikuj natywnych tabel PS
- NIE używaj die() poza controllerami
- NIE pomijaj try/catch przy operacjach na Product/Image

### Testy
- Framework: **PHPUnit 10**
- Bootstrap: `tests/bootstrap.php` z mockami klas PS
- Mocki konieczne: Configuration, Db, Product, Image, StockAvailable, Context, ImageType, ImageManager
- Fixtures: loaduj z `/shared/fixtures/` via `file_get_contents` + `json_decode`

---

## SKILL: google-apps-script

### Kiedy aktywować
Zadanie dotyczy plików w `/apps-script/`.

### Kontekst technologiczny
- Google Apps Script (JavaScript ES5+ z V8 runtime)
- Wbudowane: UrlFetchApp, SpreadsheetApp, PropertiesService, Utilities, HtmlService, Logger
- Limit timeout: **6 minut** (dla trigger-bound), 30s dla simple triggers
- Limit UrlFetchApp: 100 MB/day, 60s timeout per request

### Wzorce obowiązkowe

#### Menu:
```javascript
function onOpen() {
  SpreadsheetApp.getUi()
    .createMenu('PrestaBridge')
    .addItem('Wyślij zaznaczone produkty', 'sendSelectedProducts')
    .addItem('Ustawienia', 'showSettings')
    .addToUi();
}
```

#### HMAC (GAS specyficzny):
```javascript
function generateHmacAuth(secret, body) {
  const timestamp = Math.floor(Date.now() / 1000).toString();
  const payload = timestamp + '.' + body;
  const signature = Utilities.computeHmacSha256Signature(payload, secret);
  const hex = signature.map(function(b) {
    return ('0' + (b & 0xFF).toString(16)).slice(-2);
  }).join('');
  return timestamp + '.' + hex;
}
```

#### HTTP request:
```javascript
const options = {
  method: 'post',
  contentType: 'application/json',
  headers: { 'X-PrestaBridge-Auth': authHeader },
  payload: body,
  muteHttpExceptions: true
};
const response = UrlFetchApp.fetch(url, options);
```

#### Settings dialog:
```javascript
function showSettings() {
  const html = HtmlService.createHtmlOutputFromFile('Settings')
    .setWidth(400)
    .setHeight(300);
  SpreadsheetApp.getUi().showModalDialog(html, 'PrestaBridge Settings');
}
```

### Zakazy bezwzględne
- NIE przechowuj secretów w kodzie — tylko PropertiesService
- NIE używaj fetch() — tylko UrlFetchApp.fetch()
- NIE zapominaj o muteHttpExceptions: true
- NIE buduj HTML inline — używaj HtmlService.createHtmlOutputFromFile()

---

## SKILL: testing

### Kiedy aktywować
Zadanie dotyczy pisania lub modyfikacji testów w dowolnym komponencie.

### Zasada 1: Jeden scenariusz = jedna funkcja testowa
NIE łącz scenariuszy. Nawet jeśli testują tę samą metodę.

### Zasada 2: Arrange-Act-Assert (AAA)
```javascript
// Arrange
const product = { sku: 'TEST', name: 'Test', price: 10 };
// Act
const result = validateProduct(product);
// Assert
expect(result.valid).toBe(true);
```

### Zasada 3: Nazewnictwo
- JS: `it('should reject product without sku', () => { ... })`
- PHP: `public function testRejectsProductWithoutSku(): void { ... }`

### Zasada 4: Fixtures z `/shared/fixtures/`
```javascript
// JS
import validProducts from '../../shared/fixtures/valid-products.json';
const product = validProducts.minimal[0];
```
```php
// PHP
$fixtures = json_decode(
    file_get_contents(__DIR__ . '/../../shared/fixtures/valid-products.json'),
    true
);
$product = $fixtures['minimal'][0];
```

### Zasada 5: Komunikaty błędów testuj dokładnie
NIE testuj tylko `result.valid === false`. ZAWSZE sprawdzaj też `result.errors` zawiera konkretny komunikat. Komunikaty są zdefiniowane w TESTING-STRATEGY.md.

### Zasada 6: Edge cases z fixtures
Plik `edge-cases.json` zawiera gotowe dane graniczne. ZAWSZE ich używaj zamiast tworzyć własne.

---

## SKILL: security

### Kiedy aktywować
Zadanie dotyczy autentykacji, autoryzacji, walidacji inputu lub bezpieczeństwa danych.

### HMAC — spójność implementacji
Format nagłówka jest IDENTYCZNY w trzech środowiskach:
```
X-PrestaBridge-Auth: <unix_timestamp_seconds>.<hex_hmac_sha256>
```

Payload do podpisu:
```
<timestamp>.<raw_request_body>
```

- **Timestamp**: Unix seconds (nie milliseconds!), jako string
- **Separator**: kropka (`.`) — nie myślnik, nie spacja
- **Hex**: lowercase, bez prefixu 0x
- **Tolerancja**: 300 sekund (5 minut)
- **Porównanie**: constant-time (hash_equals w PHP, ręczna pętla XOR w JS)

### Walidacja inputu
Każdy input z zewnątrz (HTTP body, query params) MUSI być:
1. Sprawdzony pod kątem typu (typeof, is_array, is_numeric)
2. Sprawdzony pod kątem limitów (minLength, maxLength, min, max)
3. Oczyszczony przed użyciem w SQL (pSQL, (int) w PHP)
4. Nigdy nie wstawiany bezpośrednio do HTML (htmlspecialchars w PHP, textContent w JS)

### Constant-time comparison (JS — brak timing-safe w CF Workers):
```javascript
function constantTimeEqual(a, b) {
  if (a.length !== b.length) return false;
  let result = 0;
  for (let i = 0; i < a.length; i++) {
    result |= a.charCodeAt(i) ^ b.charCodeAt(i);
  }
  return result === 0;
}
```

### Constant-time comparison (PHP):
```php
// Wbudowana funkcja — ZAWSZE używaj zamiast ===
hash_equals($expected, $received);
```

### Zabezpieczenia transportu
- Wszystkie endpointy: HTTPS only
- CF Worker Router: brak CORS (server-to-server z Apps Script)
- PS endpoint: sprawdzaj Content-Type: application/json
- CRON endpoint: osobny token w query param, GET only, brak body

### Przechowywanie secretów
| Środowisko | Mechanizm | Jak ustawić |
|------------|-----------|------------|
| CF Workers | wrangler secret | `wrangler secret put AUTH_SECRET` |
| PrestaShop | ps_configuration | Panel admin modułu |
| Apps Script | PropertiesService | Dialog ustawień w Sheets |

NIGDY w kodzie, NIGDY w wrangler.toml, NIGDY w repozytorium.

---

## SKILL: error-handling

### Kiedy aktywować
Zadanie dotyczy obsługi błędów, logowania lub diagnostyki w dowolnym komponencie.

### CF Workers — wzorzec obsługi błędów

#### W handler:
```javascript
try {
  const result = await processRequest(request, env);
  return response.success(result);
} catch (error) {
  logger.error('Request processing failed', {
    error: error.message,
    stack: error.stack,
    requestId
  });
  return response.error('Internal server error', 500);
}
```

#### W queue consumer:
```javascript
for (const message of batch.messages) {
  try {
    const result = await processMessage(message.body, env);
    if (result.success) {
      message.ack();
    } else {
      message.retry({ delaySeconds: backoff(message.attempts) });
    }
  } catch (error) {
    // Jeśli body jest malformed — ack (nie retry, nigdy się nie naprawi)
    if (error instanceof SyntaxError) {
      logger.error('Malformed message, discarding', { messageId: message.id });
      message.ack();
    } else {
      logger.error('Processing failed, retrying', { error: error.message });
      message.retry({ delaySeconds: backoff(message.attempts) });
    }
  }
}
```

### PrestaShop — wzorzec obsługi błędów

#### W kontrolerze:
```php
try {
    $result = ProductImporter::import($payload);
    $results[] = $result;
} catch (\Exception $e) {
    BridgeLogger::error(
        'Import failed: ' . $e->getMessage(),
        ['sku' => $payload['sku'] ?? 'unknown', 'trace' => $e->getTraceAsString()],
        'import',
        $payload['sku'] ?? null
    );
    $results[] = [
        'success' => false,
        'sku' => $payload['sku'] ?? 'unknown',
        'status' => 'error',
        'error' => $e->getMessage()
    ];
}
```

#### Nigdy nie łykaj wyjątków cicho:
```php
// ZŁE:
try { $product->add(); } catch (\Exception $e) { /* cicho */ }

// DOBRE:
try {
    $product->add();
} catch (\Exception $e) {
    BridgeLogger::error('Product add failed', ['error' => $e->getMessage()], 'import');
    throw $e; // lub return error result
}
```

### Backoff strategy (Consumer):
```javascript
function calculateBackoff(attempts) {
  const delays = [10, 30, 60]; // sekundy
  return delays[Math.min(attempts, delays.length - 1)];
}
```

### Poziomy logowania — kiedy którego użyć:
| Poziom | Kiedy | Przykład |
|--------|-------|---------|
| debug | Szczegóły operacji, tylko development | "Processing product SKU-001, mapped fields: ..." |
| info | Pomyślne zakończenie operacji | "Product created: SKU-001, id=42" |
| warning | Pominięte elementy, nieoptymalne sytuacje | "Duplicate SKU-001 skipped (overwrite=false)" |
| error | Operacja nie powiodła się ale system działa | "Image download failed for SKU-001: timeout" |
| critical | System nie może kontynuować | "Database connection lost", "Auth secret not configured" |

---

## SKILL: race-conditions

### Kiedy aktywować
Zadanie dotyczy operacji na zdjęciach, CRON, lub równoległego dostępu do danych.

### Problem 1: Produkt usunięty między importem a przypisaniem zdjęcia
```
Timeline:
  t0: Import produktu SKU-001 → id_product = 42
  t1: Zdjęcia dodane do kolejki z id_product = 42
  t2: Admin usuwa produkt id=42 z PS
  t3: CRON próbuje przypisać zdjęcie do id=42 → ERROR
```

**Rozwiązanie:** ZAWSZE sprawdzaj istnienie produktu PRZED operacją:
```php
if (!Product::existsInDatabase($id_product, 'product')) {
    ImageQueueManager::markFailed($imageQueueId, 'Product does not exist');
    continue;
}
```

### Problem 2: Dwa CRON-y pobierają te same zdjęcia
```
Timeline:
  t0: CRON #1 pobiera batch 10 pending images
  t1: CRON #2 (uruchomiony ręcznie) pobiera ten sam batch
  t2: Oba próbują przypisać te same zdjęcia → duplikaty
```

**Rozwiązanie:** Pessimistic locking z lock_token:
```php
// 1. Generuj unikalny token
$lockToken = bin2hex(random_bytes(16));

// 2. Lockuj batch (atomowy UPDATE)
UPDATE prestabridge_image_queue
SET lock_token = '$lockToken', locked_at = NOW(), status = 'processing'
WHERE status = 'pending' AND attempts < max_attempts
ORDER BY created_at ASC
LIMIT $limit;

// 3. Pobierz tylko SWOJE zlockowane rekordy
SELECT * FROM prestabridge_image_queue WHERE lock_token = '$lockToken';
```

### Problem 3: CRON padnie w trakcie — locki nie zwolnione
```
Timeline:
  t0: CRON lockuje 10 images z lock_token = ABC
  t1: CRON crashuje (OOM, timeout)
  t2: Images z lock ABC zostają w status = 'processing' na zawsze
```

**Rozwiązanie:** Safety net na początku każdego CRON:
```php
// Zwolnij locki starsze niż 10 minut
UPDATE prestabridge_image_queue
SET lock_token = NULL, locked_at = NULL, status = 'pending'
WHERE status = 'processing'
AND locked_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE);
```

### Problem 4: Cover image przypisywany przez dwa procesy
```
Timeline:
  t0: CRON #1 przypisuje image position=0 (cover) do product 42
  t1: CRON #2 przypisuje image position=0 (cover) do product 42
  t2: Dwa cover images na jednym produkcie
```

**Rozwiązanie:** Image::deleteCover() PRZED ustawieniem nowego cover:
```php
if ($isCover) {
    Image::deleteCover($productId);
}
$image->cover = 1;
$image->add();
```
W kontekście CRON to jest bezpieczne bo locking zapobiega przetwarzaniu tego samego zdjęcia dwukrotnie.

---

## SKILL: prestashop-admin-ui

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

---

## SKILL: deployment

### Kiedy aktywować
Zadanie dotyczy deployu, konfiguracji serwera lub CI/CD.

### Kolejność deployu
1. **CF Queue** (musi istnieć zanim Workers będą je referencować)
2. **CF Worker Router** (producent — musi mieć Queue)
3. **CF Worker Consumer** (consumer — musi mieć Queue + PS endpoint)
4. **PS Module** (musi być zainstalowany zanim Consumer wyśle dane)
5. **CRON** (musi być skonfigurowany po instalacji modułu)
6. **Apps Script** (musi znać URL Routera)

### Weryfikacja po deploy:
```bash
# 1. Router health check
curl -s -o /dev/null -w "%{http_code}" https://prestabridge-router.xxx.workers.dev/import
# Oczekiwane: 405 (Method Not Allowed — bo GET, a akceptujemy POST)

# 2. PS endpoint health check
curl -s -o /dev/null -w "%{http_code}" https://shop.com/module/prestabridge/api
# Oczekiwane: 401 (brak auth header)

# 3. CRON health check
curl -s "https://shop.com/module/prestabridge/cron?token=WRONG"
# Oczekiwane: 401 JSON response

# 4. CRON prawidłowy
curl -s "https://shop.com/module/prestabridge/cron?token=CORRECT_TOKEN&limit=0"
# Oczekiwane: 200 JSON z pustym raportem
```

### Rollback plan
1. CF Workers: `wrangler rollback` przywraca poprzednią wersję
2. PS Module: Wyłącz moduł w BO (nie odinstalowuj — zachowuje dane)
3. Queue: Wiadomości pozostają — Consumer je przetworzy po naprawie

---

## PODSUMOWANIE — KTÓRY SKILL KIEDY

| Pracujesz nad... | Przeczytaj skille |
|-------------------|-------------------|
| workers/router/* | cloudflare-workers, security, testing |
| workers/consumer/* | cloudflare-workers, security, error-handling, testing |
| prestashop-module/classes/Auth/* | prestashop-module, security, testing |
| prestashop-module/classes/Import/* | prestashop-module, error-handling, testing |
| prestashop-module/classes/Image/* | prestashop-module, race-conditions, error-handling, testing |
| prestashop-module/classes/Logging/* | prestashop-module, error-handling, testing |
| prestashop-module/views/* | prestashop-admin-ui |
| prestashop-module/controllers/* | prestashop-module, security, error-handling |
| apps-script/* | google-apps-script, security |
| Deploy/konfiguracja | deployment |
| Cokolwiek z testami | testing + skill danego komponentu |
