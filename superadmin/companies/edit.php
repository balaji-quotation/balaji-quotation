<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}
require_once __DIR__ . '/../../includes/permissions.php';
erpRequire('companies.edit');
require_once __DIR__ . '/../../includes/db.php';
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    http_response_code(400);
    exit('Invalid company.');
}
$stmt = $conn->prepare(
    'SELECT c.id, c.company_name, c.contact_person, c.email, c.mobile, c.alternate_mobile, c.gstin, c.billing_address, c.city, c.state, c.pincode, c.status,
            (SELECT s.quotation_prefix FROM quotation_company_settings s WHERE s.company_id = c.id ORDER BY s.id ASC LIMIT 1) AS quotation_prefix
     FROM companies c WHERE c.id = ? LIMIT 1'
);
$stmt->bind_param('i', $id);
$stmt->execute();
$company = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$company) {
    http_response_code(404);
    exit('Company not found.');
}
$error = $_SESSION['company_error'] ?? '';
unset($_SESSION['company_error']);
function companyEditEscape($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/superadmin_sidebar.php';
?>
<div class="content-page"><div class="content"><div class="container-fluid">
    <div class="page-title-box"><h4 class="page-title">Edit Company</h4></div>
    <?php if ($error !== '') { ?><div class="alert alert-danger"><?= companyEditEscape($error) ?></div><?php } ?>
    <form method="post" action="update.php">
        <input type="hidden" name="csrf_token" value="<?= companyEditEscape($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="id" value="<?= (int)$company['id'] ?>">
        <div class="card"><div class="card-body"><div class="row">
            <div class="col-md-6 mb-3"><label class="form-label">Company name *</label><input class="form-control" name="company_name" required maxlength="255" value="<?= companyEditEscape($company['company_name']) ?>"></div>
            <div class="col-md-6 mb-3"><label class="form-label">Contact person</label><input class="form-control" name="contact_person" maxlength="150" value="<?= companyEditEscape($company['contact_person']) ?>"></div>
            <div class="col-md-6 mb-3"><label class="form-label">Company email</label><input class="form-control" type="email" name="company_email" maxlength="150" value="<?= companyEditEscape($company['email']) ?>"></div>
            <div class="col-md-3 mb-3"><label class="form-label">Mobile</label><input class="form-control" name="mobile" maxlength="30" value="<?= companyEditEscape($company['mobile']) ?>"></div>
            <div class="col-md-3 mb-3"><label class="form-label">Alternate mobile</label><input class="form-control" name="alternate_mobile" maxlength="30" value="<?= companyEditEscape($company['alternate_mobile']) ?>"></div>
            <div class="col-md-4 mb-3"><label class="form-label">GSTIN</label><input class="form-control" name="gstin" maxlength="30" value="<?= companyEditEscape($company['gstin']) ?>"></div>
            <div class="col-md-8 mb-3"><label class="form-label">Billing address</label><input class="form-control" name="billing_address" value="<?= companyEditEscape($company['billing_address']) ?>"></div>
            <div class="col-md-4 mb-3"><label class="form-label">City</label><input class="form-control" name="city" maxlength="100" value="<?= companyEditEscape($company['city']) ?>"></div>
            <div class="col-md-4 mb-3"><label class="form-label">State</label><input class="form-control" name="state" maxlength="100" value="<?= companyEditEscape($company['state']) ?>"></div>
            <div class="col-md-4 mb-3"><label class="form-label">Pincode</label><input class="form-control" name="pincode" maxlength="20" value="<?= companyEditEscape($company['pincode']) ?>"></div>
            <div class="col-md-6 mb-3"><label class="form-label">Quotation number format *</label><input class="form-control" name="quotation_prefix" required maxlength="100" value="<?= companyEditEscape(trim((string)($company['quotation_prefix'] ?? '')) !== '' ? $company['quotation_prefix'] : 'QTN/{FY}/{SEQ:4}') ?>"><small class="text-muted">Tokens: {FY}, {YYYY}, {YY}, {SEQ}, {SEQ:5}. Sequence continues within the company.</small></div>
            <div class="col-md-4 mb-3"><label class="form-label">Status</label><select class="form-select" name="status">
                <?php foreach (['Active', 'Inactive', 'Suspended'] as $status) { ?><option value="<?= $status ?>" <?= $company['status'] === $status ? 'selected' : '' ?>><?= $status ?></option><?php } ?>
            </select></div>
        </div>
        <button class="btn btn-primary" type="submit">Save changes</button>
        <a class="btn btn-light" href="index.php">Cancel</a>
        </div></div>
    </form>
</div></div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
