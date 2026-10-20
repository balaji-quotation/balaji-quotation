<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}
require_once __DIR__ . '/../../includes/permissions.php';
erpRequire('superadmin.change_requests');
require_once __DIR__ . '/../../includes/db.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}
$token = (string)($_POST['csrf_token'] ?? '');
if (empty($_SESSION['csrf_token']) || $token === '' || !hash_equals($_SESSION['csrf_token'], $token)) {
    http_response_code(400);
    exit('Request expired. Reload the page and try again.');
}
$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
$status = trim((string)($_POST['status'] ?? ''));
$note = mb_substr(trim((string)($_POST['admin_note'] ?? '')), 0, 2000, 'UTF-8');
$allowed = ['Pending','In Review','Approved','Rejected','Completed'];
if (!$id || $id < 1 || !in_array($status, $allowed, true)) {
    http_response_code(400);
    exit('Invalid request update.');
}
$reviewerId = (int)$_SESSION['user_id'];
$stmt = $conn->prepare(
    'UPDATE template_change_requests SET status = ?, admin_note = NULLIF(?, \'\'), reviewed_by = ?, reviewed_at = NOW() WHERE id = ? LIMIT 1'
);
$stmt->bind_param('ssii', $status, $note, $reviewerId, $id);
$stmt->execute();
$stmt->close();
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
header('Location: index.php?updated=1');
exit;
