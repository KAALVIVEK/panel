<?php
declare(strict_types=1);

/**
 * Create Payment Order (Minimal Integration)
 * - Generates a unique order_id automatically
 * - Accepts an amount via GET/POST (?amount=)
 * - Calls https://pay.t-g.xyz/api/create-order with Authorization header
 * - Parses JSON response to retrieve result.payment_url
 * - Displays the payment_url as a clickable link and auto-redirects the user
 * - Logs outcomes to storage/payment_logs.log
 */

require_once __DIR__ . '/config.php';
// Use DB helpers for order->user mapping (safe: routing disabled)
if (!defined('DASHBOARD_LIB_ONLY')) { define('DASHBOARD_LIB_ONLY', true); }
require_once __DIR__ . '/dashboard.php';

header('Content-Type: text/html; charset=UTF-8');

function generateOrderId(): string {
    try {
        $rand = bin2hex(random_bytes(6));
    } catch (Throwable $e) {
        $rand = substr(md5(uniqid('', true)), 0, 12);
    }
    return 'ORD-' . date('YmdHis') . '-' . strtoupper($rand);
}

function sanitizeAmount($value, float $default = 10.00): float {
    $amount = is_numeric($value) ? (float)$value : $default;
    if (!is_finite($amount) || $amount <= 0) { return $default; }
    return round($amount, 2);
}

// Inputs
$amount = sanitizeAmount($_POST['amount'] ?? $_GET['amount'] ?? null);
$orderId = generateOrderId();
$redirectUrlParam = trim((string)($_REQUEST['redirect_url'] ?? ''));
if ($redirectUrlParam === '' || !filter_var($redirectUrlParam, FILTER_VALIDATE_URL)) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
    if ($base === '') { $base = '/'; }
    $path = rtrim($base, '/') . '/dashboard.html';
    $redirectUrlParam = $scheme . '://' . $host . $path;
}

// Request to Gateway
$payload = [
    'order_id' => $orderId,
    'amount'   => number_format($amount, 2, '.', ''),
];

$url = apiUrl('/api/create-order');
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, true);
// Align with gateway: form-encoded body including user_token and route
$form = [
    'user_token' => USER_TOKEN,
    'order_id'   => $payload['order_id'],
    'amount'     => $payload['amount'],
    'redirect_url' => $redirectUrlParam,
    'route'        => defined('DEFAULT_ROUTE') ? DEFAULT_ROUTE : 1,
];
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/x-www-form-urlencoded',
]);
$response = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

// Store mapping for webhook crediting (does not affect gateway request)
try {
    if ($remark1 !== '') {
        $conn = connectDB();
        ensurePaymentsTables($conn);
        $amtDec = (float)$payload['amount'];
        $stmt = $conn->prepare("INSERT INTO payments (order_id, user_id, amount, status) VALUES (?, ?, ?, 'INIT') ON DUPLICATE KEY UPDATE user_id=VALUES(user_id), amount=VALUES(amount)");
        if ($stmt) { $stmt->bind_param("ssd", $orderId, $remark1, $amtDec); $stmt->execute(); $stmt->close(); }
        $conn->close();
    }
} catch (Throwable $e) { /* ignore mapping failures */ }

// (No DB writes here; keep gateway request minimal and unchanged)

logPaymentEvent('create_order.requested', [
    'order_id' => $orderId,
    'amount'   => $payload['amount'],
    'url'      => $url,
    'http'     => $httpCode,
]);

$errorMessage = null;
$paymentUrl = null;
$parsed = null;

if ($curlErr) {
    $errorMessage = 'Failed to reach the payment gateway.';
} elseif ($httpCode < 200 || $httpCode >= 300) {
    $errorMessage = 'Gateway returned an error (HTTP ' . $httpCode . ').';
} else {
    $parsed = json_decode((string)$response, true);
    if (!is_array($parsed)) {
        $errorMessage = 'Invalid JSON received from gateway.';
    } else {
        // Expected: { status, message, result: { payment_url } }
        $paymentUrl = $parsed['result']['payment_url'] ?? ($parsed['payment_url'] ?? null);
        if (!is_string($paymentUrl) || $paymentUrl === '') {
            $errorMessage = 'payment_url not found in gateway response.';
        }
    }
}

if ($errorMessage !== null) {
    logPaymentEvent('create_order.failed', [
        'order_id' => $orderId,
        'reason'   => $errorMessage,
        'body'     => is_string($response) ? mb_substr($response, 0, 2000) : null,
    ]);
} else {
    logPaymentEvent('create_order.succeeded', [
        'order_id' => $orderId,
        'payment_url' => $paymentUrl,
    ]);
}

$pageTitle = 'Create Payment Order';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES); ?></title>
  <?php if ($paymentUrl): ?>
  <meta http-equiv="refresh" content="1;url=<?php echo htmlspecialchars($paymentUrl, ENT_QUOTES); ?>">
  <?php endif; ?>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;line-height:1.5;padding:24px;background:#0b1020;color:#e9eefc}
    .card{max-width:720px;margin:40px auto;background:#121a35;border:1px solid #22305c;border-radius:12px;padding:24px;box-shadow:0 10px 30px rgba(0,0,0,.35)}
    .muted{opacity:.85}
    a.button{display:inline-block;margin-top:8px;background:#4da3ff;color:#001;padding:10px 14px;border-radius:8px;text-decoration:none;font-weight:700}
    .error{background:#2a0f14;border:1px solid #7a1b27;color:#ffd7de;padding:12px;border-radius:8px}
    pre{white-space:pre-wrap;word-break:break-word;background:#0b132b;border:1px solid #21335a;border-radius:8px;padding:12px;overflow:auto}
  </style>
</head>
<body>
  <div class="card">
    <h2>Payment Initialization</h2>
    <p class="muted">Order ID: <strong><?php echo htmlspecialchars($orderId, ENT_QUOTES); ?></strong></p>
    <p class="muted">Amount: <strong>₹<?php echo htmlspecialchars(number_format($amount, 2, '.', ''), ENT_QUOTES); ?></strong></p>
    <?php if ($paymentUrl): ?>
      <p>Payment link:
        <a class="button" href="<?php echo htmlspecialchars($paymentUrl, ENT_QUOTES); ?>" target="_blank" rel="noopener">Pay Now</a>
      </p>
      <p class="muted">Redirecting you automatically…</p>
      <script>
        setTimeout(function(){ window.location.href = <?php echo json_encode($paymentUrl); ?>; }, 800);
      </script>
    <?php else: ?>
      <div class="error">
        <strong>Unable to create the payment order.</strong>
        <div class="muted">Reason: <?php echo htmlspecialchars($errorMessage ?? 'Unknown error', ENT_QUOTES); ?></div>
      </div>
      <?php if (isset($parsed) && is_array($parsed)): ?>
        <details style="margin-top:8px">
          <summary>Gateway response</summary>
          <pre><?php echo htmlspecialchars(json_encode($parsed, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), ENT_QUOTES); ?></pre>
        </details>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</body>
</html>
