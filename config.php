<?php
// Replace with your merchant details or set as environment variables
define('PAYTM_ENVIRONMENT', getenv('PAYTM_ENVIRONMENT') ?: 'PROD'); // or 'STAGING'
define('PAYTM_MID', getenv('PAYTM_MID') ?: 'YOUR_MID');
define('PAYTM_MERCHANT_KEY', getenv('PAYTM_MERCHANT_KEY') ?: 'YOUR_MERCHANT_KEY');
define('PAYTM_WEBSITE', getenv('PAYTM_WEBSITE') ?: 'DEFAULT');
// Callback URL should point to your deployed paytm_callback.php endpoint
// e.g., https://yourdomain.com/paytm_callback.php
define('PAYTM_CALLBACK_URL', getenv('PAYTM_CALLBACK_URL') ?: ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']==='on'?'https':'http').'://'.$_SERVER['HTTP_HOST'].str_replace('dashboard.html','', $_SERVER['REQUEST_URI']).'paytm_callback.php'));
