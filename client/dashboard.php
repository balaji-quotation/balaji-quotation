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

$stats = ['quotations' => 0, 'drafts' => 0, 'customers' => 0];
$stmt = $conn->prepare(
    "SELECT COUNT(*) AS total, SUM(status = 'Draft') AS drafts
     FROM quotations WHERE company_id = ?"
);
$stmt->bind_param('i', $companyId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stats['quotations'] = (int)($row['total'] ?? 0);
$stats['drafts'] = (int)($row['drafts'] ?? 0);
$stmt->close();

$stmt = $conn->prepare('SELECT COUNT(*) AS total FROM clients WHERE company_id = ?');
$stmt->bind_param('i', $companyId);
$stmt->execute();
$stats['customers'] = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$stmt->close();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<div class="content-page"><div class="content"><div class="container-fluid">
    <div class="page-title-box"><h4 class="page-title">Company Dashboard</h4><p class="text-muted">Welcome, <?= htmlspecialchars((string)($_SESSION['user_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p></div>
    <div class="row">
        <div class="col-md-4"><div class="card"><div class="card-body"><h5>Quotations</h5><h2><?= $stats['quotations'] ?></h2><a href="../quotations/index.php">Open quotations</a></div></div></div>
        <div class="col-md-4"><div class="card"><div class="card-body"><h5>Drafts</h5><h2><?= $stats['drafts'] ?></h2></div></div></div>
        <div class="col-md-4"><div class="card"><div class="card-body"><h5>Customers</h5><h2><?= $stats['customers'] ?></h2><a href="../clients/index.php">Manage customers</a></div></div></div>
    </div>
</div></div></div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
