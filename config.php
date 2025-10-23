<?php
// Replace with your merchant details or set as environment variables
define('PAYTM_ENVIRONMENT', getenv('PAYTM_ENVIRONMENT') ?: 'PROD'); // or 'STAGING'
define('PAYTM_MID', getenv('PAYTM_MID') ?: 'YOUR_MID');
define('PAYTM_UPI_ID', getenv('PAYTM_UPI_ID') ?: 'your-upi-id@paytm');
define('PAYTM_API_BASE', PAYTM_ENVIRONMENT === 'PROD' ? 'https://securegw.paytm.in' : 'https://securegw-stage.paytm.in');
