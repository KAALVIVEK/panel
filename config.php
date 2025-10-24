<?php
declare(strict_types=1);

// Minimal Payment Gateway Configuration (pay.t-g.xyz)
// Override via environment variables where available

define('USER_TOKEN', getenv('USER_TOKEN') ?: 'YOUR_API_TOKEN_HERE');
define('API_BASE_URL', getenv('API_BASE_URL') ?: 'https://pay.t-g.xyz');
define('DEFAULT_ROUTE', is_numeric(getenv('DEFAULT_ROUTE')) ? (int)getenv('DEFAULT_ROUTE') : 1);
// Redirect landing used by gateway after payment; caller can override per-request
define('GATEWAY_REDIRECT_URL', getenv('GATEWAY_REDIRECT_URL') ?: 'https://pay.t-g.xyz/');

function apiUrl(string $path): string {
    return rtrim(API_BASE_URL, '/') . '/' . ltrim($path, '/');
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
