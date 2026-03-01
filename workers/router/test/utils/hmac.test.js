/**
 * Tests for utils/hmac.js
 * Covers: generateHmac, verifyHmac, generateAuthHeader
 */
import { describe, it, expect } from 'vitest';
import { generateHmac, verifyHmac, generateAuthHeader } from '../../src/utils/hmac.js';

const SECRET = 'test-secret-key-for-hmac-256-minimum-32chars!!';
const BODY = '{"test":"data"}';
const TIMESTAMP = '1709136000';

describe('hmac utils', () => {

    it('generateHmac returns lowercase hex string of 64 chars', async () => {
        const result = await generateHmac(SECRET, TIMESTAMP, BODY);
        expect(typeof result).toBe('string');
        expect(result.length).toBe(64);
        expect(result).toMatch(/^[0-9a-f]+$/);
    });

    it('generateHmac produces same result for same inputs', async () => {
        const r1 = await generateHmac(SECRET, TIMESTAMP, BODY);
        const r2 = await generateHmac(SECRET, TIMESTAMP, BODY);
        expect(r1).toBe(r2);
    });

    it('verifyHmac returns true for correct signature', async () => {
        const sig = await generateHmac(SECRET, TIMESTAMP, BODY);
        const result = await verifyHmac(SECRET, TIMESTAMP, BODY, sig);
        expect(result).toBe(true);
    });

    it('verifyHmac returns false for wrong signature', async () => {
        const result = await verifyHmac(SECRET, TIMESTAMP, BODY, 'a'.repeat(64));
        expect(result).toBe(false);
    });

    it('verifyHmac is case-insensitive (uppercase hex)', async () => {
        const sig = await generateHmac(SECRET, TIMESTAMP, BODY);
        const result = await verifyHmac(SECRET, TIMESTAMP, BODY, sig.toUpperCase());
        expect(result).toBe(true);
    });

    it('generateAuthHeader returns timestamp.signature format', async () => {
        const header = await generateAuthHeader(SECRET, BODY);
        const parts = header.split('.');
        expect(parts.length).toBe(2);
        const ts = parseInt(parts[0], 10);
        expect(ts).toBeGreaterThan(0);
        expect(parts[1].length).toBe(64);
    });

});
