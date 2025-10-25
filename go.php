<?php
// Secure short link: /go/<token>
// Token format: base64url(JSON).base64url(HMAC_SHA256)
// JSON payload example:
// {"path":"/ztrax/dashboard.html","qs":"?user_id=UID-...&role=owner","exp":1735689600}

declare(strict_types=1);

function b64u_decode(string $s): string { $s = strtr($s, '-_', '+/'); return base64_decode($s . str_repeat('=', (4 - strlen($s) % 4) % 4)); }
function b64u_encode(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

$token = $_GET['t'] ?? '';
if ($token === '' || strpos($token, '.') === false) {
  http_response_code(404);
  echo 'Invalid link';
  exit;
}

[$p, $sig] = explode('.', $token, 2);
$payloadRaw = b64u_decode($p);
$payload = json_decode($payloadRaw, true);
if (!is_array($payload)) { http_response_code(404); echo 'Bad token'; exit; }

$secret = getenv('LINK_SECRET') ?: 'CHANGE_ME_TO_LONG_RANDOM';
$expected = hash_hmac('sha256', $payloadRaw, $secret, true);
if (!hash_equals($expected, b64u_decode($sig))) { http_response_code(404); echo 'Bad token'; exit; }

$exp = (int)($payload['exp'] ?? 0);
if ($exp > 0 && time() > $exp) { http_response_code(410); echo 'Link expired'; exit; }

$path = (string)($payload['path'] ?? '');
$qs = (string)($payload['qs'] ?? '');
// Allowlist target paths to prevent open redirect
$allowed = [
  '/ztrax/dashboard.html',
  '/ztrax/create_order.php',
  '/payment_return.php',
];
if (!in_array($path, $allowed, true)) { http_response_code(404); echo 'Target not allowed'; exit; }

$url = $path . $qs;
header('Location: ' . $url, true, 302);
exit;
