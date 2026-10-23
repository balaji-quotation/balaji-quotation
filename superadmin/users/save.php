<?php
declare(strict_types=1);
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: ../../login.php'); exit; }
require_once __DIR__ . '/../../includes/permissions.php';
erpRequire('superadmin.users');
require_once __DIR__ . '/../../includes/db.php';
function adminUserFail(string $message): never { $_SESSION['admin_user_error'] = $message; header('Location: add.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
$token = (string)($_POST['csrf_token'] ?? '');
if (empty($_SESSION['csrf_token']) || $token === '' || !hash_equals($_SESSION['csrf_token'], $token)) adminUserFail('Request expired. Reload and try again.');
$companyId = filter_var($_POST['company_id'] ?? null, FILTER_VALIDATE_INT);
$templateVersionId = filter_var($_POST['template_version_id'] ?? null, FILTER_VALIDATE_INT);
$name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 100, 'UTF-8');
$email = mb_substr(strtolower(trim((string)($_POST['email'] ?? ''))), 0, 100, 'UTF-8');
$password = (string)($_POST['password'] ?? '');
$role = trim((string)($_POST['role'] ?? 'User'));
$roles = ['Administrator','Manager','Production','Quality','Dispatch','Accounts','User'];
if (!$companyId || $companyId < 1 || !$templateVersionId || $templateVersionId < 1 || $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12 || !in_array($role, $roles, true)) adminUserFail('Check the company, template, name, email, role, and minimum 12-character password.');
$company = $conn->prepare("SELECT id, subscription_plan, status FROM companies WHERE id = ? LIMIT 1");
$company->bind_param('i', $companyId); $company->execute(); $companyRow = $company->get_result()->fetch_assoc(); $company->close();
if (!$companyRow || $companyRow['status'] !== 'Active') adminUserFail('Choose an active company.');
$limit = $companyRow['subscription_plan'] === 'Premium' ? 2 : ($companyRow['subscription_plan'] === 'Business' ? 1 : 0);
if ($limit === 0) adminUserFail('Set the company plan in Subscriptions before creating users.');
$count = $conn->prepare("SELECT COUNT(*) AS total FROM users WHERE company_id = ? AND status = 'Active'");
$count->bind_param('i', $companyId); $count->execute(); $activeUsers = (int)($count->get_result()->fetch_assoc()['total'] ?? 0); $count->close();
if ($activeUsers >= $limit) adminUserFail('The company has reached its active-user limit for this plan.');
$template = $conn->prepare("SELECT v.id FROM quotation_template_versions v JOIN quotation_templates t ON t.id = v.template_id WHERE v.id = ? AND t.company_id = ? AND t.is_active = 1 LIMIT 1");
$template->bind_param('ii', $templateVersionId, $companyId); $template->execute(); $templateRow = $template->get_result()->fetch_assoc(); $template->close();
if (!$templateRow) adminUserFail('Choose an active template version.');
$dupe = $conn->prepare('SELECT id FROM users WHERE LOWER(email) = ? LIMIT 1');
$dupe->bind_param('s', $email); $dupe->execute(); $dupe->store_result(); $hasDuplicate = $dupe->num_rows > 0; $dupe->close();
if ($hasDuplicate) adminUserFail('That email address is already in use.');
$hash = password_hash($password, PASSWORD_DEFAULT);
if ($hash === false) adminUserFail('Password could not be secured.');
$stmt = $conn->prepare("INSERT INTO users (name, email, password, role, status, company_id, assigned_template_version_id) VALUES (?, ?, ?, ?, 'Active', ?, ?)");
$stmt->bind_param('ssssii', $name, $email, $hash, $role, $companyId, $templateVersionId);
require_once __DIR__ . '/../../includes/user_permissions.php';
try {
    $conn->begin_transaction();
    $stmt->execute();
    $newUserId = (int)$conn->insert_id;
    $stmt->close();
    erpSaveUserPermissionOverrides($conn, $newUserId, $_POST['permissions'] ?? []);
    $userTemplateVersionId = erpCreateUserQuotationTemplate(
        $conn,
        (int)$companyId,
        $newUserId,
        $name,
        (int)$_SESSION['user_id'],
        (int)$templateVersionId,
        0,
        (string)($_POST['quotation_columns_json'] ?? ''),
        (string)($_POST['print_layout'] ?? '')
    );
    $assign = $conn->prepare('UPDATE users SET assigned_template_version_id = ? WHERE id = ? AND company_id = ?');
    $assign->bind_param('iii', $userTemplateVersionId, $newUserId, $companyId);
    $assign->execute();
    $assign->close();
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    error_log('Company user creation failed: ' . $e->getMessage());
    $message = $e instanceof mysqli_sql_exception
        ? 'User could not be created. Confirm the user-permissions migration is applied and the selected template settings are valid.'
        : $e->getMessage();
    adminUserFail($message);
}
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
header('Location: index.php?created=1');
exit;
?>