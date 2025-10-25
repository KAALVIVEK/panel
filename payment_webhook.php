<?php
declare(strict_types=1);

// Simple payment webhook to credit balance when gateway notifies success
// Expected JSON: { order_id, user_id, amount, status }
// status values: SUCCESS|FAILED (case-insensitive)

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/config.php';
// Load DB helpers without triggering API routing
if (!defined('DASHBOARD_LIB_ONLY')) { define('DASHBOARD_LIB_ONLY', true); }
require_once __DIR__ . '/dashboard.php'; // reuse DB helpers and ensure tables

try {
    if (!isWebhookEnabled()) {
        logPaymentEvent('payment_webhook.disabled', []);
        http_response_code(200);
        echo json_encode(['success'=>false,'message'=>'Webhook disabled']);
        exit;
    }

    $raw = file_get_contents('php://input');
    $ctype = $_SERVER['CONTENT_TYPE'] ?? '';
    $hdrs = function_exists('getallheaders') ? getallheaders() : [];
    logPaymentEvent('payment_webhook.received', [
        'content_type' => $ctype,
        'len' => strlen((string)$raw),
        'raw_snippet' => mb_substr((string)$raw, 0, 2000),
        'headers' => $hdrs,
    ]);
    $payload = json_decode($raw, true);
    // Fallbacks for gateways that POST form-encoded instead of JSON
    if (!is_array($payload) || empty($payload)) {
        if (!empty($_POST)) {
            $payload = $_POST;
        } else {
            $tmp = [];
            parse_str((string)$raw, $tmp);
            if (!empty($tmp)) { $payload = $tmp; }
        }
    }
    if (!is_array($payload) || empty($payload)) {
        // Always reply 200 to avoid provider disabling webhook; log for diagnostics
        logPaymentEvent('payment_webhook.invalid_payload', [ 'content_type' => $ctype, 'raw_snippet' => mb_substr((string)$raw, 0, 500) ]);
        http_response_code(200);
        echo json_encode(['success'=>false,'message'=>'Invalid payload']);
        exit;
    }

    // Normalize common field variants
    $orderId = trim((string)($payload['order_id'] ?? $payload['orderId'] ?? $payload['ORDERID'] ?? ''));
    $userId  = trim((string)($payload['user_id'] ?? ''));
    // Fallback to remark1/remark2 for user id when not provided explicitly
    if ($userId === '') {
        $userId = trim((string)($payload['remark1'] ?? $payload['REMARK1'] ?? '')) ?: trim((string)($payload['remark2'] ?? $payload['REMARK2'] ?? ''));
    }
    // Accept alternative field names commonly used by gateways
    $amountRaw = $payload['amount'] ?? ($payload['txn_amount'] ?? $payload['TXNAMOUNT'] ?? $payload['txnAmount'] ?? $payload['AMOUNT'] ?? null);
    $amount  = is_numeric($amountRaw) ? (float)$amountRaw : 0.0;
    $statusRaw = $payload['status'] ?? ($payload['STATUS'] ?? $payload['orderStatus'] ?? '');
    $status  = strtoupper(trim((string)$statusRaw));

    if ($orderId === '' || $status === '') {
        http_response_code(200);
        echo json_encode(['success'=>false,'message'=>'Missing order_id/status']);
        exit;
    }
    if ($status !== 'SUCCESS' && $status !== 'FAILED' && $status !== 'TXN_SUCCESS' && $status !== 'COMPLETED') {
        http_response_code(200);
        echo json_encode(['success'=>false,'message'=>'Invalid status']);
        exit;
    }

    $conn = connectDB();
    ensurePaymentsTables($conn);

    // Upsert payment record and credit on SUCCESS (idempotent)
    // Create row if not exists, preserve earliest known user_id/amount when missing in payload
    $ins = $conn->prepare("INSERT INTO payments (order_id, user_id, amount, status, raw_response) VALUES (?, ?, ?, 'INIT', ?) ON DUPLICATE KEY UPDATE raw_response = VALUES(raw_response), user_id = IF(VALUES(user_id)<>'' AND user_id='', VALUES(user_id), user_id), amount = IF(VALUES(amount)>0 AND amount=0, VALUES(amount), amount)");
    $ins->bind_param('ssds', $orderId, $userId, $amount, $raw);
    $ins->execute();
    $ins->close();

    // Fetch current status
    $sel = $conn->prepare('SELECT status, user_id, amount FROM payments WHERE order_id = ? LIMIT 1');
    $sel->bind_param('s', $orderId);
    $sel->execute();
    $row = $sel->get_result()->fetch_assoc();
    $sel->close();

    $current = $row['status'] ?? 'INIT';
    if ($userId === '' && !empty($row['user_id'])) { $userId = $row['user_id']; }
    if (($amount ?? 0) <= 0 && isset($row['amount'])) { $amount = (float)$row['amount']; }
    if ($current === 'SUCCESS') {
        echo json_encode(['success'=>true, 'message'=>'Already processed']);
        $conn->close();
        exit;
    }

    if ($status === 'SUCCESS' || $status === 'TXN_SUCCESS' || $status === 'COMPLETED') {
        $upd = $conn->prepare("UPDATE payments SET status='SUCCESS' WHERE order_id = ?");
        $upd->bind_param('s', $orderId);
        $upd->execute();
        $upd->close();

        if ($userId !== '' && $amount > 0) {
            $credit = $conn->prepare('UPDATE users SET balance = balance + ? WHERE user_id = ?');
            $credit->bind_param('ds', $amount, $userId);
            $credit->execute();
            $credit->close();
        }
        logPaymentEvent('payment_webhook.result', [ 'order_id'=>$orderId, 'status'=>'SUCCESS', 'user_id'=>$userId, 'amount'=>$amount ]);
    } else {
        $upd = $conn->prepare("UPDATE payments SET status='FAILED' WHERE order_id = ?");
        $upd->bind_param('s', $orderId);
        $upd->execute();
        $upd->close();
        logPaymentEvent('payment_webhook.result', [ 'order_id'=>$orderId, 'status'=>'FAILED' ]);
    }

    echo json_encode(['success'=>true]);
    $conn->close();
} catch (Throwable $e) {
    logPaymentEvent('payment_webhook.exception', [ 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString() ]);
    http_response_code(200);
    echo json_encode(['success'=>false,'message'=>'Server error']);
}
