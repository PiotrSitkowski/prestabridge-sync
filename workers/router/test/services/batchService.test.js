/**
 * Tests for batchService.js
 * Scenariusze: R-B1..R-B7
 */
import { describe, it, expect } from 'vitest';
import { createBatches } from '../../src/services/batchService.js';

const product = { sku: 'TEST-001', name: 'Test Product', price: 9.99 };

describe('batchService', () => {
    describe('createBatches()', () => {

        // R-B1
        it('R-B1: splits 10 products into 2 batches of 5', () => {
            const result = createBatches(Array(10).fill(product), 5);
            expect(result.length).toBe(2);
            expect(result[0].length).toBe(5);
            expect(result[1].length).toBe(5);
        });

        // R-B2
        it('R-B2: splits 7 products into batch of 5 and batch of 2', () => {
            const result = createBatches(Array(7).fill(product), 5);
            expect(result.length).toBe(2);
            expect(result[0].length).toBe(5);
            expect(result[1].length).toBe(2);
        });

        // R-B3
        it('R-B3: handles single product', () => {
            const result = createBatches([product], 5);
            expect(result.length).toBe(1);
            expect(result[0].length).toBe(1);
        });

        // R-B4
        it('R-B4: creates 50 batches for 50 products with batchSize 1', () => {
            const result = createBatches(Array(50).fill(product), 1);
            expect(result.length).toBe(50);
            expect(result.every(batch => batch.length === 1)).toBe(true);
        });

        // R-B5
        it('R-B5: returns empty array for empty input', () => {
            const result = createBatches([], 5);
            expect(result.length).toBe(0);
        });

        // R-B6
        it('R-B6: preserves product data integrity', () => {
            const products = [
                { sku: 'A', name: 'Product A', price: 10 },
                { sku: 'B', name: 'Product B', price: 20 },
            ];
            const result = createBatches(products, 1);
            expect(result[0][0].sku).toBe('A');
            expect(result[1][0].sku).toBe('B');
        });

        // R-B7
        it('R-B7: handles batchSize larger than product count', () => {
            const result = createBatches(Array(3).fill(product), 10);
            expect(result.length).toBe(1);
            expect(result[0].length).toBe(3);
        });

    });
});
