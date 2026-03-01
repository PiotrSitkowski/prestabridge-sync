# TESTING-STRATEGY.md — Strategia testów PrestaBridge

> Dokument zawiera KONKRETNE asercje i dane testowe dla każdego scenariusza.
> Agent AI implementuje testy DOKŁADNIE według tego dokumentu.

---

## 1. NARZĘDZIA

| Komponent | Framework | Env |
|-----------|-----------|-----|
| CF Workers (Router, Consumer) | Vitest 1.x + @cloudflare/vitest-pool-workers | miniflare |
| PrestaShop Module | PHPUnit 10.x | Custom bootstrap z mockami PS |

---

## 2. CF WORKERS — TESTY JEDNOSTKOWE

### 2.1 `authMiddleware.test.js`

```
SETUP:
  const TEST_SECRET = 'test-secret-key-for-hmac-256-minimum-32chars!!'
  const TEST_BODY = '{"products":[{"sku":"A","name":"B","price":10}]}'

HELPER: generateValidAuth(secret, body, timestampOverride?)
  → generuje prawidłowy nagłówek X-PrestaBridge-Auth

TESTY:

TEST R-A1: "returns 401 when auth header is missing"
  INPUT:  Request bez nagłówka X-PrestaBridge-Auth
  ASSERT: result.valid === false
  ASSERT: result.error === 'Missing auth header'

TEST R-A2: "returns 401 when auth header format is invalid"
  INPUT:  X-PrestaBridge-Auth: "not-a-valid-format"
  ASSERT: result.valid === false
  ASSERT: result.error === 'Invalid auth format'

  INPUT:  X-PrestaBridge-Auth: "onlyonepart"
  ASSERT: result.valid === false

  INPUT:  X-PrestaBridge-Auth: "abc.def.ghi" (3 parts)
  ASSERT: result.valid === false

TEST R-A3: "returns 401 when timestamp is expired"
  INPUT:  timestamp = Math.floor(Date.now()/1000) - 600 (10 min ago)
          Prawidłowy HMAC z tym timestampem
  ASSERT: result.valid === false
  ASSERT: result.error zawiera 'expired'

TEST R-A4: "returns 401 when signature is invalid"
  INPUT:  timestamp = aktualny
          signature = 'a'.repeat(64) (losowy hex)
  ASSERT: result.valid === false
  ASSERT: result.error === 'Invalid signature'

TEST R-A5: "returns valid for correct auth"
  INPUT:  generateValidAuth(TEST_SECRET, TEST_BODY)
  ASSERT: result.valid === true
  ASSERT: result.error === undefined

TEST R-A6: "returns 401 when timestamp is from future"
  INPUT:  timestamp = Math.floor(Date.now()/1000) + 600 (10 min ahead)
          Prawidłowy HMAC z tym timestampem
  ASSERT: result.valid === false
  ASSERT: result.error zawiera 'expired'

TEST R-A7: "handles empty body correctly"
  INPUT:  body = ''
          generateValidAuth(TEST_SECRET, '')
  ASSERT: result.valid === true

TEST R-A8: "signature is case-insensitive hex"
  INPUT:  Prawidłowy auth z uppercase hex signature
  ASSERT: Powinien zadziałać (porównanie lowercase)
```

### 2.2 `validationService.test.js`

