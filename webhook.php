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

// Log the event. In production, update your database accordingly.
logPaymentEvent('webhook.received', [
    'order_id' => $orderId,
    'status'   => $status,
    'amount'   => $amount,
    'txn_id'   => $txnId,
]);

// Example: echo back a simple OK for testing
echo json_encode([
    'success' => true,
    'message' => 'Webhook processed',
]);
