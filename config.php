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

// (Security helpers moved to external security.php as per deployment preference)

