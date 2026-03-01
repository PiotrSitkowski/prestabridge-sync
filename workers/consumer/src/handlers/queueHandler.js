/**
 * queueHandler.js — Processes queue messages from CF Queue.
 *
 * Responsibilities:
 *   - Deserialize QueueMessage body
 *   - Call prestashopClient.sendBatch()
 *   - Return { success: boolean } to tell caller whether to ack or retry
 *   - Handle malformed messages (ack — will never be fixed by retry)
 *   - Provide exponential backoff delays
 */

import { sendBatch } from '../services/prestashopClient.js';
import * as logger from '../utils/logger.js';

/**
 * Exponential backoff delays for retry.
 * attempts=0 → 10s, attempts=1 → 30s, attempts≥2 → 60s
 *
 * @param {number} attempts - Number of previous delivery attempts
 * @returns {number} Delay in seconds
 */
export function calculateBackoff(attempts) {
    const delays = [10, 30, 60];
    return delays[Math.min(attempts, delays.length - 1)];
}

/**
 * Processes a single queue message body.
 *
 * Returns { success: true } to signal ack, { success: false } to signal retry.
 * Malformed (unparseable) messages always return { success: true } — ack to discard,
 * because retrying will never fix a structurally broken message.
 *
 * @param {unknown} messageBody - Deserialized message body from CF Queue
 * @param {object} env - CF Worker env bindings
 * @returns {Promise<{ success: boolean }>}
 */
export async function process(messageBody, env) {
    // C-6: Detect malformed/non-object messages — ack immediately (discard)
    if (typeof messageBody === 'string') {
        // This happens if the body is a raw string instead of a parsed object
        logger.error('Malformed queue message: body is a raw string, discarding', {
            bodyPreview: messageBody.slice(0, 100),
        });
        return { success: true };
    }

    if (!messageBody || typeof messageBody !== 'object' || !Array.isArray(messageBody.products)) {
        logger.error('Malformed queue message: missing or invalid products array, discarding', {
            bodyType: typeof messageBody,
        });
        return { success: true };
    }

    const { requestId, batchIndex, products } = messageBody;
    const logCtx = { requestId, batchIndex, productCount: products.length };

    try {
        logger.info('Processing queue batch', logCtx);

        // C-1, C-5: Call PS endpoint
        const psResponse = await sendBatch(products, env);

        // C-5: PS returned 200 — even partial success is treated as ack
        // Individual product errors are logged by PS and tracked in its DB
        logger.info('Batch sent to PrestaShop successfully', {
            ...logCtx,
            psSuccess: psResponse.success,
            resultsCount: psResponse.results ? psResponse.results.length : 0,
        });

        return { success: true };

    } catch (err) {
        // C-2, C-3, C-4: PS unavailable / timeout / auth failed → retry
        logger.error('Failed to send batch to PrestaShop', {
            ...logCtx,
            error: err.message,
            errorName: err.name,
        });

        return { success: false };
    }
}
