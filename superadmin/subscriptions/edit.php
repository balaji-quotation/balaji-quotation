<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: ../../login.php'); exit; }
require_once __DIR__ . '/../../includes/permissions.php';
erpRequire('superadmin.subscriptions');
require_once __DIR__ . '/../../includes/db.php';
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$id || $id < 1) { header('Location: index.php'); exit; }
$stmt = $conn->prepare('SELECT id, company_name, status, subscription_plan, trial_start_date, trial_end_date, paid_start_date, paid_end_date FROM companies WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $id);
$stmt->execute();
$company = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$company) { header('Location: index.php'); exit; }
$count = $conn->prepare("SELECT COUNT(*) AS total FROM users WHERE company_id = ? AND status = 'Active'");
$count->bind_param('i', $id); $count->execute();
$activeUsers = (int)($count->get_result()->fetch_assoc()['total'] ?? 0); $count->close();
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/superadmin_sidebar.php';
function subEditEscape($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$error = $_SESSION['subscription_error'] ?? ''; unset($_SESSION['subscription_error']);
?>
<div class="content-page"><div class="content"><div class="container-fluid">
    <div class="page-title-box"><h4 class="page-title">Manage Subscription: <?= subEditEscape($company['company_name']) ?></h4></div>
    <?php if ($error !== '') { ?><div class="alert alert-danger"><?= subEditEscape($error) ?></div><?php } ?>
    <div class="alert alert-info">Active users: <?= $activeUsers ?>. Business allows 1 active user; Premium allows 2. Existing quotations remain available after expiry in read-only mode.</div>
    <?php if ($activeUsers > 2) { ?>
        <div class="alert alert-warning" role="alert">
            <strong>Existing users are preserved:</strong> This company has <?= $activeUsers ?> active users. They will remain unchanged when you assign a plan. Business allows 1 active user and Premium allows 2; while the count is above the selected plan limit, new users cannot be created or reactivated. <a href="../users/index.php">Review company users</a>.
        </div>
    <?php } ?>
    <form method="post" action="update.php">
        <input type="hidden" name="csrf_token" value="<?= subEditEscape($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="company_id" value="<?= (int)$company['id'] ?>">
        <div class="card"><div class="card-body"><div class="row">
            <div class="col-md-4 mb-3"><label class="form-label">Plan *</label><select name="subscription_plan" class="form-select" required>
                <option value="">Choose plan</option>
                <?php foreach (['Business','Premium'] as $plan) { ?><option value="<?= $plan ?>" <?= $company['subscription_plan'] === $plan ? 'selected' : '' ?>><?= $plan ?> (<?= $plan === 'Premium' ? 2 : 1 ?> active users)</option><?php } ?>
            </select></div>
            <div class="col-md-4 mb-3"><label class="form-label">Trial start</label><input type="date" name="trial_start_date" class="form-control" value="<?= subEditEscape($company['trial_start_date']) ?>"></div>
            <div class="col-md-4 mb-3"><label class="form-label">Trial end</label><input type="date" name="trial_end_date" class="form-control" value="<?= subEditEscape($company['trial_end_date']) ?>"></div>
            <div class="col-md-4 mb-3"><label class="form-label">Paid start</label><input type="date" name="paid_start_date" class="form-control" value="<?= subEditEscape($company['paid_start_date']) ?>"></div>
            <div class="col-md-4 mb-3"><label class="form-label">Paid end</label><input type="date" name="paid_end_date" class="form-control" value="<?= subEditEscape($company['paid_end_date']) ?>"></div>
        </div><button class="btn btn-primary" type="submit">Save subscription</button> <a class="btn btn-light" href="index.php">Back</a></div></div>
    </form>
</div></div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
