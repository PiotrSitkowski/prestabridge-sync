/**
 * HMAC authentication middleware for CF Worker Router.
 *
 * Verifies the X-PrestaBridge-Auth header:
 *   Format: <unix_timestamp>.<hex_hmac_sha256>
 *   Payload signed: timestamp + '.' + rawBody
 *   Tolerance: 300 seconds (5 minutes)
 */
import { verifyHmac } from '../utils/hmac.js';

const TIMESTAMP_TOLERANCE_SECONDS = 300;

/**
 * Verifies the HMAC auth header of an incoming request.
 *
 * @param {Request} request - Incoming CF Worker request
 * @param {string} secret - AUTH_SECRET from env
 * @param {string} rawBody - Raw request body string (already read)
 * @returns {Promise<{ valid: boolean, error?: string }>}
 */
export async function verify(request, secret, rawBody) {
    const authHeader = request.headers.get('X-PrestaBridge-Auth');

    if (!authHeader) {
        return { valid: false, error: 'Missing auth header' };
    }

    // The header must have exactly one dot separator: "timestamp.signature"
    const dotIndex = authHeader.indexOf('.');
    if (dotIndex === -1) {
        return { valid: false, error: 'Invalid auth format' };
    }

    const timestamp = authHeader.slice(0, dotIndex);
    const receivedSignature = authHeader.slice(dotIndex + 1);

    // Must be exactly two parts — signature must not contain a dot
    if (!timestamp || !receivedSignature || receivedSignature.includes('.')) {
        return { valid: false, error: 'Invalid auth format' };
    }

    // Validate timestamp is a valid integer
    const tsInt = parseInt(timestamp, 10);
    if (isNaN(tsInt) || tsInt.toString() !== timestamp) {
        return { valid: false, error: 'Invalid auth format' };
    }

    // Check timestamp tolerance
    const now = Math.floor(Date.now() / 1000);
    if (Math.abs(now - tsInt) > TIMESTAMP_TOLERANCE_SECONDS) {
        return { valid: false, error: 'Request expired' };
    }

    // Verify HMAC signature (constant-time)
    const isValid = await verifyHmac(secret, timestamp, rawBody, receivedSignature);
    if (!isValid) {
        return { valid: false, error: 'Invalid signature' };
    }

    return { valid: true };
}
