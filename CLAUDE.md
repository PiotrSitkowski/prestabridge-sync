# CLAUDE.md — PrestaBridge: System Synchronizacji Google Sheets → CloudFlare → PrestaShop

> **Wersja dokumentu:** 2.0.0
> **Data:** 2026-02-28
> **Autor architektury:** Senior Architect (Claude)
> **Cel dokumentu:** Specyfikacja techniczna systemu dla agentów AI — zero interpretacji, zero halucynacji.

---

## SPIS TREŚCI

1. [Wizja systemu](#1-wizja-systemu)
2. [Architektura wysokiego poziomu](#2-architektura-wysokiego-poziomu)
3. [Komponenty systemu](#3-komponenty-systemu)
4. [Struktury danych](#4-struktury-danych)
5. [Google Apps Script](#5-google-apps-script)
6. [CloudFlare Worker — Router/Orkiestrator](#6-cloudflare-worker--routerorkiestrator)
7. [CloudFlare Queue](#7-cloudflare-queue)
8. [CloudFlare Worker — Consumer](#8-cloudflare-worker--consumer)
9. [Moduł PrestaShop](#9-modul-prestashop)
10. [Bezpieczeństwo](#10-bezpieczenstwo)
11. [Logowanie błędów](#11-logowanie-bledow)
12. [Testy](#12-testy)
13. [Limity i optymalizacja](#13-limity-i-optymalizacja)
14. [Plan realizacji — etapy](#14-plan-realizacji--etapy)
15. [Przyszły rozwój — PaaS](#15-przyszly-rozwoj--paas)
16. [Konwencje kodowania](#16-konwencje-kodowania)
17. [Drzewo plików](#17-drzewo-plikow)

---

## 1. WIZJA SYSTEMU

### 1.1 Cel
PrestaBridge to uniwersalny pomost do importu danych produktowych z Google Sheets do PrestaShop przez CloudFlare. System jest projektowany z myślą o przyszłym rozszerzeniu do pełnej platformy PaaS synchronizacji danych e-commerce w środowisku CloudFlare.

### 1.2 Jednokierunkowy przepływ danych (MVP)
```
Google Sheets → Apps Script → CF Worker (Router) → CF Queue → CF Worker (Consumer) → PrestaShop Module
```

### 1.3 Przyszły przepływ dwukierunkowy (PaaS)
```
PrestaShop ←→ CloudFlare (D1/R2/Workers) ←→ Zewnętrzne systemy
```

### 1.4 Kluczowe założenia
- **Asynchroniczność**: Zdjęcia pobierane są oddzielnym procesem CRON
- **Paczki (batching)**: Produkty dzielone na paczki przed wysłaniem do kolejki
- **Idempotentność**: Każda operacja musi być bezpieczna przy powtórzeniu
- **Race condition safety**: Kontrola stanu produktu przed przypisaniem zdjęć
- **Rozwojowość**: Kod przygotowany na dwukierunkową synchronizację

---

## 2. ARCHITEKTURA WYSOKIEGO POZIOMU

### 2.1 Diagram przepływu (ASCII)

```
┌─────────────────┐
│  Google Sheets   │
│  (dane produktów)│
└───────┬─────────┘
        │ POST JSON (batch + auth token)
        ▼
┌─────────────────────────────────────────────────────────┐
│  CloudFlare Worker: prestaBridge-router                  │
│  ┌─────────────────────────────────────────────────┐    │
│  │ 1. Walidacja tokenu (HMAC-SHA256 timestamp)     │    │
│  │ 2. Walidacja JSON Schema                         │    │
│  │ 3. Podział na paczki (batchSize z parametru)     │    │
│  │ 4. Enqueue do CF Queue                           │    │
│  │ 5. Zwrot raportu: accepted/rejected per product  │    │
│  └─────────────────────────────────────────────────┘    │
└───────┬─────────────────────────────────────────────────┘
        │ Queue messages (batches)
        ▼
┌─────────────────────────────────────────────────────────┐
│  CloudFlare Queue: prestaBridge-product-queue            │
│  (max batch_size: 10, max_retries: 3)                    │
└───────┬─────────────────────────────────────────────────┘
        │ Batch consume
        ▼
┌─────────────────────────────────────────────────────────┐
│  CloudFlare Worker: prestaBridge-consumer                 │
│  ┌─────────────────────────────────────────────────┐    │
│  │ 1. Deserializacja paczki                         │    │
│  │ 2. POST do PrestaShop endpoint                   │    │
│  │ 3. Obsługa response / retry                      │    │
│  └─────────────────────────────────────────────────┘    │
└───────┬─────────────────────────────────────────────────┘
        │ POST JSON (batch + auth token)
        ▼
┌─────────────────────────────────────────────────────────┐
│  PrestaShop Module: prestaBridge                         │
│  ┌─────────────────────────────────────────────────┐    │
│  │ ENDPOINT: /module/prestabridge/api               │    │
│  │                                                   │    │
│  │ 1. Walidacja tokenu                              │    │
│  │ 2. Walidacja duplikatów SKU                      │    │
│  │ 3. Upsert produktów (ObjectModel)                │    │
│  │ 4. Zapis URL-i zdjęć do tabeli kolejki           │    │
│  │ 5. Response z raportem per produkt               │    │
│  └─────────────────────────────────────────────────┘    │
│                                                          │
│  CRON: /module/prestabridge/cron                         │
│  ┌─────────────────────────────────────────────────┐    │
│  │ 1. Pobierz pending images z tabeli               │    │
│  │ 2. Sprawdź czy produkt istnieje w DB             │    │
│  │ 3. Pobierz zdjęcie z URL                         │    │
│  │ 4. Przypisz do produktu (Image ObjectModel)      │    │
│  │ 5. Oznacz jako processed/failed                   │    │
│  └─────────────────────────────────────────────────┘    │
│                                                          │
│  ADMIN: Panel konfiguracji + Logi                        │
└─────────────────────────────────────────────────────────┘
```

### 2.2 Decyzje architektoniczne

| Decyzja | Wybór | Uzasadnienie |
|---------|-------|--------------|
| Import produktów | PrestaShop ObjectModel (nie API) | Szybszy, brak narzutu HTTP, pełna kontrola, natywne hooki |
| Zdjęcia | Osobny CRON, nie inline | Unikamy timeoutów, kontrola race conditions |
| Kolejka CF | Queue (nie D1 polling) | Natywny mechanizm, retry, dead letter |
| Autentykacja | HMAC-SHA256 z timestampem | Lekki, bezpieczny, bez stanu na workerze |
| Batch size | Parametr z Apps Script (domyślnie 5) | Elastyczność, ochrona przed przeciążeniem |
| Format danych | JSON z jawnym schematem | Walidowalny, samodokumentujący się |

---

## 3. KOMPONENTY SYSTEMU

### 3.1 Lista komponentów

| ID | Komponent | Technologia | Lokalizacja kodu |
|----|-----------|-------------|-------------------|
| C1 | Google Apps Script | JavaScript (GAS) | `/apps-script/` |
| C2 | CF Worker Router | JavaScript (ES modules) | `/workers/router/` |
| C3 | CF Queue | CloudFlare config | `wrangler.toml` |
| C4 | CF Worker Consumer | JavaScript (ES modules) | `/workers/consumer/` |
| C5 | PS Module | PHP 8.1+ | `/prestashop-module/prestabridge/` |
| C6 | Shared Types/Schemas | JSON Schema | `/shared/schemas/` |
| C7 | Testy | Vitest (CF), PHPUnit (PS) | `/tests/` |

### 3.2 Zależności między komponentami

```
C1 → C2: HTTP POST (JSON payload + HMAC auth header)
C2 → C3: env.QUEUE.send() (JSON batches)
C3 → C4: queue handler batch consume
C4 → C5: HTTP POST (JSON payload + HMAC auth header)
C5: wewnętrzny CRON (zdjęcia)
C6: wspólne schematy walidacji (kopiowane do C2, C4, C5)
```

---

## 4. STRUKTURY DANYCH

### 4.1 Schemat produktu (ProductPayload)

**UWAGA: To jest jedyne źródło prawdy o strukturze danych produktu w całym systemie.**

```json
{
  "$schema": "http://json-schema.org/draft-07/schema#",
  "$id": "product-payload",
  "type": "object",
  "required": ["sku", "name", "price"],
  "properties": {
    "sku": {
      "type": "string",
      "minLength": 1,
      "maxLength": 64,
      "description": "Unikalny identyfikator produktu (reference w PrestaShop)"
    },
    "name": {
      "type": "string",
      "minLength": 1,
      "maxLength": 128,
      "description": "Nazwa produktu"
    },
    "price": {
      "type": "number",
      "minimum": 0,
      "exclusiveMinimum": true,
      "description": "Cena netto produktu (bez podatku)"
    },
    "description": {
      "type": "string",
      "maxLength": 65535,
      "default": "",
      "description": "Opis produktu (HTML dozwolony)"
    },
    "description_short": {
      "type": "string",
      "maxLength": 800,
      "default": "",
      "description": "Krótki opis produktu"
    },
    "images": {
      "type": "array",
      "items": {
        "type": "string",
        "format": "uri",
        "pattern": "^https?://"
      },
      "default": [],
      "description": "Lista URL-i zdjęć produktu. Pierwszy = cover image."
    },
    "quantity": {
      "type": "integer",
      "minimum": 0,
      "default": 0,
      "description": "Stan magazynowy"
    },
    "ean13": {
      "type": "string",
      "maxLength": 13,
      "default": "",
      "description": "Kod EAN13"
    },
    "weight": {
      "type": "number",
      "minimum": 0,
      "default": 0,
      "description": "Waga w kg"
    },
    "active": {
      "type": "boolean",
      "default": false,
      "description": "Czy produkt jest aktywny. Domyślnie false — aktywacja po pobraniu zdjęć."
    },
    "meta_title": {
      "type": "string",
      "maxLength": 128,
      "default": "",
      "description": "SEO meta title"
    },
    "meta_description": {
      "type": "string",
      "maxLength": 512,
      "default": "",
      "description": "SEO meta description"
    }
  },
  "additionalProperties": false
}
```

### 4.2 Schemat żądania do Routera (RouterRequest)

```json
{
  "$schema": "http://json-schema.org/draft-07/schema#",
  "$id": "router-request",
  "type": "object",
  "required": ["products"],
  "properties": {
    "products": {
      "type": "array",
      "items": { "$ref": "product-payload" },
      "minItems": 1,
      "maxItems": 1000,
      "description": "Lista produktów do importu"
    },
    "batchSize": {
      "type": "integer",
      "minimum": 1,
      "maximum": 50,
      "default": 5,
      "description": "Ilość produktów w jednej paczce do Queue"
    },
    "callbackUrl": {
      "type": "string",
      "format": "uri",
      "description": "Opcjonalny URL callback po zakończeniu (przyszłość)"
    }
  }
}
```

### 4.3 Schemat odpowiedzi z Routera (RouterResponse)

```json
{
  "type": "object",
  "properties": {
    "success": { "type": "boolean" },
    "requestId": { "type": "string", "format": "uuid" },
    "timestamp": { "type": "string", "format": "date-time" },
    "summary": {
      "type": "object",
      "properties": {
        "totalReceived": { "type": "integer" },
        "totalAccepted": { "type": "integer" },
        "totalRejected": { "type": "integer" },
        "batchesCreated": { "type": "integer" },
        "batchSize": { "type": "integer" }
      }
    },
    "rejected": {
      "type": "array",
      "items": {
        "type": "object",
        "properties": {
          "index": { "type": "integer" },
          "sku": { "type": "string" },
          "errors": { "type": "array", "items": { "type": "string" } }
        }
      }
    }
  }
}
```

### 4.4 Schemat wiadomości Queue (QueueMessage)

```json
{
  "type": "object",
  "properties": {
    "requestId": { "type": "string", "format": "uuid" },
    "batchIndex": { "type": "integer" },
    "totalBatches": { "type": "integer" },
    "products": {
      "type": "array",
      "items": { "$ref": "product-payload" }
    },
    "metadata": {
      "type": "object",
      "properties": {
        "enqueuedAt": { "type": "string", "format": "date-time" },
        "source": { "type": "string", "enum": ["google-sheets", "api", "manual"] }
      }
    }
  }
}
```

### 4.5 Schemat odpowiedzi z PrestaShop (PSResponse)

```json
{
  "type": "object",
  "properties": {
    "success": { "type": "boolean" },
    "results": {
      "type": "array",
      "items": {
        "type": "object",
        "properties": {
          "sku": { "type": "string" },
          "status": {
            "type": "string",
            "enum": ["created", "updated", "skipped", "error"]
          },
          "productId": { "type": "integer" },
          "imagesQueued": { "type": "integer" },
          "error": { "type": "string" }
        }
      }
    }
  }
}
```

### 4.6 Tabele bazy danych PrestaShop (tworzenie przy instalacji modułu)

#### Tabela: `ps_prestabridge_image_queue`
```sql
CREATE TABLE IF NOT EXISTS `PREFIX_prestabridge_image_queue` (
  `id_image_queue` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_product` INT(11) UNSIGNED NOT NULL,
  `sku` VARCHAR(64) NOT NULL,
  `image_url` VARCHAR(2048) NOT NULL,
  `position` INT(3) UNSIGNED NOT NULL DEFAULT 0,
  `is_cover` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  `status` ENUM('pending', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'pending',
  `error_message` VARCHAR(512) DEFAULT NULL,
  `attempts` TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
  `max_attempts` TINYINT(3) UNSIGNED NOT NULL DEFAULT 3,
  `lock_token` VARCHAR(36) DEFAULT NULL,
  `locked_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_image_queue`),
  INDEX `idx_status_attempts` (`status`, `attempts`),
  INDEX `idx_product` (`id_product`),
  INDEX `idx_sku` (`sku`),
  INDEX `idx_lock` (`lock_token`, `locked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

#### Tabela: `ps_prestabridge_log`
```sql
CREATE TABLE IF NOT EXISTS `PREFIX_prestabridge_log` (
  `id_log` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `level` ENUM('debug', 'info', 'warning', 'error', 'critical') NOT NULL DEFAULT 'info',
  `source` ENUM('import', 'image', 'cron', 'api', 'config', 'system') NOT NULL,
  `message` TEXT NOT NULL,
  `context` TEXT DEFAULT NULL COMMENT 'JSON z dodatkowymi danymi kontekstowymi',
  `sku` VARCHAR(64) DEFAULT NULL,
  `id_product` INT(11) UNSIGNED DEFAULT NULL,
  `request_id` VARCHAR(36) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_log`),
  INDEX `idx_level` (`level`),
  INDEX `idx_source` (`source`),
  INDEX `idx_sku` (`sku`),
  INDEX `idx_request_id` (`request_id`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

#### Tabela: `ps_prestabridge_import_tracking`
```sql
CREATE TABLE IF NOT EXISTS `PREFIX_prestabridge_import_tracking` (
  `id_tracking` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `request_id` VARCHAR(36) NOT NULL,
  `id_product` INT(11) UNSIGNED NOT NULL,
  `sku` VARCHAR(64) NOT NULL,
  `status` ENUM('imported', 'images_pending', 'images_partial', 'completed', 'error') NOT NULL DEFAULT 'imported',
  `images_total` INT(3) UNSIGNED NOT NULL DEFAULT 0,
  `images_completed` INT(3) UNSIGNED NOT NULL DEFAULT 0,
  `images_failed` INT(3) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_tracking`),
  UNIQUE KEY `uniq_sku` (`sku`),
  INDEX `idx_request` (`request_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_product` (`id_product`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## 5. GOOGLE APPS SCRIPT

### 5.1 Plik: `Code.gs`

**Odpowiedzialność:** Interfejs użytkownika w Google Sheets — odczyt zaznaczonych wierszy, budowa JSON, wysyłka do CF Worker.

### 5.2 Specyfikacja arkusza

| Kolumna | Nagłówek | Typ | Mapowanie na ProductPayload |
|---------|----------|-----|----------------------------|
| A | ☑ (checkbox) | Boolean | — (selektor) |
| B | SKU | String | `sku` |
| C | Nazwa | String | `name` |
| D | Cena | Number | `price` |
| E | Opis | String | `description` |
| F | Krótki opis | String | `description_short` |
| G | Zdjęcia (JSON) | String (JSON array) | `images` |
| H | Ilość | Number | `quantity` |
| I | EAN13 | String | `ean13` |
| J | Waga | Number | `weight` |
| K | Aktywny | Boolean | `active` |
| L | Meta Title | String | `meta_title` |
| M | Meta Description | String | `meta_description` |

### 5.3 Wymagana funkcjonalność

#### Funkcja: `onOpen()`
- Dodaje menu "PrestaBridge" do arkusza
- Opcje menu: "Wyślij zaznaczone produkty", "Ustawienia"

#### Funkcja: `sendSelectedProducts()`
1. Odczytaj aktywny arkusz
2. Pobierz wiersz nagłówka (row 1) — użyj do mapowania
3. Iteruj od wiersza 2 w dół
4. Dla każdego wiersza gdzie kolumna A = `true`:
   - Zbuduj obiekt ProductPayload z mapowaniem kolumn
   - Kolumna G (images): `JSON.parse()` — jeśli parse error, ustaw `[]`
   - Kolumna D (price): `parseFloat()` — jeśli NaN, pomiń wiersz z logiem
5. Pobierz ustawienia z PropertiesService (workerUrl, authSecret, batchSize)
6. Zbuduj RouterRequest JSON
7. Wygeneruj HMAC-SHA256 nagłówek autoryzacji (patrz sekcja 10)
8. Wyślij POST do workerUrl
9. Wyświetl podsumowanie w oknie dialogowym (Toast lub SpreadsheetApp.getUi().alert)
10. Odznacz checkboxy pomyślnie wysłanych produktów

#### Funkcja: `showSettings()`
- Okno dialogowe HtmlService z polami:
  - Worker URL (string)
  - Auth Secret (string, maskowany)
  - Batch Size (number, domyślnie 5, min 1, max 50)
- Zapis do `PropertiesService.getScriptProperties()`

### 5.4 Generowanie HMAC

```javascript
// DOKŁADNA IMPLEMENTACJA — nie zmieniaj
function generateHmacAuth(secret, body) {
  const timestamp = Math.floor(Date.now() / 1000).toString();
  const payload = timestamp + '.' + body;
  const signature = Utilities.computeHmacSha256Signature(payload, secret);
  const signatureHex = signature.map(b => ('0' + (b & 0xFF).toString(16)).slice(-2)).join('');
  return `${timestamp}.${signatureHex}`;
}
// Nagłówek: X-PrestaBridge-Auth: <timestamp>.<signature>
```

### 5.5 Obsługa błędów
- Catch na `UrlFetchApp.fetch()` — loguj do Logger i wyświetl alert
- Timeout: 60s (limit GAS)
- Jeśli response status !== 200, wyświetl treść błędu

---

## 6. CLOUDFLARE WORKER — ROUTER/ORKIESTRATOR

### 6.1 Identyfikator: `prestaBridge-router`

### 6.2 Plik wejściowy: `src/index.js` (ES module format)

### 6.3 Zmienne środowiskowe (wrangler.toml)

```toml
name = "prestabridge-router"
main = "src/index.js"
compatibility_date = "2024-01-01"

[vars]
ENVIRONMENT = "production"
MAX_PRODUCTS_PER_REQUEST = "1000"
MAX_BATCH_SIZE = "50"
DEFAULT_BATCH_SIZE = "5"
HMAC_TIMESTAMP_TOLERANCE_SECONDS = "300"

[[queues.producers]]
queue = "prestabridge-product-queue"
binding = "PRODUCT_QUEUE"

# Secret (ustawiane przez wrangler secret)
# AUTH_SECRET = "..." — NIGDY nie w wrangler.toml
```

### 6.4 Struktura plików Worker Router

```
workers/router/
├── src/
│   ├── index.js              # Entry point — fetch handler
│   ├── handlers/
│   │   └── importHandler.js  # Logika obsługi POST /import
│   ├── services/
│   │   ├── validationService.js  # Walidacja JSON Schema
│   │   ├── batchService.js       # Podział na paczki
│   │   └── queueService.js       # Enqueue do CF Queue
│   ├── middleware/
│   │   ├── authMiddleware.js     # Weryfikacja HMAC
│   │   └── rateLimitMiddleware.js # Prosty rate limit (opcja)
│   ├── utils/
│   │   ├── hmac.js               # Funkcje HMAC
│   │   ├── response.js           # Helpery budowania Response
│   │   └── logger.js             # Formatowanie logów
│   └── schemas/
│       └── productSchema.js      # JSON Schema (kopia z /shared/)
├── test/
│   ├── handlers/
│   │   └── importHandler.test.js
│   ├── services/
│   │   ├── validationService.test.js
│   │   ├── batchService.test.js
│   │   └── queueService.test.js
│   ├── middleware/
│   │   └── authMiddleware.test.js
│   ├── utils/
│   │   └── hmac.test.js
│   └── fixtures/
│       ├── validProducts.json
│       ├── invalidProducts.json
│       └── edgeCases.json
├── wrangler.toml
├── package.json
└── vitest.config.js
```

### 6.5 Logika `index.js` — fetch handler

```javascript
// PSEUDOKOD — dokładna implementacja w pliku docelowym
export default {
  async fetch(request, env, ctx) {
    // 1. Sprawdź metodę: tylko POST
    // 2. Sprawdź path: tylko /import
    // 3. authMiddleware.verify(request, env.AUTH_SECRET)
    // 4. Parsuj body JSON
    // 5. validationService.validateRequest(body)
    // 6. Odfiltruj nieprawidłowe produkty, zbierz błędy
    // 7. batchService.createBatches(validProducts, batchSize)
    // 8. queueService.enqueueBatches(env.PRODUCT_QUEUE, batches, requestId)
    // 9. Zwróć RouterResponse
  }
};
```

### 6.6 Szczegóły implementacji per plik

#### `authMiddleware.js`
- Eksportuje: `verify(request, secret) → { valid: boolean, error?: string }`
- Pobiera nagłówek `X-PrestaBridge-Auth`
- Parsuje format `<timestamp>.<signature>`
- Sprawdza timestamp tolerance (env.HMAC_TIMESTAMP_TOLERANCE_SECONDS)
- Oblicza HMAC-SHA256: `timestamp + '.' + rawBody`
- Porównuje timing-safe (crypto.subtle.timingSafeEqual nie jest dostępne w CF Workers — użyj porównania constant-time ręcznie implementowanego)

**UWAGA:** W CF Workers Free Tier crypto.subtle jest dostępne. Użyj:
```javascript
async function verifyHmac(secret, timestamp, body, receivedSignature) {
  const encoder = new TextEncoder();
  const key = await crypto.subtle.importKey(
    'raw', encoder.encode(secret), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']
  );
  const payload = encoder.encode(timestamp + '.' + body);
  const expectedSig = await crypto.subtle.sign('HMAC', key, payload);
  const expectedHex = [...new Uint8Array(expectedSig)]
    .map(b => b.toString(16).padStart(2, '0')).join('');
  // Constant-time comparison
  if (expectedHex.length !== receivedSignature.length) return false;
  let result = 0;
  for (let i = 0; i < expectedHex.length; i++) {
    result |= expectedHex.charCodeAt(i) ^ receivedSignature.charCodeAt(i);
  }
  return result === 0;
}
```

#### `validationService.js`
- Eksportuje: `validateProduct(product) → { valid: boolean, errors: string[] }`
- Eksportuje: `validateRequest(body) → { valid: boolean, errors: string[] }`
- Walidacja ręczna (BEZ biblioteki JSON Schema — za ciężka dla CF Workers Free):
  - `sku`: typeof string, length >= 1, length <= 64
  - `name`: typeof string, length >= 1, length <= 128
  - `price`: typeof number, > 0, isFinite
  - `images`: Array.isArray, każdy element typeof string, startsWith('http')
  - Pozostałe pola: typowanie i limity jak w schemacie 4.1

#### `batchService.js`
- Eksportuje: `createBatches(products, batchSize) → Array<Array<ProductPayload>>`
- Prosta implementacja: `products.slice(i, i + batchSize)`
- Brak logiki biznesowej — pure function

#### `queueService.js`
- Eksportuje: `enqueueBatches(queue, batches, requestId, metadata) → Promise<void>`
- Iteruje batches, dla każdego wywołuje `queue.send(message)`
- Message format: QueueMessage (sekcja 4.4)
- **WAŻNE**: `queue.send()` jest asynchroniczne — użyj `Promise.all()` z limitem concurrency
- **LIMIT FREE TIER**: Max 100 messages/s — przy 200 produktach / batch 5 = 40 messages, OK

#### `response.js`
- Eksportuje: `success(data, status)`, `error(message, status)`, `validationError(errors)`
- Zawsze zwraca `new Response(JSON.stringify(body), { headers: { 'Content-Type': 'application/json' }, status })`

#### `logger.js`
- Eksportuje: `log(level, message, context)`, `error(message, context)`, `info(message, context)`
- W CF Workers logowanie przez `console.log()` trafia do Workers Logs
- Format: `[LEVEL] [timestamp] [requestId] message | context_json`

---

## 7. CLOUDFLARE QUEUE

### 7.1 Konfiguracja w `wrangler.toml` (Router)

```toml
[[queues.producers]]
queue = "prestabridge-product-queue"
binding = "PRODUCT_QUEUE"
```

### 7.2 Konfiguracja w `wrangler.toml` (Consumer)

```toml
[[queues.consumers]]
queue = "prestabridge-product-queue"
max_batch_size = 5
max_retries = 3
dead_letter_queue = "prestabridge-dlq"
max_batch_timeout = 30
```

### 7.3 Dead Letter Queue

```toml
[[queues.producers]]
queue = "prestabridge-dlq"
binding = "DLQ"
```

Wiadomości, które nie mogą być przetworzone po 3 próbach, trafiają do DLQ. W przyszłości: Worker monitorujący DLQ z alertami.

---

## 8. CLOUDFLARE WORKER — CONSUMER

### 8.1 Identyfikator: `prestaBridge-consumer`

### 8.2 Zmienne środowiskowe

```toml
name = "prestabridge-consumer"
main = "src/index.js"
compatibility_date = "2024-01-01"

[vars]
PRESTASHOP_ENDPOINT = "https://shop.example.com/module/prestabridge/api"
REQUEST_TIMEOUT_MS = "25000"

[[queues.consumers]]
queue = "prestabridge-product-queue"
max_batch_size = 5
max_retries = 3
dead_letter_queue = "prestabridge-dlq"
max_batch_timeout = 30

# Secrets
# AUTH_SECRET = "..." — wrangler secret
```

### 8.3 Struktura plików Worker Consumer

```
workers/consumer/
├── src/
│   ├── index.js              # Entry point — queue handler
│   ├── handlers/
│   │   └── queueHandler.js   # Logika obsługi batch z Queue
│   ├── services/
│   │   └── prestashopClient.js  # HTTP client do PS endpoint
│   ├── middleware/
│   │   └── authSigner.js     # Generowanie HMAC dla requestów do PS
│   ├── utils/
│   │   ├── hmac.js           # Współdzielone funkcje HMAC
│   │   ├── response.js       # Helpery response
│   │   └── logger.js         # Logger
│   └── schemas/
│       └── responseSchema.js # Walidacja response z PS
├── test/
│   ├── handlers/
│   │   └── queueHandler.test.js
│   ├── services/
│   │   └── prestashopClient.test.js
│   └── fixtures/
│       ├── queueMessages.json
│       └── prestashopResponses.json
├── wrangler.toml
├── package.json
└── vitest.config.js
```

### 8.4 Logika `index.js` — queue handler

```javascript
// PSEUDOKOD
export default {
  async queue(batch, env, ctx) {
    // batch.messages jest tablicą wiadomości z Queue
    for (const message of batch.messages) {
      try {
        const result = await queueHandler.process(message.body, env);
        if (result.success) {
          message.ack();
        } else {
          message.retry({ delaySeconds: calculateBackoff(message.attempts) });
        }
      } catch (error) {
        logger.error('Queue message processing failed', { error, messageId: message.id });
        message.retry({ delaySeconds: calculateBackoff(message.attempts) });
      }
    }
  }
};
```

### 8.5 Szczegóły implementacji

#### `queueHandler.js`
- Eksportuje: `process(messageBody, env) → Promise<{ success: boolean, response?: object }>`
- Deserializuje QueueMessage
- Wywołuje `prestashopClient.sendBatch(products, env)`
- Interpretuje response z PS
- **Jeśli PS zwróci partial success** (niektóre produkty OK, niektóre nie): ack całość, błędy logowane po stronie PS

#### `prestashopClient.js`
- Eksportuje: `sendBatch(products, env) → Promise<PSResponse>`
- Buduje request body w formacie oczekiwanym przez PS module
- Generuje HMAC auth header
- `fetch()` z timeout (AbortController, env.REQUEST_TIMEOUT_MS)
- Parsuje response JSON
- **KRYTYCZNE**: timeout musi być < 30s (worker time limit na free tier)

#### `authSigner.js`
- Eksportuje: `sign(body, secret) → string`
- Identyczna logika jak w Apps Script, ale w Web Crypto API
- Zwraca wartość nagłówka `X-PrestaBridge-Auth`

### 8.6 Backoff strategy

```javascript
function calculateBackoff(attempts) {
  // Exponential backoff: 10s, 30s, 60s
  const delays = [10, 30, 60];
  return delays[Math.min(attempts, delays.length - 1)];
}
```

---

## 9. MODUŁ PRESTASHOP

### 9.1 Nazwa modułu: `prestabridge`
### 9.2 Wersja PS: 8.1+
### 9.3 PHP: 8.1+
### 9.4 Namespace: `PrestaBridge`

### 9.5 Struktura plików modułu

```
prestashop-module/prestabridge/
├── prestabridge.php                    # Główna klasa modułu
├── config.xml                          # Metadane modułu PS
├── logo.png                            # Logo modułu (32x32)
├── composer.json                       # Autoload PSR-4
│
├── controllers/
│   └── front/
│       ├── api.php                     # ModuleFrontController: /module/prestabridge/api
│       └── cron.php                    # ModuleFrontController: /module/prestabridge/cron
│
├── classes/
│   ├── Auth/
│   │   └── HmacAuthenticator.php       # Weryfikacja HMAC-SHA256
│   │
│   ├── Import/
│   │   ├── ProductImporter.php         # Główna klasa importu produktów
│   │   ├── ProductValidator.php        # Walidacja danych produktu
│   │   ├── ProductMapper.php           # Mapowanie payload → Product ObjectModel
│   │   └── DuplicateChecker.php        # Sprawdzanie duplikatów SKU
│   │
│   ├── Image/
│   │   ├── ImageQueueManager.php       # Zarządzanie kolejką zdjęć
│   │   ├── ImageDownloader.php         # Pobieranie zdjęć z URL
│   │   ├── ImageAssigner.php           # Przypisywanie zdjęć do produktów
│   │   └── ImageLockManager.php        # Pessimistic locking dla race conditions
│   │
│   ├── Logging/
│   │   ├── BridgeLogger.php            # Centralna klasa logowania
│   │   └── LogLevel.php                # Enum poziomów logowania
│   │
│   ├── Config/
│   │   └── ModuleConfig.php            # Klasa konfiguracji modułu
│   │
│   ├── Tracking/
│   │   └── ImportTracker.php           # Śledzenie statusu importu
│   │
│   └── Export/                         # PRZYGOTOWANE NA PRZYSZŁOŚĆ
│       ├── ProductExporter.php         # Interface/abstract (przyszłość)
│       └── ExportableInterface.php     # Interface dla eksportowalnych encji
│
├── sql/
│   ├── install.sql                     # CREATE TABLE statements
│   └── uninstall.sql                   # DROP TABLE statements
│
├── views/
│   └── templates/
│       └── admin/
│           ├── configure.tpl           # Formularz konfiguracji
│           └── logs.tpl                # Widok logów
│
├── translations/                       # Tłumaczenia
│   └── pl.php
│
└── tests/
    ├── bootstrap.php                   # PHPUnit bootstrap z mockami PS
    ├── Unit/
    │   ├── Auth/
    │   │   └── HmacAuthenticatorTest.php
    │   ├── Import/
    │   │   ├── ProductImporterTest.php
    │   │   ├── ProductValidatorTest.php
    │   │   ├── ProductMapperTest.php
    │   │   └── DuplicateCheckerTest.php
    │   ├── Image/
    │   │   ├── ImageQueueManagerTest.php
    │   │   ├── ImageDownloaderTest.php
    │   │   ├── ImageAssignerTest.php
    │   │   └── ImageLockManagerTest.php
    │   └── Logging/
    │       └── BridgeLoggerTest.php
    ├── Integration/
    │   ├── ProductImportFlowTest.php
    │   └── ImageProcessingFlowTest.php
    └── Fixtures/
        ├── valid_product_payload.json
        ├── invalid_product_payload.json
        └── duplicate_sku_payload.json
```

### 9.6 Główna klasa modułu: `prestabridge.php`

```php
// PSEUDOKOD STRUKTURY — agent MUSI zachować tę strukturę
class PrestaBridge extends Module
{
    // Stałe konfiguracji
    const CONFIG_AUTH_SECRET = 'PRESTABRIDGE_AUTH_SECRET';
    const CONFIG_IMPORT_CATEGORY = 'PRESTABRIDGE_IMPORT_CATEGORY';
    const CONFIG_WORKER_ENDPOINT = 'PRESTABRIDGE_WORKER_ENDPOINT';
    const CONFIG_OVERWRITE_DUPLICATES = 'PRESTABRIDGE_OVERWRITE_DUPLICATES';
    const CONFIG_CRON_TOKEN = 'PRESTABRIDGE_CRON_TOKEN';
    const CONFIG_IMAGES_PER_CRON = 'PRESTABRIDGE_IMAGES_PER_CRON';
    const CONFIG_IMAGE_TIMEOUT = 'PRESTABRIDGE_IMAGE_TIMEOUT';
    const CONFIG_DEFAULT_ACTIVE = 'PRESTABRIDGE_DEFAULT_ACTIVE';

    public function __construct() { /* name, tab, version, author, need_instance, bootstrap */ }
    public function install() { /* parent::install + SQL + registerHook + default config */ }
    public function uninstall() { /* parent::uninstall + SQL + deleteConfig */ }
    public function getContent() { /* Formularz konfiguracji + widok logów */ }

    // Hooki (przyszłość - eksport do CF)
    // public function hookActionProductUpdate($params) {}
    // public function hookActionProductAdd($params) {}
    // public function hookActionProductDelete($params) {}
}
```

### 9.7 Kontroler API: `controllers/front/api.php`

**Klasa:** `PrestaBridgeApiModuleFrontController extends ModuleFrontController`

**Endpoint:** `https://shop.example.com/module/prestabridge/api`

**Metoda:** POST only

**Flow:**

```
1. $this->ajax = true; $this->content_type = 'application/json';
2. Sprawdź metodę POST
3. HmacAuthenticator::verify($request, ConfigHelper::getAuthSecret())
4. $body = json_decode(file_get_contents('php://input'), true)
5. Waliduj strukturę (musi mieć klucz 'products' jako array)
6. Dla każdego produktu:
   a. ProductValidator::validate($product) → errors[]
   b. Jeśli errors → dodaj do rejected[], loguj, continue
   c. DuplicateChecker::check($product['sku'])
      - Jeśli duplikat i !CONFIG_OVERWRITE_DUPLICATES → skip, loguj
      - Jeśli duplikat i CONFIG_OVERWRITE_DUPLICATES → update mode
   d. ProductImporter::import($product, $updateMode)
   e. ImageQueueManager::enqueue($productId, $product['sku'], $product['images'])
   f. ImportTracker::track($requestId, $productId, $product)
   g. Dodaj do results[]
7. Zwróć PSResponse JSON
```

### 9.8 Kontroler CRON: `controllers/front/cron.php`

**Klasa:** `PrestaBridgeCronModuleFrontController extends ModuleFrontController`

**Endpoint:** `https://shop.example.com/module/prestabridge/cron?token=CRON_TOKEN`

**Metoda:** GET

**Parametry query:**
- `token` (required): Token CRON z konfiguracji modułu
- `limit` (optional): Ile zdjęć przetworzyć (domyślnie z konfiguracji)

**Flow:**

```
1. Sprawdź token CRON
2. $limit = Config::get(CONFIG_IMAGES_PER_CRON) lub param limit
3. ImageLockManager::acquireBatch($limit) → zwraca pending images z lockiem
4. Dla każdego image z batcha:
   a. Sprawdź czy produkt istnieje: Product::existsInDatabase($id_product)
      - Jeśli NIE → oznacz jako 'failed', error "Product not found", continue
   b. ImageDownloader::download($imageUrl, $timeout)
      - Jeśli FAIL → incrementAttempts(), jeśli max → 'failed', else → 'pending' (unlock)
   c. ImageAssigner::assign($id_product, $tmpFile, $position, $is_cover)
      - Używa Image ObjectModel PrestaShop
      - Po sukcesie: oznacz jako 'completed'
   d. ImportTracker::updateImageStatus($id_product)
5. ImageLockManager::releaseExpiredLocks() — safety net
6. Zwróć JSON raport
```

### 9.9 Klasy szczegółowo

#### `HmacAuthenticator.php`
```php
namespace PrestaBridge\Auth;

class HmacAuthenticator
{
    private const TIMESTAMP_TOLERANCE = 300; // 5 minut

    public static function verify(string $authHeader, string $secret): bool
    {
        // 1. Parsuj "timestamp.signature" z nagłówka X-PrestaBridge-Auth
        // 2. Sprawdź timestamp tolerance
        // 3. Odczytaj raw body (php://input)
        // 4. Oblicz HMAC-SHA256: hash_hmac('sha256', $timestamp . '.' . $body, $secret)
        // 5. hash_equals($expectedSignature, $receivedSignature)
        // return bool
    }
}
```

#### `ProductValidator.php`
```php
namespace PrestaBridge\Import;

class ProductValidator
{
    /**
     * @return array{valid: bool, errors: string[]}
     */
    public static function validate(array $product): array
    {
        $errors = [];
        // REQUIRED fields:
        // - sku: string, not empty, max 64
        // - name: string, not empty, max 128
        // - price: numeric, > 0
        // OPTIONAL fields (validate only if present):
        // - images: array of strings starting with http
        // - quantity: integer >= 0
        // - ean13: string, max 13, digits only
        // - weight: numeric >= 0
        // - description: string, max 65535
        // - description_short: string, max 800
        // - active: boolean
        return ['valid' => empty($errors), 'errors' => $errors];
    }
}
```

#### `ProductMapper.php`
```php
namespace PrestaBridge\Import;

use Product;
use PrestaBridge\Config\ModuleConfig;

class ProductMapper
{
    /**
     * Mapuje payload na Product ObjectModel.
     * UWAGA: NIE wywołuje save() — to robi ProductImporter.
     */
    public static function mapToProduct(array $payload, ?Product $existing = null): Product
    {
        $product = $existing ?? new Product();
        $id_lang = (int) Configuration::get('PS_LANG_DEFAULT');
        $id_shop = (int) Context::getContext()->shop->id;

        $product->reference = $payload['sku'];
        $product->name[$id_lang] = $payload['name'];
        $product->price = (float) $payload['price'];
        $product->description[$id_lang] = $payload['description'] ?? '';
        $product->description_short[$id_lang] = $payload['description_short'] ?? '';
        $product->quantity = (int) ($payload['quantity'] ?? 0);
        $product->ean13 = $payload['ean13'] ?? '';
        $product->weight = (float) ($payload['weight'] ?? 0);
        $product->active = (bool) ($payload['active'] ?? ModuleConfig::getDefaultActive());
        $product->meta_title[$id_lang] = $payload['meta_title'] ?? '';
        $product->meta_description[$id_lang] = $payload['meta_description'] ?? '';

        // Kategoria importu
        $importCategoryId = (int) ModuleConfig::getImportCategory();
        $product->id_category_default = $importCategoryId;

        // Shop association
        $product->id_shop_default = $id_shop;

        return $product;
    }
}
```

#### `ProductImporter.php`
```php
namespace PrestaBridge\Import;

use Product;
use StockAvailable;
use PrestaBridge\Logging\BridgeLogger;

class ProductImporter
{
    /**
     * @return array{success: bool, productId: int, status: string, error?: string}
     */
    public static function import(array $payload, bool $updateMode = false): array
    {
        try {
            $existing = null;
            if ($updateMode) {
                $existingId = DuplicateChecker::getProductIdBySku($payload['sku']);
                if ($existingId) {
                    $existing = new Product($existingId);
                }
            }

            $product = ProductMapper::mapToProduct($payload, $existing);

            if ($updateMode && $existing) {
                $result = $product->update();
                $status = 'updated';
            } else {
                $result = $product->add();
                $status = 'created';
            }

            if (!$result) {
                throw new \RuntimeException('Product save failed for SKU: ' . $payload['sku']);
            }

            // Przypisz kategorię
            $product->updateCategories([(int) ModuleConfig::getImportCategory()]);

            // Ustaw stock
            StockAvailable::setQuantity(
                (int) $product->id,
                0, // id_product_attribute
                (int) ($payload['quantity'] ?? 0)
            );

            BridgeLogger::info('Product ' . $status, [
                'sku' => $payload['sku'],
                'productId' => $product->id
            ], 'import', $payload['sku'], (int) $product->id);

            return [
                'success' => true,
                'productId' => (int) $product->id,
                'status' => $status
            ];
        } catch (\Exception $e) {
            BridgeLogger::error('Product import failed: ' . $e->getMessage(), [
                'sku' => $payload['sku'] ?? 'unknown',
                'trace' => $e->getTraceAsString()
            ], 'import', $payload['sku'] ?? null);

            return [
                'success' => false,
                'productId' => 0,
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }
    }
}
```

#### `DuplicateChecker.php`
```php
namespace PrestaBridge\Import;

use Db;

class DuplicateChecker
{
    /**
     * Sprawdza czy produkt o danym SKU istnieje.
     */
    public static function exists(string $sku): bool
    {
        return self::getProductIdBySku($sku) !== null;
    }

    /**
     * Zwraca id_product lub null.
     */
    public static function getProductIdBySku(string $sku): ?int
    {
        $result = Db::getInstance()->getValue(
            'SELECT id_product FROM ' . _DB_PREFIX_ . 'product
             WHERE reference = \'' . pSQL($sku) . '\'
             LIMIT 1'
        );
        return $result ? (int) $result : null;
    }
}
```

#### `ImageQueueManager.php`
```php
namespace PrestaBridge\Image;

use Db;

class ImageQueueManager
{
    /**
     * Dodaje URLe zdjęć do kolejki.
     * @return int Liczba dodanych zdjęć
     */
    public static function enqueue(int $productId, string $sku, array $imageUrls): int
    {
        if (empty($imageUrls)) {
            return 0;
        }

        $count = 0;
        foreach ($imageUrls as $position => $url) {
            $isCover = ($position === 0) ? 1 : 0;

            Db::getInstance()->insert('prestabridge_image_queue', [
                'id_product' => $productId,
                'sku' => pSQL($sku),
                'image_url' => pSQL($url),
                'position' => (int) $position,
                'is_cover' => (int) $isCover,
                'status' => 'pending',
                'attempts' => 0,
                'max_attempts' => 3,
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Pobiera pending images z lockiem.
     * Pessimistic locking przez UPDATE z lock_token.
     */
    public static function acquireBatch(int $limit): array
    {
        $lockToken = bin2hex(random_bytes(16));
        $lockTimeout = date('Y-m-d H:i:s', strtotime('-10 minutes'));

        // Najpierw zwalniamy stare locki (safety net)
        Db::getInstance()->execute(
            'UPDATE ' . _DB_PREFIX_ . 'prestabridge_image_queue
             SET lock_token = NULL, locked_at = NULL, status = "pending"
             WHERE status = "processing"
             AND locked_at < "' . pSQL($lockTimeout) . '"'
        );

        // Lockujemy batch
        Db::getInstance()->execute(
            'UPDATE ' . _DB_PREFIX_ . 'prestabridge_image_queue
             SET lock_token = "' . pSQL($lockToken) . '",
                 locked_at = NOW(),
                 status = "processing"
             WHERE status = "pending"
             AND attempts < max_attempts
             ORDER BY created_at ASC
             LIMIT ' . (int) $limit
        );

        // Pobieramy zlockowane
        return Db::getInstance()->executeS(
            'SELECT * FROM ' . _DB_PREFIX_ . 'prestabridge_image_queue
             WHERE lock_token = "' . pSQL($lockToken) . '"'
        );
    }

    /**
     * Oznacza image jako completed.
     */
    public static function markCompleted(int $id): void
    {
        Db::getInstance()->update('prestabridge_image_queue', [
            'status' => 'completed',
            'lock_token' => null,
            'locked_at' => null,
        ], 'id_image_queue = ' . (int) $id);
    }

    /**
     * Oznacza image jako failed.
     */
    public static function markFailed(int $id, string $error): void
    {
        Db::getInstance()->execute(
            'UPDATE ' . _DB_PREFIX_ . 'prestabridge_image_queue
             SET status = CASE WHEN attempts + 1 >= max_attempts THEN "failed" ELSE "pending" END,
                 attempts = attempts + 1,
                 error_message = "' . pSQL($error) . '",
                 lock_token = NULL,
                 locked_at = NULL
             WHERE id_image_queue = ' . (int) $id
        );
    }
}
```

#### `ImageDownloader.php`
```php
namespace PrestaBridge\Image;

use PrestaBridge\Config\ModuleConfig;

class ImageDownloader
{
    /**
     * Pobiera zdjęcie z URL do pliku tymczasowego.
     * @return array{success: bool, tmpPath?: string, mimeType?: string, error?: string}
     */
    public static function download(string $url): array
    {
        $timeout = ModuleConfig::getImageTimeout();

        $context = stream_context_create([
            'http' => [
                'timeout' => $timeout,
                'method' => 'GET',
                'header' => 'User-Agent: PrestaBridge/1.0',
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $tmpPath = tempnam(sys_get_temp_dir(), 'pb_img_');

        try {
            $content = @file_get_contents($url, false, $context);
            if ($content === false) {
                throw new \RuntimeException('Failed to download image from: ' . $url);
            }

            file_put_contents($tmpPath, $content);

            // Weryfikacja typu MIME
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($tmpPath);
            $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

            if (!in_array($mimeType, $allowedMimes)) {
                unlink($tmpPath);
                throw new \RuntimeException('Invalid image MIME type: ' . $mimeType);
            }

            return [
                'success' => true,
                'tmpPath' => $tmpPath,
                'mimeType' => $mimeType,
            ];
        } catch (\Exception $e) {
            if (file_exists($tmpPath)) {
                unlink($tmpPath);
            }
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
```

#### `ImageAssigner.php`
```php
namespace PrestaBridge\Image;

use Image;
use Product;
use ImageManager;
use PrestaBridge\Logging\BridgeLogger;

class ImageAssigner
{
    /**
     * Przypisuje zdjęcie do produktu.
     * KRYTYCZNE: Sprawdza istnienie produktu przed operacją (race condition safety).
     */
    public static function assign(
        int $productId,
        string $tmpFilePath,
        int $position,
        bool $isCover
    ): array {
        // RACE CONDITION CHECK: Produkt musi istnieć
        if (!Product::existsInDatabase($productId, 'product')) {
            return [
                'success' => false,
                'error' => "Product ID $productId does not exist in database"
            ];
        }

        try {
            $image = new Image();
            $image->id_product = $productId;
            $image->position = $position;
            $image->cover = $isCover ? 1 : 0;

            // Jeśli cover, usuń cover flag z innych zdjęć
            if ($isCover) {
                Image::deleteCover($productId);
            }

            if (!$image->add()) {
                throw new \RuntimeException('Failed to create Image record');
            }

            // Kopiuj plik do prawidłowej lokalizacji PS
            $newPath = $image->getPathForCreation();

            // Użyj ImageManager do resize
            $mimeType = mime_content_type($tmpFilePath);
            $extension = image_type_to_extension(exif_imagetype($tmpFilePath), false);

            if (!copy($tmpFilePath, $newPath . '.' . $extension)) {
                $image->delete();
                throw new \RuntimeException('Failed to copy image file');
            }

            // Generuj thumbnails
            $types = ImageType::getImagesTypes('products');
            foreach ($types as $type) {
                ImageManager::resize(
                    $newPath . '.' . $extension,
                    $newPath . '-' . stripslashes($type['name']) . '.' . $extension,
                    (int) $type['width'],
                    (int) $type['height'],
                    $extension
                );
            }

            // Cleanup
            @unlink($tmpFilePath);

            return [
                'success' => true,
                'imageId' => (int) $image->id,
            ];
        } catch (\Exception $e) {
            @unlink($tmpFilePath);
            BridgeLogger::error('Image assign failed', [
                'productId' => $productId,
                'position' => $position,
                'error' => $e->getMessage()
            ], 'image', null, $productId);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
```

#### `BridgeLogger.php`
```php
namespace PrestaBridge\Logging;

use Db;
use PrestaBridge\Config\ModuleConfig;

class BridgeLogger
{
    public static function log(
        string $level,
        string $message,
        array $context = [],
        string $source = 'system',
        ?string $sku = null,
        ?int $productId = null,
        ?string $requestId = null
    ): void {
        Db::getInstance()->insert('prestabridge_log', [
            'level' => pSQL($level),
            'source' => pSQL($source),
            'message' => pSQL($message),
            'context' => pSQL(json_encode($context)),
            'sku' => $sku ? pSQL($sku) : null,
            'id_product' => $productId ? (int) $productId : null,
            'request_id' => $requestId ? pSQL($requestId) : null,
        ]);
    }

    public static function debug(string $msg, array $ctx = [], string $src = 'system', ?string $sku = null, ?int $pid = null): void
    { self::log('debug', $msg, $ctx, $src, $sku, $pid); }

    public static function info(string $msg, array $ctx = [], string $src = 'system', ?string $sku = null, ?int $pid = null): void
    { self::log('info', $msg, $ctx, $src, $sku, $pid); }

    public static function warning(string $msg, array $ctx = [], string $src = 'system', ?string $sku = null, ?int $pid = null): void
    { self::log('warning', $msg, $ctx, $src, $sku, $pid); }

    public static function error(string $msg, array $ctx = [], string $src = 'system', ?string $sku = null, ?int $pid = null): void
    { self::log('error', $msg, $ctx, $src, $sku, $pid); }

    public static function critical(string $msg, array $ctx = [], string $src = 'system', ?string $sku = null, ?int $pid = null): void
    { self::log('critical', $msg, $ctx, $src, $sku, $pid); }

    /**
     * Pobiera logi z paginacją i filtrami.
     */
    public static function getLogs(
        int $page = 1,
        int $perPage = 50,
        ?string $level = null,
        ?string $source = null
    ): array {
        $where = '1=1';
        if ($level) $where .= ' AND level = "' . pSQL($level) . '"';
        if ($source) $where .= ' AND source = "' . pSQL($source) . '"';

        $total = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'prestabridge_log WHERE ' . $where
        );

        $offset = ($page - 1) * $perPage;
        $logs = Db::getInstance()->executeS(
            'SELECT * FROM ' . _DB_PREFIX_ . 'prestabridge_log
             WHERE ' . $where . '
             ORDER BY created_at DESC
             LIMIT ' . (int) $offset . ', ' . (int) $perPage
        );

        return [
            'logs' => $logs,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => ceil($total / $perPage),
        ];
    }

    /**
     * Czyści logi starsze niż $days dni.
     */
    public static function clearLogs(?int $days = null): int
    {
        if ($days === null) {
            $affected = Db::getInstance()->execute(
                'DELETE FROM ' . _DB_PREFIX_ . 'prestabridge_log'
            );
        } else {
            $affected = Db::getInstance()->execute(
                'DELETE FROM ' . _DB_PREFIX_ . 'prestabridge_log
                 WHERE created_at < DATE_SUB(NOW(), INTERVAL ' . (int) $days . ' DAY)'
            );
        }
        return (int) $affected;
    }
}
```

#### `ModuleConfig.php`
```php
namespace PrestaBridge\Config;

use Configuration;

class ModuleConfig
{
    // Gettery — KAŻDA WARTOŚĆ KONFIGURACJI MA GETTER
    public static function getAuthSecret(): string
    { return (string) Configuration::get('PRESTABRIDGE_AUTH_SECRET'); }

    public static function getImportCategory(): int
    { return (int) Configuration::get('PRESTABRIDGE_IMPORT_CATEGORY'); }

    public static function getWorkerEndpoint(): string
    { return (string) Configuration::get('PRESTABRIDGE_WORKER_ENDPOINT'); }

    public static function getOverwriteDuplicates(): bool
    { return (bool) Configuration::get('PRESTABRIDGE_OVERWRITE_DUPLICATES'); }

    public static function getCronToken(): string
    { return (string) Configuration::get('PRESTABRIDGE_CRON_TOKEN'); }

    public static function getImagesPerCron(): int
    { return (int) Configuration::get('PRESTABRIDGE_IMAGES_PER_CRON') ?: 10; }

    public static function getImageTimeout(): int
    { return (int) Configuration::get('PRESTABRIDGE_IMAGE_TIMEOUT') ?: 30; }

    public static function getDefaultActive(): bool
    { return (bool) Configuration::get('PRESTABRIDGE_DEFAULT_ACTIVE'); }

    // Instalacja domyślnych wartości
    public static function installDefaults(): void
    {
        $defaults = [
            'PRESTABRIDGE_AUTH_SECRET' => bin2hex(random_bytes(32)),
            'PRESTABRIDGE_IMPORT_CATEGORY' => 2, // Home
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

    // Usunięcie konfiguracji
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
```

### 9.10 Konfiguracja CRON (na VPS)

```bash
# Przetwarzaj 10 zdjęć co 2 minuty
*/2 * * * * curl -s "https://shop.example.com/module/prestabridge/cron?token=CRON_TOKEN&limit=10" > /dev/null 2>&1
```

### 9.11 Panel administracyjny modułu

#### Zakładki:
1. **Konfiguracja** — formularz z polami konfiguracji
2. **Logi** — tabela logów z filtrami (level, source), paginacją, przyciskiem czyszczenia
3. **Status importu** — tabela z import tracking (przyszłość: monitoring)

#### Pola konfiguracji:

| Pole | Typ | Opis | Domyślna wartość |
|------|-----|------|-----------------|
| Auth Secret | text (readonly, z przyciskiem regeneracji) | Token HMAC | Auto-generated |
| Import Category | select (z listy kategorii PS) | Kategoria dla importowanych produktów | Home |
| Worker Endpoint | text/url | URL workera CF Router | — |
| Overwrite Duplicates | switch/checkbox | Czy nadpisywać istniejące produkty przy tym samym SKU | false |
| CRON Token | text (readonly, z przyciskiem regeneracji) | Token autoryzacji CRON | Auto-generated |
| Images Per CRON | number | Ile zdjęć per wywołanie CRON | 10 |
| Image Timeout | number (sekundy) | Timeout pobierania zdjęcia | 30 |
| Default Active | switch/checkbox | Domyślny stan produktu po imporcie | false |

---

## 10. BEZPIECZEŃSTWO

### 10.1 Autentykacja HMAC-SHA256

**Dlaczego HMAC zamiast prostego tokenu:**
- Nie przesyłamy sekretu w żądaniu (w przeciwieństwie do Bearer token)
- Podpis zależy od body — zapobiega replay z innym payloadem
- Timestamp zapobiega replay starych requestów
- Brak stanu na workerze (nie trzeba sesji/DB)

**Przepływ:**
```
1. Nadawca (Apps Script / CF Worker):
   timestamp = current_unix_timestamp
   body = JSON.stringify(payload)
   signature = HMAC-SHA256(timestamp + "." + body, SECRET)
   header: X-PrestaBridge-Auth: timestamp.signature

2. Odbiorca (CF Worker / PrestaShop):
   Parsuj nagłówek → timestamp, signature
   Sprawdź |current_time - timestamp| < TOLERANCE (300s)
   Oblicz expected = HMAC-SHA256(timestamp + "." + rawBody, SECRET)
   Porównaj constant-time: expected === signature
```

### 10.2 Dlaczego NIE JWT / OAuth na tym etapie
- JWT wymaga parsowania, weryfikacji, zarządzania kluczami — overhead dla Workers Free
- OAuth wymaga serwera autoryzacji — zbyt złożony dla MVP
- HMAC jest lekki, bezpieczny, stateless

### 10.3 Przyszłe rozszerzenia bezpieczeństwa (przygotowane architektonicznie)
- Middleware pattern pozwala na łatwą podmianę mechanizmu auth
- Interface `AuthenticatorInterface` w PHP z metodą `verify()` — przyszła implementacja JWT/OAuth
- CF Worker: `authMiddleware.js` jako osobny moduł — łatwa podmiana

### 10.4 Zabezpieczenia dodatkowe
- CORS: Worker Router odpowiada tylko na origin z konfiguracji (lub brak CORS dla server-to-server)
- Rate limiting: Worker Router — max 10 req/min per IP (prosty InMemory counter, opcjonalny)
- CRON endpoint: osobny token, GET only, brak body
- Wszystkie endpointy: force HTTPS

---

## 11. LOGOWANIE BŁĘDÓW

### 11.1 Dwutorowe logowanie

| Warstwa | Mechanizm | Cel |
|---------|-----------|-----|
| CF Workers | `console.log/error()` → Workers Logs | Monitoring w dashboard CF |
| CF Workers | Response body z raportem | Informacja zwrotna do orkiestratora |
| PrestaShop | Tabela `prestabridge_log` | Trwały log, dostępny z panelu admin |

### 11.2 Poziomy logów

| Poziom | Użycie |
|--------|--------|
| debug | Szczegóły operacji (tylko dev) |
| info | Pomyślne operacje (produkt created, image processed) |
| warning | Pominięte produkty (duplikat, brak danych opcjonalnych) |
| error | Błędy operacji (zapis failed, download failed) |
| critical | Błędy systemowe (DB down, auth failure) |

### 11.3 Format logów CF Workers

```
[ERROR] [2026-02-28T12:00:00Z] [req-abc123] Product import failed | {"sku":"TEST001","error":"DB connection timeout"}
```

---

## 12. TESTY

### 12.1 Strategia testów

| Warstwa | Framework | Typ testów |
|---------|-----------|-----------|
| CF Workers (Router + Consumer) | Vitest | Unit + Integration |
| PrestaShop Module | PHPUnit 10 | Unit + Integration |
| Google Apps Script | clasp + GAS mocks | Basic smoke tests |
| E2E | Shell script + curl | Przepływ end-to-end |

### 12.2 Testy CF Workers — scenariusze

#### Router — authMiddleware
| ID | Scenariusz | Input | Oczekiwany wynik |
|----|-----------|-------|-----------------|
| R-A1 | Brak nagłówka auth | Request bez X-PrestaBridge-Auth | 401, `{ error: "Missing auth header" }` |
| R-A2 | Nieprawidłowy format nagłówka | "invalid-format" | 401, `{ error: "Invalid auth format" }` |
| R-A3 | Wygasły timestamp | timestamp sprzed 10 min | 401, `{ error: "Request expired" }` |
| R-A4 | Nieprawidłowa sygnatura | prawidłowy timestamp, zły podpis | 401, `{ error: "Invalid signature" }` |
| R-A5 | Prawidłowa autoryzacja | poprawny timestamp + HMAC | { valid: true } |
| R-A6 | Timestamp z przyszłości | timestamp + 600s | 401, `{ error: "Request expired" }` |

#### Router — validationService
| ID | Scenariusz | Input | Oczekiwany wynik |
|----|-----------|-------|-----------------|
| R-V1 | Prawidłowy produkt (min fields) | { sku, name, price } | { valid: true, errors: [] } |
| R-V2 | Prawidłowy produkt (all fields) | Pełny payload | { valid: true, errors: [] } |
| R-V3 | Brak SKU | { name, price } | { valid: false, errors: ["sku is required"] } |
| R-V4 | Brak nazwy | { sku, price } | { valid: false, errors: ["name is required"] } |
| R-V5 | Brak ceny | { sku, name } | { valid: false, errors: ["price is required"] } |
| R-V6 | Cena = 0 | { sku, name, price: 0 } | { valid: false, errors: ["price must be > 0"] } |
| R-V7 | Cena ujemna | { sku, name, price: -10 } | { valid: false, errors: ["price must be > 0"] } |
| R-V8 | SKU za długie | { sku: "x".repeat(65), ... } | { valid: false } |
| R-V9 | Images nie jest tablicą | { ..., images: "url" } | { valid: false } |
| R-V10 | Images z nieprawidłowym URL | { ..., images: ["not-a-url"] } | { valid: false } |
| R-V11 | Pusty body | {} | { valid: false, errors: ["products is required"] } |
| R-V12 | Products nie jest tablicą | { products: "string" } | { valid: false } |
| R-V13 | Pusta tablica products | { products: [] } | { valid: false } |
| R-V14 | Powyżej limitu produktów | { products: [1001 items] } | { valid: false } |

#### Router — batchService
| ID | Scenariusz | Input | Oczekiwany wynik |
|----|-----------|-------|-----------------|
| R-B1 | 10 produktów, batch 5 | 10 items, batchSize=5 | 2 batche po 5 |
| R-B2 | 7 produktów, batch 5 | 7 items, batchSize=5 | 2 batche (5+2) |
| R-B3 | 1 produkt, batch 5 | 1 item | 1 batch z 1 item |
| R-B4 | 50 produktów, batch 1 | 50 items, batchSize=1 | 50 batchy po 1 |
| R-B5 | 0 produktów | [] | [] |

#### Router — Integration (importHandler)
| ID | Scenariusz | Oczekiwany wynik |
|----|-----------|-----------------|
| R-I1 | Happy path: 10 valid products, batch 5 | 200, 10 accepted, 0 rejected, 2 batches |
| R-I2 | Mixed: 8 valid + 2 invalid | 200, 8 accepted, 2 rejected z errors |
| R-I3 | All invalid | 200, 0 accepted, all rejected |
| R-I4 | Metoda GET | 405 Method Not Allowed |
| R-I5 | Path /unknown | 404 Not Found |
| R-I6 | Invalid JSON body | 400 Bad Request |
| R-I7 | Queue send failure | 500 z error details |

#### Consumer — scenariusze
| ID | Scenariusz | Oczekiwany wynik |
|----|-----------|-----------------|
| C-1 | Happy path: batch 5 products | ack, PS response OK |
| C-2 | PS endpoint timeout | retry z backoff |
| C-3 | PS endpoint 500 | retry z backoff |
| C-4 | PS endpoint 401 | retry (może secret się zmienił) |
| C-5 | PS partial success | ack (błędy logowane po stronie PS) |
| C-6 | Malformed queue message | ack (nie retry — nigdy się nie naprawi) |

### 12.3 Testy PrestaShop — scenariusze

#### HmacAuthenticator
| ID | Scenariusz | Oczekiwany wynik |
|----|-----------|-----------------|
| P-A1 | Prawidłowy HMAC | true |
| P-A2 | Nieprawidłowy HMAC | false |
| P-A3 | Wygasły timestamp | false |
| P-A4 | Brak nagłówka | false |

#### ProductValidator
| ID | Scenariusz | Oczekiwany wynik |
|----|-----------|-----------------|
| P-V1 | Minimalny prawidłowy | valid: true |
| P-V2 | Pełny prawidłowy | valid: true |
| P-V3–V14 | Identyczne z R-V3–V14 | Analogiczne |

#### ProductImporter
| ID | Scenariusz | Oczekiwany wynik |
|----|-----------|-----------------|
| P-I1 | Nowy produkt | status: created, productId > 0 |
| P-I2 | Update produktu | status: updated, ten sam productId |
| P-I3 | Duplikat, overwrite=false | status: skipped |
| P-I4 | Duplikat, overwrite=true | status: updated |
| P-I5 | Błąd DB | status: error, log |

#### ImageQueueManager
| ID | Scenariusz | Oczekiwany wynik |
|----|-----------|-----------------|
| P-IM1 | Enqueue 3 images | 3 rekordy w pending |
| P-IM2 | Enqueue puste images | 0 rekordów |
| P-IM3 | AcquireBatch limit 5 | Max 5 rekordów z lockiem |
| P-IM4 | AcquireBatch gdy puste | [] |
| P-IM5 | MarkCompleted | status = completed, lock = null |
| P-IM6 | MarkFailed last attempt | status = failed |
| P-IM7 | MarkFailed not last | status = pending, attempts++ |
| P-IM8 | Expired lock release | Stare locki zwolnione |

#### ImageAssigner
| ID | Scenariusz | Oczekiwany wynik |
|----|-----------|-----------------|
| P-IA1 | Prawidłowe zdjęcie, produkt istnieje | success: true, imageId > 0 |
| P-IA2 | Produkt nie istnieje (race condition!) | success: false, error "not exist" |
| P-IA3 | Nieprawidłowy plik (nie obraz) | success: false, error |
| P-IA4 | Cover image | cover = 1, inne cover usunięte |

### 12.4 Konfiguracja Vitest (CF Workers)

```javascript
// vitest.config.js
import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    globals: true,
    environment: 'miniflare', // Emulacja CF Workers
    coverage: {
      provider: 'v8',
      reporter: ['text', 'json', 'html'],
      thresholds: {
        branches: 80,
        functions: 80,
        lines: 80,
        statements: 80,
      }
    }
  }
});
```

### 12.5 Konfiguracja PHPUnit (PrestaShop)

```xml
<!-- phpunit.xml -->
<phpunit bootstrap="tests/bootstrap.php" colors="true">
  <testsuites>
    <testsuite name="Unit">
      <directory>tests/Unit</directory>
    </testsuite>
    <testsuite name="Integration">
      <directory>tests/Integration</directory>
    </testsuite>
  </testsuites>
  <coverage>
    <include>
      <directory>classes</directory>
    </include>
  </coverage>
</phpunit>
```

### 12.6 Bootstrap PHPUnit (mocki PrestaShop)

```php
// tests/bootstrap.php
// Definiujemy stałe PS
if (!defined('_DB_PREFIX_')) define('_DB_PREFIX_', 'ps_');
if (!defined('_PS_VERSION_')) define('_PS_VERSION_', '8.1.0');

// Mock klas PS
// UWAGA: Agent MUSI zaimplementować pełne mocki następujących klas:
// - Configuration (get, updateValue, deleteByName, hasKey)
// - Db (getInstance → mock z insert, update, execute, executeS, getValue)
// - Product (add, update, existsInDatabase, updateCategories, id)
// - Image (add, delete, deleteCover, getPathForCreation, id)
// - StockAvailable (setQuantity)
// - ImageType (getImagesTypes)
// - ImageManager (resize)
// - Context (getContext → mock z shop->id, language->id)
// - Module (parent class mock)
// - pSQL function
// - Tools (getValue)
```

---

## 13. LIMITY I OPTYMALIZACJA

### 13.1 CloudFlare Free Tier

| Zasób | Limit | Nasz usage (worst case) |
|-------|-------|------------------------|
| CPU time | 10ms per request | ~2-5ms (walidacja + enqueue) |
| Worker execution | 30s wall time | ~1-3s (Router), ~25s (Consumer z fetch do PS) |
| Requests/day | 100,000 | ~100-500 (per import session) |
| Queue messages | 1,000/s send | ~40/s (200 products / batch 5) |
| Queue consumers | 5 concurrent | 1-2 (nasz workload) |
| Queue message size | 128 KB | ~1-5 KB per batch |
| Workers | 20 (nasz limit) | 2 (Router + Consumer) |

### 13.2 Optymalizacje Worker Router
- Walidacja synchroniczna (pure CPU, szybka)
- Batch creation: O(n) — prosta iteracja
- Queue.send: parallelize z Promise.all (ale max concurrent na Free = bez explicit limit, Queue obsłuży)
- NIE logować do D1/R2 (oszczędność CPU) — tylko console.log

### 13.3 Optymalizacje Worker Consumer
- Timeout na fetch do PS: 25s (zostaje 5s na overhead)
- Nie parsuj response jeśli status !== 200
- Prosty retry z backoff — nie próbuj "naprawiać" danych

### 13.4 Kiedy rozważyć Paid Workers ($5/mc)
- Jeśli import > 1000 produktów dziennie regularnie
- Jeśli potrzebne D1 do śledzenia stanów
- Jeśli potrzebne R2 do cache'owania zdjęć
- CPU limit 10ms jest przekraczany (monitoring w CF dashboard)

---

## 14. PLAN REALIZACJI — ETAPY

### Etap 1: Fundament (Shared + Schemas)
**Czas: 2h** | **Pliki: `/shared/`**
1. Stwórz JSON Schema pliki
2. Stwórz shared utils (HMAC, response format)
3. Stwórz fixtures testowe (valid/invalid products)

### Etap 2: CF Worker Router
**Czas: 4h** | **Pliki: `/workers/router/`**
1. `package.json`, `wrangler.toml`, `vitest.config.js`
2. `utils/hmac.js`, `utils/response.js`, `utils/logger.js`
3. `middleware/authMiddleware.js` + testy
4. `services/validationService.js` + testy
5. `services/batchService.js` + testy
6. `services/queueService.js` + testy
7. `handlers/importHandler.js` + testy integracyjne
8. `src/index.js` (entry point)

### Etap 3: CF Worker Consumer
**Czas: 3h** | **Pliki: `/workers/consumer/`**
1. `package.json`, `wrangler.toml`, `vitest.config.js`
2. `middleware/authSigner.js` + testy
3. `services/prestashopClient.js` + testy
4. `handlers/queueHandler.js` + testy
5. `src/index.js` (entry point)

### Etap 4: PrestaShop Module — Core
**Czas: 6h** | **Pliki: `/prestashop-module/prestabridge/`**
1. `composer.json`, `config.xml`
2. `sql/install.sql`, `sql/uninstall.sql`
3. `classes/Config/ModuleConfig.php` + testy
4. `classes/Logging/BridgeLogger.php` + testy
5. `classes/Auth/HmacAuthenticator.php` + testy
6. `classes/Import/ProductValidator.php` + testy
7. `classes/Import/DuplicateChecker.php` + testy
8. `classes/Import/ProductMapper.php` + testy
9. `classes/Import/ProductImporter.php` + testy
10. `prestabridge.php` (install/uninstall/getContent)

### Etap 5: PrestaShop Module — Images
**Czas: 4h** | **Pliki: `classes/Image/`**
1. `ImageQueueManager.php` + testy
2. `ImageDownloader.php` + testy
3. `ImageAssigner.php` + testy
4. `ImageLockManager.php` (jeśli wyodrębniony) + testy

### Etap 6: PrestaShop Module — Controllers
**Czas: 3h** | **Pliki: `controllers/`**
1. `controllers/front/api.php` + testy integracyjne
2. `controllers/front/cron.php` + testy integracyjne

### Etap 7: PrestaShop Module — Admin
**Czas: 2h** | **Pliki: `views/`**
1. `views/templates/admin/configure.tpl`
2. `views/templates/admin/logs.tpl`
3. Logika getContent() w prestabridge.php

### Etap 8: Google Apps Script
**Czas: 2h** | **Pliki: `/apps-script/`**
1. `Code.gs` — wszystkie funkcje
2. `Settings.html` — dialog ustawień

### Etap 9: Integration Testing & Deploy
**Czas: 3h**
1. Deploy Workers na CF (wrangler publish)
2. Deploy moduł na VPS
3. E2E test: Sheets → CF → PS
4. Setup CRON
5. Monitoring setup

### Etap 10: Dokumentacja finalna
**Czas: 1h**
1. README.md per komponent
2. CHANGELOG.md
3. DEPLOYMENT.md

**Łączny szacowany czas: ~30h**

---

## 15. PRZYSZŁY ROZWÓJ — PaaS

### 15.1 Architektura gotowa na rozszerzenia

System jest zaprojektowany z myślą o przyszłym przekształceniu w platformę PaaS. Kluczowe punkty przygotowania:

#### 15.1.1 Eksport z PrestaShop do CloudFlare
- `ExportableInterface.php` — interface dla dowolnych encji PS
- `ProductExporter.php` — abstrakcyjna klasa eksportu
- Hooki PS: `hookActionProductUpdate/Add/Delete` — trigery eksportu
- Docelowy target: CF D1 (SQLite) jako centralna baza danych

#### 15.1.2 CF D1 jako hub danych
```
PrestaShop → D1 (source of truth) → Inne systemy
Inne systemy → D1 → PrestaShop
```

#### 15.1.3 CF R2 jako storage zdjęć
- Zamiast bezpośredniego pobierania z URL → cache w R2
- CDN przez CF dla zdjęć

#### 15.1.4 CF Workers AI
- Automatyczne generowanie opisów produktów
- Tłumaczenie opisów
- Analiza i optymalizacja zdjęć

#### 15.1.5 Multi-tenant
- Identyfikacja tenant per Worker Binding
- Izolacja danych per D1 database

### 15.2 Co NIE jest realizowane w MVP
- Eksport z PS do CF
- D1 jako hub
- R2 storage
- Workers AI
- Multi-tenant
- OAuth/JWT auth
- Webhook callbacks
- Dashboard monitoring CF

---

## 16. KONWENCJE KODOWANIA

### 16.1 JavaScript (CF Workers)

| Zasada | Szczegół |
|--------|---------|
| Format modułów | ES Modules (export/import) |
| Nazewnictwo plików | camelCase.js |
| Nazewnictwo klas | PascalCase |
| Nazewnictwo funkcji | camelCase |
| Nazewnictwo stałych | UPPER_SNAKE_CASE |
| Komentarze | JSDoc dla każdej eksportowanej funkcji |
| Error handling | try/catch z strukturyzowanym logowaniem |
| Async | async/await (nie .then() chain) |
| Linting | ESLint z config "recommended" |

### 16.2 PHP (PrestaShop Module)

| Zasada | Szczegół |
|--------|---------|
| Standard | PSR-12 |
| Namespace | `PrestaBridge\{Subdir}` |
| Autoload | PSR-4 via composer.json |
| Nazewnictwo klas | PascalCase |
| Nazewnictwo metod | camelCase |
| Nazewnictwo stałych | UPPER_SNAKE_CASE |
| Type hints | Pełne (return types, param types) PHP 8.1 |
| Error handling | Exceptions z custom exception classes |
| Komentarze | PHPDoc dla każdej publicznej metody |
| DB queries | Zawsze pSQL() dla parametrów, (int) dla numerycznych |
| PS compatibility | Natywne klasy PS (Product, Image, Configuration, Db) |

### 16.3 Zasady SOLID w projekcie

| Zasada | Realizacja |
|--------|-----------|
| S — Single Responsibility | Każda klasa ma jedno zadanie (Validator, Mapper, Importer, Logger — osobno) |
| O — Open/Closed | Middleware pattern (auth), ExportableInterface (przyszłość) |
| L — Liskov Substitution | Interface auth z zamiennymi implementacjami (HMAC → JWT) |
| I — Interface Segregation | Małe, skupione interfejsy (ExportableInterface, nie MonsterInterface) |
| D — Dependency Inversion | Config przez klasy abstrakcji (ModuleConfig), nie hardcoded |

---

## 17. DRZEWO PLIKÓW

```
prestaBridge/
├── CLAUDE.md                           # TEN DOKUMENT
├── README.md                           # Ogólny opis projektu
├── DEPLOYMENT.md                       # Instrukcja wdrożenia
├── CHANGELOG.md                        # Historia zmian
│
├── shared/
│   ├── schemas/
│   │   ├── product-payload.json        # JSON Schema produktu
│   │   ├── router-request.json         # JSON Schema żądania do routera
│   │   ├── router-response.json        # JSON Schema odpowiedzi routera
│   │   ├── queue-message.json          # JSON Schema wiadomości Queue
│   │   └── ps-response.json            # JSON Schema odpowiedzi PS
│   └── fixtures/
│       ├── valid-products.json         # Fixture: prawidłowe produkty
│       ├── invalid-products.json       # Fixture: nieprawidłowe produkty
│       └── edge-cases.json             # Fixture: edge cases
│
├── apps-script/
│   ├── Code.gs                         # Główny skrypt
│   ├── Settings.html                   # Dialog ustawień
│   └── README.md
│
├── workers/
│   ├── router/
│   │   ├── src/
│   │   │   ├── index.js
│   │   │   ├── handlers/
│   │   │   │   └── importHandler.js
│   │   │   ├── services/
│   │   │   │   ├── validationService.js
│   │   │   │   ├── batchService.js
│   │   │   │   └── queueService.js
│   │   │   ├── middleware/
│   │   │   │   └── authMiddleware.js
│   │   │   ├── utils/
│   │   │   │   ├── hmac.js
│   │   │   │   ├── response.js
│   │   │   │   └── logger.js
│   │   │   └── schemas/
│   │   │       └── productSchema.js
│   │   ├── test/
│   │   │   └── ... (jak w sekcji 6.4)
│   │   ├── wrangler.toml
│   │   ├── package.json
│   │   └── vitest.config.js
│   │
│   └── consumer/
│       ├── src/
│       │   ├── index.js
│       │   ├── handlers/
│       │   │   └── queueHandler.js
│       │   ├── services/
│       │   │   └── prestashopClient.js
│       │   ├── middleware/
│       │   │   └── authSigner.js
│       │   ├── utils/
│       │   │   ├── hmac.js
│       │   │   ├── response.js
│       │   │   └── logger.js
│       │   └── schemas/
│       │       └── responseSchema.js
│       ├── test/
│       │   └── ... (jak w sekcji 8.3)
│       ├── wrangler.toml
│       ├── package.json
│       └── vitest.config.js
│
└── prestashop-module/
    └── prestabridge/
        ├── prestabridge.php
        ├── config.xml
        ├── logo.png
        ├── composer.json
        ├── phpunit.xml
        ├── controllers/
        │   └── front/
        │       ├── api.php
        │       └── cron.php
        ├── classes/
        │   ├── Auth/
        │   │   └── HmacAuthenticator.php
        │   ├── Import/
        │   │   ├── ProductImporter.php
        │   │   ├── ProductValidator.php
        │   │   ├── ProductMapper.php
        │   │   └── DuplicateChecker.php
        │   ├── Image/
        │   │   ├── ImageQueueManager.php
        │   │   ├── ImageDownloader.php
        │   │   └── ImageAssigner.php
        │   ├── Logging/
        │   │   ├── BridgeLogger.php
        │   │   └── LogLevel.php
        │   ├── Config/
        │   │   └── ModuleConfig.php
        │   ├── Tracking/
        │   │   └── ImportTracker.php
        │   └── Export/
        │       ├── ProductExporter.php
        │       └── ExportableInterface.php
        ├── sql/
        │   ├── install.sql
        │   └── uninstall.sql
        ├── views/
        │   └── templates/
        │       └── admin/
        │           ├── configure.tpl
        │           └── logs.tpl
        ├── translations/
        │   └── pl.php
        └── tests/
            ├── bootstrap.php
            ├── Unit/
            │   └── ... (jak w sekcji 9.5)
            ├── Integration/
            │   └── ...
            └── Fixtures/
                └── ...
```

---

## ZASADY DLA AGENTÓW AI

### ABSOLUTNE ZAKAZY
1. **NIE dodawaj plików, klas, ani funkcji, które nie są opisane w tym dokumencie**
2. **NIE zmieniaj nazw plików, klas, metod, ani zmiennych**
3. **NIE zmieniaj schematów JSON**
4. **NIE zmieniaj struktury tabel SQL**
5. **NIE używaj bibliotek zewnętrznych niewymienionych w tym dokumencie**
6. **NIE implementuj funkcji oznaczonych jako "przyszłość" — zostaw je jako puste klasy/interfejsy**
7. **NIE pomijaj testów — KAŻDA klasa/moduł MUSI mieć testy**
8. **NIE łącz odpowiedzialności — KAŻDA klasa robi JEDNĄ rzecz**

### OBOWIĄZKOWE ZACHOWANIA
1. **ZAWSZE czytaj CLAUDE.md przed rozpoczęciem pracy**
2. **ZAWSZE implementuj DOKŁADNIE to co jest opisane — litera za literą**
3. **ZAWSZE pisz testy PRZED lub RÓWNOCZEŚNIE z implementacją**
4. **ZAWSZE używaj typowania (JSDoc w JS, type hints w PHP)**
5. **ZAWSZE loguj błędy przez BridgeLogger (PHP) lub logger.js (CF)**
6. **ZAWSZE sprawdzaj istnienie produktu przed operacjami na zdjęciach**
7. **ZAWSZE używaj pSQL() i (int) w zapytaniach SQL**
8. **ZAWSZE zachowuj porządek plików jak w sekcji 17**

### KIEDY MASZ WĄTPLIWOŚCI
1. Sprawdź schemat JSON w sekcji 4
2. Sprawdź tabelę testów w sekcji 12
3. Sprawdź strukturę plików w sekcji 17
4. Jeśli czegoś nie ma w tym dokumencie — NIE IMPLEMENTUJ TEGO
5. Jeśli coś jest niejasne — NIE INTERPRETUJ, zostaw TODO z pytaniem

---

*Koniec dokumentu CLAUDE.md*
