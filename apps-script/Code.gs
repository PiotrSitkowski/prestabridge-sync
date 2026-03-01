/**
 * PrestaBridge — Google Apps Script
 *
 * Interfejs użytkownika w Google Sheets do synchronizacji produktów
 * z Arkusza Google → CF Worker Router → PrestaShop.
 *
 * @version 1.0.0
 * @see https://github.com/PiotrSitkowski/prestabridge-sync
 */

// ---------------------------------------------------------------------------
// STAŁE — nazwy właściwości w PropertiesService (NIE wartości, tylko klucze)
// ---------------------------------------------------------------------------
var PROP_WORKER_URL  = 'workerUrl';
var PROP_AUTH_SECRET = 'authSecret';
var PROP_BATCH_SIZE  = 'batchSize';

// Domyślny batch size zgodny z sekcją 4.2 CLAUDE.md
var DEFAULT_BATCH_SIZE = 5;

// ---------------------------------------------------------------------------
// MENU
// ---------------------------------------------------------------------------

/**
 * Dodaje menu "PrestaBridge" do arkusza Google.
 * Wywoływane automatycznie przy otwarciu arkusza (simple trigger).
 */
function onOpen() {
  SpreadsheetApp.getUi()
    .createMenu('PrestaBridge')
    .addItem('Wyślij zaznaczone produkty', 'sendSelectedProducts')
    .addSeparator()
    .addItem('Ustawienia', 'showSettings')
    .addToUi();
}

// ---------------------------------------------------------------------------
// GŁÓWNA LOGIKA WYSYŁKI
// ---------------------------------------------------------------------------

/**
 * Odczytuje zaznaczone wiersze arkusza (kolumna A = true), buduje
 * RouterRequest JSON i wysyła go do CF Worker Router przez POST.
 *
 * Mapowanie kolumn (sekcja 5.2 CLAUDE.md):
 * A=selektor, B=sku, C=name, D=price, E=description, F=description_short,
 * G=images(JSON), H=quantity, I=ean13, J=weight, K=active,
 * L=meta_title, M=meta_description
 */
