<?php
// =========================================================================
// ZTRAX DASHBOARD API - SECURE PHP BACKEND
// =========================================================================

// Set headers for CORS and JSON response
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// --- 1. DATABASE CONFIGURATION (LIVE CREDENTIALS) ---
define('DB_HOST', 'sql108.ezyro.com');
define('DB_USER', 'ezyro_40038768');
define('DB_PASS', '13579780');
define('DB_NAME', 'ezyro_40038768_vivek');

// --- 2. CORE UTILITIES ---

/**
 * Connects to the database or throws an Exception on failure.
 */
function connectDB() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        throw new Exception("Database connection failed: " . $conn->connect_error);
    }
    return $conn;
}

/**
 * Checks if the current user role is sufficient for the required action.
 */
function checkRole($currentRole, $requiredRole) {
    $roles = ['user' => 1, 'reseller' => 2, 'admin' => 3, 'owner' => 4];
    if (!isset($roles[$currentRole]) || !isset($roles[$requiredRole])) {
        return false;
    }
    return $roles[$currentRole] >= $roles[$requiredRole];
}


// --- 3. INPUT HANDLING ---
$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, true);

// Check if input is valid
if (!isset($input['action'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid API request format.']);
    exit();
}

$action = $input['action'];
$user_id = $input['user_id'] ?? null;
$role = $input['role'] ?? null;

// --- 4. API ROUTING ---
try {
    switch ($action) {
        case 'load_initial_data':
            loadInitialData($user_id);
            break;
        
        case 'load_licenses':
            loadLicenses($user_id, $role);
            break;
        case 'create_license':
            createLicense($user_id, $role, $input);
            break;
        case 'reset_license':
            resetLicense($user_id, $role, $input['license_id']);
            break;
        case 'update_license_details':
            updateLicenseDetails($user_id, $role, $input);
            break;
        case 'ban_license':
            banLicense($user_id, $role, $input['license_id']);
            break;
        case 'delete_license':
            deleteLicense($user_id, $role, $input['license_id']);
            break;
        
        case 'load_referrals':
            loadReferrals($role);
            break;
        case 'create_referral':
            createReferral($user_id, $role, $input);
            break;
        case 'delete_referral':
            deleteReferral($user_id, $role, $input['code'] ?? '');
            break;
        case 'load_managed_users':
            loadManagedUsers($user_id, $role);
            break;
        case 'admin_add_balance':
            adminAddBalance($user_id, $role, $input['target_user_id'], (float)($input['amount'] ?? 0));
            break;
        case 'admin_change_role':
            adminChangeRole($user_id, $role, $input['target_user_id'], $input['new_role'] ?? 'user');
            break;
        case 'load_license_info':
            loadLicenseInfo($user_id, $role, (int)($input['license_id'] ?? 0));
            break;
        case 'admin_reset_client_key':
            adminResetClientKey($user_id, $role, $input['license_id']);
            break;
        case 'admin_reset_all_licenses':
            adminResetAllLicenses($user_id, $role);
            break;
        case 'admin_delete_all_licenses':
            adminDeleteAllLicenses($user_id, $role);
            break;
        case 'admin_extend_all_licenses':
            adminExtendAllLicenses($user_id, $role, (float)($input['extra_days'] ?? 0));
            break;
        
        case 'load_system_keys':
            loadSystemKeys($role);
            break;
        case 'generate_system_key':
            generateSystemKey($user_id, $role, $input);
            break;
        case 'owner_generate_api_key':
            ownerGenerateApiKey($user_id, $role, (float)($input['amount'] ?? 0));
            break;

        case 'get_service_status':
            getServiceStatus();
            break;
        case 'owner_set_key_generation':
            ownerSetKeyGeneration($user_id, $role, (bool)($input['enabled'] ?? false));
            break;

        case 'reset_user_login_key':
            resetUserLoginKey($user_id, $role, $input['target_user_id']);
            break;
        case 'block_user':
            blockUser($user_id, $role, $input['target_user_id']);
            break;
        case 'delete_user_account':
            deleteUserAccount($user_id, $role, $input['target_user_id']);
            break;
        case 'change_password':
            changePassword($user_id, $input['current_password'] ?? '', $input['new_password'] ?? '');
            break;
        case 'check_license':
            checkLicense($user_id, $role, (int)($input['license_id'] ?? 0), $input['device_id'] ?? null);
            break;
        case 'extend_license_time':
            extendLicenseTime($user_id, $role, (int)($input['license_id'] ?? 0), (float)($input['extra_days'] ?? 0));
            break;
        case 'owner_reset_all_licenses':
            ownerResetAllLicenses($user_id, $role);
            break;
        case 'owner_delete_all_licenses':
            ownerDeleteAllLicenses($user_id, $role);
            break;
        case 'owner_extend_all_licenses':
            ownerExtendAllLicenses($user_id, $role, (float)($input['extra_days'] ?? 0));
            break;

        case 'owner_bulk_add_keys':
            ownerBulkAddKeys($user_id, $role, $input['keys'] ?? [], $input['name'] ?? 'SYSTEM');
            break;
        case 'get_pricing':
            getPricing($role);
            break;
        case 'owner_update_pricing':
            ownerUpdatePricing($user_id, $role, $input['pricing'] ?? []);
            break;

        // Token-based API (for bots/external integrations)
        case 'api_create_license':
            apiCreateLicense($input);
            break;
        case 'api_reset_license':
            apiResetLicense($input);
            break;
        case 'api_delete_license':
            apiDeleteLicense($input);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Unknown API action specified.']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server Error: ' . $e->getMessage()]);
}


// --- 5. FUNCTION IMPLEMENTATIONS ---

/**
 * Loads essential user data (role, balance, active keys) for dashboard rendering.
 */
function loadInitialData($user_id) {
    $conn = connectDB();
    
    $stmt = $conn->prepare("SELECT role, balance, email, referred_by_id FROM users WHERE user_id = ?");
    $stmt->bind_param("s", $user_id);
    $stmt->execute();
    $userData = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$userData) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User data not found. Please re-login.']);
        $conn->close();
        return;
    }

    $stmt = $conn->prepare("SELECT COUNT(*) as active_keys FROM licenses WHERE creator_id = ? AND status != 'Deleted'");
    $stmt->bind_param("s", $user_id);
    $stmt->execute();
    $keyData = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    // Derive a rough key rate from recent activity (keys created in last 10 minutes)
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM licenses WHERE created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
    $stmt->execute();
    $kr = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $key_rate = round(($kr['c'] ?? 0) / 10, 2);

    // Recent activity (10 most recent)
    $actRes = $conn->query("SELECT created_at AS ts, license_id, status FROM licenses ORDER BY created_at DESC LIMIT 10");
    $activity = [];
    if ($actRes) {
        while ($r = $actRes->fetch_assoc()) { $activity[] = $r; }
    }

    $data = [
        'role' => $userData['role'],
        'balance' => (float)$userData['balance'],
        'active_keys' => (int)$keyData['active_keys'],
        'key_rate' => $key_rate,
        'email' => $userData['email'],
        'referred_by_status' => $userData['referred_by_id'],
        'recent_activity' => $activity
    ];

    echo json_encode(['success' => true, 'data' => $data]);
    $conn->close();
}


