<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: ../../login.php'); exit; }
require_once __DIR__ . '/../../includes/permissions.php';
erpRequire('superadmin.users');
require_once __DIR__ . '/../../includes/db.php';
$companies = $conn->query("SELECT id, company_name, subscription_plan FROM companies WHERE status = 'Active' ORDER BY company_name");
$templates = $conn->query(
    "SELECT t.company_id, c.company_name, t.template_name, v.id AS version_id, v.version_number
     FROM quotation_templates t
     JOIN companies c ON c.id = t.company_id
     JOIN quotation_template_versions v ON v.template_id = t.id
     WHERE t.is_active = 1
       AND v.version_number = (SELECT MAX(v2.version_number) FROM quotation_template_versions v2 WHERE v2.template_id = t.id)
     ORDER BY c.company_name, t.template_name"
);
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
include __DIR__ . '/../../includes/header.php'; include __DIR__ . '/../../includes/superadmin_sidebar.php';
function adminAddEscape($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$error = $_SESSION['admin_user_error'] ?? ''; unset($_SESSION['admin_user_error']);
?>
<div class="content-page"><div class="content"><div class="container-fluid">
    <div class="page-title-box d-flex justify-content-between align-items-center flex-wrap gap-2"><div><h4 class="page-title">Create Company User</h4><p class="text-muted mb-0">Create the client-specific quotation template first, then assign it here. The user sees only the fields in the assigned template.</p></div><a class="btn btn-outline-primary" href="../templates/index.php">Configure Client Quotation Fields</a></div>
    <?php if ($error !== '') { ?><div class="alert alert-danger"><?= adminAddEscape($error) ?></div><?php } ?>
    <form method="post" action="save.php" autocomplete="off"><input type="hidden" name="csrf_token" value="<?= adminAddEscape($_SESSION['csrf_token']) ?>">
        <div class="card"><div class="card-body"><div class="row">
            <div class="col-md-6 mb-3"><label class="form-label">Company *</label><select name="company_id" id="company_id" class="form-select" required><option value="">Choose company</option><?php while ($company = $companies->fetch_assoc()) { ?><option value="<?= (int)$company['id'] ?>"><?= adminAddEscape($company['company_name']) ?> · <?= adminAddEscape($company['subscription_plan'] ?? 'Plan not set') ?></option><?php } ?></select></div>
            <div class="col-md-6 mb-3"><label class="form-label">Assigned template version *</label><select name="template_version_id" id="template_version_id" class="form-select" required disabled><option value="">Choose company first</option><?php while ($template = $templates->fetch_assoc()) { ?><option value="<?= (int)$template['version_id'] ?>" data-company-id="<?= (int)$template['company_id'] ?>"><?= adminAddEscape($template['company_name']) ?> · <?= adminAddEscape($template['template_name']) ?> · v<?= (int)$template['version_number'] ?></option><?php } ?></select></div>
            <div class="col-md-4 mb-3"><label class="form-label">Full name *</label><input class="form-control" name="name" maxlength="100" required></div>
            <div class="col-md-4 mb-3"><label class="form-label">Login email *</label><input class="form-control" name="email" type="email" maxlength="100" required></div>
            <div class="col-md-4 mb-3"><label class="form-label">Temporary password *</label><input class="form-control" name="password" type="password" minlength="12" required autocomplete="new-password"></div>
            <div class="col-md-6 mb-3"><label class="form-label">Role</label><select class="form-select" name="role"><?php foreach (['Administrator','Manager','Production','Quality','Dispatch','Accounts','User'] as $role) { ?><option value="<?= $role ?>" <?= $role === 'User' ? 'selected' : '' ?>><?= $role ?></option><?php } ?></select></div>
        </div><button class="btn btn-primary" type="submit">Create user</button> <a class="btn btn-light" href="index.php">Cancel</a></div></div>
    </form>
</div></div></div>
<script>
(function () {
    var companySelect = document.getElementById('company_id');
    var templateSelect = document.getElementById('template_version_id');
    if (!companySelect || !templateSelect) return;
    var options = Array.prototype.slice.call(templateSelect.options);

    function filterTemplates() {
        var companyId = companySelect.value;
        templateSelect.value = '';
        templateSelect.disabled = !companyId;
        options.forEach(function (option) {
            if (!option.value) return;
            var allowed = option.getAttribute('data-company-id') === companyId;
            option.hidden = !allowed;
            option.disabled = !allowed;
        });
        var placeholder = templateSelect.options[0];
        placeholder.textContent = companyId ? 'Choose template' : 'Choose company first';
    }

    companySelect.addEventListener('change', filterTemplates);
    filterTemplates();
})();
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
