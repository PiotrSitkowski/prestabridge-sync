# apps-script — PrestaBridge Google Apps Script

Skrypt Google Apps Script za­pewniający interfejs użytkownika do synchronizacji produktów z Arkusza Google do PrestaShop przez CloudFlare Worker.

## Pliki

| Plik | Opis |
|------|------|
| `Code.gs` | Główny plik skryptu (logika, HMAC, wysyłka) |
| `Settings.html` | Dialog ustawień (Worker URL, Auth Secret, Batch Size) |
| `README.md` | Ten plik |

---

## Wymagania wstępne

- Dostęp do Google Sheets (konto Google)
- Wdrożony CF Worker Router (Etap 2)
- Znany `Auth Secret` zgodny z ustawieniem `wrangler secret put AUTH_SECRET` w Routerze

---

## Instrukcja wdrożenia krok po kroku

### 1. Otwórz edytor Apps Script

W arkuszu Google kliknij: **Rozszerzenia → Apps Script**

> Alternatywnie: [script.google.com](https://script.google.com) → Nowy projekt

### 2. Skopiuj pliki projektu

W edytorze Apps Script:

1. Usuń domyślną zawartość pliku `Kod.gs`
2. Wklej całą zawartość pliku `Code.gs` z tego repozytorium
3. Kliknij **+ → HTML** i nazwij go `Settings` (bez rozszerzenia)
4. Wklej całą zawartość pliku `Settings.html`

> ⚠️ **Uwaga:** Plik HTML musi nazywać się dokładnie `Settings` — bez `.html`. Apps Script dodaje rozszerzenie automatycznie.

### 3. Zapisz projekt

Kliknij **Ctrl+S** (lub ikonę dyskietki). Nadaj projektowi nazwę np. `PrestaBridge`.

### 4. Autoryzuj skrypt

Przy pierwszym uruchomieniu Google poprosi o autoryzację uprawnień:
- Odczyt/zapis arkusza (SpreadsheetApp)
- Wykonywanie żądań zewnętrznych (UrlFetchApp)
- Przechowywanie właściwości skryptu (PropertiesService)

Kliknij **Przejrzyj uprawnienia → Zezwól**.

### 5. Odśwież arkusz Google

Po zapisaniu skryptu odśwież stronę arkusza. W górnym menu pojawi się nowe menu **PrestaBridge**.

### 6. Skonfiguruj ustawienia

Kliknij **PrestaBridge → Ustawienia** i wypełnij:

| Pole | Opis | Przykład |
|------|------|---------|
| **Worker URL** | URL endpointu CF Router | `https://prestabridge-router.xxx.workers.dev/import` |
| **Auth Secret** | Identyczny z `AUTH_SECRET` Cloud Florea Worker | (skopiuj z wrangler / menedżera haseł) |
| **Batch Size** | Liczba produktów na paczkę (1–50) | `5` |

Kliknij **Zapisz**.

> 🔒 Auth Secret jest przechowywany wyłącznie w `PropertiesService.getScriptProperties()` — nigdy w kodzie źródłowym.

### 7. Przygotuj arkusz danych

Skrypt oczekuje arkusza z nagłówkami w **wierszu 1** i danymi od **wiersza 2**:

| Kolumna | Nagłówek (przykładowy) | Typ | Uwagi |
|---------|----------------------|-----|-------|
| **A** | ☑ | Checkbox | Zaznaczenie = wyślij |
| **B** | SKU | Tekst | Wymagane, 1–64 znaki |
| **C** | Nazwa | Tekst | Wymagane, 1–128 znaki |
| **D** | Cena | Liczba | Wymagane, > 0 |
| **E** | Opis | Tekst | Opcjonalny HTML |
| **F** | Krótki opis | Tekst | Opcjonalny |
| **G** | Zdjęcia (JSON) | Tekst (JSON) | Tablica URL-i, np. `["https://...","https://..."]` |
| **H** | Ilość | Liczba całkowita | Domyślnie 0 |
| **I** | EAN13 | Tekst | Opcjonalny |
| **J** | Waga (kg) | Liczba | Domyślnie 0 |
| **K** | Aktywny | Checkbox | Domyślnie false |
| **L** | Meta Title | Tekst | SEO, opcjonalny |
| **M** | Meta Description | Tekst | SEO, opcjonalny |

> **Checkbox w kolumnie A:** Wstaw checkbox przez **Wstaw → Checkbox** (nie wpisuj TRUE/FALSE ręcznie).

### 8. Wyślij produkty

1. Zaznacz checkboxy w kolumnie A dla produktów do wysłania
2. Kliknij **PrestaBridge → Wyślij zaznaczone produkty**
3. Po wysyłce pojawi się okno z podsumowaniem
4. Pomyślnie wysłane produkty zostaną automatycznie odznaczone (checkbox = false)

---

## Obsługa błędów i logi

- Logi wykonania: **Rozszerzenia → Apps Script → Wykonania** (ikona listwy po lewej)
- Wiersze z brakującą lub zerową ceną są **pomijane** (błąd w logu z numerem wiersza i SKU)
- Wiersz z błędem JSON w kolumnie G (zdjęcia) — `images` ustawiane na `[]`, produkt **NIE** jest pomijany
- Błąd sieciowy (brak połączenia, zły URL) — alert z komunikatem błędu

---

## Bezpieczeństwo

- Nagłówek autoryzacji: `X-PrestaBridge-Auth: <timestamp>.<hmac-sha256-hex>`
- Payload HMAC: `timestamp + '.' + rawBody`
- Klucz: `Auth Secret` z PropertiesService
- Algorytm: `Utilities.computeHmacSha256Signature` — identyczny wynik jak `hash_hmac('sha256', ...)` w PHP

---

## Rozwiązywanie problemów

| Problem | Rozwiązanie |
|---------|------------|
| Menu "PrestaBridge" nie pojawia się | Odśwież stronę, sprawdź czy skrypt jest zapisany |
| Błąd 401 Unauthorized | Sprawdź czy Auth Secret jest identyczny z `AUTH_SECRET` w CF Worker |
| Błąd 404 | Sprawdź Worker URL — musi kończyć się na `/import` |
| Dialog ustawień się nie otwiera | Sprawdź czy plik HTML nazywa się dokładnie `Settings` |
| Produkty nie są odznaczane | Oznacza że Worker zwrócił `success: false` — sprawdź logi |
