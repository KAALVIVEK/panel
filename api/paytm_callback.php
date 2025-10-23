<?php
header('Content-Type: application/json');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/paytm_checksum.php';
require_once __DIR__ . '/../dashboard.php'; // reuse DB connection and ensure tables

$inputJSON = file_get_contents('php://input');
$payload = json_decode($inputJSON, true);
if (!$payload) { $payload = $_POST; }

$orderId = $payload['ORDERID'] ?? $payload['orderId'] ?? null;
$status = $payload['STATUS'] ?? $payload['status'] ?? null;
$txnId = $payload['TXNID'] ?? $payload['txnId'] ?? null;
$amount = (float)($payload['TXNAMOUNT'] ?? $payload['txnAmount'] ?? 0);
$checksum = $payload['CHECKSUMHASH'] ?? $payload['checksum'] ?? '';

if (!PaytmChecksum::verifySignature($payload, PAYTM_MERCHANT_KEY, $checksum)) {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>'Checksum mismatch']);
    exit;
}

try {
    $conn = connectDB();
    ensurePaymentsTables($conn);
    $sel = $conn->prepare('SELECT user_id, amount, status FROM payments WHERE order_id = ? LIMIT 1');
    $sel->bind_param('s', $orderId);
    $sel->execute();
    $row = $sel->get_result()->fetch_assoc();
    if (!$row) { http_response_code(404); echo json_encode(['success'=>false,'message'=>'Order not found']); exit; }
    if ($row['status'] === 'SUCCESS') { echo json_encode(['success'=>true]); exit; }

    if ($status === 'TXN_SUCCESS' || $status === 'SUCCESS') {
        $upd = $conn->prepare('UPDATE payments SET status="SUCCESS", txn_id=? WHERE order_id=?');
        $upd->bind_param('ss', $txnId, $orderId);
        $upd->execute();
        $uid = $row['user_id'];
        $amt = $row['amount'];
        $credit = $conn->prepare('UPDATE users SET balance = balance + ? WHERE user_id = ?');
        $credit->bind_param('ds', $amt, $uid);
        $credit->execute();
        echo json_encode(['success'=>true]);
    } else {
        $upd = $conn->prepare('UPDATE payments SET status="FAILED" WHERE order_id=?');
        $upd->bind_param('s', $orderId);
        $upd->execute();
        echo json_encode(['success'=>true]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
