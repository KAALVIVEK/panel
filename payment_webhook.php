<?php
declare(strict_types=1);

// Simple payment webhook to credit balance when gateway notifies success
// Expected JSON: { order_id, user_id, amount, status }
// status values: SUCCESS|FAILED (case-insensitive)

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// Load DB helpers without triggering API routing
if (!defined('DASHBOARD_LIB_ONLY')) { define('DASHBOARD_LIB_ONLY', true); }
require_once __DIR__ . '/dashboard.php'; // reuse DB helpers and ensure tables

try {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);
    if (!is_array($payload)) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'Invalid JSON']); exit; }

    $orderId = trim((string)($payload['order_id'] ?? ''));
    $userId  = trim((string)($payload['user_id'] ?? ''));
    // Fallback to remark1/remark2 for user id when not provided explicitly
    if ($userId === '') {
        $userId = trim((string)($payload['remark1'] ?? '')) ?: trim((string)($payload['remark2'] ?? ''));
    }
    $amount  = (float)($payload['amount'] ?? 0);
    $status  = strtoupper(trim((string)($payload['status'] ?? '')));

    if ($orderId === '' || $userId === '' || $amount <= 0 || ($status !== 'SUCCESS' && $status !== 'FAILED')) {
        http_response_code(400);
        echo json_encode(['success'=>false,'message'=>'Missing/invalid fields']);
        exit;
    }

    $conn = connectDB();
    ensurePaymentsTables($conn);

    // Upsert payment record and credit on SUCCESS (idempotent)
    // Create row if not exists
    $ins = $conn->prepare("INSERT INTO payments (order_id, user_id, amount, status, raw_response) VALUES (?, ?, ?, 'INIT', ?) ON DUPLICATE KEY UPDATE raw_response = VALUES(raw_response)");
    $ins->bind_param('ssds', $orderId, $userId, $amount, $raw);
    $ins->execute();
    $ins->close();

    // Fetch current status
    $sel = $conn->prepare('SELECT status FROM payments WHERE order_id = ? LIMIT 1');
    $sel->bind_param('s', $orderId);
    $sel->execute();
    $row = $sel->get_result()->fetch_assoc();
    $sel->close();

    $current = $row['status'] ?? 'INIT';
    if ($current === 'SUCCESS') {
        echo json_encode(['success'=>true, 'message'=>'Already processed']);
        $conn->close();
        exit;
    }

    if ($status === 'SUCCESS') {
        $upd = $conn->prepare("UPDATE payments SET status='SUCCESS' WHERE order_id = ?");
        $upd->bind_param('s', $orderId);
        $upd->execute();
        $upd->close();

        $credit = $conn->prepare('UPDATE users SET balance = balance + ? WHERE user_id = ?');
        $credit->bind_param('ds', $amount, $userId);
        $credit->execute();
        $credit->close();
    } else {
        $upd = $conn->prepare("UPDATE payments SET status='FAILED' WHERE order_id = ?");
        $upd->bind_param('s', $orderId);
        $upd->execute();
        $upd->close();
    }

    echo json_encode(['success'=>true]);
    $conn->close();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Server error']);
}
