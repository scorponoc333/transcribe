<?php
/**
 * JWT HS256 helpers for cross-subdomain SSO.
 * Shared secret comes from cfg('JWT_SECRET'). Tokens minted by the hub
 * at app.jasonai.ca are verified here.
 */
declare(strict_types=1);

function jwt_b64url_encode(string $raw): string {
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function jwt_b64url_decode(string $s): string {
    $pad = strlen($s) % 4;
    if ($pad) $s .= str_repeat('=', 4 - $pad);
    return base64_decode(strtr($s, '-_', '+/'), true) ?: '';
}

/**
 * Verify an HS256 JWT. Returns the payload array on success, null on failure.
 * Enforces exp claim if present.
 */
function jwt_verify(string $token, string $secret): ?array {
    if ($secret === '') return null;
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;

    [$h64, $p64, $s64] = $parts;
    $header = json_decode(jwt_b64url_decode($h64), true);
    if (!is_array($header) || ($header['alg'] ?? '') !== 'HS256' || ($header['typ'] ?? '') !== 'JWT') return null;

    $expected = jwt_b64url_encode(hash_hmac('sha256', "$h64.$p64", $secret, true));
    if (!hash_equals($expected, $s64)) return null;

    $payload = json_decode(jwt_b64url_decode($p64), true);
    if (!is_array($payload)) return null;

    if (isset($payload['exp']) && time() >= (int)$payload['exp']) return null;
    if (isset($payload['nbf']) && time() <  (int)$payload['nbf']) return null;

    return $payload;
}
