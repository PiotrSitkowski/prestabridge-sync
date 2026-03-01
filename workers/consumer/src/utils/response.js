/**
 * Response builder helpers for CF Worker Consumer.
 * All responses are JSON with Content-Type: application/json.
 */

const JSON_HEADERS = { 'Content-Type': 'application/json' };

/**
 * Success response.
 *
 * @param {object} data - Response payload
 * @param {number} [status=200] - HTTP status code
 * @returns {Response}
 */
export function success(data, status = 200) {
    return new Response(JSON.stringify({ success: true, ...data }), {
        status,
        headers: JSON_HEADERS,
    });
}

/**
 * Generic error response.
 *
 * @param {string} message - Error description
 * @param {number} [status=500] - HTTP status code
 * @returns {Response}
 */
export function error(message, status = 500) {
    return new Response(JSON.stringify({ success: false, error: message }), {
        status,
        headers: JSON_HEADERS,
    });
}

/**
 * Validation error response (400).
 *
 * @param {string[]} errors - Array of error messages
 * @param {number} [status=400] - HTTP status code
 * @returns {Response}
 */
export function validationError(errors, status = 400) {
    return new Response(JSON.stringify({ success: false, errors }), {
        status,
        headers: JSON_HEADERS,
    });
}
