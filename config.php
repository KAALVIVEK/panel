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

// ---------- Field encryption helpers (AEAD) ----------
const ENC_PREFIX_SODIUM = 'enc1s:'; // sodium xchacha20poly1305-ietf
const ENC_PREFIX_OPENSSL = 'enc1o:'; // openssl aes-256-gcm

function get_encryption_key(): string {
    static $cached = null;
    if ($cached !== null) { return $cached; }
    $b64 = require_env('APP_ENC_KEY_B64');
    $key = base64_decode($b64, true);
    if ($key === false || strlen($key) < 32) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Server misconfiguration.']);
        error_log('APP_ENC_KEY_B64 must be base64 of 32+ random bytes');
        exit();
    }
    $cached = substr($key, 0, 32);
    return $cached;
}

function encrypt_field(string $plaintext): string {
    $key = get_encryption_key();
    $aad = 'ztrax:aead:v1';
    if (function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ct = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $aad, $nonce, $key);
        return ENC_PREFIX_SODIUM . base64_encode($nonce . $ct);
    }
    // OpenSSL AES-256-GCM fallback
    $iv = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad, 16);
    if ($ct === false) {
        throw new Exception('Encryption failed');
    }
    return ENC_PREFIX_OPENSSL . base64_encode($iv . $tag . $ct);
}

function decrypt_field(?string $stored): ?string {
    if ($stored === null || $stored === '') return $stored;
    if (strpos($stored, ENC_PREFIX_SODIUM) === 0) {
        $blob = base64_decode(substr($stored, strlen(ENC_PREFIX_SODIUM)), true);
        if ($blob === false) return null;
        $nonce = substr($blob, 0, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ct = substr($blob, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $aad = 'ztrax:aead:v1';
        $key = get_encryption_key();
        $pt = @sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ct, $aad, $nonce, $key);
        return $pt === false ? null : $pt;
    }
    if (strpos($stored, ENC_PREFIX_OPENSSL) === 0) {
        $blob = base64_decode(substr($stored, strlen(ENC_PREFIX_OPENSSL)), true);
        if ($blob === false) return null;
        $iv = substr($blob, 0, 12);
        $tag = substr($blob, 12, 16);
        $ct = substr($blob, 28);
        $aad = 'ztrax:aead:v1';
        $key = get_encryption_key();
        $pt = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad);
        return $pt === false ? null : $pt;
    }
    // Backward compatibility: not encrypted
    return $stored;
}
