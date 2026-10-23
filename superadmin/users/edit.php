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
$templateQuery = $conn->prepare("SELECT t.template_name, v.id AS version_id, v.version_number, v.config_json FROM quotation_templates t JOIN quotation_template_versions v ON v.template_id=t.id WHERE t.company_id = ? AND t.is_active=1 AND v.version_number=(SELECT MAX(v2.version_number) FROM quotation_template_versions v2 WHERE v2.template_id=t.id) ORDER BY t.template_name");
$templateQuery->bind_param('i', $user['company_id']);
$templateQuery->execute();
$templates = $templateQuery->get_result();
$templateQuery->close();
require_once __DIR__ . '/../../includes/user_permissions.php';
$permissionSelection = erpUserPermissionSelection($conn, (int)$user['id'], (string)$user['role']);
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
            <div class="col-md-4 mb-3"><label class="form-label">Assigned template *</label><select class="form-select" name="template_version_id" id="template_version_id" required><?php while ($template = $templates->fetch_assoc()) { $fieldConfig = json_decode((string)$template['config_json'], true); $fieldLabels = array_values(array_filter(array_map(static fn($field) => (string)($field['label'] ?? ''), is_array($fieldConfig['columns'] ?? null) ? $fieldConfig['columns'] : []))); ?><option value="<?= (int)$template['version_id'] ?>" data-fields="<?= adminEditEscape(json_encode($fieldLabels, JSON_UNESCAPED_UNICODE)) ?>" <?= (int)$user['assigned_template_version_id'] === (int)$template['version_id'] ? 'selected' : '' ?>><?= adminEditEscape($template['template_name']) ?> · v<?= (int)$template['version_number'] ?></option><?php } ?></select><small class="text-muted">Quotation item fields are controlled by this assigned template.</small><div id="assignedTemplateFields" class="small text-primary mt-1"></div></div>
            <div class="col-md-6 mb-3"><label class="form-label">Reset password</label><input class="form-control" type="password" name="password" minlength="12" autocomplete="new-password"><small class="text-muted">Leave blank to keep the current password.</small></div>
        </div></div></div>
        <div class="card mt-3"><div class="card-header"><h5 class="mb-0">Modules and actions for this user</h5><small class="text-muted">Unchecked actions are blocked, including direct page access. Quotation fields come from the assigned template above.</small></div><div class="card-body"><div class="row">
            <?php foreach (erpUserPermissionOptions() as $module => $permissions) { ?>
                <div class="col-lg-4 col-md-6 mb-3"><div class="border rounded p-3 h-100"><strong><?= adminEditEscape($module) ?></strong>
                    <?php foreach ($permissions as $key => $label) { $permissionId = 'permission_' . str_replace('.', '_', $key); ?>
                        <div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="<?= adminEditEscape($permissionId) ?>" name="permissions[<?= adminEditEscape($key) ?>]" value="1" <?= !empty($permissionSelection[$key]) ? 'checked' : '' ?>><label class="form-check-label" for="<?= adminEditEscape($permissionId) ?>"><?= adminEditEscape($label) ?></label></div>
                    <?php } ?>
                </div></div>
            <?php } ?>
        </div></div></div>
        <button class="btn btn-primary" type="submit">Save user</button> <a class="btn btn-light" href="index.php">Cancel</a>
    </form>
</div></div></div>
<script>
(function () {
    var select = document.getElementById('template_version_id');
    var preview = document.getElementById('assignedTemplateFields');
    if (!select || !preview) return;
    function showFields() {
        var option = select.options[select.selectedIndex];
        try {
            var fields = JSON.parse(option.getAttribute('data-fields') || '[]');
            preview.textContent = fields.length ? 'Assigned quotation fields: ' + fields.join(', ') : 'This template has no quotation item fields.';
        } catch (e) {
            preview.textContent = 'The template fields could not be previewed.';
        }
    }
    select.addEventListener('change', showFields);
    showFields();
})();
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
