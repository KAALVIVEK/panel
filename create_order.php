<?php
declare(strict_types=1);

/**
 * Create Payment Order (Clean Integration)
 *
 * - Generates a unique order_id automatically
 * - Accepts an amount via GET/POST (?amount=) or uses a default
 * - Calls the gateway API https://pay.t-g.xyz/api/create-order with Authorization header
 * - Parses the JSON response to retrieve payment_url
 * - Displays the payment_url as a clickable link and auto-redirects the user
 * - Handles HTTP errors and invalid API responses gracefully
 */

require_once __DIR__ . '/config.php';

header('Content-Type: text/html; charset=UTF-8');

// ----- Minimal DB helpers (isolated from dashboard.php to avoid routing side-effects) -----
// Keep these in sync with dashboard.php credentials
define('DB_HOST', 'sql108.ezyro.com');
define('DB_USER', 'ezyro_40038768');
define('DB_PASS', '13579780');
define('DB_NAME', 'ezyro_40038768_vivek');

function co_connectDB(): mysqli {
    $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        throw new Exception('DB connect failed: ' . $conn->connect_error);
    }
    return $conn;
}

function co_ensurePaymentsTables(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS payments (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        order_id VARCHAR(64) UNIQUE,
        txn_id VARCHAR(64) NULL,
        user_id VARCHAR(64) NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'INIT',
        raw_response MEDIUMTEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Best-effort schema upgrades (idempotent)
    @$conn->query("ALTER TABLE payments ADD COLUMN raw_response MEDIUMTEXT NULL");
    @$conn->query("ALTER TABLE payments ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
}

// ----- Helpers -----
function buildWebhookUrl(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $scheme . '://' . $host . ($basePath ? $basePath : '') . '/webhook.php';
}

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

// ----- Inputs -----
$amount = sanitizeAmount($_POST['amount'] ?? $_GET['amount'] ?? null);
$userId = isset($_POST['user_id']) ? (string)$_POST['user_id'] : (isset($_GET['user_id']) ? (string)$_GET['user_id'] : '');
// allow letters, digits, dash/underscore/dot only
$userId = preg_replace('/[^A-Za-z0-9._\-]/', '', $userId);
$userId = $userId !== '' ? $userId : 'ANON';
$orderId = generateOrderId();
$webhookUrl = buildWebhookUrl();

// ----- Persist INIT order in DB before calling gateway -----
try {
    $db = co_connectDB();
    co_ensurePaymentsTables($db);
    $stmt = $db->prepare('INSERT INTO payments (order_id, user_id, amount, status) VALUES (?, ?, ?, "INIT")');
    if ($stmt) {
        $amtStr = number_format($amount, 2, '.', '');
        $stmt->bind_param('ssd', $orderId, $userId, $amount);
        if (!$stmt->execute()) {
            throw new Exception('Failed to create local order record.');
        }
        $stmt->close();
    } else {
        throw new Exception('DB prepare failed.');
    }
    $db->close();
} catch (Throwable $e) {
    $errorMessage = 'Internal error creating order. Please try again.';
    logPaymentEvent('create_order.db_error', ['order_id' => $orderId, 'error' => $e->getMessage()]);
}

// ----- Request to Gateway -----
$payload = [
    'order_id'    => $orderId,
    'amount'      => number_format($amount, 2, '.', ''),
    'webhook_url' => $webhookUrl,
];

$url = apiUrl('/api/create-order');
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: application/json',
    'Authorization: Bearer ' . USER_TOKEN,
]);
$response = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

// Log the API call outcome (without sensitive data)
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
        // Common structures: { payment_url: "..." } OR { data: { payment_url: "..." }}
        $paymentUrl = $parsed['payment_url'] ?? ($parsed['data']['payment_url'] ?? null);
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
}

// ----- Output HTML -----
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
