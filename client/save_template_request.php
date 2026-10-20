<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/permissions.php';
erpRequireCompanyUser($conn);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: template_request.php');
    exit;
}
$token = (string)($_POST['csrf_token'] ?? '');
if (empty($_SESSION['csrf_token']) || $token === '' || !hash_equals($_SESSION['csrf_token'], $token)) {
    $_SESSION['template_request_error'] = 'Request expired. Please try again.';
    header('Location: template_request.php');
    exit;
}
$title = trim((string)($_POST['title'] ?? ''));
$details = trim((string)($_POST['details'] ?? ''));
$title = mb_substr($title, 0, 200, 'UTF-8');
$details = mb_substr($details, 0, 5000, 'UTF-8');
if ($title === '' || $details === '') {
    $_SESSION['template_request_error'] = 'Enter a title and describe the requested change.';
    header('Location: template_request.php');
    exit;
}
$companyId = erpCurrentCompanyId();
$userId = (int)$_SESSION['user_id'];
$stmt = $conn->prepare(
    "INSERT INTO template_change_requests (company_id, requested_by, title, details)
     VALUES (?, ?, ?, ?)"
);
$stmt->bind_param('iiss', $companyId, $userId, $title, $details);
$stmt->execute();
$stmt->close();
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
header('Location: template_request.php?submitted=1');
exit;