```
FIXTURE: VALID_MINIMAL = { sku: 'TEST-001', name: 'Test Product', price: 29.99 }
FIXTURE: VALID_FULL = {
  sku: 'TEST-002',
  name: 'Full Product',
  price: 49.99,
  description: '<p>Full description</p>',
  description_short: 'Short desc',
  images: ['https://example.com/img1.jpg', 'https://example.com/img2.png'],
  quantity: 100,
  ean13: '5901234123457',
  weight: 0.5,
  active: true,
  meta_title: 'SEO Title',
  meta_description: 'SEO Description'
}

--- validateProduct() ---

TEST R-V1: "accepts product with minimum required fields"
  INPUT:  VALID_MINIMAL
  ASSERT: result.valid === true
  ASSERT: result.errors.length === 0

TEST R-V2: "accepts product with all fields"
  INPUT:  VALID_FULL
  ASSERT: result.valid === true
  ASSERT: result.errors.length === 0

TEST R-V3: "rejects product without sku"
  INPUT:  { name: 'Test', price: 10 }
  ASSERT: result.valid === false
  ASSERT: result.errors zawiera 'sku is required'

TEST R-V4: "rejects product without name"
  INPUT:  { sku: 'X', price: 10 }
  ASSERT: result.valid === false
  ASSERT: result.errors zawiera 'name is required'

TEST R-V5: "rejects product without price"
  INPUT:  { sku: 'X', name: 'Test' }
  ASSERT: result.valid === false
  ASSERT: result.errors zawiera 'price is required'

TEST R-V6: "rejects product with price = 0"
  INPUT:  { sku: 'X', name: 'Test', price: 0 }
  ASSERT: result.valid === false
  ASSERT: result.errors zawiera 'price must be greater than 0'

TEST R-V7: "rejects product with negative price"
  INPUT:  { sku: 'X', name: 'Test', price: -5.99 }
  ASSERT: result.valid === false
  ASSERT: result.errors zawiera 'price must be greater than 0'

TEST R-V8: "rejects product with sku exceeding 64 chars"
  INPUT:  { sku: 'X'.repeat(65), name: 'Test', price: 10 }
  ASSERT: result.valid === false
  ASSERT: result.errors zawiera 'sku must not exceed 64 characters'

TEST R-V9: "rejects product with empty sku"
  INPUT:  { sku: '', name: 'Test', price: 10 }
  ASSERT: result.valid === false
  ASSERT: result.errors zawiera 'sku is required'

TEST R-V10: "rejects product when images is not array"
  INPUT:  { ...VALID_MINIMAL, images: 'https://example.com/img.jpg' }
  ASSERT: result.valid === false
  ASSERT: result.errors zawiera 'images must be an array'

TEST R-V11: "rejects product with invalid image URL"
  INPUT:  { ...VALID_MINIMAL, images: ['not-a-url', 'ftp://wrong.com/img.jpg'] }
  ASSERT: result.valid === false
  ASSERT: result.errors zawiera string match 'image URL must start with http'

TEST R-V12: "accepts product with empty images array"
  INPUT:  { ...VALID_MINIMAL, images: [] }
  ASSERT: result.valid === true

TEST R-V13: "rejects product with non-numeric price"
  INPUT:  { sku: 'X', name: 'Test', price: 'free' }
  ASSERT: result.valid === false
  ASSERT: result.errors zawiera 'price must be a number'

TEST R-V14: "rejects product with price = NaN"
  INPUT:  { sku: 'X', name: 'Test', price: NaN }
  ASSERT: result.valid === false

TEST R-V15: "rejects product with price = Infinity"
  INPUT:  { sku: 'X', name: 'Test', price: Infinity }
  ASSERT: result.valid === false

TEST R-V16: "accepts product with unknown extra fields (ignores them)"
  INPUT:  { ...VALID_MINIMAL, unknownField: 'value' }
  ASSERT: result.valid === true
  NOTE:   Walidator ignoruje nieznane pola — nie filtruje ich (to robi mapper)

TEST R-V17: "accumulates multiple errors"
  INPUT:  { sku: '', name: '', price: -1 }
  ASSERT: result.valid === false
  ASSERT: result.errors.length >= 3

--- validateRequest() ---

TEST R-V18: "accepts valid request"
  INPUT:  { products: [VALID_MINIMAL], batchSize: 5 }
  ASSERT: result.valid === true

TEST R-V19: "rejects request without products key"
  INPUT:  { batchSize: 5 }
  ASSERT: result.valid === false
  ASSERT: result.errors zawiera 'products is required'

TEST R-V20: "rejects request where products is not array"
  INPUT:  { products: 'string' }
  ASSERT: result.valid === false

TEST R-V21: "rejects request with empty products array"
  INPUT:  { products: [] }
  ASSERT: result.valid === false
  ASSERT: result.errors zawiera 'products must not be empty'

TEST R-V22: "rejects request exceeding 1000 products"
  INPUT:  { products: Array(1001).fill(VALID_MINIMAL) }
  ASSERT: result.valid === false

TEST R-V23: "uses default batchSize when not provided"
  INPUT:  { products: [VALID_MINIMAL] }
  ASSERT: wynik parsowania batchSize === 5

TEST R-V24: "rejects batchSize > 50"
  INPUT:  { products: [VALID_MINIMAL], batchSize: 51 }
  ASSERT: result.valid === false

TEST R-V25: "rejects batchSize < 1"
  INPUT:  { products: [VALID_MINIMAL], batchSize: 0 }
  ASSERT: result.valid === false
```

