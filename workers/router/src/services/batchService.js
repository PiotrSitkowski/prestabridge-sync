/**
 * Batch service for CF Worker Router.
 * Splits an array of products into fixed-size batches.
 * Pure function — no side effects, no business logic.
 */

/**
 * Splits an array of products into batches of the specified size.
 *
 * @param {Array} products - Array of validated ProductPayload objects
 * @param {number} batchSize - Max products per batch
 * @returns {Array<Array>} Array of batches
 */
export function createBatches(products, batchSize) {
    const batches = [];
    for (let i = 0; i < products.length; i += batchSize) {
        batches.push(products.slice(i, i + batchSize));
    }
    return batches;
}
