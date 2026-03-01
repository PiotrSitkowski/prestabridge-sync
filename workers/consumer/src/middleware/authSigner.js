/**
 * authSigner.js — HMAC Auth signer for outgoing requests from Consumer to PrestaShop.
 *
 * Generates X-PrestaBridge-Auth header value for signing requests sent to PS module.
 * Uses identical HMAC format as Router and Apps Script (Reguła #5).
 */

import { generateAuthHeader } from '../utils/hmac.js';

/**
 * Signs a request body and returns the X-PrestaBridge-Auth header value.
 *
 * @param {string} body - Raw JSON body string to sign
 * @param {string} secret - Shared HMAC secret
 * @returns {Promise<string>} Header value in format "timestamp.hexSignature"
 */
export async function sign(body, secret) {
    return generateAuthHeader(secret, body);
}
