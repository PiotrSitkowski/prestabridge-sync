/**
 * prestashopClient.test.js
 * Tests for PrestaBridge PS HTTP client in CF Worker Consumer.
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { sendBatch } from '../../src/services/prestashopClient.js';
import psResponses from '../fixtures/prestashopResponses.json';

const TEST_SECRET = 'test-secret-key-for-hmac-256-minimum-32chars!!';
const TEST_ENDPOINT = 'https://shop.example.com/module/prestabridge/api';

const TEST_PRODUCTS = [
    { sku: 'SKU-001', name: 'Product One', price: 29.99 },
    { sku: 'SKU-002', name: 'Product Two', price: 49.99 },
];

function createMockEnv(overrides = {}) {
    return {
        AUTH_SECRET: TEST_SECRET,
        PRESTASHOP_ENDPOINT: TEST_ENDPOINT,
        REQUEST_TIMEOUT_MS: '5000',
        ...overrides,
    };
}

describe('prestashopClient', () => {
    let fetchSpy;

    beforeEach(() => {
        fetchSpy = vi.fn();
        globalThis.fetch = fetchSpy;
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    // -----------------------------------------------------------------------
    // sendBatch — success path
    // -----------------------------------------------------------------------
    it('should return parsed PSResponse on 200 success', async () => {
        // Arrange
        const env = createMockEnv();
        fetchSpy.mockResolvedValue(
            new Response(JSON.stringify(psResponses.success), { status: 200 })
        );

        // Act
        const result = await sendBatch(TEST_PRODUCTS, env);

        // Assert
        expect(result.success).toBe(true);
        expect(Array.isArray(result.results)).toBe(true);
    });

    // -----------------------------------------------------------------------
    // sendBatch — sends POST to correct URL
    // -----------------------------------------------------------------------
    it('should POST to PRESTASHOP_ENDPOINT', async () => {
        // Arrange
        const env = createMockEnv();
        fetchSpy.mockResolvedValue(
            new Response(JSON.stringify(psResponses.success), { status: 200 })
        );

        // Act
        await sendBatch(TEST_PRODUCTS, env);

        // Assert
        const [url, options] = fetchSpy.mock.calls[0];
        expect(url).toBe(TEST_ENDPOINT);
        expect(options.method).toBe('POST');
    });

    // -----------------------------------------------------------------------
    // sendBatch — sends products in body
    // -----------------------------------------------------------------------
    it('should include products array in request body', async () => {
        // Arrange
        const env = createMockEnv();
        fetchSpy.mockResolvedValue(
            new Response(JSON.stringify(psResponses.success), { status: 200 })
        );

        // Act
        await sendBatch(TEST_PRODUCTS, env);

        // Assert
        const [, options] = fetchSpy.mock.calls[0];
        const body = JSON.parse(options.body);
        expect(body.products).toHaveLength(TEST_PRODUCTS.length);
        expect(body.products[0].sku).toBe('SKU-001');
    });

    // -----------------------------------------------------------------------
    // sendBatch — sends X-PrestaBridge-Auth header
    // -----------------------------------------------------------------------
    it('should send X-PrestaBridge-Auth header in timestamp.hex format', async () => {
        // Arrange
        const env = createMockEnv();
        fetchSpy.mockResolvedValue(
            new Response(JSON.stringify(psResponses.success), { status: 200 })
        );

        // Act
        await sendBatch(TEST_PRODUCTS, env);

        // Assert
        const [, options] = fetchSpy.mock.calls[0];
        const authHeader = options.headers['X-PrestaBridge-Auth'];
        expect(authHeader).toMatch(/^\d+\.[0-9a-f]{64}$/);
    });

    // -----------------------------------------------------------------------
    // sendBatch — sends Content-Type: application/json
    // -----------------------------------------------------------------------
    it('should send Content-Type: application/json header', async () => {
        // Arrange
        const env = createMockEnv();
        fetchSpy.mockResolvedValue(
            new Response(JSON.stringify(psResponses.success), { status: 200 })
        );

        // Act
        await sendBatch(TEST_PRODUCTS, env);

        // Assert
        const [, options] = fetchSpy.mock.calls[0];
        expect(options.headers['Content-Type']).toBe('application/json');
    });

    // -----------------------------------------------------------------------
    // sendBatch — throws on non-200 status
    // -----------------------------------------------------------------------
    it('should throw on PS 500 response', async () => {
        // Arrange
        const env = createMockEnv();
        fetchSpy.mockResolvedValue(
            new Response(JSON.stringify(psResponses.serverError), { status: 500 })
        );

        // Act & Assert
        await expect(sendBatch(TEST_PRODUCTS, env)).rejects.toThrow('500');
    });

    it('should throw on PS 401 response', async () => {
        // Arrange
        const env = createMockEnv();
        fetchSpy.mockResolvedValue(
            new Response(JSON.stringify(psResponses.unauthorized), { status: 401 })
        );

        // Act & Assert
        await expect(sendBatch(TEST_PRODUCTS, env)).rejects.toThrow('401');
    });

    // -----------------------------------------------------------------------
    // sendBatch — uses AbortController for timeout
    // -----------------------------------------------------------------------
    it('should propagate AbortError on timeout', async () => {
        // Arrange
        const env = createMockEnv({ REQUEST_TIMEOUT_MS: '100' });
        const abortError = new DOMException('The operation was aborted', 'AbortError');
        fetchSpy.mockRejectedValue(abortError);

        // Act & Assert
        let caughtError;
        try {
            await sendBatch(TEST_PRODUCTS, env);
        } catch (err) {
            caughtError = err;
        }
        expect(caughtError).toBeDefined();
        expect(caughtError.name).toBe('AbortError');
    });
});