/**
 * Helper function to define the visibility filter for licenses based on user role.
 */
function getLicenseFilterSQL($current_user_id, $current_role) {
    if ($current_role === 'owner') {
        return ['WHERE 1=1', []];
    } elseif ($current_role === 'admin') {
        $conn = connectDB();
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE referred_by_id = ? OR user_id = ?");
        $stmt->bind_param("ss", $current_user_id, $current_user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $managed_ids = [$current_user_id];
        while ($row = $result->fetch_assoc()) {
            $managed_ids[] = $row['user_id'];
        }
        $in_clause = implode("','", $managed_ids);
        return ["WHERE creator_id IN ('$in_clause')", []];
        
    } else {
        return ['WHERE creator_id = ?', [$current_user_id]];
    }
}


/**
 * Loads licenses based on the user's role and visibility rules.
 */
function loadLicenses($user_id, $role) {
    try {
        $conn = connectDB();
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'DB Connection Error: ' . $e->getMessage()]);
        return;
    }
    
    list($where_clause, $params) = getLicenseFilterSQL($user_id, $role);
    
    $sql = "SELECT license_id, key_string, game_package, duration, max_devices, devices_used, status, created_at, expires FROM licenses $where_clause ORDER BY created_at DESC";
    
    $result = false;
    
    try {
        if (!empty($params)) {
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception("SQL Prepare failed: " . $conn->error);
            }
            $stmt->bind_param(str_repeat('s', count($params)), ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            $stmt->close();
        } else {
            $result = $conn->query($sql);
            if (!$result) {
                 throw new Exception("SQL Query failed: " . $conn->error);
            }
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'License Load Failed: ' . $e->getMessage()]);
        $conn->close();
        return;
    }
    
    $licenses = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $licenses[] = $row;
        }
    }
    
    echo json_encode(['success' => true, 'data' => $licenses]);
    $conn->close();
}


/**
 * Maps duration id to hours for expiry computation.
 */
function getDurationHours($duration_id) {
    // Support custom formats: h:<hours> or d:<days>
    if (is_string($duration_id)) {
        if (preg_match('/^h:(\d{1,5})$/', $duration_id, $m)) {
            return max(1, (int)$m[1]);
        }
        if (preg_match('/^d:(\d{1,5})$/', $duration_id, $m)) {
            return max(1, (int)$m[1]) * 24;
        }
    }
    $map = [
        'opt1' => 5,     // 5 hours
        'opt2' => 24,    // 1 day
        'opt3' => 72,    // 3 days
        'opt4' => 168,   // 7 days
        'opt5' => 360,   // 15 days
        'opt6' => 720,   // 30 days
        'opt7' => 1440,  // 60 days
    ];
    return isset($map[$duration_id]) ? (int)$map[$duration_id] : 24;
}

