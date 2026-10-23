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
    'SELECT company_name, contact_person, email, mobile, alternate_mobile, gstin, billing_address, city, state, pincode, status, logo_path, subscription_plan, trial_start_date, trial_end_date, paid_start_date, paid_end_date
     FROM companies WHERE id = ? LIMIT 1'
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
function profileAccessState(array $company, string $today): string {
    if (($company['status'] ?? '') !== 'Active') {
        return 'Company ' . (string)$company['status'];
    }
    if (!empty($company['paid_start_date']) && !empty($company['paid_end_date'])
        && $today >= $company['paid_start_date'] && $today <= $company['paid_end_date']) {
        return 'Paid';
    }
    if (!empty($company['trial_start_date']) && !empty($company['trial_end_date'])
        && $today >= $company['trial_start_date'] && $today <= $company['trial_end_date']) {
        return 'Trial';
    }
    return 'Read Only';
}
$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
$plan = (string)($company['subscription_plan'] ?? '');
$userLimit = $plan === 'Premium' ? '2 users' : ($plan === 'Business' ? '1 user' : '—');
$accessState = profileAccessState($company, $today);
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<div class="content-page"><div class="content"><div class="container-fluid">
    <div class="page-title-box"><h4 class="page-title">Company Profile</h4><p class="text-muted">Company details and subscription are managed by Balaji Webtech support.</p></div>
    <div class="card mb-3"><div class="card-header"><h5 class="mb-0">Company details</h5></div><div class="card-body"><dl class="row mb-0">
        <?php if (!empty($company['logo_path'])) { ?><dt class="col-sm-3">Logo</dt><dd class="col-sm-9"><img src="<?= profileEscape($company['logo_path']) ?>" alt="<?= profileEscape($company['company_name']) ?> logo" style="max-width:180px;max-height:90px;object-fit:contain"></dd><?php } ?>
        <dt class="col-sm-3">Company name</dt><dd class="col-sm-9"><?= profileEscape($company['company_name']) ?></dd>
        <dt class="col-sm-3">Contact person</dt><dd class="col-sm-9"><?= profileEscape($company['contact_person']) ?></dd>
        <dt class="col-sm-3">Email</dt><dd class="col-sm-9"><?= profileEscape($company['email']) ?></dd>
        <dt class="col-sm-3">Mobile</dt><dd class="col-sm-9"><?= profileEscape($company['mobile']) ?></dd>
        <dt class="col-sm-3">Alternate mobile</dt><dd class="col-sm-9"><?= profileEscape($company['alternate_mobile']) ?></dd>
        <dt class="col-sm-3">GSTIN</dt><dd class="col-sm-9"><?= profileEscape($company['gstin']) ?></dd>
        <dt class="col-sm-3">Billing address</dt><dd class="col-sm-9"><?= nl2br(profileEscape($company['billing_address'])) ?></dd>
        <dt class="col-sm-3">City / State / Pincode</dt><dd class="col-sm-9"><?= profileEscape(trim(($company['city'] ?? '') . ', ' . ($company['state'] ?? '') . ' ' . ($company['pincode'] ?? ''), ', ')) ?></dd>
        <dt class="col-sm-3">Company status</dt><dd class="col-sm-9"><?= profileEscape($company['status']) ?></dd>
    </dl></div></div>
    <div class="card"><div class="card-header"><h5 class="mb-0">Subscription</h5></div><div class="card-body"><dl class="row mb-0">
        <dt class="col-sm-3">Plan</dt><dd class="col-sm-9"><?= profileEscape($plan !== '' ? $plan : null) ?><?= $plan !== '' ? ' · ' . profileEscape($userLimit) : '' ?></dd>
        <dt class="col-sm-3">Trial period</dt><dd class="col-sm-9"><?= profileEscape($company['trial_start_date']) ?> → <?= profileEscape($company['trial_end_date']) ?></dd>
        <dt class="col-sm-3">Paid subscription period</dt><dd class="col-sm-9"><?= profileEscape($company['paid_start_date']) ?> → <?= profileEscape($company['paid_end_date']) ?></dd>
        <dt class="col-sm-3">Current access</dt><dd class="col-sm-9"><span class="badge bg-<?= $accessState === 'Read Only' ? 'warning text-dark' : ($accessState === 'Paid' || $accessState === 'Trial' ? 'success' : 'secondary') ?>"><?= profileEscape($accessState) ?></span></dd>
    </dl></div></div>
</div></div></div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
