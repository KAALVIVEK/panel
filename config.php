<?php
declare(strict_types=1);

// Minimal Payment Gateway Configuration (pay.t-g.xyz)
// Override via environment variables where available

define('USER_TOKEN', getenv('USER_TOKEN') ?: 'YOUR_API_TOKEN_HERE');
define('API_BASE_URL', getenv('API_BASE_URL') ?: 'https://pay.t-g.xyz');
define('DEFAULT_ROUTE', is_numeric(getenv('DEFAULT_ROUTE')) ? (int)getenv('DEFAULT_ROUTE') : 1);
// Default redirect URL used when not provided per request
define('REDIRECT_URL', getenv('REDIRECT_URL') ?: 'https://pay.t-g.xyz/');

// Secret used to sign redirect return parameters (set via env in production)
define('RETURN_HMAC_SECRET', getenv('RETURN_HMAC_SECRET') ?: 'CHANGE_ME_TO_A_LONG_RANDOM_SECRET');

function apiUrl(string $path): string {
    return rtrim(API_BASE_URL, '/') . '/' . ltrim($path, '/');
}

function signReturnParams(string $orderId, string $amountStr, string $userId, int $ts): string {
    $base = $orderId . '|' . $amountStr . '|' . $userId . '|' . $ts;
    return hash_hmac('sha256', $base, RETURN_HMAC_SECRET);
}

function verifyReturnSig(string $orderId, string $amountStr, string $userId, int $ts, string $sig, int $ttlSeconds = 7200): bool {
    if ($ts <= 0) { return false; }
    if (abs(time() - $ts) > $ttlSeconds) { return false; }
    $expected = signReturnParams($orderId, $amountStr, $userId, $ts);
    return hash_equals($expected, $sig);
}

/**
 * Best-effort server-to-server verification with the gateway.
 * Tries common endpoints; returns ['ok'=>bool, 'status'=>string, 'amount'=>string|null]
 */
function verifyGatewayOrderStatus(string $orderId): array {
    $endpoints = [
        '/api/check-order',
        '/api/order-status',
        '/api/get-order',
        '/api/order',
    ];
    $headers = ['Content-Type: application/x-www-form-urlencoded'];
    foreach ($endpoints as $ep) {
        $url = apiUrl($ep);
        $ch = curl_init($url);
        $body = http_build_query(['user_token' => USER_TOKEN, 'order_id' => $orderId]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($resp) || $code < 200 || $code >= 300) { continue; }
        $data = json_decode($resp, true);
        if (!is_array($data)) { continue; }
        // Common shapes
        $status = strtoupper((string)($data['result']['status'] ?? $data['status'] ?? $data['result']['order_status'] ?? ''));
        $amount = (string)($data['result']['amount'] ?? $data['amount'] ?? '');
        if ($status !== '') {
            return ['ok' => true, 'status' => $status, 'amount' => $amount ?: null];
        }
    }
    return ['ok' => false, 'status' => 'UNKNOWN', 'amount' => null];
}

function logPaymentEvent(string $event, array $data = []): void {
    $logFile = __DIR__ . '/storage/payment_logs.log';
    $dir = dirname($logFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $line = '[' . date('c') . '] ' . $event;
    if (!empty($data)) {
        $line .= ' ' . json_encode($data, JSON_UNESCAPED_SLASHES);
    }
    $line .= PHP_EOL;
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

// =========================
// Security Helper Functions
// =========================

/** Returns best-effort client IP (do not trust XFF unless behind trusted proxy) */
function getClientIp(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $trusted = getenv('TRUSTED_PROXIES');
    if ($trusted) {
        // If explicitly configured, trust X-Forwarded-For from known reverse proxies
        $proxies = array_filter(array_map('trim', explode(',', $trusted)));
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        if ($remote && in_array($remote, $proxies, true)) {
            $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
            if ($xff) {
                $parts = array_map('trim', explode(',', $xff));
                if (!empty($parts)) { $ip = $parts[0]; }
            }
        }
    }
    return $ip;
}

/**
 * Sends common security headers. Options:
 * - contentType: 'json'|'html' (default: none)
 * - cspApi: bool set strict CSP for APIs (default: false)
 */
function sendSecurityHeaders(array $opts = []): void {
    // Idempotent-ish: avoid duplicating headers if already set by server
    if (!headers_sent()) {
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('X-XSS-Protection: 0');
        // HSTS only when HTTPS
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        }
        // Content Type convenience
        $ct = $opts['contentType'] ?? null;
        if ($ct === 'json') {
            header('Content-Type: application/json; charset=UTF-8');
        } elseif ($ct === 'html') {
            header('Content-Type: text/html; charset=UTF-8');
        }
        // CSP
        if (!empty($opts['cspApi'])) {
            header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
        }
    }
}

/**
 * Allows CORS for configured origins only. Set ALLOWED_ORIGINS (comma-separated) env.
 * If not set, defaults to same-origin only (no Access-Control-Allow-Origin header).
 */
function corsAllowOrigin(?array $methods = null, ?array $headers = null): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowed = array_filter(array_map('trim', explode(',', getenv('ALLOWED_ORIGINS') ?: '')));
    if ($origin && (in_array('*', $allowed, true) || in_array($origin, $allowed, true))) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        if ($methods) { header('Access-Control-Allow-Methods: ' . implode(', ', $methods)); }
        if ($headers) { header('Access-Control-Allow-Headers: ' . implode(', ', $headers)); }
        header('Access-Control-Max-Age: 3600');
    }
}

/** Returns true if preflight handled */
function handleCorsPreflight(?array $methods = null, ?array $headers = null): bool {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        corsAllowOrigin($methods ?? ['POST'], $headers ?? ['Content-Type', 'Authorization', 'X-Requested-With']);
        http_response_code(204);
        return true;
    }
    return false;
}

/** Simple file-based rate limiter (best-effort). Returns true when allowed, false when limited. */
function rateLimit(string $key, int $limit, int $windowSeconds = 60): bool {
    $dir = __DIR__ . '/storage/ratelimits';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $now = time();
    $hash = substr(hash('sha256', $key), 0, 32);
    $file = $dir . '/' . $hash . '.json';
    $data = ['count' => 0, 'reset' => $now + $windowSeconds];
    $fh = @fopen($file, 'c+');
    if ($fh === false) { return true; }
    @flock($fh, LOCK_EX);
    $raw = stream_get_contents($fh);
    if ($raw) {
        $tmp = json_decode($raw, true);
        if (is_array($tmp) && isset($tmp['count'], $tmp['reset'])) { $data = $tmp; }
    }
    if ($now > ($data['reset'] ?? 0)) { $data = ['count' => 0, 'reset' => $now + $windowSeconds]; }
    $data['count'] = ($data['count'] ?? 0) + 1;
    $allowed = $data['count'] <= $limit;
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($data));
    fflush($fh);
    @flock($fh, LOCK_UN);
    fclose($fh);
    if (!$allowed) {
        header('Retry-After: ' . max(1, ($data['reset'] - $now)));
    }
    return $allowed;
}

// ===== CSRF helpers for HTML forms =====
function ensureSessionConfigured(): void {
    if (session_status() === PHP_SESSION_NONE) {
        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        @session_start();
    }
}

function csrfGetToken(): string {
    ensureSessionConfigured();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfValidate(string $token): bool {
    ensureSessionConfigured();
    return !empty($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

