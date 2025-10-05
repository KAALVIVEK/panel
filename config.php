<?php
// Central configuration and security headers for Ztrax

// Database configuration (MUST come from environment)
function require_env(string $key): string {
    $val = getenv($key);
    if ($val === false || $val === '') {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Server misconfiguration.']);
        error_log("Missing required env var: $key");
        exit();
    }
    return $val;
}

define('DB_HOST', require_env('DB_HOST'));
define('DB_USER', require_env('DB_USER'));
define('DB_PASS', require_env('DB_PASS'));
define('DB_NAME', require_env('DB_NAME'));

// Sessions
if (!defined('SESSION_TTL_SECONDS')) {
    define('SESSION_TTL_SECONDS', 60 * 60 * 24 * 3); // 3 days
}

// CORS
const ALLOW_ALL_ORIGINS = false; // set true only for local debugging
const ALLOWED_ORIGINS = [
    'http://localhost',
    'http://localhost:3000',
    'http://127.0.0.1',
    'http://127.0.0.1:3000'
];

function get_request_origin() {
    if (!empty($_SERVER['HTTP_ORIGIN'])) {
        return $_SERVER['HTTP_ORIGIN'];
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    return $host ? ($scheme . '://' . $host) : null;
}

function allow_origin($origin) {
    if (ALLOW_ALL_ORIGINS) {
        return '*';
    }
    if (!$origin) {
        return null;
    }
    foreach (ALLOWED_ORIGINS as $allowed) {
        if (stripos($origin, $allowed) === 0) {
            return $origin;
        }
    }
    return null;
}

function emit_security_headers($origin = null, $json = true) {
    if ($json) {
        header('Content-Type: application/json; charset=UTF-8');
    }
    $allowed = allow_origin($origin ?? get_request_origin());
    if ($allowed) {
        header("Access-Control-Allow-Origin: $allowed");
    }
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    header('Access-Control-Max-Age: 3600');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    // CSP: allow self + required CDNs; tighten as needed
    $csp = [
        "default-src 'self'",
        "base-uri 'none'",
        "object-src 'none'",
        "frame-ancestors 'none'",
        "img-src 'self' data:",
        "style-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com",
        "script-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com https://unpkg.com",
        "font-src 'self' data:",
        "connect-src 'self'",
        'upgrade-insecure-requests',
        'block-all-mixed-content'
    ];
    header('Content-Security-Policy: ' . implode('; ', $csp));
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Embedder-Policy: require-corp');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    }
}

// Encryption helpers removed per request; values are stored in plaintext.