/** Service flags helpers **/
function ensureServiceFlagsTable($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS service_flags (flag VARCHAR(64) PRIMARY KEY, value VARCHAR(16) NOT NULL DEFAULT '0', updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
}

function getServiceStatus() {
    $conn = connectDB();
    ensureServiceFlagsTable($conn);
    $res = $conn->query("SELECT value FROM service_flags WHERE flag='key_generation_enabled' LIMIT 1");
    $enabled = ($res && $row = $res->fetch_assoc()) ? $row['value'] === '1' : true; // default enabled
    echo json_encode(['success' => true, 'data' => ['key_generation_enabled' => $enabled]]);
    $conn->close();
}

/** Ensure system_keys table exists for pre-added keys pool */
function ensureSystemKeysTable($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS system_keys (
        key_string VARCHAR(64) PRIMARY KEY,
        name VARCHAR(128) NOT NULL,
        created_by_id VARCHAR(36) NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'Generated',
        duration VARCHAR(16) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    // Best-effort schema upgrade if column didn't exist before
    @ $conn->query("ALTER TABLE system_keys ADD COLUMN duration VARCHAR(16) NULL");
}

/** Pricing helpers **/
function ensurePricingTable($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS pricing (
        duration_id VARCHAR(16) PRIMARY KEY,
        price DECIMAL(10,2) NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
}

function getDefaultPricing() {
    return [
        'opt1' => 30.00,
        'opt2' => 60.00,
        'opt3' => 150.00,
        'opt4' => 250.00,
        'opt5' => 400.00,
        'opt6' => 600.00,
        'opt7' => 800.00,
    ];
}

/**
 * Claims the next available pre-added key from system_keys.
 * Caller should be inside a transaction for row-level locks to apply.
 * Returns key string or null when unavailable.
 */
function claimPreAddedKey($conn) {
    ensureSystemKeysTable($conn);
    // Lock next available key and mark it as Issued
    $select = $conn->prepare("SELECT key_string, duration FROM system_keys WHERE status = 'Generated' ORDER BY created_at ASC LIMIT 1 FOR UPDATE");
    if (!$select) { return null; }
    $select->execute();
    $res = $select->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $select->close();
    if (!$row || empty($row['key_string'])) { return null; }
    $candidate = $row['key_string'];
    $update = $conn->prepare("UPDATE system_keys SET status = 'Issued' WHERE key_string = ? AND status = 'Generated'");
    if (!$update) { return null; }
    $update->bind_param("s", $candidate);
    $update->execute();
    $ok = $update->affected_rows === 1;
    $update->close();
    if (!$ok) { return null; }
    return [
        'key_string' => $candidate,
        'duration' => $row['duration'] ?? null,
    ];
}

function ownerSetKeyGeneration($user_id, $role, $enabled) {
    if (!checkRole($role, 'owner')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Owner authorization required.']);
        return;
    }
    $conn = connectDB();
    ensureServiceFlagsTable($conn);
    $val = $enabled ? '1' : '0';
    $stmt = $conn->prepare("INSERT INTO service_flags (flag, value) VALUES ('key_generation_enabled', ?) ON DUPLICATE KEY UPDATE value=VALUES(value)");
    $stmt->bind_param("s", $val);
    $stmt->execute();
    echo json_encode(['success' => true]);
    $conn->close();
}

/**
 * Generates a license key, deducts balance, and saves the record.
 */
function createLicense($user_id, $role, $input) {
    if (!checkRole($role, 'user')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Authorization required to create license.']);
        return;
    }
    
    $cost = (float)($input['cost'] ?? 0);
    if ($cost <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid license cost.']);
        return;
    }

    $conn = connectDB();
    
    $conn->begin_transaction();
    $stmt = $conn->prepare("SELECT balance FROM users WHERE user_id = ? FOR UPDATE");
    $stmt->bind_param("s", $user_id);
    $stmt->execute();
    $userBalance = $stmt->get_result()->fetch_assoc()['balance'] ?? 0;
    $stmt->close();

    if ($userBalance < $cost) {
        $conn->rollback();
        http_response_code(402);
        echo json_encode(['success' => false, 'message' => 'Insufficient balance.']);
        $conn->close();
        return;
    }
    
    // Claim a pre-added key from the pool managed by owner
    $claimed = claimPreAddedKey($conn);
    if (!$claimed) {
        $conn->rollback();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'No pre-added keys available. Contact owner.']);
        $conn->close();
        return;
    }
    $key_string = $claimed['key_string'];
    // If owner provided a specific duration on the key, use it; else use selected duration
    $duration = $claimed['duration'] ?: $input['duration_id'];
    $max_devices = (int)($input['max_devices'] ?? 1);
    $game_package = $input['package_id'];
    $days = $input['days'] ?? null; 
    $expires = NULL; // start on first use (activation)
    
    $sql = "INSERT INTO licenses (key_string, game_package, duration, max_devices, devices_used, status, creator_id, expires) 
            VALUES (?, ?, ?, ?, 0, 'Issued', ?, ?)";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt->bind_param("sssiss", $key_string, $game_package, $duration, $max_devices, $user_id, $expires) || !$stmt->execute()) {
        $error_message = $conn->error;
        $conn->rollback();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Key creation failed: ' . $error_message]);
        $conn->close();
        return;
    }
    
    $newBalance = $userBalance - $cost;
    $stmt = $conn->prepare("UPDATE users SET balance = ? WHERE user_id = ?");
    $stmt->bind_param("ds", $newBalance, $user_id);
    $stmt->execute();
    
    $conn->commit();
    echo json_encode(['success' => true, 'data' => ['key_string' => $key_string, 'new_balance' => $newBalance]]);
    $conn->close();
}


/**
 * Resets the device linkage for a specific license key (User/Reseller action).
 */
function resetLicense($user_id, $role, $license_id) {
    if (!checkRole($role, 'user')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Authorization required to reset key.']);
        return;
    }
    
    $conn = connectDB();
    
    // 1. Check current devices_used status
    $stmt = $conn->prepare("SELECT devices_used FROM licenses WHERE license_id = ? AND creator_id = ?");
    $stmt->bind_param("is", $license_id, $user_id);
    $stmt->execute();
    $key_data = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$key_data) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Key not found or user not authorized.']);
        $conn->close();
        return;
    }

    if ($key_data['devices_used'] == 0) {
        // Condition 1: Already reset/free
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'Key is already reset and ready for new device login.']);
        $conn->close();
        return;
    }
    
    // 2. Perform the UPDATE (Reset)
    $sql = "UPDATE licenses SET devices_used = 0, linked_device_id = NULL 
            WHERE license_id = ? AND creator_id = ? AND status != 'Banned'";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt->bind_param("is", $license_id, $user_id) || !$stmt->execute()) {
        $error_message = $conn->error;
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Key reset failed: ' . $error_message]);
        $conn->close();
        return;
    }

    // 3. Success Confirmation
    if ($stmt->affected_rows > 0) {
        echo json_encode(['success' => true, 'message' => 'License device linkage reset successfully!']);
    } else {
        // Should not be reached if devices_used > 0, but included as a fail-safe
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Key reset failed (No rows were updated).']);
    }
    $conn->close();
}

