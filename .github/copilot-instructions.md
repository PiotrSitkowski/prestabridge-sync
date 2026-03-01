# .cursorrules / .claude-rules — PrestaBridge Project Rules

> Ten plik zawiera zasady obowiązujące WSZYSTKIE agenty AI pracujące z projektem PrestaBridge.
> Obowiązuje zarówno w Claude Code, Cursor, Windsurf, Google Antigravity, jak i każdym innym środowisku.

---

## REGUŁA #0 — PRZECZYTAJ CLAUDE.md ZANIM NAPISZESZ CHOĆ LINIĘ KODU

Przed każdą sesją pracy:
1. Przeczytaj `/CLAUDE.md` — to jedyne źródło prawdy
2. Zidentyfikuj etap, nad którym pracujesz (sekcja 14)
3. Sprawdź dokładnie jakie pliki masz stworzyć/edytować
4. Przeczytaj odpowiednią sekcję szczegółową (6-9)
5. Przeczytaj scenariusze testów (sekcja 12)

---

## REGUŁA #1 — ZERO INTERPRETACJI

- Implementujesz DOKŁADNIE to, co jest w CLAUDE.md
- Nie "ulepszasz" — tworzysz zgodnie z planem
- Nie dodajesz "na wszelki wypadek" — tworzysz tylko to, co opisano
- Nie zmieniasz nazw, typów, kolejności parametrów
- Jeśli czegoś nie rozumiesz — wstawiasz `// TODO: QUESTION: <opis wątpliwości>`

---

## REGUŁA #2 — STRUKTURA PLIKÓW JEST ŚWIĘTA

```
NIE tworzysz plików poza strukturą z sekcji 17 CLAUDE.md
NIE zmieniasz nazw katalogów ani plików
NIE przenosisz klas między katalogami
NIE łączysz plików ("bo tak będzie prościej")
```

---

## REGUŁA #3 — KAŻDY PLIK = JEDEN CEL

Zasada Single Responsibility:
- `ProductValidator.php` — TYLKO walidacja
- `ProductMapper.php` — TYLKO mapowanie
- `ProductImporter.php` — TYLKO import (używa Validator i Mapper)
- `ImageDownloader.php` — TYLKO pobieranie z URL
- `ImageAssigner.php` — TYLKO przypisywanie do PS
- `BridgeLogger.php` — TYLKO logowanie

Jeśli metoda robi dwie rzeczy — rozbij na dwie metody lub dwie klasy.

---

## REGUŁA #4 — TESTY SĄ OBOWIĄZKOWE

Dla każdego pliku klasy/modułu MUSISZ stworzyć odpowiedni plik testowy.
Scenariusze testów są opisane w sekcji 12 CLAUDE.md — implementuj KAŻDY scenariusz.
Nazewnictwo testów: `test<NazwaMetody>_<scenariusz>` lub `it('should <opis>', ...)`

### CF Workers (Vitest):
```javascript
// Pattern:
import { describe, it, expect } from 'vitest';
import { validateProduct } from '../src/services/validationService.js';

describe('validationService', () => {
  describe('validateProduct', () => {
    it('should accept product with minimum required fields', () => {
      // scenariusz R-V1
    });
  });
});
```

### PrestaShop (PHPUnit):
```php
// Pattern:
namespace PrestaBridge\Tests\Unit\Import;

use PHPUnit\Framework\TestCase;
use PrestaBridge\Import\ProductValidator;

class ProductValidatorTest extends TestCase
{
    public function testValidateMinimalValidProduct(): void
    {
        // scenariusz P-V1
    }
}
```

---

## REGUŁA #5 — HMAC IMPLEMENTACJA JEST IDENTYCZNA WSZĘDZIE

HMAC-SHA256 musi produkować identyczny podpis na:
- Google Apps Script (Utilities.computeHmacSha256Signature)
- CF Workers (crypto.subtle)
- PHP (hash_hmac)

Format: `timestamp.hex_signature`
Payload: `timestamp + '.' + rawBody`

**NIE ZMIENIAJ formatu, separatora, kodowania.**

---

## REGUŁA #6 — SQL — BEZPIECZEŃSTWO

Każdy parametr w zapytaniu SQL:
- String → `pSQL($value)`
- Integer → `(int) $value`
- Nigdy nie wstawiaj zmiennych bezpośrednio do SQL
- Nigdy nie używaj `sprintf` z `%s` na nieprzefiltrowanych danych

---

## REGUŁA #7 — ERROR HANDLING

### CF Workers:
```javascript
// ZAWSZE try/catch na zewnętrznych operacjach
try {
  const result = await operation();
} catch (error) {
  logger.error('Operation failed', { error: error.message, stack: error.stack });
  return response.error('Internal error', 500);
}
```

