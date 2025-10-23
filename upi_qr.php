<?php
require_once __DIR__ . '/config.php';
header('Content-Type: text/html; charset=UTF-8');
$amount = isset($_GET['amount']) ? number_format((float)$_GET['amount'], 2, '.', '') : '10.00';
$orderId = isset($_GET['orderId']) ? preg_replace('/[^A-Za-z0-9_\-]/','', $_GET['orderId']) : ('ORDER_' . time());
// Expiry after 5 minutes
$expiresAt = time() + 300;
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
    $upiId = PAYTM_UPI_ID;
    $upiLink = 'upi://pay?pa=' . urlencode($upiId) . '&pn=' . urlencode('Payee') . '&am=' . urlencode($amount) . '&cu=INR&tn=' . urlencode('Order ' . $orderId) . '&tr=' . urlencode($orderId);
    $qrSrc = 'https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=' . urlencode($upiLink);
    // Try to fetch PNG and embed as base64 to avoid external hotlink issues
    $ch2 = curl_init($qrSrc);
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    $png = curl_exec($ch2);
    $code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);
    if ($png !== false && $code2 === 200) {
        $qrImg = 'data:image/png;base64,' . base64_encode($png);
    } else {
        $qrImg = $qrSrc; // final fallback to direct URL
    }
}
?>
<!doctype html>
<html>
<head>
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Paytm UPI Payment</title>
<style>
 body{display:flex;align-items:center;justify-content:center;min-height:100vh;background:radial-gradient( circle at 30% 30%,#08c,#006, #001), url('https://images.unsplash.com/photo-1508051123996-69f8caf4891d?auto=format&fit=crop&w=1400&q=60') center/cover no-repeat fixed;color:#fff;font-family:Inter,system-ui,Segoe UI,Roboto,Helvetica,Arial,sans-serif}
 .card{background:rgba(0,0,0,.55);backdrop-filter:blur(10px);padding:24px 28px;border-radius:16px;box-shadow:0 10px 40px rgba(0,0,0,.45);text-align:center;max-width:400px;border:1px solid rgba(255,255,255,.2)}
 img{width:260px;height:260px;border:6px solid rgba(255,255,255,.9);border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.35)}
 .btn{margin-top:16px;background:linear-gradient(135deg,#00E5FF,#5a5aff);color:#001;padding:10px 16px;border:none;border-radius:8px;font-weight:800;cursor:pointer;box-shadow:0 8px 20px rgba(0,229,255,.35)}
 .muted{opacity:.85}
 .timer{font-variant-numeric:tabular-nums;letter-spacing:.5px}
</style>
</head>
<body>
<div class="card">
  <h2>UPI PAYMENTS</h2>
  <?php if ($qrImg): ?>
    <img src="<?php echo htmlspecialchars($qrImg, ENT_QUOTES); ?>" alt="UPI QR">
  <?php else: ?>
    <div class="muted">QR could not be generated. Please set a valid PAYTM_UPI_ID in config.php.</div>
  <?php endif; ?>
  <p class="muted">Scan to pay ₹<?php echo htmlspecialchars($amount, ENT_QUOTES); ?></p>
  <p class="muted">Order ID: <?php echo htmlspecialchars($orderId, ENT_QUOTES); ?></p>
  <p class="muted">Expires in <span id="timer" class="timer">05:00</span></p>
  <button class="btn" onclick="checkStatus()">Check Payment</button>
  <div id="status" class="muted" style="margin-top:8px"></div>
</div>
<script>
 function checkStatus(){
   fetch('verify.php?orderId=<?php echo rawurlencode($orderId); ?>').then(r=>r.json()).then(d=>{
     document.getElementById('status').textContent = d.status || 'UNKNOWN';
   }).catch(()=>{document.getElementById('status').textContent='ERROR'});
 }
 // 5-minute countdown
 (function(){
   const end = Date.now() + 300000;
   const el = document.getElementById('timer');
   function tick(){
     const remain = Math.max(0, end - Date.now());
     const m = Math.floor(remain/60000), s = Math.floor((remain%60000)/1000);
     el.textContent = String(m).padStart(2,'0')+':'+String(s).padStart(2,'0');
     if (remain <= 0) { document.getElementById('status').textContent='QR expired. Create a new order.'; return; }
     requestAnimationFrame(tick);
   }
   tick();
 })();
</script>
</body>
</html>