### 2.3 `batchService.test.js`

```
TESTY:

TEST R-B1: "splits 10 products into 2 batches of 5"
  INPUT:  products = Array(10).fill(product), batchSize = 5
  ASSERT: result.length === 2
  ASSERT: result[0].length === 5
  ASSERT: result[1].length === 5

TEST R-B2: "splits 7 products into batch of 5 and batch of 2"
  INPUT:  products = Array(7).fill(product), batchSize = 5
  ASSERT: result.length === 2
  ASSERT: result[0].length === 5
  ASSERT: result[1].length === 2

TEST R-B3: "handles single product"
  INPUT:  products = [product], batchSize = 5
  ASSERT: result.length === 1
  ASSERT: result[0].length === 1

TEST R-B4: "creates 50 batches for 50 products with batchSize 1"
  INPUT:  products = Array(50).fill(product), batchSize = 1
  ASSERT: result.length === 50
  ASSERT: every batch has length 1

TEST R-B5: "returns empty array for empty input"
  INPUT:  products = [], batchSize = 5
  ASSERT: result.length === 0

TEST R-B6: "preserves product data integrity"
  INPUT:  products = [{sku:'A',...}, {sku:'B',...}], batchSize = 1
  ASSERT: result[0][0].sku === 'A'
  ASSERT: result[1][0].sku === 'B'

TEST R-B7: "handles batchSize larger than product count"
  INPUT:  products = Array(3).fill(product), batchSize = 10
  ASSERT: result.length === 1
  ASSERT: result[0].length === 3
```

### 2.4 `queueService.test.js`

```
SETUP:
  Mock Queue object: { send: vi.fn().mockResolvedValue(undefined) }

TESTY:

TEST R-Q1: "sends correct number of messages"
  INPUT:  3 batches, requestId = 'req-123'
  ASSERT: queue.send called 3 times

TEST R-Q2: "sends correct QueueMessage format"
  INPUT:  1 batch with 2 products
  ASSERT: queue.send argument zawiera:
    - requestId: string
    - batchIndex: 0
    - totalBatches: 1
    - products: array length 2
    - metadata.enqueuedAt: ISO date string
    - metadata.source: 'google-sheets'

TEST R-Q3: "handles empty batches array"
  INPUT:  0 batches
  ASSERT: queue.send NOT called

TEST R-Q4: "propagates queue.send errors"
  INPUT:  queue.send rejects with Error('Queue full')
  ASSERT: throws error
```

### 2.5 `importHandler.test.js` (integracyjny)

```
SETUP:
  Mock env z Queue, AUTH_SECRET, zmiennymi
  Helper: buildRequest(body, authSecret?)

TESTY:

TEST R-I1: "happy path - 10 valid products, batch 5"
  INPUT:  POST /import, 10 valid products, batchSize 5
  ASSERT: status 200
  ASSERT: body.success === true
  ASSERT: body.summary.totalReceived === 10
  ASSERT: body.summary.totalAccepted === 10
  ASSERT: body.summary.totalRejected === 0
  ASSERT: body.summary.batchesCreated === 2
  ASSERT: body.rejected.length === 0
  ASSERT: queue.send called 2 times

TEST R-I2: "mixed valid and invalid products"
  INPUT:  POST /import, 8 valid + 2 invalid (no price, no sku)
  ASSERT: status 200
  ASSERT: body.summary.totalAccepted === 8
  ASSERT: body.summary.totalRejected === 2
  ASSERT: body.rejected.length === 2
  ASSERT: body.rejected[0].errors.length > 0

TEST R-I3: "all invalid products"
  INPUT:  POST /import, 5 products all missing required fields
  ASSERT: status 200
  ASSERT: body.summary.totalAccepted === 0
  ASSERT: body.summary.totalRejected === 5
  ASSERT: queue.send NOT called

TEST R-I4: "rejects GET method"
  INPUT:  GET /import
  ASSERT: status 405
  ASSERT: body.error zawiera 'Method not allowed'

TEST R-I5: "returns 404 for unknown path"
  INPUT:  POST /unknown
  ASSERT: status 404

TEST R-I6: "rejects invalid JSON"
  INPUT:  POST /import, body = "not json{{"
  ASSERT: status 400
  ASSERT: body.error zawiera 'Invalid JSON'

TEST R-I7: "returns requestId in response"
  INPUT:  valid request
  ASSERT: body.requestId is string matching UUID format

TEST R-I8: "rejects unauthenticated request"
  INPUT:  POST /import bez auth header
  ASSERT: status 401
```