### PHP PrestaShop:
```php
// ZAWSZE try/catch na import/image operacjach
try {
    $result = $this->operation();
} catch (\Exception $e) {
    BridgeLogger::error($e->getMessage(), ['trace' => $e->getTraceAsString()], 'source');
    return ['success' => false, 'error' => $e->getMessage()];
}
```

---

## REGUŁA #8 — RACE CONDITION SAFETY

PRZED przypisaniem zdjęcia do produktu ZAWSZE:
```php
if (!Product::existsInDatabase($id_product, 'product')) {
    // STOP — produkt nie istnieje
}
```

PRZED update produktu ZAWSZE:
```php
$existing = new Product($existingId);
if (!Validate::isLoadedObject($existing)) {
    // STOP — produkt został usunięty między checkiem a update
}
```

---

## REGUŁA #9 — CF WORKERS FREE TIER

- CPU time < 10ms: NIE rób ciężkich obliczeń, NIE parsuj ogromnych JSON-ów
- Wall time < 30s: timeout na fetch do PS = 25s max
- NIE używaj: eval(), Function(), importScripts()
- NIE importuj: npm packages > 50KB (walidacja ręczna, nie ajv)
- TAK używaj: crypto.subtle, TextEncoder, Response, Request, Headers

---

## REGUŁA #10 — RESPONSE FORMAT

### CF Worker odpowiedzi — ZAWSZE JSON:
```javascript
return new Response(JSON.stringify({
  success: true/false,
  // ... data
}), {
  status: 200/400/401/500,
  headers: { 'Content-Type': 'application/json' }
});
```

### PS Controller odpowiedzi — ZAWSZE JSON:
```php
header('Content-Type: application/json');
die(json_encode([
    'success' => true/false,
    // ... data
]));
```

---

## REGUŁA #11 — KOMENTARZE I DOKUMENTACJA

### JS (CF Workers):
```javascript
/**
 * Validates a single product payload against the schema.
 * @param {Object} product - Product payload
 * @returns {{ valid: boolean, errors: string[] }}
 */
export function validateProduct(product) { ... }
```

### PHP:
```php
/**
 * Validates a single product payload.
 *
 * @param array<string, mixed> $product Product data
 * @return array{valid: bool, errors: string[]}
 */
public static function validate(array $product): array { ... }
```

---

## REGUŁA #12 — NIE IMPLEMENTUJ PRZYSZŁOŚCI

Te elementy istnieją TYLKO jako puste klasy/interfejsy:
- `Export/ProductExporter.php` — tylko abstract class z todo
- `Export/ExportableInterface.php` — tylko interface z metodami
- Hooki PS (`hookActionProductUpdate` etc.) — zarejestrowane ale puste
- D1 integration — nie istnieje w kodzie
- R2 storage — nie istnieje w kodzie
- Workers AI — nie istnieje w kodzie

---

## REGUŁA #13 — GIT WORKFLOW

- Jeden commit per etap (sekcja 14 CLAUDE.md)
- Commit message: `[Etap X] <opis>` np. `[Etap 2] CF Worker Router - implementation`
- Branch: `main` (MVP)
- Nie commituj: `node_modules/`, `.wrangler/`, `vendor/`

---

## REGUŁA #14 — DEPENDENCY MANAGEMENT

### CF Workers (package.json):
```json
{
  "devDependencies": {
    "vitest": "^1.0.0",
    "@cloudflare/vitest-pool-workers": "^0.1.0",
    "miniflare": "^3.0.0",
    "wrangler": "^3.0.0"
  }
}
```
ZERO production dependencies — CF Workers bundle all.

### PrestaShop (composer.json):
```json
{
  "name": "prestabridge/prestabridge",
  "autoload": {
    "psr-4": {
      "PrestaBridge\\": "classes/"
    }
  },
  "require-dev": {
    "phpunit/phpunit": "^10.0"
  }
}
```
ZERO production dependencies — PS ma wszystko.

---

## REGUŁA #15 — KONFIGURACJA ŚRODOWISKA

### Secrets NIGDY w kodzie:
- CF Workers: `wrangler secret put AUTH_SECRET`
- PrestaShop: tabela `ps_configuration` (PRESTABRIDGE_AUTH_SECRET)
- Apps Script: `PropertiesService.getScriptProperties()`

### Zmienne konfiguracyjne w wrangler.toml:
- Tylko NON-SECRET values
- Typy: string (nawet numery — parsowane w runtime)

---

## LISTA KONTROLNA PRZED ZAKOŃCZENIEM SESJI

- [ ] Każdy nowy plik ma testy
- [ ] Wszystkie testy przechodzą
- [ ] Brak `console.log` w produkcyjnym kodzie PS (tylko Logger)
- [ ] Brak hardcoded secrets
- [ ] Brak TODO bez wyjaśnienia
- [ ] Struktura plików zgodna z sekcją 17 CLAUDE.md
- [ ] Response format zgodny z sekcją 4 CLAUDE.md
- [ ] HMAC format identyczny we wszystkich komponentach