function sendSelectedProducts() {
  var ui         = SpreadsheetApp.getUi();
  var sheet      = SpreadsheetApp.getActiveSpreadsheet().getActiveSheet();
  var lastRow    = sheet.getLastRow();
  var lastColumn = 13; // kolumny A–M

  if (lastRow < 2) {
    ui.alert('PrestaBridge', 'Arkusz nie zawiera danych produktowych (brak wierszy od wiersza 2).', ui.ButtonSet.OK);
    return;
  }

  // Pobierz ustawienia z PropertiesService
  var props      = PropertiesService.getScriptProperties();
  var workerUrl  = props.getProperty(PROP_WORKER_URL);
  var authSecret = props.getProperty(PROP_AUTH_SECRET);
  var batchSize  = parseInt(props.getProperty(PROP_BATCH_SIZE), 10) || DEFAULT_BATCH_SIZE;

  if (!workerUrl || !authSecret) {
    ui.alert('PrestaBridge', 'Brak konfiguracji. Otwórz menu PrestaBridge → Ustawienia i uzupełnij Worker URL oraz Auth Secret.', ui.ButtonSet.OK);
    return;
  }

  // Odczytaj wszystkie dane (od wiersza 2, bez nagłówka)
  var dataRange  = sheet.getRange(2, 1, lastRow - 1, lastColumn);
  var data       = dataRange.getValues();

  var products         = [];
  var selectedRows     = []; // numery wierszy arkusza (1-indexed) dla odznaczania checkboxów
  var skippedNanPrice  = [];

  for (var i = 0; i < data.length; i++) {
    var row     = data[i];
    var rowNum  = i + 2; // numer wiersza w arkuszu (1-indexed, nagłówek = 1)

    // Kolumna A — checkbox selektor
    if (row[0] !== true) continue;

    // Kolumna D — cena (wymagana, >0)
    var price = parseFloat(row[3]);
    if (isNaN(price) || price <= 0) {
      Logger.log('[PrestaBridge] Wiersz %s: pominięto — nieprawidłowa cena (wartość: %s, SKU: %s)', rowNum, row[3], row[1]);
      skippedNanPrice.push({ row: rowNum, sku: String(row[1] || '').trim() });
      continue;
    }

    // Kolumna G — zdjęcia (opcjonalne, JSON array string)
    var images = [];
    var imagesRaw = row[6];
    if (imagesRaw && String(imagesRaw).trim() !== '') {
      try {
        var parsed = JSON.parse(String(imagesRaw).trim());
        if (Array.isArray(parsed)) {
          images = parsed;
        } else {
          Logger.log('[PrestaBridge] Wiersz %s: kolumna G nie jest tablicą JSON — ustawiono images=[]', rowNum);
        }
      } catch (e) {
        Logger.log('[PrestaBridge] Wiersz %s: błąd JSON.parse kolumny G (%s) — ustawiono images=[]', rowNum, e.message);
      }
    }

    // Zbuduj obiekt ProductPayload (sekcja 4.1 CLAUDE.md)
    var product = {
      sku:               String(row[1]  || '').trim(),
      name:              String(row[2]  || '').trim(),
      price:             price,
      description:       String(row[4]  || ''),
      description_short: String(row[5]  || ''),
      images:            images,
      quantity:          parseInt(row[7],  10) || 0,
      ean13:             String(row[8]  || '').trim(),
      weight:            parseFloat(row[9]) || 0,
      active:            row[10] === true,
      meta_title:        String(row[11] || '').trim(),
      meta_description:  String(row[12] || '').trim()
    };

    products.push(product);
    selectedRows.push(rowNum);
  }

  if (products.length === 0) {
    var msg = 'Nie znaleziono zaznaczonych produktów do wysłania.';
    if (skippedNanPrice.length > 0) {
      msg += '\n\nPominięto ' + skippedNanPrice.length + ' wiersz(y) z powodu braku/nieprawidłowej ceny (sprawdź Logi wykonania).';
    }
    ui.alert('PrestaBridge', msg, ui.ButtonSet.OK);
    return;
  }

  // Zbuduj RouterRequest (sekcja 4.2 CLAUDE.md)
  var requestBody = JSON.stringify({
    products:  products,
    batchSize: batchSize
  });

  // Wygeneruj nagłówek HMAC (sekcja 5.4 CLAUDE.md)
  var authHeader = generateHmacAuth(authSecret, requestBody);

  // Wyślij POST do CF Worker Router
  var options = {
    method:           'post',
    contentType:      'application/json',
    headers:          { 'X-PrestaBridge-Auth': authHeader },
    payload:          requestBody,
    muteHttpExceptions: true
  };

  var response;
  try {
    response = UrlFetchApp.fetch(workerUrl, options);
  } catch (e) {
    Logger.log('[PrestaBridge] Błąd sieciowy UrlFetchApp.fetch: %s', e.message);
    ui.alert('PrestaBridge — Błąd', 'Nie udało się połączyć z Worker URL:\n' + e.message, ui.ButtonSet.OK);
    return;
  }

  var statusCode   = response.getResponseCode();
  var responseText = response.getContentText();

  Logger.log('[PrestaBridge] Response HTTP %s: %s', statusCode, responseText);

  if (statusCode !== 200) {
    ui.alert(
      'PrestaBridge — Błąd HTTP ' + statusCode,
      'Worker zwrócił błąd:\n\n' + responseText,
      ui.ButtonSet.OK
    );
    return;
  }

  // Parsuj odpowiedź
  var responseData;
  try {
    responseData = JSON.parse(responseText);
  } catch (e) {
    Logger.log('[PrestaBridge] Błąd parsowania odpowiedzi JSON: %s', e.message);
    ui.alert('PrestaBridge', 'Wysłano produkty, ale odpowiedź serwera jest nieprawidłowego formatu.', ui.ButtonSet.OK);
    return;
  }

  // Odznacz checkboxy pomyślnie wysłanych produktów
  if (responseData.success) {
    for (var r = 0; r < selectedRows.length; r++) {
      sheet.getRange(selectedRows[r], 1).setValue(false);
    }
  }

  // Podsumowanie
  var summary = responseData.summary || {};
  var rejected = responseData.rejected || [];

  var summaryMsg = [
    'Wyniki wysyłki:',
    '✓ Wysłano produktów:  ' + products.length,
    '✓ Przyjęto:           ' + (summary.totalAccepted !== undefined ? summary.totalAccepted : '—'),
    '✗ Odrzucono:          ' + (summary.totalRejected !== undefined ? summary.totalRejected : rejected.length),
    '  Paczki:             ' + (summary.batchesCreated !== undefined ? summary.batchesCreated : '—')
  ].join('\n');

  if (skippedNanPrice.length > 0) {
    summaryMsg += '\n\n⚠ Pominięto ' + skippedNanPrice.length + ' wiersz(y) — brakująca/nieprawidłowa cena (sprawdź Logi).';
  }

  if (rejected.length > 0) {
    var rejectedSkus = rejected.slice(0, 5).map(function(r) { return r.sku || '#' + r.index; }).join(', ');
    summaryMsg += '\n\n✗ Odrzucone SKU: ' + rejectedSkus;
    if (rejected.length > 5) {
      summaryMsg += ' (i ' + (rejected.length - 5) + ' więcej — sprawdź Logi)';
    }
    Logger.log('[PrestaBridge] Odrzucone produkty: %s', JSON.stringify(rejected));
  }

  ui.alert('PrestaBridge — Wyniki', summaryMsg, ui.ButtonSet.OK);
}