/**
 * Updates license details (Admin/Owner action).
 */
function updateLicenseDetails($user_id, $role, $input) {
    if (!checkRole($role, 'admin')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Admin authorization required.']);
        return;
    }
    
    echo json_encode(['success' => true, 'message' => 'License details updated (Simulated).']);
}


/**
 * Bans a license key (Admin/Owner action).
 */
function banLicense($user_id, $role, $license_id) {
    if (!checkRole($role, 'admin')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Admin authorization required.']);
        return;
    }
    
    $conn = connectDB();
    $sql = "UPDATE licenses SET status = 'Banned' WHERE license_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $license_id);

    if ($stmt->execute() && $stmt->affected_rows > 0) {
        echo json_encode(['success' => true, 'message' => 'License key banned.']);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'License not found.']);
    }
    $conn->close();
}

/**
 * Deletes a license key permanently (Admin/Owner action). No refund.
 */
function deleteLicense($user_id, $role, $license_id) {
    if (!checkRole($role, 'admin')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Admin authorization required.']);
        return;
    }
    
    $conn = connectDB();
    $sql = "DELETE FROM licenses WHERE license_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $license_id);

    if ($stmt->execute() && $stmt->affected_rows > 0) {
        echo json_encode(['success' => true, 'message' => 'License key deleted permanently.']);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'License not found.']);
    }
    $conn->close();
}

/**
 * Creates a new referral code (Admin/Owner action).
 */
function createReferral($user_id, $role, $input) {
    if (!checkRole($role, 'admin')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Admin authorization required.']);
        return;
    }

    $conn = connectDB();
    $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    $balance = (float)($input['balance'] ?? 0);
    $max_role = $input['max_role'] ?? 'user';
    if (!in_array($max_role, ['user','reseller','admin'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid max_role.']);
        $conn->close();
        return;
    }

    $sql = "INSERT INTO referrals (code, initial_balance, max_role, creator_id) 
            VALUES (?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sdss", $code, $balance, $max_role, $user_id);
    
    if ($stmt->execute()) {
        http_response_code(201);
        echo json_encode(['success' => true, 'data' => ['code' => $code]]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Referral creation failed.']);
    }
    $conn->close();
}

/**
 * Loads all referral codes created by the Admin/Owner.
 */
function loadReferrals($role) {
     if (!checkRole($role, 'admin')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Admin authorization required.']);
        return;
    }
    
    $conn = connectDB();
    $sql = "SELECT code, initial_balance, max_role, creator_id FROM referrals ORDER BY created_at DESC";
    $result = $conn->query($sql);
    
    $referrals = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $referrals[] = $row;
        }
    }
    
    echo json_encode(['success' => true, 'data' => $referrals]);
    $conn->close();
}

/**
 * Deletes a referral code (Admin/Owner action).
 */
function deleteReferral($user_id, $role, $code) {
    if (!checkRole($role, 'admin')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Admin authorization required.']);
        return;
    }
    if (!$code) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing code.']);
        return;
    }
    $conn = connectDB();
    $stmt = $conn->prepare("DELETE FROM referrals WHERE code = ?");
    $stmt->bind_param("s", $code);
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        echo json_encode(['success' => true]);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Referral not found.']);
    }
    $conn->close();
}


/**
 * Loads users managed by the current Admin/Owner (referred clients).
 */
function loadManagedUsers($user_id, $role) {
    if (!checkRole($role, 'admin')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Admin authorization required.']);
        return;
    }
    
    $conn = connectDB();
    $where_clause = ($role === 'owner') ? "" : "WHERE referred_by_id = ?";
    $sql = "SELECT user_id, email, role, referred_by_id, status FROM users $where_clause ORDER BY created_at DESC";
    
    if ($role !== 'owner') {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $conn->query($sql);
    }
    
    $users = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $row['active_license_id'] = ($row['role'] !== 'owner') ? 'LID-' . substr($row['user_id'], 0, 8) : null;
            $users[] = $row;
        }
    }
    
    echo json_encode(['success' => true, 'data' => $users]);
    $conn->close();
}


/**
 * Admin/Owner: Add balance to a user.
 */
function adminAddBalance($current_user_id, $role, $target_user_id, $amount) {
    if (!checkRole($role, 'admin')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Admin authorization required.']);
        return;
    }
    if ($amount <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid amount.']);
        return;
    }
    $conn = connectDB();
    $stmt = $conn->prepare("UPDATE users SET balance = balance + ? WHERE user_id = ?");
    $stmt->bind_param("ds", $amount, $target_user_id);
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        echo json_encode(['success' => true]);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User not found or update failed.']);
    }
    $conn->close();
}

/**
 * Admin/Owner: Change user role (cannot set owner).
 */
function adminChangeRole($current_user_id, $role, $target_user_id, $new_role) {
    if (!checkRole($role, 'admin')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Admin authorization required.']);
        return;
    }
    $allowed = ['user','reseller','admin'];
    if (!in_array($new_role, $allowed, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid role.']);
        return;
    }
    $conn = connectDB();
    $stmt = $conn->prepare("UPDATE users SET role = ? WHERE user_id = ?");
    $stmt->bind_param("ss", $new_role, $target_user_id);
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        echo json_encode(['success' => true]);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User not found or update failed.']);
    }
    $conn->close();
}

/**
 * Load single license info for the key info modal.
 */
