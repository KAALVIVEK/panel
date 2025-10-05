<?php
require_once __DIR__ . '/config.php';

emit_security_headers();
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

function connectDBorFail() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        error_log('[CONNECT DB ERROR] ' . $conn->connect_error);
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Server error.']);
        exit;
    }
    return $conn;
}

function ensureRateLimitTable($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS rate_limits (
        rl_key VARCHAR(128) PRIMARY KEY,
        count INT NOT NULL DEFAULT 0,
        window_start TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function hitRateLimit($conn, $rlKey, $limit, $windowSeconds) {
    $stmt = $conn->prepare("SELECT count, UNIX_TIMESTAMP(window_start) AS ws FROM rate_limits WHERE rl_key = ?");
    $stmt->bind_param("s", $rlKey);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $now = time();
    if ($row) {
        $ws = (int)$row['ws'];
        if ($now - $ws >= $windowSeconds) {
            $stmt = $conn->prepare("UPDATE rate_limits SET count = 1, window_start = FROM_UNIXTIME(?) WHERE rl_key = ?");
            $stmt->bind_param("is", $now, $rlKey);
            $stmt->execute();
            return false;
        } else {
            if (((int)$row['count']) + 1 > $limit) {
                return true;
            }
            $stmt = $conn->prepare("UPDATE rate_limits SET count = count + 1 WHERE rl_key = ?");
            $stmt->bind_param("s", $rlKey);
            $stmt->execute();
            return false;
        }
    } else {
        $stmt = $conn->prepare("INSERT INTO rate_limits (rl_key, count, window_start) VALUES (?, 1, FROM_UNIXTIME(?))");
        $stmt->bind_param("si", $rlKey, $now);
        $stmt->execute();
        return false;
    }
}

function getDurationHours($duration_id) {
    $map = [
        'opt1' => 5,
        'opt2' => 24,
        'opt3' => 72,
        'opt4' => 168,
        'opt5' => 360,
        'opt6' => 720,
        'opt7' => 1440,
    ];
    return isset($map[$duration_id]) ? (int)$map[$duration_id] : 24;
}

// Optional shared secret header to reduce abuse (set APP_SHARED_SECRET env)
$secret = getenv('APP_SHARED_SECRET') ?: '';
if ($secret !== '') {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $clientSecret = $headers['X-Client-Secret'] ?? $headers['x-client-secret'] ?? '';
    if (!hash_equals($secret, $clientSecret)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) { $input = []; }
$key_string = trim($input['key_string'] ?? $input['key'] ?? '');
$device_id = $input['device_id'] ?? $input['device'] ?? null;
if ($key_string === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing key_string']);
    exit;
}

$conn = connectDBorFail();
// Basic per-IP rate limit
ensureRateLimitTable($conn);
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (hitRateLimit($conn, 'connect:' . $ip, 60, 60)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Too many requests. Slow down.']);
    $conn->close();
    exit;
}

// Fetch license
$stmt = $conn->prepare("SELECT license_id, duration, max_devices, devices_used, status, expires FROM licenses WHERE key_string = ?");
$stmt->bind_param("s", $key_string);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'License not found']);
    $conn->close();
    exit;
}
if ($row['status'] === 'Banned' || $row['status'] === 'Deleted') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'License is not active']);
    $conn->close();
    exit;
}
if (!is_null($row['expires']) && strtotime($row['expires']) < time()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'License expired']);
    $conn->close();
    exit;
}

// Activate or increment usage
if ($row['expires'] === NULL) {
    $hours = getDurationHours($row['duration'] ?? 'opt2');
    $stmt = $conn->prepare("UPDATE licenses SET expires = DATE_ADD(NOW(), INTERVAL ? HOUR), status = 'Active', devices_used = LEAST(max_devices, devices_used + 1), linked_device_id = IFNULL(?, linked_device_id) WHERE license_id = ?");
    $stmt->bind_param("isi", $hours, $device_id, $row['license_id']);
    $stmt->execute();
} else if ((int)$row['devices_used'] < (int)$row['max_devices']) {
    $stmt = $conn->prepare("UPDATE licenses SET devices_used = devices_used + 1, linked_device_id = IFNULL(?, linked_device_id) WHERE license_id = ?");
    $stmt->bind_param("si", $device_id, $row['license_id']);
    $stmt->execute();
} else {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Device limit reached']);
    $conn->close();
    exit;
}

// Return current state
$stmt = $conn->prepare("SELECT devices_used, max_devices, status, expires FROM licenses WHERE license_id = ?");
$stmt->bind_param("i", $row['license_id']);
$stmt->execute();
$info = $stmt->get_result()->fetch_assoc();

http_response_code(200);
echo json_encode(['success' => true, 'data' => $info]);
$conn->close();
