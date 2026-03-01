/**
 * Validation service for CF Worker Router.
 * Manual validation (no JSON Schema library — CF Workers Free Tier restriction).
 *
 * All error messages must match TESTING-STRATEGY.md exactly.
 */
import { PRODUCT_SCHEMA, REQUEST_SCHEMA } from '../schemas/productSchema.js';

/**
 * Validates a single product payload against the schema.
 *
 * @param {object} product - Product payload to validate
 * @returns {{ valid: boolean, errors: string[] }}
 */
export function validateProduct(product) {
    const errors = [];

    // --- sku ---
    if (product.sku === undefined || product.sku === null) {
        errors.push('sku is required');
    } else if (typeof product.sku !== 'string' || product.sku.length === 0) {
        errors.push('sku is required');
    } else if (product.sku.length > PRODUCT_SCHEMA.SKU_MAX_LENGTH) {
        errors.push('sku must not exceed 64 characters');
    }

    // --- name ---
    if (product.name === undefined || product.name === null) {
        errors.push('name is required');
    } else if (typeof product.name !== 'string' || product.name.length === 0) {
        errors.push('name is required');
    } else if (product.name.length > PRODUCT_SCHEMA.NAME_MAX_LENGTH) {
        errors.push('name must not exceed 128 characters');
    }

    // --- price ---
    if (product.price === undefined || product.price === null) {
        errors.push('price is required');
    } else if (typeof product.price !== 'number' || !isFinite(product.price) || isNaN(product.price)) {
        errors.push('price must be a number');
    } else if (product.price <= 0) {
        errors.push('price must be greater than 0');
    }

    // --- images (optional, validate if present) ---
    if (product.images !== undefined) {
        if (!Array.isArray(product.images)) {
            errors.push('images must be an array');
        } else {
            for (const url of product.images) {
                if (typeof url !== 'string' || (!url.startsWith('http://') && !url.startsWith('https://'))) {
                    errors.push('image URL must start with http:// or https://');
                    break; // report once per field, all bad URLs already checked via TESTING-STRATEGY
                }
            }
        }
    }

    // --- quantity (optional) ---
    if (product.quantity !== undefined) {
        if (!Number.isInteger(product.quantity) || product.quantity < 0) {
            errors.push('quantity must be a non-negative integer');
        }
    }

    // --- weight (optional) ---
    if (product.weight !== undefined) {
        if (typeof product.weight !== 'number' || product.weight < 0) {
            errors.push('weight must be a non-negative number');
        }
    }

    // --- description (optional) ---
    if (product.description !== undefined) {
        if (typeof product.description !== 'string' || product.description.length > PRODUCT_SCHEMA.DESCRIPTION_MAX_LENGTH) {
            errors.push('description must be a string not exceeding 65535 characters');
        }
    }

    // --- description_short (optional) ---
    if (product.description_short !== undefined) {
        if (typeof product.description_short !== 'string' || product.description_short.length > PRODUCT_SCHEMA.DESCRIPTION_SHORT_MAX_LENGTH) {
            errors.push('description_short must be a string not exceeding 800 characters');
        }
    }

    // --- active (optional) ---
    if (product.active !== undefined && typeof product.active !== 'boolean') {
        errors.push('active must be a boolean');
    }

    // --- ean13 (optional) ---
    if (product.ean13 !== undefined) {
        if (typeof product.ean13 !== 'string' || product.ean13.length > PRODUCT_SCHEMA.EAN13_MAX_LENGTH) {
            errors.push('ean13 must be a string not exceeding 13 characters');
        }
    }

    // --- meta_title (optional) ---
    if (product.meta_title !== undefined) {
        if (typeof product.meta_title !== 'string' || product.meta_title.length > PRODUCT_SCHEMA.META_TITLE_MAX_LENGTH) {
            errors.push('meta_title must be a string not exceeding 128 characters');
        }
    }

    // --- meta_description (optional) ---
    if (product.meta_description !== undefined) {
        if (typeof product.meta_description !== 'string' || product.meta_description.length > PRODUCT_SCHEMA.META_DESCRIPTION_MAX_LENGTH) {
            errors.push('meta_description must be a string not exceeding 512 characters');
        }
    }

    return { valid: errors.length === 0, errors };
}

/**
 * Validates a router request body.
 *
 * @param {object} body - Parsed JSON request body
 * @returns {{ valid: boolean, errors: string[], batchSize?: number }}
 */
export function validateRequest(body) {
    const errors = [];

    // --- products ---
    if (!body || body.products === undefined) {
        errors.push('products is required');
        return { valid: false, errors };
    }

    if (!Array.isArray(body.products)) {
        errors.push('products must be an array');
        return { valid: false, errors };
    }

    if (body.products.length === 0) {
        errors.push('products must not be empty');
        return { valid: false, errors };
    }

    if (body.products.length > REQUEST_SCHEMA.PRODUCTS_MAX) {
        errors.push(`products must not exceed ${REQUEST_SCHEMA.PRODUCTS_MAX} items`);
        return { valid: false, errors };
    }

    // --- batchSize ---
    let batchSize = REQUEST_SCHEMA.BATCH_SIZE_DEFAULT;
    if (body.batchSize !== undefined) {
        if (!Number.isInteger(body.batchSize) || body.batchSize < REQUEST_SCHEMA.BATCH_SIZE_MIN) {
            errors.push('batchSize must be an integer >= 1');
            return { valid: false, errors };
        }
        if (body.batchSize > REQUEST_SCHEMA.BATCH_SIZE_MAX) {
            errors.push(`batchSize must not exceed ${REQUEST_SCHEMA.BATCH_SIZE_MAX}`);
            return { valid: false, errors };
        }
        batchSize = body.batchSize;
    }

    return { valid: true, errors: [], batchSize };
}
