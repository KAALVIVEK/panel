<?php
declare(strict_types=1);

// Centralized security utilities (include where needed)

function sec_getClientIp(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $trusted = getenv('TRUSTED_PROXIES');
    if ($trusted) {
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

function sec_sendHeaders(array $opts = []): void {
    if (!headers_sent()) {
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('X-XSS-Protection: 0');
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        }
        $ct = $opts['contentType'] ?? null;
        if ($ct === 'json') { header('Content-Type: application/json; charset=UTF-8'); }
        elseif ($ct === 'html') { header('Content-Type: text/html; charset=UTF-8'); }
        if (!empty($opts['cspApi'])) {
            header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
        }
    }
}

function sec_corsAllowOrigin(?array $methods = null, ?array $headers = null): void {
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

function sec_handlePreflight(?array $methods = null, ?array $headers = null): bool {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        sec_corsAllowOrigin($methods ?? ['POST'], $headers ?? ['Content-Type', 'Authorization', 'X-Requested-With']);
        http_response_code(204);
        return true;
    }
    return false;
}

function sec_rateLimit(string $key, int $limit, int $windowSeconds = 60): bool {
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

function sec_sessionEnsure(): void {
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

function sec_csrfToken(): string {
    sec_sessionEnsure();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function sec_csrfValidate(string $token): bool {
    sec_sessionEnsure();
    return !empty($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
