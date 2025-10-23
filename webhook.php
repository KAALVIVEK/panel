<?php
// Paytm webhook endpoint
header('Content-Type: application/json');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/paytm_checksum.php';
require_once __DIR__ . '/dashboard.php';

$input = file_get_contents('php://input');
$payload = json_decode($input, true);
if (!$payload) { $payload = $_POST; }

try {
  $orderId = $payload['ORDERID'] ?? $payload['orderId'] ?? '';
  $status = $payload['STATUS'] ?? $payload['status'] ?? '';
  $txnId = $payload['TXNID'] ?? $payload['txnId'] ?? '';
  $checksum = $payload['CHECKSUMHASH'] ?? $payload['checksum'] ?? '';
  if (!$orderId) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'Missing orderId']); exit; }

  if (!PaytmChecksum::verifySignature($payload, PAYTM_MERCHANT_KEY, $checksum)) {
    http_response_code(400); echo json_encode(['success'=>false,'message'=>'Checksum invalid']); exit; }

  $conn = connectDB(); ensurePaymentsTables($conn);
  $sel = $conn->prepare('SELECT user_id, amount, status FROM payments WHERE order_id=? LIMIT 1');
  $sel->bind_param('s', $orderId); $sel->execute(); $row = $sel->get_result()->fetch_assoc();
  if (!$row) { http_response_code(404); echo json_encode(['success'=>false,'message'=>'Order not found']); exit; }
  if ($row['status'] === 'SUCCESS') { echo json_encode(['success'=>true]); exit; }

  if ($status === 'TXN_SUCCESS' || $status === 'SUCCESS') {
    $upd = $conn->prepare('UPDATE payments SET status="SUCCESS", txn_id=?, raw_response=? WHERE order_id=?');
    $upd->bind_param('sss', $txnId, $input, $orderId); $upd->execute();
    // credit user
    if ($row['user_id'] !== 'guest') {
      $credit = $conn->prepare('UPDATE users SET balance = balance + ? WHERE user_id=?');
      $credit->bind_param('ds', $row['amount'], $row['user_id']);
      $credit->execute();
    }
    echo json_encode(['success'=>true]);
  } else {
    $upd = $conn->prepare('UPDATE payments SET status="FAILED", raw_response=? WHERE order_id=?');
    $upd->bind_param('ss', $input, $orderId); $upd->execute();
    echo json_encode(['success'=>true]);
  }
  $conn->close();
} catch (Exception $e) {
  http_response_code(500); echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
