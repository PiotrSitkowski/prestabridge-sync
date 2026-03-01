# DECISIONS.md — Architecture Decision Records (ADR)

> Dokument opisuje WSZYSTKIE kluczowe decyzje architektoniczne projektu PrestaBridge.
> Agenci AI NIE MOGĄ kwestionować ani zmieniać tych decyzji bez wyraźnej instrukcji od człowieka.

---

## ADR-001: ObjectModel zamiast PrestaShop Webservice API

### Status: ZAAKCEPTOWANE
### Data: 2026-02-28

### Kontekst
PrestaShop oferuje dwa sposoby zarządzania danymi:
1. **Webservice API** — REST API z kluczem, komunikacja HTTP
2. **ObjectModel** — natywne klasy PHP (Product, Image, Category)

### Decyzja
**Używamy ObjectModel (klasy PHP) do importu produktów.**

### Uzasadnienie
- **Wydajność**: Brak narzutu HTTP — bezpośredni dostęp do bazy danych
- **Elastyczność**: Pełna kontrola nad procesem zapisu (transakcje, hooki, walidacje)
- **Natywne hooki**: ObjectModel automatycznie wywołuje hooki PS (actionProductAdd, actionProductUpdate) — inne moduły mogą reagować
- **Brak limitu rate**: API ma limity, ObjectModel nie
- **Łatwiejsze debugowanie**: Stack trace wskazuje dokładnie na problem
- **StockAvailable**: Bezpośrednia kontrola nad stanem magazynowym bez dodatkowego endpointu

### Odrzucone alternatywy
- Webservice API: narzut HTTP, ograniczone możliwości batch, wolniejszy, trudniejszy error handling
- Bezpośredni SQL INSERT: brak hooków, brak walidacji PS, łamie integralność danych

### Konsekwencje
- Moduł musi działać w kontekście PrestaShop (nie jako standalone)
- Autoload composera musi być załadowany
- Context::getContext() musi być dostępny

---

## ADR-002: HMAC-SHA256 zamiast Bearer Token / JWT / OAuth

### Status: ZAAKCEPTOWANE
### Data: 2026-02-28

### Kontekst
System wymaga autentykacji na trzech odcinkach:
- Google Apps Script → CF Worker Router
- CF Worker Consumer → PrestaShop Module
- CRON → PrestaShop Module (osobny mechanizm)

### Decyzja
**HMAC-SHA256 z timestampem dla komunikacji Apps Script ↔ CF ↔ PrestaShop. Prosty token dla CRON.**

### Uzasadnienie
- **Stateless**: Brak potrzeby sesji, bazy tokenów, serwera autoryzacji
- **Lekki**: Minimalne zużycie CPU na CF Workers Free Tier (crypto.subtle jest natywne)
- **Bezpieczny**: Secret nigdy nie jest przesyłany — tylko podpis
- **Replay protection**: Timestamp zapobiega ponownemu użyciu przechwyconych żądań
- **Body integrity**: Podpis obejmuje body — modyfikacja payloadu unieważnia podpis
- **Implementowalny wszędzie**: GAS (Utilities.computeHmacSha256Signature), CF Workers (crypto.subtle), PHP (hash_hmac)

### Format nagłówka
```
X-PrestaBridge-Auth: <unix_timestamp>.<hex_hmac_sha256>
Payload do podpisu: "<timestamp>.<raw_body>"
```

### Odrzucone alternatywy
- **Bearer Token**: Secret przesyłany w każdym żądaniu — przechwycenie = pełen dostęp, brak ochrony body
- **JWT**: Wymaga parsowania, zarządzania kluczami, overhead dla Workers Free (10ms CPU)
- **OAuth 2.0**: Wymaga serwera autoryzacji, zbyt złożony dla MVP, niepotrzebny dla server-to-server
- **mTLS**: CF Workers Free nie obsługuje client certificates

### Przygotowanie na przyszłość
- Middleware pattern (`authMiddleware.js`, `HmacAuthenticator.php`) pozwala na podmianę mechanizmu auth bez zmiany reszty kodu
- W przyszłości: JWT z CF Access lub OAuth 2.0 client credentials

