<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/permissions.php';
erpRequireCompanyUser($conn);
$companyId = erpCurrentCompanyId();
$stmt = $conn->prepare(
    'SELECT company_name, contact_person, email, mobile, alternate_mobile, gstin, billing_address, city, state, pincode, status FROM companies WHERE id = ? LIMIT 1'
);
$stmt->bind_param('i', $companyId);
$stmt->execute();
$company = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$company) {
    http_response_code(404);
    exit('Company profile not found.');
}
function profileEscape($value): string {
    return htmlspecialchars((string)($value ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<div class="content-page"><div class="content"><div class="container-fluid">
    <div class="page-title-box"><h4 class="page-title">Company Profile</h4><p class="text-muted">Company details are managed by Balaji Webtech support.</p></div>
    <div class="card"><div class="card-body"><dl class="row mb-0">
        <dt class="col-sm-3">Company name</dt><dd class="col-sm-9"><?= profileEscape($company['company_name']) ?></dd>
        <dt class="col-sm-3">Contact person</dt><dd class="col-sm-9"><?= profileEscape($company['contact_person']) ?></dd>
        <dt class="col-sm-3">Email</dt><dd class="col-sm-9"><?= profileEscape($company['email']) ?></dd>
        <dt class="col-sm-3">Mobile</dt><dd class="col-sm-9"><?= profileEscape($company['mobile']) ?></dd>
        <dt class="col-sm-3">Alternate mobile</dt><dd class="col-sm-9"><?= profileEscape($company['alternate_mobile']) ?></dd>
        <dt class="col-sm-3">GSTIN</dt><dd class="col-sm-9"><?= profileEscape($company['gstin']) ?></dd>
        <dt class="col-sm-3">Billing address</dt><dd class="col-sm-9"><?= nl2br(profileEscape($company['billing_address'])) ?></dd>
        <dt class="col-sm-3">City / State / Pincode</dt><dd class="col-sm-9"><?= profileEscape(trim(($company['city'] ?? '') . ', ' . ($company['state'] ?? '') . ' ' . ($company['pincode'] ?? ''), ', ')) ?></dd>
        <dt class="col-sm-3">Status</dt><dd class="col-sm-9"><?= profileEscape($company['status']) ?></dd>
    </dl></div></div>
</div></div></div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
