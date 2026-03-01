---
name: cloudflare-workers
description: Użyj tego skilla gdy zadanie dotyczy plików w /workers/router/ lub /workers/consumer/ — zawiera wzorce ES Modules, HMAC, Queue API, limity Free Tier oraz zakazy importu npm.
---

# SKILL: cloudflare-workers

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