### Konsekwencje
- Jeden wspólny secret musi być identyczny w trzech miejscach (GAS, CF, PS)
- Rotacja secretu wymaga jednoczesnej aktualizacji we wszystkich trzech
- Zegary muszą być zsynchronizowane (tolerancja 300s)

---

## ADR-003: CloudFlare Queue zamiast D1 polling / bezpośredniego HTTP

### Status: ZAAKCEPTOWANE
### Data: 2026-02-28

### Kontekst
Produkty muszą być przekazywane z CF do PrestaShop w kontrolowany sposób, bez przeciążenia PS.

### Decyzja
**CloudFlare Queue z dedykowanym Worker Consumer.**

### Uzasadnienie
- **Natywny retry**: Queue automatycznie ponawia nieprzetworzone wiadomości
- **Dead Letter Queue**: Wiadomości po 3 nieudanych próbach trafiają do DLQ
- **Backpressure**: Queue buforuje gdy Consumer jest zajęty
- **Batching**: Consumer otrzymuje wiadomości w paczkach (max_batch_size)
- **Brak dodatkowej infrastruktury**: Nie potrzeba D1 do kolejki
- **Niezawodność**: Wiadomość jest usuwana dopiero po ack()
- **Darmowy**: Queue jest w Free Tier

### Odrzucone alternatywy
- **D1 jako kolejka**: Polling jest mniej wydajny, wymaga implementacji retry/lock/DLQ ręcznie
- **Bezpośredni HTTP z Routera do PS**: Brak kontroli nad szybkością, timeout na Worker Free (30s), brak retry
- **Zewnętrzna kolejka (Redis/RabbitMQ)**: Niepotrzebna złożoność, dodatkowy koszt, dodatkowa latencja

### Konfiguracja Queue
```
max_batch_size: 5       — Consumer dostaje max 5 wiadomości naraz
max_retries: 3          — 3 próby przed DLQ
max_batch_timeout: 30   — max 30s na przetworzenie batcha
dead_letter_queue: prestabridge-dlq
```

### Konsekwencje
- Asynchroniczność: Nadawca (Apps Script) nie dostaje wyniku importu w czasie rzeczywistym
- Opóźnienie: Od wysłania do importu 5-60s w normalnych warunkach
- Monitoring: Trzeba śledzić DLQ

---

## ADR-004: Asynchroniczne pobieranie zdjęć przez CRON

### Status: ZAAKCEPTOWANE
### Data: 2026-02-28

### Kontekst
Produkty mają listy URL-i zdjęć, które trzeba pobrać, przetworzyć (resize) i przypisać do produktu w PrestaShop.

### Decyzja
**Zdjęcia zapisywane jako URL w tabeli kolejki. Pobierane asynchronicznie przez osobny CRON.**

### Uzasadnienie
- **Timeouty**: Pobieranie zdjęć z zewnętrznych URL-i jest nieprzewidywalne (0.5s-30s per zdjęcie)
- **Niezależność**: Awaria pobierania zdjęcia nie blokuje importu danych produktu
- **Kontrola**: CRON pozwala na limitowanie obciążenia serwera (N zdjęć per wywołanie)
- **Retry**: Tabela kolejki śledzi attempts — ponowne próby bez reinicjowania całego importu
- **Race condition safety**: CRON sprawdza istnienie produktu PRZED przypisaniem zdjęcia
- **Skalowalność**: Można uruchamiać CRON częściej lub rzadziej w zależności od obciążenia

### Mechanizm locking
- Pessimistic locking: UPDATE z lock_token + locked_at
- Timeout locka: 10 minut (safety net — jeśli CRON padnie, lock wygaśnie)
- Jeden CRON = jeden lock_token = brak kolizji między równoległymi wywołaniami

