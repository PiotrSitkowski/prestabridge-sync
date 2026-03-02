#!/usr/bin/env node
/**
 * PrestaBridge E2E Import Test
 * Wysyła testowy produkt przez CF Worker Router → Queue → Consumer → PrestaShop
 *
 * Użycie:
 *   AUTH_SECRET=twoj_secret node tests/e2e-import-test.js
 *
 * Lub z custom parametrami:
 *   AUTH_SECRET=secret ROUTER_URL=https://... SKU=TEST-001 node tests/e2e-import-test.js
 */

import { createHmac } from 'crypto';

// ----------------------------------------------------------------
// Konfiguracja (z env lub defaults)
// ----------------------------------------------------------------
const ROUTER_URL = process.env.ROUTER_URL
    ?? 'https://prestabridge-router.meriscrap.workers.dev/import';

const AUTH_SECRET = process.env.AUTH_SECRET;
if (!AUTH_SECRET) {
    console.error('❌ Brak AUTH_SECRET. Ustaw: AUTH_SECRET=twoj_secret node tests/e2e-import-test.js');
    process.exit(1);
}

const SKU = process.env.SKU ?? `TEST-E2E-${Date.now()}`;

// ----------------------------------------------------------------
// Testowy produkt
// ----------------------------------------------------------------
const testProduct = {
    sku: SKU,
    name: 'Testowy produkt E2E PrestaBridge',
    price: 99.99,
    description: '<p>Produkt testowy wygenerowany przez skrypt E2E. Możesz go usunąć.</p>',
    description_short: 'Produkt testowy E2E',
    quantity: 5,
    weight: 1.5,
    // active: true, // ZAKOMENTOWANE: jeśli tego nie wyślesz, system weźmie wartość z konfiguracji Presty (Czy aktywować = false)
    meta_title: 'Test E2E PrestaBridge',
    meta_description: 'Produkt testowy systemu PrestaBridge',
    images: [
        'https://picsum.photos/800/600',
        'https://picsum.photos/800/601',
    ],
};

const payload = {
    products: [testProduct],
    batchSize: 5,
};

// ----------------------------------------------------------------
// Generowanie HMAC (identyczne z GAS i CF Workers)
// Format: timestamp.hex_signature
// Payload: timestamp + '.' + rawBody
// ----------------------------------------------------------------
function generateHmacAuth(secret, body) {
    const timestamp = Math.floor(Date.now() / 1000).toString();
    const payloadStr = timestamp + '.' + body;
    const signature = createHmac('sha256', secret)
        .update(payloadStr)
        .digest('hex');
    return `${timestamp}.${signature}`;
}

// ----------------------------------------------------------------
// Main
// ----------------------------------------------------------------
async function main() {
    const body = JSON.stringify(payload);
    const authHeader = generateHmacAuth(AUTH_SECRET, body);
    const timestamp = authHeader.split('.')[0];

    console.log('');
    console.log('🚀 PrestaBridge E2E Import Test');
    console.log('================================');
    console.log(`📡 Router URL:  ${ROUTER_URL}`);
    console.log(`📦 SKU:         ${SKU}`);
    console.log(`🔑 Timestamp:   ${timestamp} (${new Date(parseInt(timestamp) * 1000).toISOString()})`);
    console.log(`🔐 Auth header: ${authHeader.substring(0, 30)}...`);
    console.log('');

    console.log('📤 Wysyłam do CF Router...');
    let routerResponse;
    try {
        const res = await fetch(ROUTER_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-PrestaBridge-Auth': authHeader,
            },
            body,
        });

        const json = await res.json();
        routerResponse = { status: res.status, data: json };

        console.log(`✅ Router odpowiedź: HTTP ${res.status}`);
        console.log(JSON.stringify(json, null, 2));
    } catch (err) {
        console.error('❌ Błąd połączenia z Routerem:', err.message);
        process.exit(1);
    }

    if (!routerResponse.data.success) {
        console.error('\n❌ Router odrzucił żądanie. Sprawdź AUTH_SECRET.');
        process.exit(1);
    }

    // ----------------------------------------------------------------
    // Raport
    // ----------------------------------------------------------------
    const summary = routerResponse.data.summary ?? {};
    console.log('\n📊 Raport Routera:');
    console.log(`   Produkty przyjęte: ${summary.totalAccepted ?? '?'}`);
    console.log(`   Produkty odrzucone: ${summary.totalRejected ?? '?'}`);
    console.log(`   Paczki do Queue: ${summary.batchesCreated ?? '?'}`);
    console.log(`   Request ID: ${routerResponse.data.requestId ?? 'brak'}`);

    if (routerResponse.data.rejected?.length) {
        console.log('\n⚠️  Odrzucone:');
        routerResponse.data.rejected.forEach(r => {
            console.log(`   SKU=${r.sku}: ${r.errors.join(', ')}`);
        });
    }

    console.log('\n⏳ Produkt trafił do CF Queue → Consumer przetworzy za chwilę...');
    console.log('');
    console.log('🔍 Co sprawdzić teraz:');
    console.log(`   1. CF Logs Consumer: https://dash.cloudflare.com → Workers → prestabridge-consumer → Logs`);
    console.log(`   2. PS Katalog:       https://dev.bikeatelier.pl/wp-admin (lub PS BO → Katalog → Produkty)`);
    console.log(`   3. PS Logi modułu:   PS BO → Modules → PrestaBridge → Configure`);
    console.log(`   4. Szukaj SKU:       ${SKU}`);
    console.log('');
    console.log('✅ Test zakończony. Produkt powinien pojawić się w PS za ~5-30 sekund.');
}

main().catch(err => {
    console.error('💥 Nieoczekiwany błąd:', err);
    process.exit(1);
});