### 2.6 `queueHandler.test.js` (Consumer)

```
SETUP:
  Mock fetch (globalThis.fetch = vi.fn())
  Mock message objects: { body, ack: vi.fn(), retry: vi.fn(), attempts: 0 }

TESTY:

TEST C-1: "acks message on successful PS response"
  INPUT:  Message z 5 produktami, fetch returns 200 + success response
  ASSERT: message.ack() called
  ASSERT: message.retry() NOT called

TEST C-2: "retries on PS timeout"
  INPUT:  fetch rejects with AbortError (timeout)
  ASSERT: message.retry() called with delaySeconds
  ASSERT: message.ack() NOT called

TEST C-3: "retries on PS 500"
  INPUT:  fetch returns status 500
  ASSERT: message.retry() called

TEST C-4: "retries on PS 401"
  INPUT:  fetch returns status 401
  ASSERT: message.retry() called

TEST C-5: "acks on PS partial success (some products failed)"
  INPUT:  fetch returns 200 + response with mixed results
  ASSERT: message.ack() called
  NOTE:   Partial failure is logged by PS, not retried by consumer

TEST C-6: "acks malformed queue message (no retry)"
  INPUT:  message.body = "not valid json"
  ASSERT: message.ack() called (nie retry — nigdy się nie naprawi)

TEST C-7: "uses exponential backoff"
  INPUT:  message.attempts = 0 → delaySeconds = 10
          message.attempts = 1 → delaySeconds = 30
          message.attempts = 2 → delaySeconds = 60
  ASSERT: retry called with correct delays

TEST C-8: "sends correct HMAC auth to PS"
  INPUT:  Valid message
  ASSERT: fetch called with header X-PrestaBridge-Auth
  ASSERT: header matches format 'timestamp.signature'
```

---

## 3. PRESTASHOP MODULE — TESTY JEDNOSTKOWE

### 3.1 `HmacAuthenticatorTest.php`

```
SETUP:
  const TEST_SECRET = 'test-secret-key-for-hmac-256-minimum-32chars!!'

  Helper: generateValidHeader(string $secret, string $body, ?int $timestamp = null): string

TESTY:

TEST P-A1: "returns true for valid HMAC"
  $body = '{"products":[]}';
  $header = generateValidHeader(TEST_SECRET, $body);
  ASSERT: HmacAuthenticator::verify($header, $body, TEST_SECRET) === true

TEST P-A2: "returns false for invalid HMAC"
  $header = time() . '.' . str_repeat('a', 64);
  ASSERT: HmacAuthenticator::verify($header, $body, TEST_SECRET) === false

TEST P-A3: "returns false for expired timestamp"
  $header = generateValidHeader(TEST_SECRET, $body, time() - 600);
  ASSERT: HmacAuthenticator::verify($header, $body, TEST_SECRET) === false

TEST P-A4: "returns false for empty header"
  ASSERT: HmacAuthenticator::verify('', $body, TEST_SECRET) === false

TEST P-A5: "returns false for malformed header"
  ASSERT: HmacAuthenticator::verify('no-dot-separator', $body, TEST_SECRET) === false

TEST P-A6: "signature matches JS implementation"
  WAŻNE: Ten test weryfikuje kompatybilność PHP ↔ JS
  $timestamp = '1709136000';
  $body = '{"test":"data"}';
  $expected = hash_hmac('sha256', $timestamp . '.' . $body, TEST_SECRET);
  $header = $timestamp . '.' . $expected;
  ASSERT: HmacAuthenticator::verify($header, $body, TEST_SECRET) === true
```

### 3.2 `ProductValidatorTest.php`

