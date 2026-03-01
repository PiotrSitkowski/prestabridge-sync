/**
 * index.js — Entry point for CF Worker Consumer.
 *
 * Handles queue messages from CF Queue (prestaBridge-product-queue).
 * Each message batch is processed sequentially — if PS is down,
 * we retry with exponential backoff rather than flooding a failing endpoint.
 *
 * Per CLAUDE.md section 8.4 pseudocode.
 */

import * as queueHandler from './handlers/queueHandler.js';
import { calculateBackoff } from './handlers/queueHandler.js';
import * as logger from './utils/logger.js';

export default {
    /**
     * Queue handler — invoked by CF runtime for each batch from Queue.
     *
     * @param {MessageBatch} batch - CF Queue batch
     * @param {object} env - Worker environment bindings
     * @param {ExecutionContext} ctx - Execution context
     */
    async queue(batch, env, ctx) {
        for (const message of batch.messages) {
            try {
                const result = await queueHandler.process(message.body, env);

                if (result.success) {
                    message.ack();
                } else {
                    const delay = calculateBackoff(message.attempts);
                    logger.warning('Retrying queue message', {
                        messageId: message.id,
                        attempts: message.attempts,
                        delaySeconds: delay,
                    });
                    message.retry({ delaySeconds: delay });
                }
            } catch (error) {
                // Unexpected error — log and retry
                logger.error('Unexpected error processing queue message', {
                    messageId: message.id,
                    error: error.message,
                    stack: error.stack,
                });
                message.retry({ delaySeconds: calculateBackoff(message.attempts) });
            }
        }
    },
};
