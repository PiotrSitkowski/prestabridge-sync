---
name: google-apps-script
description: Użyj tego skilla gdy zadanie dotyczy plików w /apps-script/ — zawiera wzorce UrlFetchApp, HMAC dla GAS, PropertiesService, HtmlService oraz zakazy użycia fetch() zamiast UrlFetchApp.
---

# SKILL: google-apps-script

### Kiedy aktywować
Zadanie dotyczy plików w `/apps-script/`.

### Kontekst technologiczny
- Google Apps Script (JavaScript ES5+ z V8 runtime)
- Wbudowane: UrlFetchApp, SpreadsheetApp, PropertiesService, Utilities, HtmlService, Logger
- Limit timeout: **6 minut** (dla trigger-bound), 30s dla simple triggers
- Limit UrlFetchApp: 100 MB/day, 60s timeout per request

### Wzorce obowiązkowe

#### Menu:
```javascript
function onOpen() {
  SpreadsheetApp.getUi()
    .createMenu('PrestaBridge')
    .addItem('Wyślij zaznaczone produkty', 'sendSelectedProducts')
    .addItem('Ustawienia', 'showSettings')
    .addToUi();
}
```

#### HMAC (GAS specyficzny):
```javascript
function generateHmacAuth(secret, body) {
  const timestamp = Math.floor(Date.now() / 1000).toString();
  const payload = timestamp + '.' + body;
  const signature = Utilities.computeHmacSha256Signature(payload, secret);
  const hex = signature.map(function(b) {
    return ('0' + (b & 0xFF).toString(16)).slice(-2);
  }).join('');
  return timestamp + '.' + hex;
}
```

#### HTTP request:
```javascript
const options = {
  method: 'post',
  contentType: 'application/json',
  headers: { 'X-PrestaBridge-Auth': authHeader },
  payload: body,
  muteHttpExceptions: true
};
const response = UrlFetchApp.fetch(url, options);
```

#### Settings dialog:
```javascript
function showSettings() {
  const html = HtmlService.createHtmlOutputFromFile('Settings')
    .setWidth(400)
    .setHeight(300);
  SpreadsheetApp.getUi().showModalDialog(html, 'PrestaBridge Settings');
}
```

### Zakazy bezwzględne
- NIE przechowuj secretów w kodzie — tylko PropertiesService
- NIE używaj fetch() — tylko UrlFetchApp.fetch()
- NIE zapominaj o muteHttpExceptions: true
- NIE buduj HTML inline — używaj HtmlService.createHtmlOutputFromFile()
