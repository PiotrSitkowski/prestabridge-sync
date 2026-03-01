---
name: testing
description: Użyj tego skilla gdy zadanie dotyczy pisania lub modyfikacji testów w dowolnym komponencie — zawiera zasady AAA, nazewnictwa, użycia fixtures z /shared/fixtures/ oraz weryfikacji komunikatów błędów.
---

# SKILL: testing

### Kiedy aktywować
Zadanie dotyczy pisania lub modyfikacji testów w dowolnym komponencie.

### Zasada 1: Jeden scenariusz = jedna funkcja testowa
NIE łącz scenariuszy. Nawet jeśli testują tę samą metodę.

### Zasada 2: Arrange-Act-Assert (AAA)
```javascript
// Arrange
const product = { sku: 'TEST', name: 'Test', price: 10 };
// Act
const result = validateProduct(product);
// Assert
expect(result.valid).toBe(true);
```

### Zasada 3: Nazewnictwo
- JS: `it('should reject product without sku', () => { ... })`
- PHP: `public function testRejectsProductWithoutSku(): void { ... }`

### Zasada 4: Fixtures z `/shared/fixtures/`
```javascript
// JS
import validProducts from '../../shared/fixtures/valid-products.json';
const product = validProducts.minimal[0];
```
```php
// PHP
$fixtures = json_decode(
    file_get_contents(__DIR__ . '/../../shared/fixtures/valid-products.json'),
    true
);
$product = $fixtures['minimal'][0];
```

### Zasada 5: Komunikaty błędów testuj dokładnie
NIE testuj tylko `result.valid === false`. ZAWSZE sprawdzaj też `result.errors` zawiera konkretny komunikat. Komunikaty są zdefiniowane w TESTING-STRATEGY.md.

### Zasada 6: Edge cases z fixtures
Plik `edge-cases.json` zawiera gotowe dane graniczne. ZAWSZE ich używaj zamiast tworzyć własne.
