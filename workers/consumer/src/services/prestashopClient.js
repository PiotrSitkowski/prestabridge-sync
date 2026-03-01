/**
 * prestashopClient.js — HTTP client for sending product batches to PrestaShop.
 *
 * Responsible for:
 *   - Building the request body { products }
 *   - Generating HMAC auth header
 *   - Fetching with AbortController timeout
 *   - Parsing response JSON on success
 *   - Throwing on non-200 responses
 */

import { sign } from '../middleware/authSigner.js';
import * as logger from '../utils/logger.js';

/**
 * Sends a batch of products to the PrestaShop endpoint.
 *
 * @param {Array<object>} products - Array of ProductPayload objects
 * @param {object} env - CF Worker env bindings
 * @param {string} env.AUTH_SECRET - Shared HMAC secret
 * @param {string} env.PRESTASHOP_ENDPOINT - PS module API URL
 * @param {string} env.REQUEST_TIMEOUT_MS - Timeout in milliseconds (string)
 * @returns {Promise<object>} Parsed PSResponse JSON
 * @throws {Error} On non-200 HTTP status or network error (including AbortError on timeout)
 */
export async function sendBatch(products, env) {
    const body = JSON.stringify({ products });
    const authHeader = await sign(body, env.AUTH_SECRET);

    const controller = new AbortController();
    const timeoutMs = parseInt(env.REQUEST_TIMEOUT_MS) || 25000;
    const timeoutId = setTimeout(() => controller.abort(), timeoutMs);

    try {
        const response = await fetch(env.PRESTASHOP_ENDPOINT, {
            method: 'POST',
            signal: controller.signal,
            headers: {
                'Content-Type': 'application/json',
                'X-PrestaBridge-Auth': authHeader,
            },
            body,
        });

        if (response.status !== 200) {
            throw new Error(`PS endpoint returned HTTP ${response.status}`);
        }

        return await response.json();
    } catch (err) {
        // Re-throw — let queueHandler decide whether to ack or retry
        if (err.name === 'AbortError') {
            logger.error('PS request timed out', { timeout: timeoutMs });
            throw new DOMException('The operation was aborted', 'AbortError');
        }
        throw err;
    } finally {
        clearTimeout(timeoutId);
    }
}
