---
name: security
description: Użyj tego skilla gdy zadanie dotyczy autentykacji, autoryzacji, walidacji inputu lub bezpieczeństwa danych — zawiera format HMAC, constant-time comparison, walidację inputu i przechowywanie secretów.
---

# SKILL: security

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
