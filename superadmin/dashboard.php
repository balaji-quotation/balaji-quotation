<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}
require_once __DIR__ . '/../includes/permissions.php';
erpRequire('superadmin.dashboard');
require_once __DIR__ . '/../includes/db.php';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/superadmin_sidebar.php';

$companyCount = 0;
$userCount = 0;
$quotationCount = 0;
foreach ([
    'companies' => &$companyCount,
    'users' => &$userCount,
    'quotations' => &$quotationCount
] as $table => &$target) {
    $result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM `{$table}`");
    if ($result) {
        $row = mysqli_fetch_assoc($result);
        $target = (int)($row['total'] ?? 0);
    }
}
unset($target);
?>
<div class="content-page">
    <div class="content">
        <div class="container-fluid">
            <div class="row"><div class="col-12">
                <div class="page-title-box"><h4 class="page-title">Super Admin Dashboard</h4></div>
            </div></div>
            <div class="row">
                <div class="col-md-4"><div class="card"><div class="card-body"><h5>Companies</h5><h2><?= $companyCount ?></h2><a href="companies/index.php">Manage companies</a></div></div></div>
                <div class="col-md-4"><div class="card"><div class="card-body"><h5>Users</h5><h2><?= $userCount ?></h2><a href="users/index.php">Manage users</a></div></div></div>
                <div class="col-md-4"><div class="card"><div class="card-body"><h5>Quotations</h5><h2><?= $quotationCount ?></h2><span class="text-muted">Across all companies</span></div></div></div>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
