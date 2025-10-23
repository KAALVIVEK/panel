<?php
// Simple Paytm UPI QR payment flow without merchant key: uses MID + UPI ID
// Generates dynamic QR, polls order status, returns JSON
header('Content-Type: application/json');
require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'OPTIONS') { exit(0); }

function jsonOut($arr, $code=200){ http_response_code($code); echo json_encode($arr); exit; }

$action = $_GET['action'] ?? $_POST['action'] ?? null;
if (!$action) { jsonOut(['success'=>false,'message'=>'Missing action'], 400); }

function buildHost() {
    return PAYTM_ENVIRONMENT === 'PROD' ? 'https://securegw.paytm.in' : 'https://securegw-stage.paytm.in';
}

// Creates an order row and returns QR data (using dynamic UPI intent link)
if ($action === 'create_upi_qr') {
    $amount = (float)($_POST['amount'] ?? $_GET['amount'] ?? 0);
    $orderId = $_POST['order_id'] ?? $_GET['order_id'] ?? ( 'ORD' . date('YmdHis') . strtoupper(substr(md5(uniqid('', true)), 0, 6)) );
    if ($amount <= 0) { jsonOut(['success'=>false,'message'=>'Invalid amount'], 400); }
    // Build UPI deeplink
    $upi = urlencode(PAYTM_UPI_ID);
    $tn = urlencode('Order ' . $orderId);
    $am = number_format($amount, 2, '.', '');
    $upiLink = "upi://pay?pa={$upi}&pn=".urlencode('Payee')."&am={$am}&cu=INR&tn={$tn}&tr={$orderId}";
    // Generate a QR image URL using Google Charts API (works on shared hosting)
    $qrUrl = 'https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=' . urlencode($upiLink);
    // Optional: save to payments table for bookkeeping
    try { 
        require_once __DIR__ . '/dashboard.php';
        $conn = connectDB(); ensurePaymentsTables($conn);
        $stmt = $conn->prepare('INSERT INTO payments (order_id, user_id, amount, status) VALUES (?, ?, ?, "INIT")');
        $uid = $_POST['user_id'] ?? $_GET['user_id'] ?? 'guest';
        $stmt->bind_param('ssd', $orderId, $uid, $amount);
        $stmt->execute();
        $conn->close();
    } catch (Exception $e) { /* ignore */ }
    jsonOut(['success'=>true, 'data'=>[
        'order_id'=>$orderId,
        'amount'=>$am,
        'upi_link'=>$upiLink,
        'qr_image'=>$qrUrl,
        'mid'=>PAYTM_MID
    ]]);
}

// Poll order status via Paytm Order Status API
if ($action === 'check_status') {
    $orderId = $_POST['order_id'] ?? $_GET['order_id'] ?? '';
    if ($orderId === '') { jsonOut(['success'=>false,'message'=>'Missing order_id'], 400); }
    $host = buildHost();
    $url = $host . '/v3/order/status';
    $payload = json_encode(['mid'=>PAYTM_MID, 'orderId'=>$orderId]);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err || !$resp) { jsonOut(['success'=>false,'message'=>'Status check failed'], 500); }
    $res = json_decode($resp, true);
    $status = $res['resultInfo']['resultStatus'] ?? 'PENDING';
    $msg = $res['resultInfo']['resultMsg'] ?? '';
    $txnId = $res['txnId'] ?? null;
    $success = ($status === 'TXN_SUCCESS' || $status === 'SUCCESS');
    // Update DB
    try {
        require_once __DIR__ . '/dashboard.php';
        $conn = connectDB(); ensurePaymentsTables($conn);
        if ($success) {
            $upd = $conn->prepare('UPDATE payments SET status="SUCCESS", txn_id=? WHERE order_id=?');
            $upd->bind_param('ss', $txnId, $orderId);
            $upd->execute();
            // Credit user if known
            $sel = $conn->prepare('SELECT user_id, amount FROM payments WHERE order_id=?');
            $sel->bind_param('s', $orderId); $sel->execute(); $row = $sel->get_result()->fetch_assoc();
            if ($row && $row['user_id'] !== 'guest') {
                $credit = $conn->prepare('UPDATE users SET balance = balance + ? WHERE user_id = ?');
                $credit->bind_param('ds', $row['amount'], $row['user_id']);
                $credit->execute();
            }
        }
        $conn->close();
    } catch (Exception $e) { /* ignore */ }
    jsonOut(['success'=>true, 'data'=>[
        'order_id'=>$orderId,
        'status'=>$status,
        'message'=>$msg,
        'txn_id'=>$txnId
    ]]);
}

jsonOut(['success'=>false,'message'=>'Invalid action'], 400);
