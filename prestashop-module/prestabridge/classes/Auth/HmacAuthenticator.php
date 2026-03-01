<?php

declare(strict_types = 1)
;

namespace PrestaBridge\Auth;

/**
 * HMAC-SHA256 authenticator for incoming requests from CF Worker.
 *
 * Header format: X-PrestaBridge-Auth: <timestamp>.<hex_signature>
 * Payload for signing: timestamp + '.' + rawBody
 * Tolerance: ±300 seconds
 */
class HmacAuthenticator
{
    private const TIMESTAMP_TOLERANCE = 300; // 5 minutes

    /**
     * Verify the HMAC authentication header.
     *
     * @param string $authHeader Raw value of X-PrestaBridge-Auth header
     * @param string $body       Raw request body (php://input)
     * @param string $secret     Shared secret from module configuration
     *
     * @return bool True if authentication is valid
     */
    public static function verify(string $authHeader, string $body, string $secret): bool
    {
        if ($authHeader === '') {
            return false;
        }

        // Parse "timestamp.signature"
        $parts = explode('.', $authHeader, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return false;
        }

        [$timestamp, $receivedSignature] = $parts;

        // Validate timestamp is numeric
        if (!ctype_digit($timestamp)) {
            return false;
        }

        $ts = (int)$timestamp;
        $now = time();

        // Check timestamp tolerance (past and future)
        if (abs($now - $ts) > self::TIMESTAMP_TOLERANCE) {
            return false;
        }

        // Compute expected HMAC
        $expectedSignature = hash_hmac('sha256', $timestamp . '.' . $body, $secret);

        // Constant-time comparison
        return hash_equals($expectedSignature, strtolower($receivedSignature));
    }
}
