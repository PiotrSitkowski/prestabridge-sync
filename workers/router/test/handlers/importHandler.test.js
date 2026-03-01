/**
 * Tests for importHandler.js (integration)
 * Scenariusze: R-I1..R-I8
 *
 * Tests the full request handling pipeline:
 *   auth → parse JSON → validate → batch → enqueue → response
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { handle } from '../../src/handlers/importHandler.js';
import { generateHmac } from '../../src/utils/hmac.js';

const TEST_SECRET = 'test-secret-key-for-hmac-256-minimum-32chars!!';

const VALID_PRODUCT = { sku: 'TEST-001', name: 'Test Product', price: 9.99 };

/**
 * Helper: builds a POST /import Request with optional auth and body.
 * @param {object} bodyObj - JSON body object
 * @param {string} [authSecret] - If provided, generate auth header
 * @returns {Promise<Request>}
 */
async function buildRequest(bodyObj, authSecret = TEST_SECRET) {
    const body = JSON.stringify(bodyObj);
    const headers = { 'Content-Type': 'application/json' };

    if (authSecret !== null) {
        const timestamp = Math.floor(Date.now() / 1000).toString();
        const signature = await generateHmac(authSecret, timestamp, body);
        headers['X-PrestaBridge-Auth'] = `${timestamp}.${signature}`;
    }

    return new Request('https://worker.example.com/import', {
        method: 'POST',
        body,
        headers,
    });
}

/**
 * Mock env factory
 */
function buildEnv(queueMock) {
    return {
        AUTH_SECRET: TEST_SECRET,
        PRODUCT_QUEUE: queueMock ?? { send: vi.fn().mockResolvedValue(undefined) },
        ENVIRONMENT: 'test',
        MAX_PRODUCTS_PER_REQUEST: '1000',
        MAX_BATCH_SIZE: '50',
        DEFAULT_BATCH_SIZE: '5',
        HMAC_TIMESTAMP_TOLERANCE_SECONDS: '300',
    };
}

describe('importHandler', () => {
    describe('handle()', () => {

        // R-I1: Happy path — 10 valid products, batch 5
        it('R-I1: happy path - 10 valid products, batch 5, returns 200', async () => {
            // Arrange
            const products = Array(10).fill(null).map((_, i) => ({
                sku: `BATCH-${String(i + 1).padStart(3, '0')}`,
                name: `Batch Product ${i + 1}`,
                price: (i + 1) * 10.0,
            }));
            const queueMock = { send: vi.fn().mockResolvedValue(undefined) };
            const env = buildEnv(queueMock);
            const request = await buildRequest({ products, batchSize: 5 });

            // Act
            const response = await handle(request, env);
            const body = await response.json();

            // Assert
            expect(response.status).toBe(200);
            expect(body.success).toBe(true);
            expect(body.summary.totalReceived).toBe(10);
            expect(body.summary.totalAccepted).toBe(10);
            expect(body.summary.totalRejected).toBe(0);
            expect(body.summary.batchesCreated).toBe(2);
            expect(body.rejected.length).toBe(0);
            expect(queueMock.send).toHaveBeenCalledTimes(2);
        });

        // R-I2: Mixed valid and invalid products
        it('R-I2: mixed 8 valid + 2 invalid products', async () => {
            // Arrange
            const validProducts = Array(8).fill(null).map((_, i) => ({
                sku: `VALID-${i + 1}`,
                name: `Valid Product ${i + 1}`,
                price: 10 + i,
            }));
            const invalidProducts = [
                { name: 'No SKU', price: 10 },        // no sku
                { sku: 'NO-PRICE', name: 'No Price' }, // no price
            ];
            const env = buildEnv();
            const request = await buildRequest({ products: [...validProducts, ...invalidProducts] });

            // Act
            const response = await handle(request, env);
            const body = await response.json();

            // Assert
            expect(response.status).toBe(200);
            expect(body.summary.totalAccepted).toBe(8);
            expect(body.summary.totalRejected).toBe(2);
            expect(body.rejected.length).toBe(2);
            expect(body.rejected[0].errors.length).toBeGreaterThan(0);
        });

        // R-I3: All invalid products
        it('R-I3: all invalid products — queue never called', async () => {
            // Arrange
            const queueMock = { send: vi.fn() };
            const env = buildEnv(queueMock);
            const invalidProducts = Array(5).fill({ name: 'No SKU no price' });
            const request = await buildRequest({ products: invalidProducts });

            // Act
            const response = await handle(request, env);
            const body = await response.json();

            // Assert
            expect(response.status).toBe(200);
            expect(body.summary.totalAccepted).toBe(0);
            expect(body.summary.totalRejected).toBe(5);
            expect(queueMock.send).not.toHaveBeenCalled();
        });

        // R-I4: GET method rejected
        it('R-I4: rejects GET method with 405', async () => {
            // Arrange
            const env = buildEnv();
            const request = new Request('https://worker.example.com/import', { method: 'GET' });

            // Act
            const response = await handle(request, env);
            const body = await response.json();

            // Assert
            expect(response.status).toBe(405);
            expect(body.error).toContain('Method not allowed');
        });

        // R-I5: Unknown path → 404
        it('R-I5: returns 404 for unknown path', async () => {
            // Arrange
            const env = buildEnv();
            const request = new Request('https://worker.example.com/unknown', { method: 'POST' });

            // Act
            const response = await handle(request, env);

            // Assert
            expect(response.status).toBe(404);
        });

        // R-I6: Invalid JSON body
        it('R-I6: rejects invalid JSON body with 400', async () => {
            // Arrange
            const timestamp = Math.floor(Date.now() / 1000).toString();
            const body = 'not json{{';
            const signature = await generateHmac(TEST_SECRET, timestamp, body);
            const request = new Request('https://worker.example.com/import', {
                method: 'POST',
                body,
                headers: {
                    'Content-Type': 'application/json',
                    'X-PrestaBridge-Auth': `${timestamp}.${signature}`,
                },
            });
            const env = buildEnv();

            // Act
            const response = await handle(request, env);
            const responseBody = await response.json();

            // Assert
            expect(response.status).toBe(400);
            expect(responseBody.error).toContain('Invalid JSON');
        });

        // R-I7: requestId is UUID in response
        it('R-I7: returns requestId matching UUID format', async () => {
            // Arrange
            const env = buildEnv();
            const request = await buildRequest({ products: [VALID_PRODUCT] });

            // Act
            const response = await handle(request, env);
            const body = await response.json();

            // Assert
            const uuidRegex = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
            expect(body.requestId).toMatch(uuidRegex);
        });

        // R-I8: Missing auth header → 401
        it('R-I8: rejects unauthenticated request with 401', async () => {
            // Arrange
            const env = buildEnv();
            const request = new Request('https://worker.example.com/import', {
                method: 'POST',
                body: JSON.stringify({ products: [VALID_PRODUCT] }),
                headers: { 'Content-Type': 'application/json' },
                // NO X-PrestaBridge-Auth header
            });

            // Act
            const response = await handle(request, env);

            // Assert
            expect(response.status).toBe(401);
        });

    });
});
