/**
 * Tests for validationService.js
 * Scenariusze: R-V1..R-V25
 */
import { describe, it, expect } from 'vitest';
import { validateProduct, validateRequest } from '../../src/services/validationService.js';

// --- Fixtures (per Skill testing Zasada 4: import from shared/fixtures via @shared alias) ---
import validProductsFixture from '@shared/fixtures/valid-products.json';
import invalidProductsFixture from '@shared/fixtures/invalid-products.json';


const VALID_MINIMAL = { sku: 'TEST-001', name: 'Test Product', price: 29.99 };
const VALID_FULL = {
    sku: 'TEST-002',
    name: 'Full Product',
    price: 49.99,
    description: '<p>Full description</p>',
    description_short: 'Short desc',
    images: ['https://example.com/img1.jpg', 'https://example.com/img2.png'],
    quantity: 100,
    ean13: '5901234123457',
    weight: 0.5,
    active: true,
    meta_title: 'SEO Title',
    meta_description: 'SEO Description',
};

// ============================================================
// validateProduct()
// ============================================================
describe('validationService', () => {
    describe('validateProduct()', () => {

        // R-V1
        it('R-V1: accepts product with minimum required fields', () => {
            const result = validateProduct(VALID_MINIMAL);
            expect(result.valid).toBe(true);
            expect(result.errors.length).toBe(0);
        });

        // R-V2
        it('R-V2: accepts product with all fields', () => {
            const result = validateProduct(VALID_FULL);
            expect(result.valid).toBe(true);
            expect(result.errors.length).toBe(0);
        });

        // R-V3
        it('R-V3: rejects product without sku', () => {
            const result = validateProduct({ name: 'Test', price: 10 });
            expect(result.valid).toBe(false);
            expect(result.errors).toContain('sku is required');
        });

        // R-V4
        it('R-V4: rejects product without name', () => {
            const result = validateProduct({ sku: 'X', price: 10 });
            expect(result.valid).toBe(false);
            expect(result.errors).toContain('name is required');
        });

        // R-V5
        it('R-V5: rejects product without price', () => {
            const result = validateProduct({ sku: 'X', name: 'Test' });
            expect(result.valid).toBe(false);
            expect(result.errors).toContain('price is required');
        });

        // R-V6
        it('R-V6: rejects product with price = 0', () => {
            const result = validateProduct({ sku: 'X', name: 'Test', price: 0 });
            expect(result.valid).toBe(false);
            expect(result.errors).toContain('price must be greater than 0');
        });

        // R-V7
        it('R-V7: rejects product with negative price', () => {
            const result = validateProduct({ sku: 'X', name: 'Test', price: -5.99 });
            expect(result.valid).toBe(false);
            expect(result.errors).toContain('price must be greater than 0');
        });

        // R-V8
        it('R-V8: rejects product with sku exceeding 64 chars', () => {
            const result = validateProduct({ sku: 'X'.repeat(65), name: 'Test', price: 10 });
            expect(result.valid).toBe(false);
            expect(result.errors).toContain('sku must not exceed 64 characters');
        });

        // R-V9
        it('R-V9: rejects product with empty sku', () => {
            const result = validateProduct({ sku: '', name: 'Test', price: 10 });
            expect(result.valid).toBe(false);
            expect(result.errors).toContain('sku is required');
        });

        // R-V10
        it('R-V10: rejects product when images is not array', () => {
            const result = validateProduct({ ...VALID_MINIMAL, images: 'https://example.com/img.jpg' });
            expect(result.valid).toBe(false);
            expect(result.errors).toContain('images must be an array');
        });

        // R-V11
        it('R-V11: rejects product with invalid image URLs', () => {
            const result = validateProduct({ ...VALID_MINIMAL, images: ['not-a-url', 'ftp://wrong.com/img.jpg'] });
            expect(result.valid).toBe(false);
            expect(result.errors.some(e => e.includes('image URL must start with http'))).toBe(true);
        });

        // R-V12
        it('R-V12: accepts product with empty images array', () => {
            const result = validateProduct({ ...VALID_MINIMAL, images: [] });
            expect(result.valid).toBe(true);
        });

        // R-V13
        it('R-V13: rejects product with non-numeric price', () => {
            const result = validateProduct({ sku: 'X', name: 'Test', price: 'free' });
            expect(result.valid).toBe(false);
            expect(result.errors).toContain('price must be a number');
        });

        // R-V14
        it('R-V14: rejects product with price = NaN', () => {
            const result = validateProduct({ sku: 'X', name: 'Test', price: NaN });
            expect(result.valid).toBe(false);
        });

        // R-V15
        it('R-V15: rejects product with price = Infinity', () => {
            const result = validateProduct({ sku: 'X', name: 'Test', price: Infinity });
            expect(result.valid).toBe(false);
        });

        // R-V16
        it('R-V16: accepts product with unknown extra fields (ignores them)', () => {
            const result = validateProduct({ ...VALID_MINIMAL, unknownField: 'value' });
            expect(result.valid).toBe(true);
        });

        // R-V17
        it('R-V17: accumulates multiple errors', () => {
            const result = validateProduct({ sku: '', name: '', price: -1 });
            expect(result.valid).toBe(false);
            expect(result.errors.length).toBeGreaterThanOrEqual(3);
        });

        // Fixture-based verification (Skill testing Zasada 6)
        it('fixture: shared/fixtures/valid-products.json minimal products all pass', () => {
            for (const product of validProductsFixture.minimal) {
                const result = validateProduct(product);
                expect(result.valid, `Expected ${product.sku} to be valid`).toBe(true);
            }
        });

        it('fixture: shared/fixtures/valid-products.json full products all pass', () => {
            for (const product of validProductsFixture.full) {
                const result = validateProduct(product);
                expect(result.valid, `Expected ${product.sku} to be valid`).toBe(true);
            }
        });

        it('fixture: shared/fixtures/invalid-products.json missing_required all fail', () => {
            for (const product of invalidProductsFixture.missing_required) {
                const { _test_id, _expected_errors, _comment, ...data } = product;
                const result = validateProduct(data);
                expect(result.valid, `Expected ${_test_id} to be invalid`).toBe(false);
            }
        });

    });

    // ============================================================
    // validateRequest()
    // ============================================================
    describe('validateRequest()', () => {

        // R-V18
        it('R-V18: accepts valid request', () => {
            const result = validateRequest({ products: [VALID_MINIMAL], batchSize: 5 });
            expect(result.valid).toBe(true);
        });

        // R-V19
        it('R-V19: rejects request without products key', () => {
            const result = validateRequest({ batchSize: 5 });
            expect(result.valid).toBe(false);
            expect(result.errors).toContain('products is required');
        });

        // R-V20
        it('R-V20: rejects request where products is not array', () => {
            const result = validateRequest({ products: 'string' });
            expect(result.valid).toBe(false);
        });

        // R-V21
        it('R-V21: rejects request with empty products array', () => {
            const result = validateRequest({ products: [] });
            expect(result.valid).toBe(false);
            expect(result.errors).toContain('products must not be empty');
        });

        // R-V22
        it('R-V22: rejects request exceeding 1000 products', () => {
            const result = validateRequest({ products: Array(1001).fill(VALID_MINIMAL) });
            expect(result.valid).toBe(false);
        });

        // R-V23
        it('R-V23: uses default batchSize (5) when not provided', () => {
            const result = validateRequest({ products: [VALID_MINIMAL] });
            expect(result.valid).toBe(true);
            expect(result.batchSize).toBe(5);
        });

        // R-V24
        it('R-V24: rejects batchSize > 50', () => {
            const result = validateRequest({ products: [VALID_MINIMAL], batchSize: 51 });
            expect(result.valid).toBe(false);
        });

        // R-V25
        it('R-V25: rejects batchSize < 1', () => {
            const result = validateRequest({ products: [VALID_MINIMAL], batchSize: 0 });
            expect(result.valid).toBe(false);
        });

    });
});
