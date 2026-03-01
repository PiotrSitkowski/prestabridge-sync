---
name: race-conditions
description: Użyj tego skilla gdy zadanie dotyczy operacji na zdjęciach, CRON lub równoległego dostępu do danych — zawiera wzorce pessimistic locking, safety net dla crashed CRONów i obsługę cover image.
---

# SKILL: race-conditions

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
