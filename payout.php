<?php
declare(strict_types=1);

/**
 * UPI Payout (Minimal Integration)
 * - Accepts UPI ID and amount
 * - Calls https://pay.t-g.xyz/api/payout with Authorization header
 * - Parses JSON response and shows status
 * - Logs each payout attempt to storage/payment_logs.log
 */

require_once __DIR__ . '/config.php';

header('Content-Type: text/html; charset=UTF-8');

function sanitizeAmount($value, float $default = 0.00): float {
    $amount = is_numeric($value) ? (float)$value : $default;
    if (!is_finite($amount) || $amount <= 0) { return $default; }
    return round($amount, 2);
}

function sanitizeUpiId(?string $upi): string {
    $upi = trim((string)$upi);
    // Basic sanitation, do not enforce strict pattern to allow various handles
    return substr($upi, 0, 100);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$result = null;
$errorMessage = null;
$success = false;

if ($method === 'POST') {
    $upiId = sanitizeUpiId($_POST['upi_id'] ?? '');
    $amount = sanitizeAmount($_POST['amount'] ?? null);
    $note = trim((string)($_POST['note'] ?? 'User payout'));

    if ($upiId === '' || $amount <= 0) {
        $errorMessage = 'Please enter a valid UPI ID and amount.';
    } else {
        $payload = [
            'upi_id' => $upiId,
            'amount' => number_format($amount, 2, '.', ''),
            'note'   => $note,
        ];

        $url = apiUrl('/api/payout');
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

        logPaymentEvent('payout.requested', [
            'upi_id' => $upiId,
            'amount' => $payload['amount'],
            'http'   => $httpCode,
            'url'    => $url,
        ]);

        if ($curlErr) {
            $errorMessage = 'Failed to reach the payment gateway.';
        } elseif ($httpCode < 200 || $httpCode >= 300) {
            $errorMessage = 'Gateway returned an error (HTTP ' . $httpCode . ').';
        } else {
            $parsed = json_decode((string)$response, true);
            if (!is_array($parsed)) {
                $errorMessage = 'Invalid JSON received from gateway.';
            } else {
                $result = $parsed;
                $success = (bool)($parsed['status'] ?? false);
                if (!$success && isset($parsed['message'])) {
                    $errorMessage = (string)$parsed['message'];
                }
            }
        }

        if ($success) {
            logPaymentEvent('payout.succeeded', [
                'upi_id' => $upiId,
                'amount' => $payload['amount'],
                'response' => isset($result['result']) ? $result['result'] : $result,
            ]);
        } else {
            logPaymentEvent('payout.failed', [
                'upi_id' => $upiId,
                'amount' => $payload['amount'],
                'reason' => $errorMessage,
                'body'   => isset($response) && is_string($response) ? mb_substr($response, 0, 2000) : null,
            ]);
        }
    }
}

$pageTitle = 'Send UPI Payout';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES); ?></title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;line-height:1.5;padding:24px;background:#0b1020;color:#e9eefc}
    .card{max-width:720px;margin:40px auto;background:#121a35;border:1px solid #22305c;border-radius:12px;padding:24px;box-shadow:0 10px 30px rgba(0,0,0,.35)}
    .muted{opacity:.85}
    label{display:block;margin-top:12px;margin-bottom:6px}
    input,textarea{width:100%;background:#0b132b;border:1px solid #21335a;border-radius:8px;padding:10px;color:#e9eefc}
    button{margin-top:12px;background:#4da3ff;color:#001;padding:10px 14px;border-radius:8px;font-weight:700;border:none;cursor:pointer}
    .error{background:#2a0f14;border:1px solid #7a1b27;color:#ffd7de;padding:12px;border-radius:8px;margin-top:12px}
    .success{background:#0f2a19;border:1px solid #1b7a3d;color:#d7ffea;padding:12px;border-radius:8px;margin-top:12px}
    pre{white-space:pre-wrap;word-break:break-word;background:#0b132b;border:1px solid #21335a;border-radius:8px;padding:12px;overflow:auto}
  </style>
</head>
<body>
  <div class="card">
    <h2><?php echo htmlspecialchars($pageTitle, ENT_QUOTES); ?></h2>

    <form method="post" action="">
      <label for="upi_id">UPI ID</label>
      <input type="text" id="upi_id" name="upi_id" placeholder="example@upi" required value="<?php echo htmlspecialchars($_POST['upi_id'] ?? '', ENT_QUOTES); ?>">

      <label for="amount">Amount (₹)</label>
      <input type="number" id="amount" name="amount" min="1" step="0.01" placeholder="0.00" required value="<?php echo htmlspecialchars($_POST['amount'] ?? '', ENT_QUOTES); ?>">

      <label for="note">Note (optional)</label>
      <input type="text" id="note" name="note" maxlength="64" placeholder="User withdrawal" value="<?php echo htmlspecialchars($_POST['note'] ?? 'User withdrawal', ENT_QUOTES); ?>">

      <button type="submit">Send Payout</button>
    </form>

    <?php if ($method === 'POST'): ?>
      <?php if ($success): ?>
        <div class="success"><strong>Payout submitted successfully.</strong> You can check status later.</div>
      <?php else: ?>
        <div class="error"><strong>Payout failed.</strong> <?php echo htmlspecialchars($errorMessage ?? 'Unknown error', ENT_QUOTES); ?></div>
      <?php endif; ?>

      <?php if (is_array($result)): ?>
        <details style="margin-top:8px">
          <summary>Gateway response</summary>
          <pre><?php echo htmlspecialchars(json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), ENT_QUOTES); ?></pre>
        </details>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</body>
</html>
