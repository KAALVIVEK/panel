<?php
// Byte gateway redirect handler to credit user balance without webhook
// Expected GET params appended by gateway on redirect (example):
// ?order_id=...&status=SUCCESS&amount=...&remark1=UID-... (names may vary)

require_once __DIR__ . '/dashboard.php';

define('DASHBOARD_LIB_ONLY', true);

header('Content-Type: text/html; charset=UTF-8');

function normalize($key, $arr) {
    $map = [
        'order_id' => ['order_id','orderId','ORDERID'],
        'status'   => ['status','STATUS','orderStatus'],
        'amount'   => ['amount','txn_amount','TXNAMOUNT','txnAmount','AMOUNT'],
        'remark1'  => ['remark1','REMARK1'],
        'remark2'  => ['remark2','REMARK2'],
    ];
    foreach ($map[$key] as $k) { if (isset($arr[$k])) return trim((string)$arr[$k]); }
    return '';
}

try {
    $q = $_GET;
    $orderId = normalize('order_id', $q);
    $status  = strtoupper(normalize('status', $q));
    $amountV = normalize('amount', $q);
    $amount  = is_numeric($amountV) ? (float)$amountV : 0.0;
    $userId  = normalize('remark1', $q);

    if ($orderId === '' || $status === '') { throw new Exception('Missing order_id/status'); }

    // Only credit on success-equivalent statuses
    $success = in_array($status, ['SUCCESS','TXN_SUCCESS','COMPLETED'], true);

    // Record return event
    require_once __DIR__ . '/config.php';
    logPaymentEvent('payment_return.received', [
        'order_id'=>$orderId, 'status'=>$status, 'amount'=>$amount, 'user_id'=>$userId, 'query'=>$q
    ]);

    $msg = 'Payment failed or cancelled.';
    $ok  = false;
    if ($success) {
        $conn = connectDB();
        ensurePaymentsTables($conn);
        // Read or create payment row; prefer stored amount when missing
        $sel = $conn->prepare('SELECT amount,status FROM payments WHERE order_id = ? LIMIT 1');
        $sel->bind_param('s', $orderId);
        $sel->execute();
        $row = $sel->get_result()->fetch_assoc();
        $sel->close();
        $dbAmount = isset($row['amount']) ? (float)$row['amount'] : 0.0;
        $useAmount = ($amount > 0 ? $amount : $dbAmount);

        // Mark success and upsert mapping
        $ins = $conn->prepare("INSERT INTO payments (order_id, user_id, amount, status) VALUES (?, ?, ?, 'SUCCESS') ON DUPLICATE KEY UPDATE status='SUCCESS', user_id=IF(VALUES(user_id)<>'' AND user_id='', VALUES(user_id), user_id), amount=IF(VALUES(amount)>0 AND amount=0, VALUES(amount), amount)");
        $ins->bind_param('ssd', $orderId, $userId, $useAmount);
        $ins->execute();
        $ins->close();

        if ($userId !== '' && $useAmount > 0) {
            $credit = $conn->prepare('UPDATE users SET balance = balance + ? WHERE user_id = ?');
            $credit->bind_param('ds', $useAmount, $userId);
            $credit->execute();
            $credit->close();
            $ok = true;
            $msg = 'Payment successful. Balance credited.';
        } else {
            $msg = 'Payment successful, but missing user/amount for credit.';
        }
        $conn->close();
    }

    // Redirect back to dashboard with status message
    $dest = 'dashboard.html';
    $sep = (strpos($dest,'?')!==false?'&':'?');
    $qs = http_build_query([
        'pay_status'=>$success?'success':'failed',
        'order_id'=>$orderId,
        'msg'=>$msg
    ]);
    header('Location: ' . $dest . $sep . $qs);
    exit;

} catch (Throwable $e) {
    // Fallback message
    header('Location: dashboard.html?pay_status=error&msg=' . urlencode('Payment processing error'));
    exit;
}
