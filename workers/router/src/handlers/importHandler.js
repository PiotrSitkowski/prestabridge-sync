/**
 * Import handler for CF Worker Router.
 * Handles POST /import requests: auth → parse → validate → batch → enqueue → respond.
 */
import { verify } from '../middleware/authMiddleware.js';
import { validateProduct, validateRequest } from '../services/validationService.js';
import { createBatches } from '../services/batchService.js';
import { enqueueBatches } from '../services/queueService.js';
import * as response from '../utils/response.js';
import * as logger from '../utils/logger.js';

/**
 * Handles a POST /import request.
 *
 * @param {Request} request - Incoming CF Worker request
 * @param {object} env - CF Worker environment bindings
 * @returns {Promise<Response>}
 */
export async function handle(request, env) {
    const url = new URL(request.url);

    // Path routing
    if (url.pathname !== '/import') {
        return response.error('Not found', 404);
    }

    // Method check
    if (request.method !== 'POST') {
        return response.error('Method not allowed', 405);
    }

    // Read raw body (needed for HMAC verification)
    const rawBody = await request.text();

    // Auth verification
    const authResult = await verify(request, env.AUTH_SECRET, rawBody);
    if (!authResult.valid) {
        return response.error(authResult.error ?? 'Unauthorized', 401);
    }

    // Parse JSON
    let body;
    try {
        body = JSON.parse(rawBody);
    } catch {
        return response.error('Invalid JSON body', 400);
    }

    // Validate request structure (products array, batchSize)
    const requestValidation = validateRequest(body);
    if (!requestValidation.valid) {
        return response.validationError(requestValidation.errors);
    }

    const batchSize = requestValidation.batchSize;
    const requestId = crypto.randomUUID();
    const timestamp = new Date().toISOString();

    // Validate individual products, separate valid from rejected
    const validProducts = [];
    const rejected = [];

    for (let i = 0; i < body.products.length; i++) {
        const product = body.products[i];
        const productValidation = validateProduct(product);

        if (productValidation.valid) {
            validProducts.push(product);
        } else {
            rejected.push({
                index: i,
                sku: product.sku ?? null,
                errors: productValidation.errors,
            });
        }
    }

    // Enqueue valid products
    let batchesCreated = 0;
    if (validProducts.length > 0) {
        const batches = createBatches(validProducts, batchSize);
        batchesCreated = batches.length;

        try {
            await enqueueBatches(env.PRODUCT_QUEUE, batches, requestId, {
                source: 'google-sheets',
            });
        } catch (err) {
            logger.error('Queue enqueue failed', { error: err.message }, requestId);
            return response.error('Queue enqueue failed: ' + err.message, 500);
        }
    }

    logger.info('Import completed', {
        totalReceived: body.products.length,
        totalAccepted: validProducts.length,
        totalRejected: rejected.length,
        batchesCreated,
    }, requestId);

    return response.success({
        requestId,
        timestamp,
        summary: {
            totalReceived: body.products.length,
            totalAccepted: validProducts.length,
            totalRejected: rejected.length,
            batchesCreated,
            batchSize,
        },
        rejected,
    });
}
