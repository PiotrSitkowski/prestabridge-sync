/**
 * HMAC-SHA256 utilities for PrestaBridge Consumer authentication.
 * Uses Web Crypto API (only allowed crypto method in CF Workers).
 *
 * Format: X-PrestaBridge-Auth: <unix_timestamp>.<hex_hmac_sha256>
 * Payload: timestamp + '.' + rawBody
 *
 * Identical implementation to workers/router/src/utils/hmac.js (Reguła #5)
 */

const encoder = new TextEncoder();

/**
 * Generates a hex HMAC-SHA256 signature.
 *
 * @param {string} secret - Shared HMAC secret
 * @param {string} timestamp - Unix timestamp (seconds) as string
 * @param {string} body - Raw request body string
 * @returns {Promise<string>} Lowercase hex signature
 */
export async function generateHmac(secret, timestamp, body) {
    const key = await crypto.subtle.importKey(
        'raw',
        encoder.encode(secret),
        { name: 'HMAC', hash: 'SHA-256' },
        false,
        ['sign']
    );

    const payload = encoder.encode(timestamp + '.' + body);
    const signature = await crypto.subtle.sign('HMAC', key, payload);

    return [...new Uint8Array(signature)]
        .map(b => b.toString(16).padStart(2, '0'))
        .join('');
}

/**
 * Verifies a HMAC-SHA256 signature using constant-time comparison.
 *
 * @param {string} secret - Shared HMAC secret
 * @param {string} timestamp - Unix timestamp (seconds) as string
 * @param {string} body - Raw request body string
 * @param {string} receivedSignature - Hex signature to verify (case-insensitive)
 * @returns {Promise<boolean>}
 */
export async function verifyHmac(secret, timestamp, body, receivedSignature) {
    const expectedHex = await generateHmac(secret, timestamp, body);
    const receivedLower = receivedSignature.toLowerCase();

    // Constant-time comparison (no timing attacks)
    if (expectedHex.length !== receivedLower.length) return false;
    let result = 0;
    for (let i = 0; i < expectedHex.length; i++) {
        result |= expectedHex.charCodeAt(i) ^ receivedLower.charCodeAt(i);
    }
    return result === 0;
}

/**
 * Generates a complete auth header value for outgoing requests.
 *
 * @param {string} secret - Shared HMAC secret
 * @param {string} body - Raw request body string
 * @returns {Promise<string>} Header value: "timestamp.signature"
 */
export async function generateAuthHeader(secret, body) {
    const timestamp = Math.floor(Date.now() / 1000).toString();
    const signature = await generateHmac(secret, timestamp, body);
    return `${timestamp}.${signature}`;
}
