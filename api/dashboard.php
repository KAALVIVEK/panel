<?php
// API entrypoint moved under api/ to match deployment structure
require_once __DIR__ . '/paytm_checksum.php';

// Simple router wrapper to include original logic
require_once __DIR__ . '/../dashboard.php';
