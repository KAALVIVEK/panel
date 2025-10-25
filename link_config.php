<?php
// Beginner-friendly config for short secure links
// 1) Set your long random secret here (or via hPanel environment LINK_SECRET)
// 2) Add/adjust allowed destination paths

// Example: set via file (simplest for beginners)
if (!defined('LINK_SECRET')) {
  define('LINK_SECRET', 'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET_32+CHARS');
}

// Only these relative paths can be targeted by /go links
if (!defined('LINK_ALLOWED_PATHS')) {
  define('LINK_ALLOWED_PATHS', [
    '/ztrax/dashboard.html',
    '/ztrax/create_order.php',
    '/payment_return.php',
  ]);
}