function loadLicenseInfo($user_id, $role, $license_id) {
    if (!checkRole($role, 'user')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Authorization required.']);
        return;
    }
    $conn = connectDB();
    // Owner/Admin can view any; users can only view their own licenses
    if ($role === 'user') {
        $stmt = $conn->prepare("SELECT license_id, key_string, game_package, duration, max_devices, devices_used, status, expires FROM licenses WHERE license_id = ? AND creator_id = ?");
        $stmt->bind_param("is", $license_id, $user_id);
    } else {
        $stmt = $conn->prepare("SELECT license_id, key_string, game_package, duration, max_devices, devices_used, status, expires FROM licenses WHERE license_id = ?");
        $stmt->bind_param("i", $license_id);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    if ($row) {
        echo json_encode(['success' => true, 'data' => $row]);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'License not found.']);
    }
    $conn->close();
}

/**
 * Check/Activate a license. Starts expiry if not started.
 */
function checkLicense($user_id, $role, $license_id, $device_id = null) {
    if (!checkRole($role, 'user')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Authorization required.']);
        return;
    }
    $conn = connectDB();
    // Users can only check their own; admin/owner can check any
    if ($role === 'user') {
        $stmt = $conn->prepare("SELECT expires, devices_used, max_devices, status FROM licenses WHERE license_id = ? AND creator_id = ? AND status != 'Banned'");
        $stmt->bind_param("is", $license_id, $user_id);
    } else {
        $stmt = $conn->prepare("SELECT expires, devices_used, max_devices, status FROM licenses WHERE license_id = ? AND status != 'Banned'");
        $stmt->bind_param("i", $license_id);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'License not found.']);
        $conn->close();
        return;
    }
    // Activation on first login + increment devices_used up to max_devices
    if ($row['expires'] === NULL) {
        $stmt = $conn->prepare("SELECT duration FROM licenses WHERE license_id = ?");
        $stmt->bind_param("i", $license_id);
        $stmt->execute();
        $durRes = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $duration_id = $durRes ? $durRes['duration'] : 'opt2';
        $hours = getDurationHours($duration_id);
        $stmt = $conn->prepare("UPDATE licenses SET expires = DATE_ADD(NOW(), INTERVAL ? HOUR), status = 'Active', devices_used = LEAST(max_devices, devices_used + 1), linked_device_id = IFNULL(?, linked_device_id) WHERE license_id = ?");
        $stmt->bind_param("isi", $hours, $device_id, $license_id);
        $stmt->execute();
    } else if ($row['devices_used'] < $row['max_devices']) {
        $stmt = $conn->prepare("UPDATE licenses SET devices_used = devices_used + 1, linked_device_id = IFNULL(?, linked_device_id) WHERE license_id = ?");
        $stmt->bind_param("si", $device_id, $license_id);
        $stmt->execute();
    }
    // Return updated devices count
    $res = $conn->prepare("SELECT devices_used, max_devices, status, expires FROM licenses WHERE license_id = ?");
    $res->bind_param("i", $license_id);
    $res->execute();
    $info = $res->get_result()->fetch_assoc();
    echo json_encode(['success' => true, 'data' => $info]);
    $conn->close();
}

/**
 * Extend license by extra days (Admin/Owner).
 */
function extendLicenseTime($user_id, $role, $license_id, $extra_days) {
    if (!checkRole($role, 'admin')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Admin authorization required.']);
        return;
    }
    if ($extra_days <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid extra_days.']);
        return;
    }
    $conn = connectDB();
    $stmt = $conn->prepare("UPDATE licenses SET expires = COALESCE(expires, NOW()) + INTERVAL ? DAY WHERE license_id = ?");
    $stmt->bind_param("ii", $extra_days, $license_id);
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        echo json_encode(['success' => true]);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'License not found or update failed.']);
    }
    $conn->close();
}

/**
 * Owner: reset all licenses (devices_used=0, linked_device_id=NULL)
 */
function ownerResetAllLicenses($user_id, $role) {
    if (!checkRole($role, 'owner')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Owner authorization required.']);
        return;
    }
    $conn = connectDB();
    $conn->query("UPDATE licenses SET devices_used = 0, linked_device_id = NULL");
    echo json_encode(['success' => true]);
    $conn->close();
}

/**
 * Owner: delete all licenses
 */
function ownerDeleteAllLicenses($user_id, $role) {
    if (!checkRole($role, 'owner')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Owner authorization required.']);
        return;
    }
    $conn = connectDB();
    $conn->query("DELETE FROM licenses");
    echo json_encode(['success' => true]);
    $conn->close();
}

/**
 * Admin: reset all managed licenses (self + referred)
 */
function adminResetAllLicenses($user_id, $role) {
    if (!checkRole($role, 'admin')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Admin authorization required.']);
        return;
    }
    $conn = connectDB();
    if ($role === 'owner') {
        $conn->query("UPDATE licenses SET devices_used = 0, linked_device_id = NULL");
    } else {
        // reset licenses created by admin or their referred users
        $stmt = $conn->prepare("UPDATE licenses SET devices_used = 0, linked_device_id = NULL WHERE creator_id IN (SELECT user_id FROM users WHERE referred_by_id = ? UNION SELECT ?)");
        $stmt->bind_param("ss", $user_id, $user_id);
        $stmt->execute();
    }
    echo json_encode(['success' => true]);
    $conn->close();
}

/**
 * Admin: delete all managed licenses
 */
function adminDeleteAllLicenses($user_id, $role) {
    if (!checkRole($role, 'admin')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Admin authorization required.']);
        return;
    }
    $conn = connectDB();
    if ($role === 'owner') {
        $conn->query("DELETE FROM licenses");
    } else {
        $stmt = $conn->prepare("DELETE FROM licenses WHERE creator_id IN (SELECT user_id FROM users WHERE referred_by_id = ? UNION SELECT ?)");
        $stmt->bind_param("ss", $user_id, $user_id);
        $stmt->execute();
    }
    echo json_encode(['success' => true]);
    $conn->close();
}

