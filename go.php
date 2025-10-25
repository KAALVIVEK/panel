<?php
// Pretty URL entry: /go/<token> -> include create_order.php with params
// This keeps the address bar clean (no long querystring).

declare(strict_types=1);

$token = $_GET['t'] ?? '';
if ($token === '') {
  http_response_code(404);
  echo 'Missing token';
  exit;
}

// Map short tokens to parameters (edit this list)
// Example:
// 'topup1' => [
//   'amount' => '1',
//   'remark1' => 'UID-68fc8522e26445.21723884',
//   'remark2' => 'topup',
//   'redirect_url' => 'https://ztrax.in/payment_return.php'
// ]
$map = [
  // 'yourtoken' => ['amount' => '1', 'remark1' => 'UID-...', 'remark2' => 'topup', 'redirect_url' => 'https://ztrax.in/payment_return.php']
];

if (!isset($map[$token]) || !is_array($map[$token])) {
  http_response_code(404);
  echo 'Invalid link';
  exit;
}

// Inject parameters so create_order.php can read them via $_GET/$_REQUEST
$params = $map[$token];
foreach ($params as $k => $v) {
  $_GET[$k] = $v;
  $_REQUEST[$k] = $v;
}

// Render create_order without showing its long query in the URL
require __DIR__ . '/create_order.php';
exit;
