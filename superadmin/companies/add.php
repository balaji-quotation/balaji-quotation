<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}
require_once __DIR__ . '/../../includes/permissions.php';
erpRequire('companies.add');
require_once __DIR__ . '/../../includes/db.php';
$templateResult = $conn->query(
    "SELECT t.template_name, v.id AS version_id, v.version_number
     FROM quotation_templates t
     JOIN quotation_template_versions v ON v.template_id = t.id
     WHERE t.is_active = 1
       AND v.version_number = (SELECT MAX(v2.version_number) FROM quotation_template_versions v2 WHERE v2.template_id = t.id)
     ORDER BY t.template_name"
);
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/superadmin_sidebar.php';
function companyAddEscape($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
$error = $_SESSION['company_error'] ?? '';
unset($_SESSION['company_error']);
?>
<div class="content-page"><div class="content"><div class="container-fluid">
    <div class="page-title-box"><h4 class="page-title">Add Company</h4></div>
    <?php if ($error !== '') { ?><div class="alert alert-danger"><?= companyAddEscape($error) ?></div><?php } ?>
    <form method="post" action="save.php" autocomplete="off" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= companyAddEscape($_SESSION['csrf_token']) ?>">
        <div class="card"><div class="card-body">
            <h5>Company details</h5>
            <div class="row">
                <div class="col-md-6 mb-3"><label class="form-label">Company name *</label><input class="form-control" name="company_name" required maxlength="255"></div>
                <div class="col-md-6 mb-3"><label class="form-label">Contact person</label><input class="form-control" name="contact_person" maxlength="150"></div>
                <div class="col-md-6 mb-3"><label class="form-label">Company email</label><input class="form-control" type="email" name="company_email" maxlength="150"></div>
                <div class="col-md-3 mb-3"><label class="form-label">Mobile</label><input class="form-control" name="mobile" maxlength="30"></div>
                <div class="col-md-3 mb-3"><label class="form-label">Alternate mobile</label><input class="form-control" name="alternate_mobile" maxlength="30"></div>
                <div class="col-md-4 mb-3"><label class="form-label">GSTIN</label><input class="form-control" name="gstin" maxlength="30"></div>
                <div class="col-md-8 mb-3"><label class="form-label">Billing address</label><input class="form-control" name="billing_address"></div>
                <div class="col-md-4 mb-3"><label class="form-label">City</label><input class="form-control" name="city" maxlength="100"></div>
                <div class="col-md-4 mb-3"><label class="form-label">State</label><input class="form-control" name="state" maxlength="100"></div>
                <div class="col-md-4 mb-3"><label class="form-label">Pincode</label><input class="form-control" name="pincode" maxlength="20"></div>
                <div class="col-md-6 mb-3"><label class="form-label">Company logo</label><input class="form-control" type="file" name="company_logo" accept="image/png,image/jpeg,image/webp"><small class="text-muted">PNG, JPEG or WebP; maximum 2 MB.</small></div>
                <div class="col-md-6 mb-3"><label class="form-label">Quotation number format *</label><input class="form-control" name="quotation_prefix" required maxlength="100" value="QTN/{FY}/{SEQ:4}"><small class="text-muted">Tokens: {FY}, {YYYY}, {YY}, {SEQ}, {SEQ:5}. Sequence continues within the company.</small></div>
            </div>
        </div></div>
        <div class="card"><div class="card-body">
            <h5>Primary company administrator</h5>
            <div class="row">
                <div class="col-md-4 mb-3"><label class="form-label">Full name *</label><input class="form-control" name="admin_name" required maxlength="100"></div>
                <div class="col-md-4 mb-3"><label class="form-label">Login email *</label><input class="form-control" type="email" name="admin_email" required maxlength="100"></div>
                <div class="col-md-4 mb-3"><label class="form-label">Temporary password *</label><input class="form-control" type="password" name="admin_password" required minlength="12" autocomplete="new-password"></div>
                <div class="col-md-6 mb-3"><label class="form-label">Assigned quotation template *</label><select class="form-select" name="admin_template_version_id" required>
                    <option value="">Choose template</option>
                    <?php while ($template = $templateResult->fetch_assoc()) { ?>
                        <option value="<?= (int)$template['version_id'] ?>"><?= companyAddEscape($template['template_name']) ?> · v<?= (int)$template['version_number'] ?></option>
                    <?php } ?>
                </select><small class="text-muted">A company-specific copy of this template version will be created and assigned to the first user.</small></div>
            </div>
            <p class="text-muted">Share the temporary password with the administrator using a secure channel. It is stored only as a password hash.</p>
            <button class="btn btn-primary" type="submit">Create company</button>
            <a class="btn btn-light" href="index.php">Cancel</a>
        </div></div>
    </form>
</div></div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
