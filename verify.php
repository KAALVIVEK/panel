<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');
$orderId = $_GET['orderId'] ?? '';
if (!$orderId) { echo json_encode(['error'=>'Order ID missing']); exit; }
$url = PAYTM_API_BASE . '/v3/order/status';
$payload = json_encode(['mid'=>PAYTM_MID, 'orderId'=>$orderId]);
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$response = curl_exec($ch);
curl_close($ch);
$result = json_decode($response, true);
$status = $result['body']['resultInfo']['resultStatus'] ?? ($result['resultInfo']['resultStatus'] ?? 'UNKNOWN');
echo json_encode(['orderId'=>$orderId, 'status'=>$status]);