### Odrzucone alternatywy
- **Inline download w API controller**: Timeout PS (30s) przy wielu zdjęciach, blokuje response
- **CF Worker pobiera zdjęcia**: Workers Free CPU 10ms — za mało na download+processing
- **CF R2 jako cache**: Dodatkowa złożoność, nie potrzebne w MVP (przyszłość: tak)
- **Webhook callback po pobraniu**: Dodatkowa infrastruktura, PS musiałby nasłuchiwać

### Konsekwencje
- Produkt jest dostępny w PS PRZED pobraniem zdjęć
- Domyślnie `active = false` — produkt nie jest widoczny bez zdjęć
- Trzeba konfigurować CRON na VPS
- Potrzebna tabela `prestabridge_image_queue` w bazie PS

---

## ADR-005: Ręczna walidacja JSON zamiast biblioteki (CF Workers)

### Status: ZAAKCEPTOWANE
### Data: 2026-02-28

### Kontekst
Walidacja payloadu produktów na CF Workers wymaga sprawdzenia typów, wymaganych pól i limitów.

### Decyzja
**Ręczna walidacja (if/typeof/Array.isArray) zamiast bibliotek typu ajv lub joi.**

### Uzasadnienie
- **Rozmiar bundla**: ajv ~150KB, joi ~200KB — za dużo dla CF Workers
- **CPU**: Parsowanie schemy ajv zużywa CPU — ryzyko przekroczenia 10ms na Free Tier
- **Prostota schemy**: 12 pól, proste typy — nie potrzeba pełnego JSON Schema parsera
- **Przewidywalność**: Ręczna walidacja = pełna kontrola nad komunikatami błędów
- **Brak zależności**: Zero production dependencies w Worker

### Odrzucone alternatywy
- **ajv**: Za ciężki, za wolny na Free Tier
- **joi**: Jeszcze cięższy niż ajv
- **zod**: Wymaga TypeScript, nadal dodaje rozmiar
- **JSON Schema z CF Workers AI**: Zużywa inferencje, niepotrzebna złożoność

### Konsekwencje
- Walidacja musi być ręcznie synchronizowana z JSON Schema w `/shared/schemas/`
- Komunikaty błędów definiowane w kodzie, nie w schemacie
- Przy dodawaniu pól: aktualizacja walidatora + schemy + testów

---

## ADR-006: Jedno źródło prawdy o schemacie danych — `/shared/schemas/`

### Status: ZAAKCEPTOWANE
### Data: 2026-02-28

### Kontekst
Schemat produktu jest używany w 4 miejscach: Apps Script, CF Router, CF Consumer, PS Module.

### Decyzja
**Formalne JSON Schema pliki w `/shared/schemas/` jako jedyne źródło prawdy. Walidatory w poszczególnych komponentach implementują reguły z tych schematów.**

### Uzasadnienie
- **Spójność**: Jedna zmiana w schemacie → jasne co trzeba zaktualizować
- **Dokumentacja**: JSON Schema jest samodokumentujący się
- **Testowalność**: Fixtures generowane na podstawie schematów
- **Przyszłość**: Schema może być użyta do auto-generowania walidatorów

### Konsekwencje
- Walidatory (JS ręczny, PHP ręczny) muszą być ręcznie synchronizowane ze schematem
- Agent AI: ZAWSZE najpierw sprawdza schemat, potem implementuje walidator

---

## ADR-007: PrestaShop ModuleFrontController zamiast custom route / API platform

### Status: ZAAKCEPTOWANE
### Data: 2026-02-28

### Kontekst
Moduł potrzebuje endpointu HTTP do przyjmowania danych z CF Worker.

### Decyzja
**Standardowy ModuleFrontController PS z URL `/module/prestabridge/api`.**

### Uzasadnienie
- **Natywny PS**: Nie wymaga dodatkowych zależności ani konfiguracji serwera
- **Kontekst PS**: Automatycznie dostępny Context (shop, language, employee)
- **Routing**: PS obsługuje routing — nie trzeba .htaccess
- **Bezpieczeństwo**: PS middleware (CSRF opcjonalny, ale dostępny)
- **Kompatybilność**: Działa na każdym hostingu z PS 8.1+

