/**
 * queueHandler.test.js
 * Tests for CF Worker Consumer queue handler.
 * Scenarios: C-1 through C-8 from TESTING-STRATEGY.md section 2.6
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { process, calculateBackoff } from '../../src/handlers/queueHandler.js';
import queueMessages from '../fixtures/queueMessages.json';
import psResponses from '../fixtures/prestashopResponses.json';

const TEST_SECRET = 'test-secret-key-for-hmac-256-minimum-32chars!!';

/**
 * Creates a mock message object matching CF Queue API.
 */
function createMockMessage(body, attempts = 0) {
    return {
        body,
        id: 'msg-' + Math.random().toString(36).slice(2),
        attempts,
        ack: vi.fn(),
        retry: vi.fn(),
    };
}

/**
 * Creates a mock env object.
 */
function createMockEnv(overrides = {}) {
    return {
        AUTH_SECRET: TEST_SECRET,
        PRESTASHOP_ENDPOINT: 'https://shop.example.com/module/prestabridge/api',
        REQUEST_TIMEOUT_MS: '25000',
        ...overrides,
    };
}

describe('queueHandler', () => {
    let fetchSpy;

    beforeEach(() => {
        fetchSpy = vi.fn();
        globalThis.fetch = fetchSpy;
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    // -----------------------------------------------------------------------
    // TEST C-1: acks message on successful PS response
    // -----------------------------------------------------------------------
    it('C-1: should ack message on successful PS response', async () => {
        // Arrange
        const message = createMockMessage(queueMessages.valid);
        const env = createMockEnv();
        fetchSpy.mockResolvedValue(
            new Response(JSON.stringify(psResponses.success), { status: 200 })
        );

        // Act
        const result = await process(message.body, env);

        // Assert
        expect(result.success).toBe(true);
        expect(fetchSpy).toHaveBeenCalledOnce();
    });

    // -----------------------------------------------------------------------
    // TEST C-2: retries on PS timeout (AbortError)
    // -----------------------------------------------------------------------
    it('C-2: should signal retry on PS timeout (AbortError)', async () => {
        // Arrange
        const message = createMockMessage(queueMessages.valid);
        const env = createMockEnv();
        const abortError = new DOMException('The operation was aborted', 'AbortError');
        fetchSpy.mockRejectedValue(abortError);

        // Act
        const result = await process(message.body, env);

        // Assert
        expect(result.success).toBe(false);
    });

    // -----------------------------------------------------------------------
    // TEST C-3: retries on PS 500
    // -----------------------------------------------------------------------
    it('C-3: should signal retry on PS 500 status', async () => {
        // Arrange
        const message = createMockMessage(queueMessages.valid);
        const env = createMockEnv();
        fetchSpy.mockResolvedValue(
            new Response(JSON.stringify(psResponses.serverError), { status: 500 })
        );

        // Act
        const result = await process(message.body, env);

        // Assert
        expect(result.success).toBe(false);
    });

    // -----------------------------------------------------------------------
    // TEST C-4: retries on PS 401
    // -----------------------------------------------------------------------
    it('C-4: should signal retry on PS 401 status', async () => {
        // Arrange
        const message = createMockMessage(queueMessages.valid);
        const env = createMockEnv();
        fetchSpy.mockResolvedValue(
            new Response(JSON.stringify(psResponses.unauthorized), { status: 401 })
        );

        // Act
        const result = await process(message.body, env);

        // Assert
        expect(result.success).toBe(false);
    });

    // -----------------------------------------------------------------------
    // TEST C-5: acks on PS partial success (some products failed)
    // -----------------------------------------------------------------------
    it('C-5: should ack on PS partial success (some products failed)', async () => {
        // Arrange — PS returns 200 with mixed results
        const message = createMockMessage(queueMessages.valid);
        const env = createMockEnv();
        fetchSpy.mockResolvedValue(
            new Response(JSON.stringify(psResponses.partialSuccess), { status: 200 })
        );

        // Act
        const result = await process(message.body, env);

        // Assert: partial failure is logged by PS, consumer acks the whole batch
        expect(result.success).toBe(true);
    });

    // -----------------------------------------------------------------------
    // TEST C-6: acks malformed queue message (no retry — will never be fixed)
    // -----------------------------------------------------------------------
    it('C-6: should ack malformed queue message (no retry)', async () => {
        // Arrange — body is a string that cannot be processed as QueueMessage
        const malformedBody = 'not valid json {{';
        const env = createMockEnv();

        // Act
        const result = await process(malformedBody, env);

        // Assert: malformed messages are acknowledged (discarded), not retried
        expect(result.success).toBe(true);
        // fetch should NOT have been called — we never reached the PS client
        expect(fetchSpy).not.toHaveBeenCalled();
    });

    // -----------------------------------------------------------------------
    // TEST C-7: uses exponential backoff
    // -----------------------------------------------------------------------
    it('C-7: calculateBackoff returns 10 for attempts=0', () => {
        expect(calculateBackoff(0)).toBe(10);
    });

    it('C-7: calculateBackoff returns 30 for attempts=1', () => {
        expect(calculateBackoff(1)).toBe(30);
    });

    it('C-7: calculateBackoff returns 60 for attempts=2', () => {
        expect(calculateBackoff(2)).toBe(60);
    });

    it('C-7: calculateBackoff returns 60 for attempts >= 3 (cap)', () => {
        expect(calculateBackoff(3)).toBe(60);
        expect(calculateBackoff(10)).toBe(60);
    });

    // -----------------------------------------------------------------------
    // TEST C-8: sends correct HMAC auth header to PS
    // -----------------------------------------------------------------------
    it('C-8: should send X-PrestaBridge-Auth header in correct format', async () => {
        // Arrange
        const message = createMockMessage(queueMessages.singleProduct);
        const env = createMockEnv();
        fetchSpy.mockResolvedValue(
            new Response(JSON.stringify(psResponses.success), { status: 200 })
        );

        // Act
        await process(message.body, env);

        // Assert
        expect(fetchSpy).toHaveBeenCalledOnce();
        const [, fetchOptions] = fetchSpy.mock.calls[0];
        const headers = fetchOptions.headers;

        // Header must exist
        const authHeader = headers['X-PrestaBridge-Auth'];
        expect(authHeader).toBeTruthy();

        // Header must match format: timestamp.hexSignature
        expect(authHeader).toMatch(/^\d+\.[0-9a-f]{64}$/);
    });
});
