<?php
require_once __DIR__ . '/config.php';
header('Content-Type: text/html; charset=UTF-8');
$amount = isset($_GET['amount']) ? number_format((float)$_GET['amount'], 2, '.', '') : '10.00';
$orderId = isset($_GET['orderId']) ? preg_replace('/[^A-Za-z0-9_\-]/','', $_GET['orderId']) : ('ORDER_' . time());
// Create UPI QR via Paytm API (if available to your MID) OR fallback to generic UPI link
$data = [ 'mid'=>PAYTM_MID, 'orderId'=>$orderId, 'amount'=>$amount, 'businessType'=>'UPI_QR', 'posId'=>'WEB_01' ];
$payload = json_encode($data);
$url = PAYTM_API_BASE . '/paymentservices/qr/create';
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$response = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$result = json_decode($response, true);
$qrImg = '';
if ($httpcode === 200 && isset($result['body']['qrImage'])) {
    $qrImg = 'data:image/png;base64,' . $result['body']['qrImage'];
} else {
    // Fallback to generic UPI deeplink QR
    $upiLink = 'upi://pay?pa=' . urlencode(PAYTM_UPI_ID) . '&pn=' . urlencode('Payee') . '&am=' . urlencode($amount) . '&cu=INR&tn=' . urlencode('Order ' . $orderId) . '&tr=' . urlencode($orderId);
    $qrImg = 'https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=' . urlencode($upiLink);
}
?>
<!doctype html>
<html>
<head>
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Paytm UPI Payment</title>
<style>
 body{display:flex;align-items:center;justify-content:center;min-height:100vh;background:#00B8F0;color:#fff;font-family:system-ui,Segoe UI,Roboto,Helvetica,Arial,sans-serif}
 .card{background:rgba(255,255,255,.1);padding:24px 28px;border-radius:16px;box-shadow:0 10px 30px rgba(0,0,0,.25);text-align:center;max-width:360px}
 img{width:260px;height:260px;border:6px solid #fff;border-radius:12px}
 .btn{margin-top:16px;background:#fff;color:#00B8F0;padding:10px 16px;border:none;border-radius:8px;font-weight:700;cursor:pointer}
 .muted{opacity:.8}
</style>
</head>
<body>
<div class="card">
  <h2>UPI PAYMENTS</h2>
  <img src="<?php echo htmlspecialchars($qrImg, ENT_QUOTES); ?>" alt="UPI QR">
  <p class="muted">Scan to pay ₹<?php echo htmlspecialchars($amount, ENT_QUOTES); ?></p>
  <p class="muted">Order ID: <?php echo htmlspecialchars($orderId, ENT_QUOTES); ?></p>
  <button class="btn" onclick="checkStatus()">Check Payment</button>
  <div id="status" class="muted" style="margin-top:8px"></div>
</div>
<script>
 function checkStatus(){
   fetch('verify.php?orderId=<?php echo rawurlencode($orderId); ?>').then(r=>r.json()).then(d=>{
     document.getElementById('status').textContent = d.status || 'UNKNOWN';
   }).catch(()=>{document.getElementById('status').textContent='ERROR'});
 }
</script>
</body>
</html>
