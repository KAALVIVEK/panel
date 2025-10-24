<?php
declare(strict_types=1);

/**
 * Webhook Listener for Payment Updates
 *
 * Accepts POST with fields: order_id, status, amount, txn_id
 * Validates a shared secret via header: X-Webhook-Secret: <WEBHOOK_SECRET>
 * Logs the received payload and returns JSON response.
 *
 * Note: This is a simple, framework-agnostic endpoint suitable for testing.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/dashboard.php';

header('Content-Type: application/json');

// Read raw input for logging and parse JSON or form data
$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) { $data = $_POST; }
if (!is_array($data)) { $data = []; }

// Validate secret header
$receivedSecret = $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? '';
if (!is_string($receivedSecret) || $receivedSecret === '' || $receivedSecret !== WEBHOOK_SECRET) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized webhook']);
    logPaymentEvent('webhook.unauthorized', ['ip' => $_SERVER['REMOTE_ADDR'] ?? '?']);
    exit;
}

// Extract expected fields safely
$orderId = isset($data['order_id']) ? (string)$data['order_id'] : '';
$status  = isset($data['status']) ? (string)$data['status'] : '';
$amount  = isset($data['amount']) ? (string)$data['amount'] : '';
$txnId   = isset($data['txn_id']) ? (string)$data['txn_id'] : '';

if ($orderId === '' || $status === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing order_id or status']);
    logPaymentEvent('webhook.invalid', ['body' => mb_substr($raw, 0, 2000)]);
    exit;
}

// Process in DB: update payments and credit user on success
try {
    $conn = connectDB();
    ensurePaymentsTables($conn);
    // Load order
    $sel = $conn->prepare('SELECT user_id, amount, status FROM payments WHERE order_id = ? LIMIT 1');
    $sel->bind_param('s', $orderId);
    $sel->execute();
    $row = $sel->get_result()->fetch_assoc();
    $sel->close();
    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Order not found']);
        logPaymentEvent('webhook.order_not_found', ['order_id' => $orderId]);
        exit;
    }
    // Idempotency
    if ($row['status'] === 'SUCCESS') {
        echo json_encode(['success' => true, 'message' => 'Already processed']);
        exit;
    }
    // Update payment record payload and status
    $payloadJson = $raw;
    if (strtoupper($status) === 'SUCCESS' || strtoupper($status) === 'TXN_SUCCESS' || strtoupper($status) === 'PAID') {
        $upd = $conn->prepare('UPDATE payments SET status = "SUCCESS", txn_id = ?, raw_response = ? WHERE order_id = ?');
        $upd->bind_param('sss', $txnId, $payloadJson, $orderId);
        $upd->execute();
        // Credit balance
        $credit = $conn->prepare('UPDATE users SET balance = balance + ? WHERE user_id = ?');
        $amt = (float)$row['amount'];
        $uid = $row['user_id'];
        $credit->bind_param('ds', $amt, $uid);
        $credit->execute();
        logPaymentEvent('webhook.credited', ['order_id' => $orderId, 'user_id' => $uid, 'amount' => $amt]);
        echo json_encode(['success' => true, 'message' => 'Payment success']);
    } else {
        $upd = $conn->prepare('UPDATE payments SET status = "FAILED", txn_id = ?, raw_response = ? WHERE order_id = ?');
        $upd->bind_param('sss', $txnId, $payloadJson, $orderId);
        $upd->execute();
        logPaymentEvent('webhook.failed', ['order_id' => $orderId, 'status' => $status]);
        echo json_encode(['success' => true, 'message' => 'Payment failed recorded']);
    }
    $conn->close();
} catch (Throwable $e) {
    http_response_code(500);
    logPaymentEvent('webhook.error', ['error' => $e->getMessage()]);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
