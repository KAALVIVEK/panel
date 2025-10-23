<?php
// Creates a Paytm UPI QR order and returns orderId, upi_link, qr_image
header('Content-Type: application/json');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/paytm_checksum.php';
require_once __DIR__ . '/dashboard.php'; // for DB connection and payments table

try {
  $amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
  $userId = isset($_POST['user_id']) ? trim($_POST['user_id']) : 'guest';
  if ($amount <= 0) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'Invalid amount']); exit; }

  $conn = connectDB(); ensurePaymentsTables($conn);
  $orderId = 'ORD' . date('YmdHis') . strtoupper(substr(md5(uniqid('', true)), 0, 8));

  // Insert INIT row
  $ins = $conn->prepare('INSERT INTO payments (order_id, user_id, amount, status) VALUES (?, ?, ?, "INIT")');
  $ins->bind_param('ssd', $orderId, $userId, $amount);
  $ins->execute();

  // Build payload for Paytm QR create
  $body = [
    'mid' => PAYTM_MID,
    'orderId' => $orderId,
    'amount' => number_format($amount, 2, '.', ''),
    'businessType' => 'UPI_QR',
    'posId' => 'WEB_01'
  ];
  $signature = PaytmChecksum::generateSignature(json_encode($body, JSON_UNESCAPED_SLASHES), PAYTM_MERCHANT_KEY);
  $payload = json_encode(['body'=>$body, 'head'=>['signature'=>$signature]], JSON_UNESCAPED_SLASHES);

  $url = PAYTM_API_BASE . '/paymentservices/qr/create';
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
  $resp = curl_exec($ch);
  $err = curl_error($ch);
  curl_close($ch);

  $qrImage = null; $upiLink = null; $raw = $resp;
  if ($err || !$resp) {
    // Fallback UPI link
    $upiLink = 'upi://pay?pa=' . urlencode(PAYTM_UPI_ID) . '&pn=' . urlencode('Payee') . '&am=' . urlencode(number_format($amount,2,'.','')) . '&cu=INR&tn=' . urlencode('Order '.$orderId) . '&tr=' . urlencode($orderId);
  } else {
    $res = json_decode($resp, true);
    if (isset($res['body']['qrImage'])) {
      $qrImage = 'data:image/png;base64,' . $res['body']['qrImage'];
    } else {
      $upiLink = 'upi://pay?pa=' . urlencode(PAYTM_UPI_ID) . '&pn=' . urlencode('Payee') . '&am=' . urlencode(number_format($amount,2,'.','')) . '&cu=INR&tn=' . urlencode('Order '.$orderId) . '&tr=' . urlencode($orderId);
    }
  }

  // Update raw response
  $upd = $conn->prepare('UPDATE payments SET raw_response=? WHERE order_id=?');
  $upd->bind_param('ss', $raw, $orderId);
  $upd->execute();

  echo json_encode(['success'=>true,'data'=>[
    'orderId'=>$orderId,
    'upi_link'=>$upiLink,
    'qr_image'=>$qrImage
  ]]);
  $conn->close();
} catch (Exception $e) {
  http_response_code(500);
  echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
