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
if (!isset($input['action']) || !isset($input['user_id']) || !isset($input['role'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid API request format.']);
    exit();
}

$action = $input['action'];
$user_id = $input['user_id'];
$role = $input['role'];

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
        case 'load_managed_users':
            loadManagedUsers($user_id, $role);
            break;
        case 'admin_reset_client_key':
            adminResetClientKey($user_id, $role, $input['license_id']);
            break;
        
        case 'load_system_keys':
            loadSystemKeys($role);
            break;
        case 'generate_system_key':
            generateSystemKey($user_id, $role, $input);
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
    
    $key_rate = 3.7; 

    $data = [
        'role' => $userData['role'],
        'balance' => (float)$userData['balance'],
        'active_keys' => (int)$keyData['active_keys'],
        'key_rate' => $key_rate,
        'email' => $userData['email'],
        'referred_by_status' => $userData['referred_by_id']
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
    
    $key_string = bin2hex(random_bytes(11)); 
    $max_devices = (int)($input['max_devices'] ?? 1);
    $duration = $input['duration_id'];
    $game_package = $input['package_id'];
    $days = $input['days'] ?? 1; 
    $expires = date('Y-m-d H:i:s', strtotime("+$days days"));
    
    $sql = "INSERT INTO licenses (key_string, game_package, duration, max_devices, devices_used, status, creator_id, expires) 
            VALUES (?, ?, ?, ?, 0, 'Active', ?, ?)";
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
    $max_role = $input['role'];

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
    // FIX BINDING: Use 'is' (Integer, String) for license_id and user_id
    if (!$stmt->bind_param("is", $license_id, $user_id) || !$stmt->execute()) {
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
    if (!checkRole($role, 'admin')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Admin authorization required.']);
        return;
    }
    
    $conn = connectDB();
    $key_string = strtoupper(substr(bin2hex(random_bytes(16)), 0, 32));
    $name = $input['name'];

    $sql = "INSERT INTO system_keys (key_string, name, created_by_id, status) 
            VALUES (?, ?, ?, 'Generated')";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sss", $key_string, $name, $user_id);
    
    if ($stmt->execute()) {
        http_response_code(201);
        echo json_encode(['success' => true, 'message' => 'System key created.', 'data' => ['key_string' => $key_string]]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'System key creation failed.']);
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
?>