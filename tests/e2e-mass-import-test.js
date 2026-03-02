import { createHmac } from 'crypto';

// ----------------------------------------------------------------
// Konfiguracja (z env lub defaults)
// ----------------------------------------------------------------
const ROUTER_URL = process.env.ROUTER_URL
    ?? 'https://prestabridge-router.meriscrap.workers.dev/import';

const AUTH_SECRET = process.env.AUTH_SECRET;
if (!AUTH_SECRET) {
    console.error('❌ Brak AUTH_SECRET. Ustaw: AUTH_SECRET=twoj_secret node tests/e2e-mass-import-test.js');
    process.exit(1);
}

const TOTAL_PRODUCTS = 200;
const BATCH_SIZE = 5;

// ----------------------------------------------------------------
// Generowanie 200 produktów
// ----------------------------------------------------------------
console.log(`Początek generowania ${TOTAL_PRODUCTS} produktów...`);
const products = [];
const baseUrl = `MASS-E2E-${Date.now()}`;

for (let i = 1; i <= TOTAL_PRODUCTS; i++) {
    products.push({
        sku: `${baseUrl}-${i}`,
        name: `Testowy produkt Mass E2E nr ${i}`,
        price: (Math.random() * 100 + 10).toFixed(2) * 1,
        description: `<p>Produkt testowy masowy nr ${i}. Możesz go usunąć.</p>`,
        description_short: `Krótki opis nr ${i}`,
        quantity: Math.floor(Math.random() * 50) + 1,
        weight: 1.5,
        // active: false, // domyślnie false (ustawienie z Presty)
        meta_title: `Test Mass E2E ${i}`,
        meta_description: `Opis masowy ${i}`,
        images: [
            `https://picsum.photos/seed/${baseUrl}-${i}-1/800/600`, // Randomize seed to avoid cache
            `https://picsum.photos/seed/${baseUrl}-${i}-2/800/600`
        ],
    });
}

const payload = {
    products: products,
    batchSize: BATCH_SIZE,
};

// ----------------------------------------------------------------
// Generowanie HMAC
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
    console.log('🚀 PrestaBridge E2E MASS Import Test (200 produktów)');
    console.log('====================================================');
    console.log(`📡 Router URL:  ${ROUTER_URL}`);
    console.log(`📦 Ilość g.:    ${TOTAL_PRODUCTS}`);
    console.log(`📦 Batch Size:  ${BATCH_SIZE}`);
    console.log(`🔑 Timestamp:   ${timestamp} (${new Date(parseInt(timestamp) * 1000).toISOString()})`);
    console.log('');

    console.log('📤 Wysyłam do CF Router...');
    const startTime = performance.now();
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
        const endTime = performance.now();
        console.log(`✅ Router odpowiedź HTTP ${res.status} (czas wykonania: ${((endTime - startTime) / 1000).toFixed(2)}s)`);
    } catch (err) {
        console.error('❌ Błąd połączenia z Routerem:', err.message);
        process.exit(1);
    }

    if (!routerResponse.data.success) {
        console.error('\n❌ Router odrzucił żądanie.');
        console.error(JSON.stringify(routerResponse.data, null, 2));
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
        console.log('\n⚠️  Odrzucone (pierwsze 5):');
        routerResponse.data.rejected.slice(0, 5).forEach(r => {
            console.log(`   SKU=${r.sku}: ${r.errors.join(', ')}`);
        });
        if (routerResponse.data.rejected.length > 5) {
            console.log(`   ... i ${routerResponse.data.rejected.length - 5} więcej.`);
        }
    }

    console.log('\n⏳ Wiadomości wysłane na kolejkę Cloudflare.');
    console.log('Teraz monitoruj logi w terminalu z npx wrangler tail prestabridge-consumer.');
}

main().catch(err => {
    console.error('💥 Nieoczekiwany błąd:', err);
    process.exit(1);
});
