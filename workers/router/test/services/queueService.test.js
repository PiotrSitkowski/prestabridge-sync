/**
 * Tests for queueService.js
 * Scenariusze: R-Q1..R-Q4
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { enqueueBatches } from '../../src/services/queueService.js';

const VALID_PRODUCT = { sku: 'TEST-001', name: 'Test Product', price: 9.99 };

describe('queueService', () => {
    describe('enqueueBatches()', () => {

        let mockQueue;
        beforeEach(() => {
            mockQueue = { send: vi.fn().mockResolvedValue(undefined) };
        });

        // R-Q1
        it('R-Q1: sends correct number of messages for 3 batches', async () => {
            // Arrange
            const batches = [
                [{ ...VALID_PRODUCT, sku: 'A' }],
                [{ ...VALID_PRODUCT, sku: 'B' }],
                [{ ...VALID_PRODUCT, sku: 'C' }],
            ];

            // Act
            await enqueueBatches(mockQueue, batches, 'req-123', { source: 'google-sheets' });

            // Assert
            expect(mockQueue.send).toHaveBeenCalledTimes(3);
        });

        // R-Q2
        it('R-Q2: sends correct QueueMessage format', async () => {
            // Arrange
            const products = [
                { ...VALID_PRODUCT, sku: 'A' },
                { ...VALID_PRODUCT, sku: 'B' },
            ];
            const batches = [products];
            const requestId = 'req-test-123';

            // Act
            await enqueueBatches(mockQueue, batches, requestId, { source: 'google-sheets' });

            // Assert
            expect(mockQueue.send).toHaveBeenCalledTimes(1);
            const sentMessage = mockQueue.send.mock.calls[0][0];
            expect(sentMessage.requestId).toBe(requestId);
            expect(sentMessage.batchIndex).toBe(0);
            expect(sentMessage.totalBatches).toBe(1);
            expect(sentMessage.products.length).toBe(2);
            expect(typeof sentMessage.metadata.enqueuedAt).toBe('string');
            // Validate ISO 8601 format
            expect(() => new Date(sentMessage.metadata.enqueuedAt)).not.toThrow();
            expect(sentMessage.metadata.source).toBe('google-sheets');
        });

        // R-Q3
        it('R-Q3: handles empty batches array (no sends)', async () => {
            // Act
            await enqueueBatches(mockQueue, [], 'req-empty', { source: 'google-sheets' });

            // Assert
            expect(mockQueue.send).not.toHaveBeenCalled();
        });

        // R-Q4
        it('R-Q4: propagates queue.send errors', async () => {
            // Arrange
            mockQueue.send.mockRejectedValue(new Error('Queue full'));
            const batches = [[VALID_PRODUCT]];

            // Act & Assert
            await expect(enqueueBatches(mockQueue, batches, 'req-fail', {})).rejects.toThrow('Queue full');
        });

    });
});
