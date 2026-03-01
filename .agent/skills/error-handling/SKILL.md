---
name: error-handling
description: Użyj tego skilla gdy zadanie dotyczy obsługi błędów, logowania lub diagnostyki w dowolnym komponencie — zawiera wzorce try/catch dla CF Workers i PrestaShop, strategię backoff oraz poziomy logowania.
---

# SKILL: error-handling

### Kiedy aktywować
Zadanie dotyczy obsługi błędów, logowania lub diagnostyki w dowolnym komponencie.

### CF Workers — wzorzec obsługi błędów

#### W handler:
```javascript
try {
  const result = await processRequest(request, env);
  return response.success(result);
} catch (error) {
  logger.error('Request processing failed', {
    error: error.message,
    stack: error.stack,
    requestId
  });
  return response.error('Internal server error', 500);
}
```

#### W queue consumer:
```javascript
for (const message of batch.messages) {
  try {
    const result = await processMessage(message.body, env);
    if (result.success) {
      message.ack();
    } else {
      message.retry({ delaySeconds: backoff(message.attempts) });
    }
  } catch (error) {
    // Jeśli body jest malformed — ack (nie retry, nigdy się nie naprawi)
    if (error instanceof SyntaxError) {
      logger.error('Malformed message, discarding', { messageId: message.id });
      message.ack();
    } else {
      logger.error('Processing failed, retrying', { error: error.message });
      message.retry({ delaySeconds: backoff(message.attempts) });
    }
  }
}
```

### PrestaShop — wzorzec obsługi błędów

#### W kontrolerze:
```php
try {
    $result = ProductImporter::import($payload);
    $results[] = $result;
} catch (\Exception $e) {
    BridgeLogger::error(
        'Import failed: ' . $e->getMessage(),
        ['sku' => $payload['sku'] ?? 'unknown', 'trace' => $e->getTraceAsString()],
        'import',
        $payload['sku'] ?? null
    );
    $results[] = [
        'success' => false,
        'sku' => $payload['sku'] ?? 'unknown',
        'status' => 'error',
        'error' => $e->getMessage()
    ];
}
```

#### Nigdy nie łykaj wyjątków cicho:
```php
// ZŁE:
try { $product->add(); } catch (\Exception $e) { /* cicho */ }

// DOBRE:
try {
    $product->add();
} catch (\Exception $e) {
    BridgeLogger::error('Product add failed', ['error' => $e->getMessage()], 'import');
    throw $e; // lub return error result
}
```

### Backoff strategy (Consumer):
```javascript
function calculateBackoff(attempts) {
  const delays = [10, 30, 60]; // sekundy
  return delays[Math.min(attempts, delays.length - 1)];
}
```

### Poziomy logowania — kiedy którego użyć:
| Poziom | Kiedy | Przykład |
|--------|-------|---------|
| debug | Szczegóły operacji, tylko development | "Processing product SKU-001, mapped fields: ..." |
| info | Pomyślne zakończenie operacji | "Product created: SKU-001, id=42" |
| warning | Pominięte elementy, nieoptymalne sytuacje | "Duplicate SKU-001 skipped (overwrite=false)" |
| error | Operacja nie powiodła się ale system działa | "Image download failed for SKU-001: timeout" |
| critical | System nie może kontynuować | "Database connection lost", "Auth secret not configured" |
