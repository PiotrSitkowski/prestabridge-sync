# DEPLOYMENT.md — Instrukcja wdrożenia PrestaBridge

## Wymagania wstępne

### Konta i dostępy
- Konto CloudFlare z dostępem do Workers (Free tier wystarczy)
- Google Workspace z dostępem do Apps Script
- VPS z PrestaShop 8.1+ (PHP 8.1+, root access)

### Narzędzia lokalne
- Node.js 18+ (dla wrangler CLI)
- npm (dla CF Workers dev)
- PHP 8.1+ (dla testów PS)
- Composer (dla autoload PS)

---

## Krok 1: CloudFlare Setup

### 1.1 Zainstaluj Wrangler CLI
```bash
npm install -g wrangler
wrangler login
```

### 1.2 Utwórz Queue
```bash
wrangler queues create prestabridge-product-queue
wrangler queues create prestabridge-dlq
```

### 1.3 Deploy Worker Router
```bash
cd workers/router
npm install
wrangler secret put AUTH_SECRET
# Wpisz wygenerowany secret (ten sam co w PrestaShop i Apps Script)
wrangler deploy
```
Zapisz URL workera (np. `https://prestabridge-router.<account>.workers.dev`)

### 1.4 Deploy Worker Consumer
```bash
cd workers/consumer
npm install
wrangler secret put AUTH_SECRET
# Ten sam secret co wyżej
wrangler deploy
```

### 1.5 Ustaw PRESTASHOP_ENDPOINT w Consumer
Edytuj `workers/consumer/wrangler.toml`:
```toml
[vars]
PRESTASHOP_ENDPOINT = "https://your-shop.com/module/prestabridge/api"
```
```bash
wrangler deploy
```

---

## Krok 2: PrestaShop Module

### 2.1 Upload modułu
```bash
cd prestashop-module
zip -r prestabridge.zip prestabridge/
# Upload przez BO PS: Modules → Upload a module
# LUB:
scp -r prestabridge/ user@vps:/var/www/prestashop/modules/
```

### 2.2 Composer autoload
```bash
ssh user@vps
cd /var/www/prestashop/modules/prestabridge
composer install --no-dev
```

### 2.3 Instalacja modułu
W panelu administracyjnym PrestaShop:
1. Modules → Module Manager
2. Znajdź "PrestaBridge"
3. Install

### 2.4 Konfiguracja modułu
1. Modules → PrestaBridge → Configure
2. Ustaw:
   - Auth Secret: **TEN SAM** co w CF Workers
   - Import Category: Wybierz kategorię
   - Worker Endpoint: URL z kroku 1.3
   - Overwrite Duplicates: Według potrzeb
   - Images Per CRON: 10 (domyślnie)
   - Image Timeout: 30s

### 2.5 Setup CRON
```bash
# Edytuj crontab
crontab -e

# Dodaj:
*/2 * * * * curl -s "https://your-shop.com/module/prestabridge/cron?token=CRON_TOKEN" > /dev/null 2>&1
```
Gdzie `CRON_TOKEN` to wartość z konfiguracji modułu.

---

## Krok 3: Google Apps Script

### 3.1 Otwórz arkusz Google Sheets
1. Utwórz nowy arkusz lub otwórz istniejący
2. Utwórz nagłówki zgodnie z sekcją 5.2 CLAUDE.md
3. W kolumnie A dodaj checkboxy: Wstaw → Checkbox

### 3.2 Dodaj Apps Script
1. Rozszerzenia → Apps Script
2. Wklej kod z `/apps-script/Code.gs`
3. Utwórz plik HTML: `Settings.html` z `/apps-script/Settings.html`
4. Zapisz

### 3.3 Konfiguracja
1. Odśwież arkusz — pojawi się menu "PrestaBridge"
2. PrestaBridge → Ustawienia
3. Wypełnij:
   - Worker URL: URL z kroku 1.3 + `/import`
   - Auth Secret: **TEN SAM** co wszędzie
   - Batch Size: 5 (domyślnie)

### 3.4 Autoryzacja
Przy pierwszym uruchomieniu Google zapyta o uprawnienia:
- Sprawdź konto
- Kliknij "Zaawansowane" → "Przejdź do PrestaBridge"
- Zaakceptuj uprawnienia (UrlFetchApp, SpreadsheetApp, PropertiesService)

---

## Krok 4: Test E2E

### 4.1 Dodaj testowe produkty
W arkuszu dodaj 2-3 produkty z checkboxami zaznaczonymi.

### 4.2 Wyślij
PrestaBridge → Wyślij zaznaczone produkty

### 4.3 Weryfikacja
1. Sprawdź odpowiedź w dialogu (Google Sheets)
2. Sprawdź Workers Logs w CF Dashboard
3. Sprawdź logi w PS: Modules → PrestaBridge → Configure → Logi
4. Sprawdź produkty w PS: Catalog → Products
5. Poczekaj na CRON (2 min) i sprawdź zdjęcia

---

## Troubleshooting

| Problem | Rozwiązanie |
|---------|------------|
| 401 na Worker Router | Sprawdź AUTH_SECRET — musi być identyczny w GAS, CF i PS |
| Queue nie konsumuje | Sprawdź binding w consumer wrangler.toml |
| PS 500 na /api | Sprawdź logi Apache/nginx, sprawdź composer autoload |
| Zdjęcia nie pobierane | Sprawdź CRON token, sprawdź uprawnienia katalogu /img/p/ |
| Timeout PS | Zmniejsz batch size, zwiększ timeout consumer |

---

## Monitoring

### CloudFlare
- Workers dashboard: Real-time Logs
- Queue dashboard: Messages in queue, DLQ count

### PrestaShop
- Modules → PrestaBridge → Logi
- Apache/nginx error logs
- PHP error logs

### CRON
```bash
# Test ręczny:
curl -v "https://your-shop.com/module/prestabridge/cron?token=CRON_TOKEN&limit=2"
```
