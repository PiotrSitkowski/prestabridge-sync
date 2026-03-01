/**
 * responseSchema.js — Minimal validation of PrestaShop API response.
 *
 * Validates that the response has the expected shape before processing.
 * Lightweight manual check — no external libraries (CF Workers Free Tier).
 */

/**
 * Validates that a PrestaShop API response has the expected structure.
 *
 * @param {unknown} response - Parsed JSON response from PS endpoint
 * @returns {boolean} True if response is valid PSResponse shape
 */
export function validatePSResponse(response) {
    if (!response || typeof response !== 'object') {
        return false;
    }

    if (typeof response.success !== 'boolean') {
        return false;
    }

    // If results is present, it must be an array
    if ('results' in response && !Array.isArray(response.results)) {
        return false;
    }

    return true;
}
