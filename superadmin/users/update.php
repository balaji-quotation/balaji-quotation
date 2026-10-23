<?php
declare(strict_types=1);
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: ../../login.php'); exit; }
require_once __DIR__ . '/../../includes/permissions.php';
erpRequire('superadmin.users');
require_once __DIR__ . '/../../includes/db.php';
function adminEditFail(string $message, int $id): never { $_SESSION['admin_user_error'] = $message; header('Location: edit.php?id=' . $id); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
$id = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
$id = $id ? (int)$id : 0;
$token = (string)($_POST['csrf_token'] ?? '');
if (empty($_SESSION['csrf_token']) || $token === '' || !hash_equals($_SESSION['csrf_token'], $token)) adminEditFail('Request expired. Reload and try again.', $id);
$name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 100, 'UTF-8');
$email = mb_substr(strtolower(trim((string)($_POST['email'] ?? ''))), 0, 100, 'UTF-8');
$role = trim((string)($_POST['role'] ?? 'User'));
$status = trim((string)($_POST['status'] ?? ''));
$templateVersionId = filter_var($_POST['template_version_id'] ?? null, FILTER_VALIDATE_INT);
$password = (string)($_POST['password'] ?? '');
$roles = ['Administrator','Manager','Production','Quality','Dispatch','Accounts','User'];
if ($id < 1 || $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($role, $roles, true) || !in_array($status, ['Active','Inactive'], true) || !$templateVersionId || $templateVersionId < 1) adminEditFail('Check the user details and template assignment.', $id);
$check = $conn->prepare('SELECT company_id, status, assigned_template_version_id FROM users WHERE id = ? AND company_id IS NOT NULL LIMIT 1');
$check->bind_param('i', $id); $check->execute(); $existing = $check->get_result()->fetch_assoc(); $check->close();
if (!$existing) adminEditFail('Company user not found.', $id);
$companyId = (int)$existing['company_id'];
if ($status === 'Active' && $existing['status'] !== 'Active') {
    $company = $conn->prepare('SELECT subscription_plan FROM companies WHERE id = ? LIMIT 1');
    $company->bind_param('i', $companyId); $company->execute(); $plan = $company->get_result()->fetch_assoc()['subscription_plan'] ?? null; $company->close();
    $limit = $plan === 'Premium' ? 2 : ($plan === 'Business' ? 1 : 0);
    $count = $conn->prepare("SELECT COUNT(*) AS total FROM users WHERE company_id = ? AND status = 'Active'");
    $count->bind_param('i', $companyId); $count->execute(); $active = (int)($count->get_result()->fetch_assoc()['total'] ?? 0); $count->close();
    if ($limit === 0 || $active >= $limit) adminEditFail('The company has reached its active-user limit or has no plan.', $id);
}
$template = $conn->prepare('SELECT v.id FROM quotation_template_versions v JOIN quotation_templates t ON t.id=v.template_id WHERE v.id=? AND t.company_id=? AND t.is_active=1 LIMIT 1');
$template->bind_param('ii', $templateVersionId, $companyId); $template->execute(); $templateOk = $template->get_result()->fetch_assoc(); $template->close();
if (!$templateOk) adminEditFail('Choose an active quotation template version.', $id);
$duplicate = $conn->prepare('SELECT id FROM users WHERE LOWER(email)=? AND id<>? LIMIT 1');
$duplicate->bind_param('si', $email, $id); $duplicate->execute(); $duplicate->store_result(); $isDuplicate = $duplicate->num_rows > 0; $duplicate->close();
if ($isDuplicate) adminEditFail('That email address is already in use.', $id);
if ($password !== '' && strlen($password) < 12) adminEditFail('Reset password must be at least 12 characters.', $id);
if ($password !== '') {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($hash === false) adminEditFail('Password could not be secured.', $id);
    $stmt = $conn->prepare('UPDATE users SET name=?, email=?, role=?, status=?, assigned_template_version_id=?, password=? WHERE id=? AND company_id=?');
    $stmt->bind_param('ssssissi', $name, $email, $role, $status, $templateVersionId, $hash, $id, $companyId);
} else {
    $stmt = $conn->prepare('UPDATE users SET name=?, email=?, role=?, status=?, assigned_template_version_id=? WHERE id=? AND company_id=?');
    $stmt->bind_param('ssssiii', $name, $email, $role, $status, $templateVersionId, $id, $companyId);
}
require_once __DIR__ . '/../../includes/user_permissions.php';
try {
    $conn->begin_transaction();
    $stmt->execute();
    $stmt->close();
    erpSaveUserPermissionOverrides($conn, $id, $_POST['permissions'] ?? []);
    $userTemplateVersionId = erpCreateUserQuotationTemplate(
        $conn,
        $companyId,
        $id,
        $name,
        (int)$_SESSION['user_id'],
        (int)$templateVersionId,
        (int)($existing['assigned_template_version_id'] ?? 0),
        $_POST['quotation_fields'] ?? [],
        (string)($_POST['print_layout'] ?? '')
    );
    $assign = $conn->prepare('UPDATE users SET assigned_template_version_id = ? WHERE id = ? AND company_id = ?');
    $assign->bind_param('iii', $userTemplateVersionId, $id, $companyId);
    $assign->execute();
    $assign->close();
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    error_log('Company user update failed: ' . $e->getMessage());
    adminEditFail('User access could not be saved. Run the user-permissions migration, then try again.', $id);
}
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
header('Location: index.php?updated=1');
exit;
?>