<?php
// Redirect handler to credit user balance without webhook
// Works with pay.t-g.xyz redirect; falls back to amount/order stored locally

if (!defined('DASHBOARD_LIB_ONLY')) { define('DASHBOARD_LIB_ONLY', true); }
require_once __DIR__ . '/dashboard.php';
require_once __DIR__ . '/config.php';

// Ensure HTML response (dashboard.php sets JSON header by default)
header_remove('Content-Type');
header('Content-Type: text/html; charset=UTF-8');

function normalize($key, $arr) {
    $map = [
        'order_id' => ['order_id','orderId','ORDERID','oid'],
        'status'   => ['status','STATUS','orderStatus'],
        'amount'   => ['amount','txn_amount','TXNAMOUNT','txnAmount','AMOUNT','amt'],
        'remark1'  => ['remark1','REMARK1','uid','user_id'],
        'remark2'  => ['remark2','REMARK2'],
    ];
    foreach ($map[$key] as $k) { if (isset($arr[$k])) return trim((string)$arr[$k]); }
    return '';
}

try {
    // Accept params from GET primarily, fallback to POST if gateway posts back
    $q = $_GET;
    if (empty($q) && !empty($_POST)) { $q = $_POST; }
    $orderId = normalize('order_id', $q);
    if ($orderId === '') {
        $lo = trim((string)($q['local_order_id'] ?? ''));
        if (stripos($lo, 'payload-') === 0) { $lo = trim(substr($lo, 8)); }
        $orderId = $lo;
    }
    $status  = strtoupper(normalize('status', $q));
    $amountV = normalize('amount', $q);
    $amount  = is_numeric($amountV) ? (float)$amountV : 0.0;
    $userId  = normalize('remark1', $q);

    // Optional override token from our dashboard to allow success without status param
    $byte = $_GET['byte_order_status'] ?? '';
    if ($orderId === '') { throw new Exception('Missing order_id'); }

    $success = ($byte === 'BYTE37091761364125') || in_array($status, ['SUCCESS','TXN_SUCCESS','COMPLETED'], true);

    logPaymentEvent('payment_return.received', [
        'order_id'=>$orderId, 'status'=>$status, 'amount'=>$amount, 'user_id'=>$userId, 'query'=>$q
    ]);

    $msg = 'Payment failed or cancelled.';
    $ok  = false;
    if ($success) {
        try {
            $conn = connectDB();
            ensurePaymentsTables($conn);
            // Read existing payment row
            $sel = $conn->prepare('SELECT amount,status,user_id FROM payments WHERE order_id = ? LIMIT 1');
            $sel->bind_param('s', $orderId);
            $sel->execute();
            $row = $sel->get_result()->fetch_assoc();
            $sel->close();

            // Idempotency: if already SUCCESS, just redirect success
            if (($row['status'] ?? '') === 'SUCCESS') {
                $msg = 'Payment already processed.';
                $ok = true;
            } else {
                $dbAmount = isset($row['amount']) ? (float)$row['amount'] : 0.0;
                $dbUser   = isset($row['user_id']) ? (string)$row['user_id'] : '';
                $useAmount = ($amount > 0 ? $amount : $dbAmount);
                if ($useAmount <= 0) {
                    // Fallback to logs (created earlier in create_order)
                    $logFile = __DIR__ . '/storage/payment_logs.log';
                    if (is_readable($logFile)) {
                        $lines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                        if ($lines) {
                            for ($i = count($lines) - 1; $i >= 0; $i--) {
                                $line = $lines[$i];
                                if (strpos($line, 'create_order.requested') !== false && strpos($line, $orderId) !== false) {
                                    if (preg_match('/"amount"\s*:\s*"([0-9]+\.[0-9]{2})"/', $line, $m)) {
                                        $useAmount = (float)$m[1];
                                    }
                                    break;
                                }
                            }
                        }
                    }
                }
                $useUser = $userId !== '' ? $userId : $dbUser;

                // Upsert SUCCESS status and fill missing fields
                $ins = $conn->prepare("INSERT INTO payments (order_id, user_id, amount, status) VALUES (?, ?, ?, 'SUCCESS')
                    ON DUPLICATE KEY UPDATE status='SUCCESS',
                        user_id = IF(VALUES(user_id)<>'' AND user_id='', VALUES(user_id), user_id),
                        amount = IF(VALUES(amount)>0 AND amount=0, VALUES(amount), amount)");
                $ins->bind_param('ssd', $orderId, $useUser, $useAmount);
                $ins->execute();
                $ins->close();

                if ($useUser !== '' && $useAmount > 0) {
                    $credit = $conn->prepare('UPDATE users SET balance = balance + ? WHERE user_id = ?');
                    $credit->bind_param('ds', $useAmount, $useUser);
                    $credit->execute();
                    $credit->close();
                    $ok = true;
                    $msg = 'Payment successful. Balance credited.';
                } else {
                    $msg = 'Payment successful, but missing user/amount for credit.';
                }
            }
            $conn->close();
        } catch (Throwable $e) {
            $msg = 'Payment processed, but DB error occurred.';
        }
    }

    $dest = 'dashboard.html';
    $sep = (strpos($dest,'?')!==false?'&':'?');
    $qs = http_build_query([
        'pay_status'=>($success && $ok)?'success':($success?'pending':'failed'),
        'order_id'=>$orderId,
        'msg'=>$msg
    ]);
    echo "<script>location.href='" . htmlspecialchars($dest . $sep . $qs, ENT_QUOTES) . "';</script>";
    exit;

} catch (Throwable $e) {
    echo "<script>location.href='dashboard.html?pay_status=error&msg=" . urlencode('Payment processing error') . "';</script>";
    exit;
}
