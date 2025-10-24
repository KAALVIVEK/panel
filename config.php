<?php
declare(strict_types=1);

/**
 * Payment Gateway Configuration
 *
 * Update these values or set the corresponding environment variables in your hosting panel:
 * - USER_TOKEN: Your gateway API key
 * - API_BASE_URL: Base URL for the gateway (default: https://pay.t-g.xyz)
 * - WEBHOOK_SECRET: Secret used to validate webhook requests
 * - PAYMENT_LOG_FILE: Absolute path to a writable log file
 * - PAYOUT_SECRET: Secret key for payout API (Authorization)
 * - WEBHOOK_ALLOWED_IPS: Comma-separated allowlist of IPs for webhook (optional)
 * - PAYOUT_API_PATH: Payout API path (default: /api/payout)
 */

// Base URL for the gateway
define('API_BASE_URL', getenv('API_BASE_URL') ?: 'https://pay.t-g.xyz');

// API key (user token) used to authenticate with the gateway
define('USER_TOKEN', getenv('USER_TOKEN') ?: 'REPLACE_WITH_YOUR_USER_TOKEN');

// Shared secret to validate incoming webhook requests
define('WEBHOOK_SECRET', getenv('WEBHOOK_SECRET') ?: 'REPLACE_WITH_A_STRONG_SECRET');

// Where to store webhook/payment logs
define('PAYMENT_LOG_FILE', getenv('PAYMENT_LOG_FILE') ?: __DIR__ . '/storage/payment_webhook.log');

// Secret for payout API (Authorization: Bearer <PAYOUT_SECRET>)
define('PAYOUT_SECRET', getenv('PAYOUT_SECRET') ?: '');

// Optional comma-separated IP allowlist for webhooks (e.g., "1.2.3.4,5.6.7.8")
define('WEBHOOK_ALLOWED_IPS', getenv('WEBHOOK_ALLOWED_IPS') ?: '');

// Payout API path (joined with API_BASE_URL). Adjust if your gateway differs.
define('PAYOUT_API_PATH', getenv('PAYOUT_API_PATH') ?: '/api/payout');

/**
 * Utility: ensure the log directory exists and is writable.
 */
function ensureLogWritable(): void {
    $dir = dirname(PAYMENT_LOG_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
}

/**
 * Utility: append a line to the payment log with a timestamp.
 */
function logPaymentEvent(string $message, array $context = []): void {
    ensureLogWritable();
    $timestamp = date('c');
    $line = sprintf('[%s] %s %s%s', $timestamp, $message, $context ? json_encode($context, JSON_UNESCAPED_SLASHES) : '', PHP_EOL);
    @file_put_contents(PAYMENT_LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

/**
 * Utility: builds the full API URL from a path (e.g., '/api/create-order').
 */
function apiUrl(string $path): string {
    return rtrim(API_BASE_URL, '/') . '/' . ltrim($path, '/');
}

/** HTML escape helper */
function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Get client IP best-effort */
function clientIp(): string {
    $keys = ['HTTP_CF_CONNECTING_IP','HTTP_X_REAL_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'];
    foreach ($keys as $k) {
        $v = $_SERVER[$k] ?? '';
        if ($v) {
            // X-Forwarded-For can carry multiple; take the first
            if ($k === 'HTTP_X_FORWARDED_FOR' && strpos($v, ',') !== false) {
                $v = trim(explode(',', $v)[0]);
            }
            return $v;
        }
    }
    return '';
}

/** Check if client IP is allowed for webhook (when configured) */
function isWebhookIpAllowed(): bool {
    $allow = trim(WEBHOOK_ALLOWED_IPS);
    if ($allow === '') { return true; }
    $list = array_filter(array_map('trim', explode(',', $allow)));
    $ip = clientIp();
    return $ip !== '' && in_array($ip, $list, true);
}

/** Sanitize monetary amount; ensures positive, finite, and rounded */
function sanitizeAmountNumeric($value, float $default = 10.00, float $min = 1.00): float {
    $amount = is_numeric($value) ? (float)$value : $default;
    if (!is_finite($amount) || $amount <= 0) { $amount = $default; }
    if ($amount < $min) { $amount = $min; }
    return round($amount, 2);
}

/** POST JSON to API and return [http_code, body, curl_error] */
function httpPostJson(string $path, array $payload, array $extraHeaders = []): array {
    $url = apiUrl($path);
    $ch = curl_init($url);
    $headers = array_merge([
        'Content-Type: application/json',
        'Accept: application/json',
    ], $extraHeaders);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    // Enforce HTTPS and SSL verification
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    return [$httpCode, $response, $curlErr];
}

