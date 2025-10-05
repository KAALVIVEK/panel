<?php
// Central configuration and security headers for Ztrax

// Database configuration (override via environment variables in production)
define('DB_HOST', getenv('DB_HOST') ?: 'sql108.ezyro.com');
define('DB_USER', getenv('DB_USER') ?: 'ezyro_40038768');
define('DB_PASS', getenv('DB_PASS') ?: '13579780');
define('DB_NAME', getenv('DB_NAME') ?: 'ezyro_40038768_vivek');

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
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    }
}