```
FIXTURE:
  VALID_MINIMAL = ['sku' => 'TEST-001', 'name' => 'Test Product', 'price' => 29.99]
  VALID_FULL = [
    'sku' => 'TEST-002', 'name' => 'Full Product', 'price' => 49.99,
    'description' => '<p>Desc</p>', 'description_short' => 'Short',
    'images' => ['https://example.com/1.jpg'], 'quantity' => 100,
    'ean13' => '5901234123457', 'weight' => 0.5, 'active' => true,
    'meta_title' => 'SEO', 'meta_description' => 'SEO Desc'
  ]

TESTY:

TEST P-V1: "validates minimal product"
  ASSERT: ['valid' => true, 'errors' => []]

TEST P-V2: "validates full product"
  ASSERT: ['valid' => true, 'errors' => []]

TEST P-V3: "rejects missing sku"
  INPUT:  ['name' => 'X', 'price' => 10]
  ASSERT: valid === false, errors zawiera 'sku is required'

TEST P-V4: "rejects missing name"
  INPUT:  ['sku' => 'X', 'price' => 10]
  ASSERT: valid === false, errors zawiera 'name is required'

TEST P-V5: "rejects missing price"
  INPUT:  ['sku' => 'X', 'name' => 'Y']
  ASSERT: valid === false, errors zawiera 'price is required'

TEST P-V6: "rejects zero price"
  INPUT:  ['sku' => 'X', 'name' => 'Y', 'price' => 0]
  ASSERT: valid === false

TEST P-V7: "rejects negative price"
  INPUT:  ['sku' => 'X', 'name' => 'Y', 'price' => -10]
  ASSERT: valid === false

TEST P-V8: "rejects sku longer than 64"
  INPUT:  ['sku' => str_repeat('X', 65), ...]
  ASSERT: valid === false

TEST P-V9: "rejects name longer than 128"
  INPUT:  ['name' => str_repeat('X', 129), ...]
  ASSERT: valid === false

TEST P-V10: "rejects non-array images"
  INPUT:  [...VALID_MINIMAL, 'images' => 'string']
  ASSERT: valid === false

TEST P-V11: "rejects invalid image URL"
  INPUT:  [...VALID_MINIMAL, 'images' => ['ftp://bad.com/img.jpg']]
  ASSERT: valid === false

TEST P-V12: "accepts empty images array"
  INPUT:  [...VALID_MINIMAL, 'images' => []]
  ASSERT: valid === true

TEST P-V13: "rejects non-numeric quantity"
  INPUT:  [...VALID_MINIMAL, 'quantity' => 'abc']
  ASSERT: valid === false

TEST P-V14: "rejects negative quantity"
  INPUT:  [...VALID_MINIMAL, 'quantity' => -1]
  ASSERT: valid === false

TEST P-V15: "accumulates multiple errors"
  INPUT:  ['sku' => '', 'name' => '', 'price' => -1]
  ASSERT: count(errors) >= 3
```

### 3.3 `DuplicateCheckerTest.php`

```
SETUP:
  Mock Db::getInstance() z mockowanym getValue()

TESTY:

TEST P-D1: "returns null when no product with sku"
  Db::getValue returns false
  ASSERT: DuplicateChecker::getProductIdBySku('NONEXIST') === null

TEST P-D2: "returns product id when sku exists"
  Db::getValue returns '42'
  ASSERT: DuplicateChecker::getProductIdBySku('EXIST') === 42

TEST P-D3: "exists() returns true for existing sku"
  Db::getValue returns '42'
  ASSERT: DuplicateChecker::exists('EXIST') === true

TEST P-D4: "exists() returns false for missing sku"
  Db::getValue returns false
  ASSERT: DuplicateChecker::exists('NONEXIST') === false

TEST P-D5: "escapes SQL injection in sku"
  INPUT:  sku = "'; DROP TABLE products; --"
  ASSERT: Db query zawiera pSQL() output (bez SQL injection)
```

### 3.4 `ProductImporterTest.php`

