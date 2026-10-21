<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: ../../login.php'); exit; }
require_once __DIR__ . '/../../includes/permissions.php';
erpRequire('superadmin.subscriptions');
require_once __DIR__ . '/../../includes/db.php';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/superadmin_sidebar.php';
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$sql = "
    SELECT c.id, c.company_name, c.status, c.subscription_plan,
           c.trial_start_date, c.trial_end_date, c.paid_start_date, c.paid_end_date,
           (SELECT COUNT(*) FROM users u WHERE u.company_id = c.id AND u.status = 'Active') AS active_users
    FROM companies c
    ORDER BY c.company_name
";
$result = $conn->query($sql);
$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
function subscriptionState(array $row, string $today): string {
    if (($row['status'] ?? '') !== 'Active') return 'Company ' . ($row['status'] ?? 'Inactive');
    if (!empty($row['paid_start_date']) && !empty($row['paid_end_date']) && $today >= $row['paid_start_date'] && $today <= $row['paid_end_date']) return 'Paid';
    if (!empty($row['trial_start_date']) && !empty($row['trial_end_date']) && $today >= $row['trial_start_date'] && $today <= $row['trial_end_date']) return 'Trial';
    return 'Read Only';
}
function subEscape($v): string { return htmlspecialchars((string)($v ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?>
<div class="content-page"><div class="content"><div class="container-fluid">
    <div class="page-title-box"><h4 class="page-title">Subscriptions</h4><p class="text-muted">Set the plan, trial window, and manually paid period for each company.</p></div>
    <?php if (isset($_GET['saved'])) { ?><div class="alert alert-success">Subscription settings saved.</div><?php } ?>
    <div class="card"><div class="card-body table-responsive"><table class="table table-striped align-middle">
        <thead><tr><th>Company</th><th>Plan</th><th>Active users</th><th>Trial</th><th>Paid period</th><th>Access</th><th></th></tr></thead>
        <tbody>
        <?php while ($row = $result->fetch_assoc()) { ?>
            <tr>
                <td><?= subEscape($row['company_name']) ?></td>
                <td><?= subEscape($row['subscription_plan']) ?></td>
                <td><?= (int)$row['active_users'] ?> / <?= ($row['subscription_plan'] === 'Premium' ? 2 : 1) ?></td>
                <td><?= subEscape($row['trial_start_date']) ?> → <?= subEscape($row['trial_end_date']) ?></td>
                <td><?= subEscape($row['paid_start_date']) ?> → <?= subEscape($row['paid_end_date']) ?></td>
                <td><span class="badge bg-<?= subscriptionState($row, $today) === 'Read Only' ? 'warning text-dark' : 'success' ?>"><?= subEscape(subscriptionState($row, $today)) ?></span></td>
                <td><a class="btn btn-sm btn-primary" href="edit.php?id=<?= (int)$row['id'] ?>">Manage</a></td>
            </tr>
        <?php } ?>
        </tbody>
    </table></div></div>
</div></div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
