<?php

// =====================================================
// SESSION ACTIVITY PING
// Resets the 30-minute inactivity timer for logged-in users.
// =====================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'active' => false,
        'message' => 'Session expired'
    ]);
    exit;
}

$session_timeout = 30 * 60;
$_SESSION['last_activity'] = time();

echo json_encode([
    'active' => true,
    'timeout_seconds' => $session_timeout,
    'last_activity' => $_SESSION['last_activity']
]);