### Odrzucone alternatywy
- **Custom PHP file w module dir**: Brak kontekstu PS, ręczne ładowanie autoload, problemy z bezpieczeństwem
- **PS Webservice override**: Złożone, łamie upgrades
- **Symfony route**: PS 8.1 obsługuje, ale wymaga dodatkowej konfiguracji, mniej portable

### Konsekwencje
- URL zawiera `/module/prestabridge/` — nie jest "czysty" REST
- Trzeba ustawić `$this->ajax = true` dla JSON response
- CRON endpoint: ten sam mechanizm, osobny controller

---

## ADR-008: Tabela import_tracking jako monitor stanu

### Status: ZAAKCEPTOWANE
### Data: 2026-02-28

### Kontekst
Potrzebujemy wiedzieć, w jakim stanie jest każdy importowany produkt: czy dane są zapisane, czy zdjęcia pobrane, czy wszystko kompletne.

### Decyzja
**Dedykowana tabela `prestabridge_import_tracking` ze statusami: imported → images_pending → images_partial → completed / error.**

### Uzasadnienie
- **Widoczność**: Admin widzi stan każdego importu w panelu
- **Diagnostyka**: Łatwo znaleźć "zablokowane" produkty
- **Przyszłość**: Webhook/callback po osiągnięciu statusu "completed"
- **Niezależność**: Nie modyfikuje natywnych tabel PS

### Status flow
```
imported → images_pending (gdy images.length > 0)
         → completed (gdy images.length === 0)

images_pending → images_partial (gdy część pobrana)
               → completed (gdy wszystkie pobrane)
               → error (gdy max_attempts wyczerpane)

images_partial → completed (gdy reszta pobrana)
               → error (gdy max_attempts wyczerpane)
```

---

## ADR-009: Konfiguracja modułu przez Configuration PS (nie YAML/ENV)

### Status: ZAAKCEPTOWANE
### Data: 2026-02-28

### Kontekst
Moduł potrzebuje przechowywać konfigurację (secret, kategoria, endpoint, flagi).

### Decyzja
**Natywna klasa Configuration PrestaShop z prefixem PRESTABRIDGE_.**

### Uzasadnienie
- **Natywne PS**: Standard PS, edytowalne z panelu admin
- **Shop-aware**: Configuration obsługuje multishop
- **Cache**: PS cachuje konfigurację — szybki dostęp
- **UI**: HelperForm PS generuje formularz automatycznie

### Odrzucone alternatywy
- **YAML file**: Nie edytowalny z panelu, wymaga dostępu do plików
- **.env**: Nie natywne PS, nie obsługuje multishop
- **Własna tabela config**: Niepotrzebna duplikacja mechanizmu PS

---

## ADR-010: Batch processing z parametrem batchSize

### Status: ZAAKCEPTOWANE
### Data: 2026-02-28

### Kontekst
Wysyłanie 500 produktów naraz do PrestaShop spowodowałoby timeout i przeciążenie.

### Decyzja
**Podział na paczki (batche) na poziomie CF Worker Router. Rozmiar paczki = parametr `batchSize` w request (domyślnie 5, max 50).**

### Uzasadnienie
- **Elastyczność**: Nadawca decyduje o rozmiarze paczki w zależności od złożoności produktów
- **Ochrona PS**: Małe paczki = krótkie requesty do PS = brak timeoutów
- **Queue friendly**: Każda paczka = 1 wiadomość w Queue
- **Kontrola**: Admin może zmieniać batchSize w Settings Apps Script

### Dlaczego domyślnie 5
- 5 produktów × ~1KB per produkt = ~5KB payload — daleko od limitu Queue (128KB)
- 5 produktów × ~200ms per insert = ~1s w PS — daleko od timeoutu
- Przy 200 produktach = 40 wiadomości Queue — daleko od limitu (1000/s)

### Limity
- Min: 1 (debugowanie, problematyczne produkty)
- Max: 50 (zabezpieczenie przed przesadą)
- Default: 5 (bezpieczny kompromis)
