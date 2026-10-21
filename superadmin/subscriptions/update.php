<?php
declare(strict_types=1);
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: ../../login.php'); exit; }
require_once __DIR__ . '/../../includes/permissions.php';
erpRequire('superadmin.subscriptions');
require_once __DIR__ . '/../../includes/db.php';
function subscriptionFail(string $message): never {
    $_SESSION['subscription_error'] = $message;
    $id = (int)($_POST['company_id'] ?? 0);
    header('Location: edit.php?id=' . $id);
    exit;
}
function subscriptionDate(string $value): ?string {
    if ($value === '') return null;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Kolkata'));
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) || $date->format('Y-m-d') !== $value) return null;
    return $value;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
$token = (string)($_POST['csrf_token'] ?? '');
if (empty($_SESSION['csrf_token']) || $token === '' || !hash_equals($_SESSION['csrf_token'], $token)) subscriptionFail('Request expired. Reload and try again.');
$id = filter_var($_POST['company_id'] ?? null, FILTER_VALIDATE_INT);
$plan = (string)($_POST['subscription_plan'] ?? '');
if (!$id || $id < 1 || !in_array($plan, ['Business','Premium'], true)) subscriptionFail('Choose a valid company and plan.');
$trialStartRaw = trim((string)($_POST['trial_start_date'] ?? ''));
$trialEndRaw = trim((string)($_POST['trial_end_date'] ?? ''));
$paidStartRaw = trim((string)($_POST['paid_start_date'] ?? ''));
$paidEndRaw = trim((string)($_POST['paid_end_date'] ?? ''));
$trialStart = subscriptionDate($trialStartRaw);
$trialEnd = subscriptionDate($trialEndRaw);
$paidStart = subscriptionDate($paidStartRaw);
$paidEnd = subscriptionDate($paidEndRaw);
foreach ([[$trialStartRaw,$trialStart],[$trialEndRaw,$trialEnd],[$paidStartRaw,$paidStart],[$paidEndRaw,$paidEnd]] as [$raw,$date]) {
    if ($raw !== '' && $date === null) subscriptionFail('Enter valid dates in YYYY-MM-DD format.');
}
if (($trialStart === null) !== ($trialEnd === null) || ($paidStart === null) !== ($paidEnd === null)) subscriptionFail('Enter both start and end dates for each period you use.');
if ($trialStart !== null && $trialEnd < $trialStart) subscriptionFail('Trial end must be on or after trial start.');
if ($paidStart !== null && $paidEnd < $paidStart) subscriptionFail('Paid end must be on or after paid start.');
if ($trialEnd !== null && $paidStart !== null && $paidStart <= $trialEnd) subscriptionFail('Paid access must start after the trial period ends.');

$companyCheck = $conn->prepare('SELECT id FROM companies WHERE id = ? LIMIT 1');
$companyCheck->bind_param('i', $id); $companyCheck->execute(); $exists = $companyCheck->get_result()->fetch_assoc(); $companyCheck->close();
if (!$exists) subscriptionFail('Company not found.');
$limit = $plan === 'Premium' ? 2 : 1;
$userCount = $conn->prepare("SELECT COUNT(*) AS total FROM users WHERE company_id = ? AND status = 'Active'");
$userCount->bind_param('i', $id); $userCount->execute(); $activeUsers = (int)($userCount->get_result()->fetch_assoc()['total'] ?? 0); $userCount->close();
if ($activeUsers > $limit) subscriptionFail("This company has {$activeUsers} active users. Deactivate users before assigning the {$plan} plan.");

$stmt = $conn->prepare(
    'UPDATE companies SET subscription_plan = ?, trial_start_date = ?, trial_end_date = ?, paid_start_date = ?, paid_end_date = ? WHERE id = ? LIMIT 1'
);
$stmt->bind_param('sssssi', $plan, $trialStart, $trialEnd, $paidStart, $paidEnd, $id);
$stmt->execute(); $stmt->close();
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
header('Location: index.php?saved=1');
exit;
?>