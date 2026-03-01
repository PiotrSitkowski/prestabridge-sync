/**
 * Tests for authMiddleware.js
 * Scenariusze: R-A1, R-A2, R-A3, R-A4, R-A5, R-A6, R-A7, R-A8
 */
import { describe, it, expect, beforeEach } from 'vitest';
import { verify } from '../../src/middleware/authMiddleware.js';
import { generateHmac } from '../../src/utils/hmac.js';

const TEST_SECRET = 'test-secret-key-for-hmac-256-minimum-32chars!!';
const TEST_BODY = '{"products":[{"sku":"A","name":"B","price":10}]}';

/**
 * Helper: generates a valid X-PrestaBridge-Auth header value.
 * @param {string} secret
 * @param {string} body
 * @param {number} [timestampOverride]
 * @returns {Promise<string>}
 */
async function generateValidAuth(secret, body, timestampOverride) {
    const timestamp = (timestampOverride ?? Math.floor(Date.now() / 1000)).toString();
    const signature = await generateHmac(secret, timestamp, body);
    return `${timestamp}.${signature}`;
}

describe('authMiddleware', () => {
    describe('verify()', () => {

        // R-A1: Brak nagłówka
        it('R-A1: returns invalid when auth header is missing', async () => {
            // Arrange
            const request = new Request('https://example.com', { method: 'POST' });

            // Act
            const result = await verify(request, TEST_SECRET, TEST_BODY);

            // Assert
            expect(result.valid).toBe(false);
            expect(result.error).toBe('Missing auth header');
        });

        // R-A2: Nieprawidłowy format nagłówka
        it('R-A2: returns invalid when auth header format is invalid (no dot)', async () => {
            // Arrange
            const request = new Request('https://example.com', {
                method: 'POST',
                headers: { 'X-PrestaBridge-Auth': 'not-a-valid-format' },
            });

            // Act
            const result = await verify(request, TEST_SECRET, TEST_BODY);

            // Assert
            expect(result.valid).toBe(false);
            expect(result.error).toBe('Invalid auth format');
        });

        it('R-A2b: returns invalid when auth header has only one part', async () => {
            // Arrange
            const request = new Request('https://example.com', {
                method: 'POST',
                headers: { 'X-PrestaBridge-Auth': 'onlyonepart' },
            });

            // Act
            const result = await verify(request, TEST_SECRET, TEST_BODY);

            // Assert
            expect(result.valid).toBe(false);
        });

        it('R-A2c: returns invalid when auth header has three parts (abc.def.ghi)', async () => {
            // Arrange
            const request = new Request('https://example.com', {
                method: 'POST',
                headers: { 'X-PrestaBridge-Auth': 'abc.def.ghi' },
            });

            // Act
            const result = await verify(request, TEST_SECRET, TEST_BODY);

            // Assert
            expect(result.valid).toBe(false);
        });

        // R-A3: Wygasły timestamp (10 min temu)
        it('R-A3: returns invalid when timestamp is expired (10 min ago)', async () => {
            // Arrange
            const expiredTimestamp = Math.floor(Date.now() / 1000) - 600;
            const authHeader = await generateValidAuth(TEST_SECRET, TEST_BODY, expiredTimestamp);
            const request = new Request('https://example.com', {
                method: 'POST',
                headers: { 'X-PrestaBridge-Auth': authHeader },
            });

            // Act
            const result = await verify(request, TEST_SECRET, TEST_BODY);

            // Assert
            expect(result.valid).toBe(false);
            expect(result.error).toContain('expired');
        });

        // R-A4: Nieprawidłowa sygnatura
        it('R-A4: returns invalid when signature is wrong', async () => {
            // Arrange
            const timestamp = Math.floor(Date.now() / 1000).toString();
            const fakeSignature = 'a'.repeat(64);
            const request = new Request('https://example.com', {
                method: 'POST',
                headers: { 'X-PrestaBridge-Auth': `${timestamp}.${fakeSignature}` },
            });

            // Act
            const result = await verify(request, TEST_SECRET, TEST_BODY);

            // Assert
            expect(result.valid).toBe(false);
            expect(result.error).toBe('Invalid signature');
        });

        // R-A5: Prawidłowa autoryzacja
        it('R-A5: returns valid for correct auth', async () => {
            // Arrange
            const authHeader = await generateValidAuth(TEST_SECRET, TEST_BODY);
            const request = new Request('https://example.com', {
                method: 'POST',
                headers: { 'X-PrestaBridge-Auth': authHeader },
            });

            // Act
            const result = await verify(request, TEST_SECRET, TEST_BODY);

            // Assert
            expect(result.valid).toBe(true);
            expect(result.error).toBeUndefined();
        });

        // R-A6: Timestamp z przyszłości (10 min ahead)
        it('R-A6: returns invalid when timestamp is from future (10 min ahead)', async () => {
            // Arrange
            const futureTimestamp = Math.floor(Date.now() / 1000) + 600;
            const authHeader = await generateValidAuth(TEST_SECRET, TEST_BODY, futureTimestamp);
            const request = new Request('https://example.com', {
                method: 'POST',
                headers: { 'X-PrestaBridge-Auth': authHeader },
            });

            // Act
            const result = await verify(request, TEST_SECRET, TEST_BODY);

            // Assert
            expect(result.valid).toBe(false);
            expect(result.error).toContain('expired');
        });

        // R-A7: Puste body
        it('R-A7: handles empty body correctly', async () => {
            // Arrange
            const emptyBody = '';
            const authHeader = await generateValidAuth(TEST_SECRET, emptyBody);
            const request = new Request('https://example.com', {
                method: 'POST',
                headers: { 'X-PrestaBridge-Auth': authHeader },
            });

            // Act
            const result = await verify(request, TEST_SECRET, emptyBody);

            // Assert
            expect(result.valid).toBe(true);
        });

        // R-A8: Uppercase hex signature powinien przejść (case-insensitive)
        it('R-A8: signature verification is case-insensitive (uppercase hex accepted)', async () => {
            // Arrange
            const timestamp = Math.floor(Date.now() / 1000).toString();
            const signature = await generateHmac(TEST_SECRET, timestamp, TEST_BODY);
            const upperSignature = signature.toUpperCase();
            const request = new Request('https://example.com', {
                method: 'POST',
                headers: { 'X-PrestaBridge-Auth': `${timestamp}.${upperSignature}` },
            });

            // Act
            const result = await verify(request, TEST_SECRET, TEST_BODY);

            // Assert
            expect(result.valid).toBe(true);
        });

    });
});