/**
 * Admin: extend all managed licenses by extra days
 */
function adminExtendAllLicenses($user_id, $role, $extra_days) {
    if (!checkRole($role, 'admin')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Admin authorization required.']);
        return;
    }
    if ($extra_days <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid extra_days.']);
        return;
    }
    $conn = connectDB();
    if ($role === 'owner') {
        $stmt = $conn->prepare("UPDATE licenses SET expires = COALESCE(expires, NOW()) + INTERVAL ? DAY");
        $stmt->bind_param("i", $extra_days);
        $stmt->execute();
    } else {
        $stmt = $conn->prepare("UPDATE licenses SET expires = COALESCE(expires, NOW()) + INTERVAL ? DAY WHERE creator_id IN (SELECT user_id FROM users WHERE referred_by_id = ? UNION SELECT ?)");
        $stmt->bind_param("iss", $extra_days, $user_id, $user_id);
        $stmt->execute();
    }
    echo json_encode(['success' => true]);
    $conn->close();
}

/**
 * Owner: extend all licenses by extra days
 */
function ownerExtendAllLicenses($user_id, $role, $extra_days) {
    if (!checkRole($role, 'owner')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Owner authorization required.']);
        return;
    }
    if ($extra_days <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid extra_days.']);
        return;
    }
    $conn = connectDB();
    $stmt = $conn->prepare("UPDATE licenses SET expires = COALESCE(expires, NOW()) + INTERVAL ? DAY");
    $stmt->bind_param("i", $extra_days);
    $stmt->execute();
    echo json_encode(['success' => true]);
    $conn->close();
}


/**
 * Admin/Owner action to reset the device linkage on a client's key.
 */
function adminResetClientKey($current_user_id, $role, $license_id) {
    if (!checkRole($role, 'admin')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Admin authorization required.']);
        return;
    }

    $conn = connectDB();
    
    $stmt = $conn->prepare("SELECT creator_id FROM licenses WHERE license_id = ?");
    $stmt->bind_param("i", $license_id);
    $stmt->execute();
    $licenseOwnerId = $stmt->get_result()->fetch_assoc()['creator_id'] ?? null;
    $stmt->close();
    
    if (!$licenseOwnerId) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'License not found.']);
        $conn->close();
        return;
    }
    
    if ($role === 'admin') {
        $stmt = $conn->prepare("SELECT referred_by_id FROM users WHERE user_id = ?");
        $stmt->bind_param("s", $licenseOwnerId);
        $stmt->execute();
        $referredById = $stmt->get_result()->fetch_assoc()['referred_by_id'] ?? null;
        $stmt->close();

        if ($referredById !== $current_user_id) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Authorization denied. License is not from a managed client.']);
            $conn->close();
            return;
        }
    }

    $sql = "UPDATE licenses SET devices_used = 0, linked_device_id = NULL WHERE license_id = ?";
    $stmt = $conn->prepare($sql);
    // Correct binding: single integer parameter for license_id
    if (!$stmt->bind_param("i", $license_id) || !$stmt->execute()) {
        $error_message = $conn->error;
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Admin reset failed: ' . $error_message]);
        $conn->close();
        return;
    }

    if ($stmt->affected_rows > 0) {
        echo json_encode(['success' => true, 'message' => 'Client license device link reset.']);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Client license reset failed (No rows affected).']);
    }
    $conn->close();
}

/**
 * Loads system keys (Admin/Owner view).
 */
function loadSystemKeys($role) {
    if (!checkRole($role, 'admin')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Admin authorization required.']);
        return;
    }
    
    $conn = connectDB();
    ensureSystemKeysTable($conn);
    $sql = "SELECT key_string, name, created_by_id, status FROM system_keys ORDER BY created_at DESC";
    $result = $conn->query($sql);
    
    $keys = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $keys[] = $row;
        }
    }
    
    echo json_encode(['success' => true, 'data' => $keys]);
    $conn->close();
}

/**
 * Generates a high-privilege system key (Admin/Owner action).
 */
function generateSystemKey($user_id, $role, $input) {
    if (!checkRole($role, 'owner')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Owner authorization required.']);
        return;
    }
    
    $conn = connectDB();
    ensureSystemKeysTable($conn);
    $name = trim($input['name'] ?? 'SYSTEM');
    $key_string = trim($input['key_string'] ?? '');
    if ($key_string === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Key string is required.']);
        $conn->close();
        return;
    }
    if (!preg_match('/^[A-Za-z0-9._\-]{6,64}$/', $key_string)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid key format. Use 6-64 chars [A-Za-z0-9._-].']);
        $conn->close();
        return;
    }

    $sql = "INSERT INTO system_keys (key_string, name, created_by_id, status) 
            VALUES (?, ?, ?, 'Generated')";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sss", $key_string, $name, $user_id);
    
    if ($stmt->execute()) {
        http_response_code(201);
        echo json_encode(['success' => true, 'message' => 'System key added to pool.', 'data' => ['key_string' => $key_string]]);
    } else {
        http_response_code(500);
        $msg = (strpos($conn->error ?? '', 'Duplicate') !== false || ($conn->errno ?? 0) === 1062) ? 'Duplicate key. Already exists.' : 'System key creation failed.';
        echo json_encode(['success' => false, 'message' => $msg]);
    }
    $conn->close();
}

/**
 * Owner bulk add keys into pool.
 */