```
SETUP:
  Mock Product (add returns true/false, update returns true/false)
  Mock StockAvailable::setQuantity
  Mock DuplicateChecker
  Mock ProductMapper::mapToProduct returns mock Product

TESTY:

TEST P-I1: "creates new product successfully"
  Product::add() returns true, Product::id = 42
  ASSERT: result['success'] === true
  ASSERT: result['status'] === 'created'
  ASSERT: result['productId'] === 42

TEST P-I2: "updates existing product when updateMode=true"
  DuplicateChecker::getProductIdBySku returns 42
  Product::update() returns true
  ASSERT: result['status'] === 'updated'

TEST P-I3: "returns error when Product::add fails"
  Product::add() returns false
  ASSERT: result['success'] === false
  ASSERT: result['status'] === 'error'
  ASSERT: result['error'] zawiera 'save failed'

TEST P-I4: "sets stock via StockAvailable"
  Successful add, quantity = 50
  ASSERT: StockAvailable::setQuantity called with (42, 0, 50)

TEST P-I5: "assigns import category"
  Successful add
  ASSERT: Product::updateCategories called with [importCategoryId]

TEST P-I6: "logs successful import"
  Successful add
  ASSERT: BridgeLogger::info called with 'Product created' and sku

TEST P-I7: "logs failed import"
  Product::add throws Exception
  ASSERT: BridgeLogger::error called
```

### 3.5 `ImageQueueManagerTest.php`

```
SETUP:
  Mock Db::getInstance() z mockowanym insert(), execute(), executeS()

TESTY:

TEST P-IM1: "enqueues 3 images correctly"
  INPUT:  productId=42, sku='SKU', images=['url1','url2','url3']
  ASSERT: Db::insert called 3 times
  ASSERT: first insert has is_cover=1, position=0
  ASSERT: second insert has is_cover=0, position=1
  ASSERT: returns 3

TEST P-IM2: "returns 0 for empty images"
  INPUT:  images=[]
  ASSERT: Db::insert NOT called
  ASSERT: returns 0

TEST P-IM3: "acquireBatch locks correct number of records"
  INPUT:  limit=5
  ASSERT: UPDATE query contains LIMIT 5
  ASSERT: UPDATE sets lock_token and locked_at
  ASSERT: SELECT returns records with matching lock_token

TEST P-IM4: "acquireBatch releases expired locks first"
  ASSERT: First query is UPDATE releasing locks older than 10 minutes

TEST P-IM5: "markCompleted sets status and clears lock"
  INPUT:  id=99
  ASSERT: UPDATE sets status='completed', lock_token=NULL

TEST P-IM6: "markFailed sets status to failed on last attempt"
  INPUT:  id=99, attempts will reach max_attempts
  ASSERT: status = 'failed'

TEST P-IM7: "markFailed sets status back to pending if retries left"
  INPUT:  id=99, attempts < max_attempts
  ASSERT: status back to pending, attempts incremented
```

### 3.6 `ImageAssignerTest.php`

```
SETUP:
  Mock Product::existsInDatabase()
  Mock Image (add, delete, deleteCover, getPathForCreation)
  Mock ImageType::getImagesTypes
  Mock ImageManager::resize
  Tmp file z prawdziwym JPEG header (create in setUp, unlink in tearDown)

TESTY:

TEST P-IA1: "assigns image to existing product"
  Product::existsInDatabase returns true
  Image::add returns true
  ASSERT: result['success'] === true
  ASSERT: result['imageId'] > 0

TEST P-IA2: "fails when product does not exist (race condition)"
  Product::existsInDatabase returns false
  ASSERT: result['success'] === false
  ASSERT: result['error'] zawiera 'does not exist'
  ASSERT: Image::add NOT called

TEST P-IA3: "sets cover and removes previous cover"
  INPUT:  isCover = true
  ASSERT: Image::deleteCover called with productId
  ASSERT: image->cover === 1

TEST P-IA4: "does not set cover for non-cover images"
  INPUT:  isCover = false
  ASSERT: Image::deleteCover NOT called
  ASSERT: image->cover === 0

TEST P-IA5: "generates thumbnails for all image types"
  ImageType::getImagesTypes returns [type1, type2, type3]
  ASSERT: ImageManager::resize called 3 times

TEST P-IA6: "cleans up tmp file on success"
  ASSERT: tmpFile nie istnieje po operacji

TEST P-IA7: "cleans up tmp file on failure"
  Image::add returns false
  ASSERT: tmpFile nie istnieje po operacji
```

### 3.7 `BridgeLoggerTest.php`

