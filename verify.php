<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/dashboard.php';
header('Content-Type: application/json');
$orderId = $_GET['orderId'] ?? '';
if (!$orderId) { echo json_encode(['error'=>'Order ID missing']); exit; }
try {
  $conn = connectDB(); ensurePaymentsTables($conn);
  $sel = $conn->prepare('SELECT status, txn_id, raw_response FROM payments WHERE order_id=? LIMIT 1');
  $sel->bind_param('s', $orderId); $sel->execute(); $row = $sel->get_result()->fetch_assoc();
  if (!$row) { echo json_encode(['orderId'=>$orderId,'status'=>'UNKNOWN','message':'Order not found']); exit; }
  echo json_encode(['orderId'=>$orderId, 'status'=>$row['status'], 'txn_id'=>$row['txn_id'], 'message'=>'OK']);
} catch (Exception $e) {
  echo json_encode(['orderId'=>$orderId,'status':'ERROR','message'=>$e->getMessage()]);
}