// ---------------------------------------------------------------------------
// USTAWIENIA
// ---------------------------------------------------------------------------

/**
 * Otwiera modal dialog z formularzem ustawień (HtmlService).
 * Zgodnie z zakazem SKILL.md: używamy createHtmlOutputFromFile, nie inline HTML.
 */
function showSettings() {
  var html = HtmlService.createHtmlOutputFromFile('Settings')
    .setWidth(480)
    .setHeight(340);
  SpreadsheetApp.getUi().showModalDialog(html, 'PrestaBridge — Ustawienia');
}

/**
 * Zwraca bieżące ustawienia do wypełnienia formularza Settings.html.
 * Wywoływane przez google.script.run.withSuccessHandler z Settings.html.
 *
 * @returns {{ workerUrl: string, authSecretSet: boolean, batchSize: number }}
 */
function getSettings() {
  var props = PropertiesService.getScriptProperties();
  return {
    workerUrl:     props.getProperty(PROP_WORKER_URL)  || '',
    authSecretSet: !!(props.getProperty(PROP_AUTH_SECRET)),
    batchSize:     parseInt(props.getProperty(PROP_BATCH_SIZE), 10) || DEFAULT_BATCH_SIZE
  };
}

/**
 * Zapisuje ustawienia do PropertiesService.
 * Wywoływana przez google.script.run.saveSettings() z Settings.html.
 *
 * @param {{ workerUrl: string, authSecret: string, batchSize: number }} settings
 */
function saveSettings(settings) {
  if (!settings) throw new Error('Brak danych ustawień.');

  var props = PropertiesService.getScriptProperties();

  var workerUrl = String(settings.workerUrl || '').trim();
  if (workerUrl) {
    props.setProperty(PROP_WORKER_URL, workerUrl);
  }

  // Auth Secret — aktualizuj tylko jeśli podano nową wartość (pole nie może być puste)
  var authSecret = String(settings.authSecret || '').trim();
  if (authSecret) {
    props.setProperty(PROP_AUTH_SECRET, authSecret);
  }

  var batchSize = parseInt(settings.batchSize, 10);
  if (!isNaN(batchSize) && batchSize >= 1 && batchSize <= 50) {
    props.setProperty(PROP_BATCH_SIZE, String(batchSize));
  } else {
    props.setProperty(PROP_BATCH_SIZE, String(DEFAULT_BATCH_SIZE));
  }
}

// ---------------------------------------------------------------------------
// HMAC — DOKŁADNA IMPLEMENTACJA (sekcja 5.4 CLAUDE.md — NIE ZMIENIAĆ)
// ---------------------------------------------------------------------------

/**
 * Generuje wartość nagłówka X-PrestaBridge-Auth w formacie: timestamp.hex_signature
 *
 * Payload HMAC = timestamp + '.' + rawBody
 * Algorytm:     HMAC-SHA256
 * Kodowanie:    hex (lowercase)
 *
 * IDENTYCZNY format jak w CF Workers (crypto.subtle) i PHP (hash_hmac).
 * Reguła #5 project-rules.md — NIE ZMIENIAĆ separatora, payloadu, kodowania.
 *
 * @param {string} secret - Auth Secret (z PropertiesService)
 * @param {string} body   - Surowe ciało żądania (JSON string)
 * @returns {string}      - "timestamp.hexSignature"
 */
function generateHmacAuth(secret, body) {
  var timestamp = Math.floor(Date.now() / 1000).toString();
  var payload   = timestamp + '.' + body;
  var signature = Utilities.computeHmacSha256Signature(payload, secret);
  var signatureHex = signature.map(function(b) {
    return ('0' + (b & 0xFF).toString(16)).slice(-2);
  }).join('');
  return timestamp + '.' + signatureHex;
}