function ownerBulkAddKeys($user_id, $role, $keys, $name) {
    if (!checkRole($role, 'owner')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Owner authorization required.']);
        return;
    }
    if (!is_array($keys) || count($keys) === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No keys provided.']);
        return;
    }
    $conn = connectDB();
    ensureSystemKeysTable($conn);
    $stmt = $conn->prepare("INSERT INTO system_keys (key_string, name, created_by_id, status, duration) VALUES (?, ?, ?, 'Generated', ?) ON DUPLICATE KEY UPDATE name=VALUES(name), duration=VALUES(duration)");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Preparation failed.']);
        $conn->close();
        return;
    }
    $added = 0; $skipped = 0;
    $defaultDuration = null; // optional default duration
    if (isset($GLOBALS['input']['duration']) && is_string($GLOBALS['input']['duration'])) {
        $d = trim($GLOBALS['input']['duration']);
        if ($d === '' || preg_match('/^(h:\\d{1,5}|d:\\d{1,5})$/', $d)) { $defaultDuration = $d ?: null; }
    }
    foreach ($keys as $k) {
        $key = trim($k);
        if ($key === '' || !preg_match('/^[A-Za-z0-9._\-]{6,64}$/', $key)) { $skipped++; continue; }
        $dur = $defaultDuration;
        $stmt->bind_param("ssss", $key, $name, $user_id, $dur);
        if ($stmt->execute()) { $added++; } else { $skipped++; }
    }
    echo json_encode(['success' => true, 'data' => ['added' => $added, 'skipped' => $skipped]]);
    $conn->close();
}

/**
 * Get pricing table (admin/owner).
 */
function getPricing($role) {
    if (!checkRole($role, 'admin')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Admin authorization required.']);
        return;
    }
    $conn = connectDB();
    ensurePricingTable($conn);
    $res = $conn->query("SELECT duration_id, price FROM pricing");
    $pricing = getDefaultPricing();
    if ($res) {
        while ($row = $res->fetch_assoc()) { $pricing[$row['duration_id']] = (float)$row['price']; }
    }
    echo json_encode(['success' => true, 'data' => $pricing]);
    $conn->close();
}

/**
 * Owner update pricing.
 */
function ownerUpdatePricing($user_id, $role, $pricing) {
    if (!checkRole($role, 'owner')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Owner authorization required.']);
        return;
    }
    if (!is_array($pricing) || empty($pricing)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid pricing payload.']);
        return;
    }
    $conn = connectDB();
    ensurePricingTable($conn);
    $stmt = $conn->prepare("INSERT INTO pricing (duration_id, price) VALUES (?, ?) ON DUPLICATE KEY UPDATE price=VALUES(price)");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Preparation failed.']);
        $conn->close();
        return;
    }
    foreach ($pricing as $durationId => $price) {
        $did = (string)$durationId;
        $p = (float)$price;
        if (!preg_match('/^opt[1-7]$/', $did) || $p <= 0) { continue; }
        $stmt->bind_param("sd", $did, $p);
        $stmt->execute();
    }
    echo json_encode(['success' => true]);
    $conn->close();
}

/**
 * Owner: Generate API key with limit/amount for external automation.
 */
function ownerGenerateApiKey($user_id, $role, $amount) {
    if (!checkRole($role, 'owner')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Owner authorization required.']);
        return;
    }
    if ($amount <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid amount.']);
        return;
    }
    $conn = connectDB();
    $conn->query("CREATE TABLE IF NOT EXISTS api_keys (api_key CHAR(40) PRIMARY KEY, amount DECIMAL(10,2) NOT NULL, created_by_id VARCHAR(36) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $api_key = bin2hex(random_bytes(20));
    $stmt = $conn->prepare("INSERT INTO api_keys (api_key, amount, created_by_id) VALUES (?, ?, ?)");
    $stmt->bind_param("sds", $api_key, $amount, $user_id);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'data' => ['api_key' => $api_key, 'amount' => (float)$amount]]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'API key generation failed.']);
    }
    $conn->close();
}

/**
 * API helpers: authenticate API key and retrieve allowed amount.
 */
function requireApiKey($input) {
    $api_key = $input['api_key'] ?? '';
    if (!$api_key) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'API key required.']);
        exit();
    }
    $conn = connectDB();
    $conn->query("CREATE TABLE IF NOT EXISTS api_keys (api_key CHAR(40) PRIMARY KEY, amount DECIMAL(10,2) NOT NULL, created_by_id VARCHAR(36) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $stmt = $conn->prepare("SELECT amount FROM api_keys WHERE api_key = ?");
    $stmt->bind_param("s", $api_key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid API key.']);
        $conn->close();
        exit();
    }
    return [$conn, (float)$row['amount']];
}

