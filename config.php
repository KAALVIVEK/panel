<?php
// Centralized configuration for database and app secrets.
// Prefer environment variables; fall back to existing values for local dev.

define('DB_HOST', getenv('DB_HOST') ?: 'sql108.ezyro.com');
define('DB_USER', getenv('DB_USER') ?: 'ezyro_40038768');
define('DB_PASS', getenv('DB_PASS') ?: '13579780');
define('DB_NAME', getenv('DB_NAME') ?: 'ezyro_40038768_vivek');

// Secret used to sign auth tokens. Override via APP_SECRET env var in production.
define('APP_SECRET', getenv('APP_SECRET') ?: 'CHANGE_ME_TO_A_LONG_RANDOM_SECRET');

// Helper for base64url encoding/decoding
function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode($data) {
    return base64_decode(strtr($data, '-_', '+/'));
}

// Issue a compact HMAC-SHA256 signed token (JWT-like) with user claims
function issue_token($user_id, $role, $ttl_seconds = 86400) {
    $header = base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $now = time();
    $payload = base64url_encode(json_encode([
        'sub' => $user_id,
        'role' => $role,
        'iat' => $now,
        'exp' => $now + max(300, (int)$ttl_seconds)
    ]));
    $sig = base64url_encode(hash_hmac('sha256', $header . '.' . $payload, APP_SECRET, true));
    return $header . '.' . $payload . '.' . $sig;
}

// Retrieve Authorization header in a server-agnostic way
function get_authorization_header() {
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        return $_SERVER['HTTP_AUTHORIZATION'];
    }
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach ($headers as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) {
                return $v;
            }
        }
    }
    return null;
}

// Verify Bearer token and return claims array ['sub' => user_id, 'role' => role]
function verify_bearer_token_or_throw($auth_header) {
    if (!$auth_header || stripos($auth_header, 'Bearer ') !== 0) {
        throw new Exception('Missing or invalid Authorization header');
    }
    $token = trim(substr($auth_header, 7));
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        throw new Exception('Invalid token');
    }
    list($h, $p, $s) = $parts;
    $expected = base64url_encode(hash_hmac('sha256', $h . '.' . $p, APP_SECRET, true));
    if (!hash_equals($expected, $s)) {
        throw new Exception('Invalid token signature');
    }
    $claims = json_decode(base64url_decode($p), true);
    if (!$claims || !isset($claims['sub'], $claims['role'], $claims['exp'])) {
        throw new Exception('Invalid token claims');
    }
    if (time() >= (int)$claims['exp']) {
        throw new Exception('Token expired');
    }
    return $claims;
}

?>
