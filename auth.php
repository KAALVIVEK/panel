<?php
// =========================================================================
// ZTRAX AUTHENTICATION API - SECURE PHP BACKEND FOR LOGIN/SIGNUP
// =========================================================================
// SECURITY NOTE: This script uses Prepared Statements and password hashing
// to prevent SQL Injection and protect user credentials.
// =========================================================================

require_once __DIR__ . '/config.php';
emit_security_headers();
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// --- 2. Input Handling and Routing ---
$input_json = file_get_contents('php://input');
$input_data = json_decode($input_json, true);

if (!isset($input_data['action'])) {
    http_response_code(400);
    echo json_encode(array("success" => false, "message" => "Missing action parameter."));
    exit;
}

$action = $input_data['action'];

try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        throw new Exception("Database connection failed: " . $conn->connect_error);
    }
    // Lightweight IP-based rate limit for auth endpoints
    $client_ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (in_array($action, ['register_user', 'login_user'], true)) {
        ensureRateLimitTable($conn);
        if (hitRateLimit($conn, 'auth:' . $client_ip . ':' . $action, 10, 60)) {
            http_response_code(429);
            echo json_encode(['success' => false, 'message' => 'Too many requests. Please wait and try again.']);
            $conn->close();
            exit;
        }
    }
    
    switch ($action) {
        case 'register_user':
            $response = handleRegistration($conn, $input_data);
            break;
        case 'login_user':
            $response = handleLogin($conn, $input_data);
            break;
        default:
            $response = array("success" => false, "message" => "Invalid action requested.");
            http_response_code(400);
            break;
    }

} catch (Exception $e) {
    $response = array("success" => false, "message" => "Server Error: " . $e->getMessage());
    http_response_code(500);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}

echo json_encode($response);
exit;

// =========================================================================
// AUTHENTICATION FUNCTIONS
// =========================================================================

function ensureSessionsTable($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS sessions (
        token CHAR(64) PRIMARY KEY,
        user_id VARCHAR(64) NOT NULL,
        ip VARCHAR(45) DEFAULT NULL,
        user_agent VARCHAR(255) DEFAULT NULL,
        expires_at DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (user_id),
        INDEX (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
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

function handleRegistration($conn, $data) {
    $email = filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL);
    $password = $data['password'] ?? '';
    $name = $data['name'] ?? 'Ztrax User'; // fixed: $input → $data
    $referral_code = strtoupper(trim($data['referral_code'] ?? ''));

    if (!$email || empty($password)) {
        http_response_code(400);
        return array("success" => false, "message" => "Invalid email or password.");
    }
    if (empty($referral_code)) {
        http_response_code(400);
        return array("success" => false, "message" => "Referral code is required for signup.");
    }
    if (strlen($password) < 8) {
        http_response_code(400);
        return array("success" => false, "message" => "Password must be at least 8 characters.");
    }

    $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        $stmt->close();
        http_response_code(409);
        return array("success" => false, "message" => "Email already registered. Try logging in.");
    }
    $stmt->close();

    $referral_data = ['initial_balance' => 0.00, 'max_role' => 'user', 'creator_id' => null];
    $stmt = $conn->prepare("SELECT initial_balance, max_role, creator_id FROM referrals WHERE code = ?");
    $stmt->bind_param("s", $referral_code);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows !== 1) {
        http_response_code(400);
        return array("success" => false, "message" => "Invalid referral code.");
    }
    $referral_data = $result->fetch_assoc();
    $stmt->close();
    
    $final_balance = $referral_data['initial_balance'];
    $final_role = $referral_data['max_role'];
    $referred_by_id = $referral_data['creator_id'];
    $final_user_id = uniqid('UID-', true);

    $password_hash = password_hash($password, PASSWORD_DEFAULT);

    $conn->begin_transaction();
    try {
        $sql = "INSERT INTO users (user_id, email, password_hash, role, balance, referred_by_id, name) 
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);

        // fixed: bind_param types corrected
        $stmt->bind_param("ssssdss", 
            $final_user_id, 
            $email, 
            $password_hash, 
            $final_role, 
            $final_balance, 
            $referred_by_id,
            $name
        );
        
        if (!$stmt->execute()) {
            throw new Exception("Database INSERT failed.");
        }
        $stmt->close();

        $conn->commit();
        http_response_code(201);
        return array("success" => true, "message" => "Registration successful. You may now log in.");

    } catch (Exception $e) {
        $conn->rollback();
        error_log("Registration Transaction Failed: " . $e->getMessage());
        http_response_code(500);
        return array("success" => false, "message" => "Registration failed due to a server error. Try again later.");
    }
}

function handleLogin($conn, $data) {
    $email = filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL);
    $password = $data['password'] ?? '';

    if (!$email || empty($password)) {
        http_response_code(400);
        return array("success" => false, "message" => "Invalid email or password format.");
    }

    $stmt = $conn->prepare("SELECT user_id, password_hash, role, status FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows !== 1) {
        $stmt->close();
        return array("success" => false, "message" => "Invalid credentials provided.");
    }

    $user = $result->fetch_assoc();
    $stmt->close();

    if (!password_verify($password, $user['password_hash'])) {
        return array("success" => false, "message" => "Invalid credentials provided.");
    }
    
    if ($user['status'] === 'Blocked') {
        return array("success" => false, "message" => "Access denied. Your account has been blocked.");
    }

    // Issue session token
    ensureSessionsTable($conn);
    $token = bin2hex(random_bytes(32));
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250);
    $ttl = (int)SESSION_TTL_SECONDS;
    $stmt = $conn->prepare("INSERT INTO sessions (token, user_id, ip, user_agent, expires_at) VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))");
    $stmt->bind_param("ssssi", $token, $user['user_id'], $ip, $ua, $ttl);
    $stmt->execute();

    http_response_code(200);
    return array(
        "success" => true,
        "message" => "Login successful.",
        "user_id" => $user['user_id'],
        "role" => $user['role'],
        "token" => $token
    );
}