function apiCreateLicense($input) {
    list($conn, $amount) = requireApiKey($input);
    // For bot-created license, expect: creator_id, package_id, duration_id, max_devices, cost
    $creator_id = $input['creator_id'] ?? null;
    $package_id = $input['package_id'] ?? null;
    $duration_id = $input['duration_id'] ?? null;
    $max_devices = (int)($input['max_devices'] ?? 1);
    $cost = (float)($input['cost'] ?? 0);
    if (!$creator_id || !$package_id || !$duration_id || $cost <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing fields for license creation.']);
        $conn->close();
        return;
    }
    if ($cost > $amount) {
        http_response_code(402);
        echo json_encode(['success' => false, 'message' => 'API key amount insufficient.']);
        $conn->close();
        return;
    }
    $conn->begin_transaction();
    // Deduct from user's balance and API key allowance
    $stmt = $conn->prepare("SELECT balance FROM users WHERE user_id = ? FOR UPDATE");
    $stmt->bind_param("s", $creator_id);
    $stmt->execute();
    $userBalance = $stmt->get_result()->fetch_assoc()['balance'] ?? 0;
    $stmt->close();
    if ($userBalance < $cost) {
        $conn->rollback();
        http_response_code(402);
        echo json_encode(['success' => false, 'message' => 'User balance insufficient.']);
        $conn->close();
        return;
    }
    // Claim a pre-added key from the pool managed by owner
    $claimed = claimPreAddedKey($conn);
    if (!$claimed) {
        $conn->rollback();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'No pre-added keys available. Contact owner.']);
        $conn->close();
        return;
    }
    $key_string = $claimed['key_string'];
    // If owner attached duration to the key, prefer it
    $duration_id = $claimed['duration'] ?: $duration_id;
    $expires = NULL;
    $sql = "INSERT INTO licenses (key_string, game_package, duration, max_devices, devices_used, status, creator_id, expires) VALUES (?, ?, ?, ?, 0, 'Issued', ?, ?)";
    $stmt = $conn->prepare($sql);
    if (!$stmt->bind_param("sssiss", $key_string, $package_id, $duration_id, $max_devices, $creator_id, $expires) || !$stmt->execute()) {
        $conn->rollback();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'API key license creation failed.']);
        $conn->close();
        return;
    }
    $newBalance = $userBalance - $cost;
    $stmt = $conn->prepare("UPDATE users SET balance = ? WHERE user_id = ?");
    $stmt->bind_param("ds", $newBalance, $creator_id);
    $stmt->execute();
    // Reduce API key allowance
    $stmt = $conn->prepare("UPDATE api_keys SET amount = amount - ? WHERE api_key = ?");
    $stmt->bind_param("ds", $cost, $input['api_key']);
    $stmt->execute();
    $conn->commit();
    echo json_encode(['success' => true, 'data' => ['key_string' => $key_string, 'new_balance' => $newBalance]]);
    $conn->close();
}

function apiResetLicense($input) {
    list($conn, $amount) = requireApiKey($input);
    $license_id = (int)($input['license_id'] ?? 0);
    $creator_id = $input['creator_id'] ?? null;
    if (!$license_id || !$creator_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing license_id/creator_id.']);
        $conn->close();
        return;
    }
    $stmt = $conn->prepare("UPDATE licenses SET devices_used = 0, linked_device_id = NULL WHERE license_id = ? AND creator_id = ?");
    $stmt->bind_param("is", $license_id, $creator_id);
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        echo json_encode(['success' => true]);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'License not found or unauthorized.']);
    }
    $conn->close();
}

function apiDeleteLicense($input) {
    list($conn, $amount) = requireApiKey($input);
    $license_id = (int)($input['license_id'] ?? 0);
    if (!$license_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing license_id.']);
        $conn->close();
        return;
    }
    $stmt = $conn->prepare("DELETE FROM licenses WHERE license_id = ?");
    $stmt->bind_param("i", $license_id);
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        echo json_encode(['success' => true]);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'License not found.']);
    }
    $conn->close();
}


/**
 * Resets a user's login token (simulated by updating password hash).
 */
function resetUserLoginKey($current_user_id, $role, $target_user_id) {
    if (!checkRole($role, 'admin')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Admin authorization required to reset login.']);
        return;
    }
    
    $conn = connectDB();
    $new_hash = password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
    $sql = "UPDATE users SET password_hash = ?, status = 'Active' WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $new_hash, $target_user_id);

    if ($stmt->execute() && $stmt->affected_rows > 0) {
        echo json_encode(['success' => true, 'message' => "User $target_user_id login token reset. User forced to re-login."]);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Login reset failed or user not found.']);
    }
    $conn->close();
}

/**
 * Blocks a user's account by updating their status.
 */
function blockUser($current_user_id, $role, $target_user_id) {
    if (!checkRole($role, 'admin')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Admin authorization required to block user.']);
        return;
    }
    
    if ($target_user_id === $current_user_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Cannot block self.']);
        return;
    }

    $conn = connectDB();
    $sql = "UPDATE users SET status = 'Blocked' WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $target_user_id);

    if ($stmt->execute() && $stmt->affected_rows > 0) {
        echo json_encode(['success' => true, 'message' => "User $target_user_id account blocked."]);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Blocking failed or user not found.']);
    }
    $conn->close();
}

/**
 * Permanently deletes a user account.
 */
function deleteUserAccount($current_user_id, $role, $target_user_id) {
    if (!checkRole($role, 'owner')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Owner authorization required for permanent deletion.']);
        return;
    }

    if ($target_user_id === $current_user_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Cannot delete your own account via this panel.']);
        return;
    }

    $conn = connectDB();
    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare("DELETE FROM licenses WHERE creator_id = ?");
        $stmt->bind_param("s", $target_user_id);
        $stmt->execute();
        
        $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
        $stmt->bind_param("s", $target_user_id);
        $stmt->execute();
        
        if ($stmt->affected_rows === 0) {
            $conn->rollback();
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'User not found for deletion.']);
            $conn->close();
            return;
        }

        $conn->commit();
        echo json_encode(['success' => true, 'message' => "User $target_user_id and all associated data permanently deleted."]);
        
    } catch (Exception $e) {
        $conn->rollback();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Deletion failed due to database error.']);
    }
    $conn->close();
}

/**
 * User: change own password (requires current password).
 */
function changePassword($user_id, $current_password, $new_password) {
    if (!$new_password || strlen($new_password) < 8) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'New password must be at least 8 characters.']);
        return;
    }
    $conn = connectDB();
    $stmt = $conn->prepare("SELECT password_hash FROM users WHERE user_id = ?");
    $stmt->bind_param("s", $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || !password_verify($current_password, $row['password_hash'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Current password is incorrect.']);
        $conn->close();
        return;
    }
    $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
    $stmt->bind_param("ss", $new_hash, $user_id);
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        echo json_encode(['success' => true, 'message' => 'Password updated successfully.']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Password update failed.']);
    }
    $conn->close();
}
?>