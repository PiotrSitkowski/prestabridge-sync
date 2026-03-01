---
name: deployment
description: Użyj tego skilla gdy zadanie dotyczy deployu, konfiguracji serwera lub CI/CD — zawiera kolejność deployu komponentów, komendy weryfikacji po deploy oraz plan rollbacku.
---

# SKILL: deployment

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
