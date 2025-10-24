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
 */

// Base URL for the gateway
define('API_BASE_URL', getenv('API_BASE_URL') ?: 'https://pay.t-g.xyz');

// API key (user token) used to authenticate with the gateway
define('USER_TOKEN', getenv('USER_TOKEN') ?: 'REPLACE_WITH_YOUR_USER_TOKEN');

// Shared secret to validate incoming webhook requests
define('WEBHOOK_SECRET', getenv('WEBHOOK_SECRET') ?: 'REPLACE_WITH_A_STRONG_SECRET');

// Where to store webhook/payment logs
define('PAYMENT_LOG_FILE', getenv('PAYMENT_LOG_FILE') ?: __DIR__ . '/storage/payment_webhook.log');

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

