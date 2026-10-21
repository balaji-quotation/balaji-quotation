<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: ../../login.php'); exit; }
require_once __DIR__ . '/../../includes/permissions.php';
erpRequire('superadmin.users');
require_once __DIR__ . '/../../includes/db.php';
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$id || $id < 1) { header('Location: index.php'); exit; }
$stmt = $conn->prepare("SELECT id, company_id, name, email, role, status, assigned_template_version_id FROM users WHERE id = ? AND company_id IS NOT NULL LIMIT 1");
$stmt->bind_param('i', $id); $stmt->execute(); $user = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$user) { header('Location: index.php'); exit; }
$templates = $conn->query("SELECT t.template_name, v.id AS version_id, v.version_number FROM quotation_templates t JOIN quotation_template_versions v ON v.template_id=t.id WHERE t.is_active=1 AND v.version_number=(SELECT MAX(v2.version_number) FROM quotation_template_versions v2 WHERE v2.template_id=t.id) ORDER BY t.template_name");
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
include __DIR__ . '/../../includes/header.php'; include __DIR__ . '/../../includes/superadmin_sidebar.php';
function adminEditEscape($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$error = $_SESSION['admin_user_error'] ?? ''; unset($_SESSION['admin_user_error']);
?>
<div class="content-page"><div class="content"><div class="container-fluid">
    <div class="page-title-box"><h4 class="page-title">Edit Company User</h4></div>
    <?php if ($error !== '') { ?><div class="alert alert-danger"><?= adminEditEscape($error) ?></div><?php } ?>
    <form method="post" action="update.php" autocomplete="off"><input type="hidden" name="csrf_token" value="<?= adminEditEscape($_SESSION['csrf_token']) ?>"><input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
        <div class="card"><div class="card-body"><div class="row">
            <div class="col-md-6 mb-3"><label class="form-label">Name *</label><input class="form-control" name="name" required maxlength="100" value="<?= adminEditEscape($user['name']) ?>"></div>
            <div class="col-md-6 mb-3"><label class="form-label">Login email *</label><input class="form-control" type="email" name="email" required maxlength="100" value="<?= adminEditEscape($user['email']) ?>"></div>
            <div class="col-md-4 mb-3"><label class="form-label">Role</label><select class="form-select" name="role"><?php foreach (['Administrator','Manager','Production','Quality','Dispatch','Accounts','User'] as $role) { ?><option value="<?= $role ?>" <?= $user['role'] === $role ? 'selected' : '' ?>><?= $role ?></option><?php } ?></select></div>
            <div class="col-md-4 mb-3"><label class="form-label">Status</label><select class="form-select" name="status"><?php foreach (['Active','Inactive'] as $status) { ?><option value="<?= $status ?>" <?= $user['status'] === $status ? 'selected' : '' ?>><?= $status ?></option><?php } ?></select></div>
            <div class="col-md-4 mb-3"><label class="form-label">Assigned template *</label><select class="form-select" name="template_version_id" required><?php while ($template = $templates->fetch_assoc()) { ?><option value="<?= (int)$template['version_id'] ?>" <?= (int)$user['assigned_template_version_id'] === (int)$template['version_id'] ? 'selected' : '' ?>><?= adminEditEscape($template['template_name']) ?> · v<?= (int)$template['version_number'] ?></option><?php } ?></select></div>
            <div class="col-md-6 mb-3"><label class="form-label">Reset password</label><input class="form-control" type="password" name="password" minlength="12" autocomplete="new-password"><small class="text-muted">Leave blank to keep the current password.</small></div>
        </div><button class="btn btn-primary" type="submit">Save user</button> <a class="btn btn-light" href="index.php">Cancel</a></div></div>
    </form>
</div></div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
