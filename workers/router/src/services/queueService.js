/**
 * Queue service for CF Worker Router.
 * Enqueues product batches to CloudFlare Queue.
 *
 * Message format: QueueMessage (see CLAUDE.md §4.4)
 */

/**
 * Enqueues an array of product batches to CloudFlare Queue.
 *
 * @param {object} queue - CF Queue binding (env.PRODUCT_QUEUE)
 * @param {Array<Array>} batches - Array of product batches
 * @param {string} requestId - UUID of the originating request
 * @param {object} [metadata={}] - Additional metadata (source, etc.)
 * @returns {Promise<void>}
 */
export async function enqueueBatches(queue, batches, requestId, metadata = {}) {
    if (batches.length === 0) return;

    const totalBatches = batches.length;
    const enqueuedAt = new Date().toISOString();

    const sends = batches.map((products, batchIndex) => {
        /** @type {import('../schemas/productSchema.js').QueueMessage} */
        const message = {
            requestId,
            batchIndex,
            totalBatches,
            products,
            metadata: {
                enqueuedAt,
                source: metadata.source ?? 'google-sheets',
                ...metadata,
            },
        };
        return queue.send(message);
    });

    await Promise.all(sends);
}