```
SETUP:
  Mock Db::getInstance()

TESTY:

TEST P-L1: "inserts log with all fields"
  BridgeLogger::error('msg', ['key'=>'val'], 'import', 'SKU-1', 42, 'req-1')
  ASSERT: Db::insert called with:
    level='error', source='import', message='msg',
    context='{"key":"val"}', sku='SKU-1', id_product=42, request_id='req-1'

TEST P-L2: "handles null optional fields"
  BridgeLogger::info('msg')
  ASSERT: Db::insert called with sku=null, id_product=null

TEST P-L3: "getLogs returns paginated results"
  Db::getValue returns 100 (total)
  Db::executeS returns 50 rows
  ASSERT: result['total'] === 100
  ASSERT: result['perPage'] === 50
  ASSERT: result['totalPages'] === 2

TEST P-L4: "getLogs filters by level"
  BridgeLogger::getLogs(1, 50, 'error')
  ASSERT: SQL query contains WHERE level = "error"

TEST P-L5: "clearLogs deletes all when days=null"
  BridgeLogger::clearLogs()
  ASSERT: SQL DELETE without WHERE date condition

TEST P-L6: "clearLogs deletes older than N days"
  BridgeLogger::clearLogs(7)
  ASSERT: SQL DELETE with WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)

TEST P-L7: "escapes special characters in message"
  BridgeLogger::error("test'; DROP TABLE --")
  ASSERT: Db::insert argument uses pSQL()
```

---

## 4. TESTY INTEGRACYJNE

### 4.1 CF Worker Router Integration

```
TEST: Full request flow
  Wysyłamy POST /import z 10 produktami (8 valid, 2 invalid)
  ASSERT: Response 200
  ASSERT: 8 accepted, 2 rejected
  ASSERT: Queue.send called for valid products only
  ASSERT: rejected array has correct indexes and error messages
```

### 4.2 PS Module Integration

```
TEST: Full import flow (wymaga DB testowej)
  POST do api.php z 3 produktami
  ASSERT: 3 products created in ps_product
  ASSERT: images enqueued in ps_prestabridge_image_queue
  ASSERT: tracking records created in ps_prestabridge_import_tracking
  ASSERT: logs created in ps_prestabridge_log
```

---

## 5. TESTY E2E (shell script)

```bash
#!/bin/bash
# e2e-test.sh — uruchamiać po deploy

WORKER_URL="https://prestabridge-router.xxx.workers.dev/import"
SECRET="test-secret"
BODY='{"products":[{"sku":"E2E-001","name":"E2E Test","price":9.99,"images":["https://via.placeholder.com/150"]}],"batchSize":5}'

TIMESTAMP=$(date +%s)
SIGNATURE=$(echo -n "${TIMESTAMP}.${BODY}" | openssl dgst -sha256 -hmac "${SECRET}" | awk '{print $2}')

RESPONSE=$(curl -s -w "\n%{http_code}" \
  -X POST "${WORKER_URL}" \
  -H "Content-Type: application/json" \
  -H "X-PrestaBridge-Auth: ${TIMESTAMP}.${SIGNATURE}" \
  -d "${BODY}")

HTTP_CODE=$(echo "$RESPONSE" | tail -1)
BODY_RESP=$(echo "$RESPONSE" | head -1)

echo "Status: ${HTTP_CODE}"
echo "Body: ${BODY_RESP}"

if [ "$HTTP_CODE" = "200" ]; then
  echo "✅ E2E Router test PASSED"
else
  echo "❌ E2E Router test FAILED"
  exit 1
fi
```

---

## 6. COVERAGE REQUIREMENTS

| Komponent | Min branches | Min functions | Min lines |
|-----------|-------------|--------------|-----------|
| CF Router | 80% | 90% | 85% |
| CF Consumer | 80% | 90% | 85% |
| PS Module | 75% | 85% | 80% |

---

## 7. REGUŁA KRYTYCZNA DLA AGENTÓW

**Każdy test z tego dokumentu MUSI być zaimplementowany.** Nie wolno:
- Pomijać testów
- Łączyć testów (każdy scenariusz = osobna funkcja testowa)
- Zmieniać oczekiwanych wyników
- Zmieniać nazw testów
- Dodawać testów nie opisanych tutaj (chyba że odkryjesz edge case — wtedy dodaj z komentarzem `// ADDED: <uzasadnienie>`)
