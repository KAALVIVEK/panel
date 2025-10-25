<?php
// Auto-prepended request firewall and headers
// Keep minimal logic to avoid breaking app

// Enforce HTTPS redirect (avoid loops)
if ((($_SERVER['HTTPS'] ?? 'off') === 'off') && !in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['OPTIONS'], true)) {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $uri  = $_SERVER['REQUEST_URI'] ?? '/';
    if ($host) {
        header('Location: https://' . $host . $uri, true, 301);
        exit;
    }
}

// Basic header hardening (idempotent)
if (!headers_sent()) {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    }
}

// Simple request filtering: block overly long query strings/body sizes to mitigate DoS
$maxQueryLen = 4096; // 4KB
if (isset($_SERVER['QUERY_STRING']) && strlen($_SERVER['QUERY_STRING']) > $maxQueryLen) {
    http_response_code(414); // URI Too Long
    exit;
}

// Block common malicious payloads early (very conservative)
$rawInput = file_get_contents('php://input');
if ($rawInput !== false && $rawInput !== '' && strlen($rawInput) > 1024 * 1024) { // >1MB
    http_response_code(413);
    exit;
}

// Optional: IP-based rate limit per script (best-effort)
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$script = basename($_SERVER['SCRIPT_NAME'] ?? 'index');
$bucket = __DIR__ . '/storage/ratelimits';
@mkdir($bucket, 0775, true);
$key = substr(hash('sha256', $ip . '|' . $script), 0, 32);
$file = $bucket . '/' . $key . '.json';
$now = time();
$window = 60; // 60s window
$limit = 240; // 240 req/min per script per IP
$state = ['c'=>0,'r'=>$now+$window];
if ($fh = @fopen($file, 'c+')) {
    @flock($fh, LOCK_EX);
    $raw = stream_get_contents($fh);
    if ($raw) { $tmp = json_decode($raw, true); if (is_array($tmp) && isset($tmp['c'],$tmp['r'])) { $state = $tmp; } }
    if ($now > ($state['r'] ?? 0)) { $state = ['c'=>0,'r'=>$now+$window]; }
    $state['c'] = ($state['c'] ?? 0) + 1;
    $allowed = $state['c'] <= $limit;
    ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($state)); fflush($fh); @flock($fh, LOCK_UN); fclose($fh);
    if (!$allowed) { header('Retry-After: '.max(1, ($state['r']-$now))); http_response_code(429); exit; }
}
