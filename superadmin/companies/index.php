<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}
require_once __DIR__ . '/../../includes/permissions.php';
erpRequire('companies.view');
require_once __DIR__ . '/../../includes/db.php';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/superadmin_sidebar.php';

$sql = "SELECT c.id, c.company_name, c.email, c.mobile, c.status, c.created_at,
               COUNT(DISTINCT u.id) AS user_count,
               COUNT(DISTINCT q.id) AS quotation_count
        FROM companies c
        LEFT JOIN users u ON u.company_id = c.id
        LEFT JOIN quotations q ON q.company_id = c.id
        GROUP BY c.id
        ORDER BY c.created_at DESC, c.id DESC";
$result = mysqli_query($conn, $sql);
if (!$result) {
    error_log('Super Admin company list query failed: ' . mysqli_error($conn));
    http_response_code(500);
    exit('Company list is temporarily unavailable.');
}
function companyEscape($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<div class="content-page">
    <div class="content"><div class="container-fluid">
        <div class="row"><div class="col-12">
            <div class="page-title-box d-flex justify-content-between align-items-center">
                <h4 class="page-title">Manage Companies</h4>
                <a class="btn btn-primary" href="add.php">Add Company</a>
            </div>
        </div></div>
        <div class="card"><div class="card-body table-responsive">
            <table class="table table-hover align-middle">
                <thead><tr><th>Company</th><th>Contact</th><th>Users</th><th>Quotations</th><th>Status</th><th>Created</th><th></th></tr></thead>
                <tbody>
                <?php while ($row = mysqli_fetch_assoc($result)) { ?>
                    <tr>
                        <td><?= companyEscape($row['company_name']) ?></td>
                        <td><?= companyEscape($row['email'] ?: $row['mobile'] ?: '—') ?></td>
                        <td><?= (int)$row['user_count'] ?></td>
                        <td><?= (int)$row['quotation_count'] ?></td>
                        <td><?= companyEscape($row['status']) ?></td>
                        <td><?= companyEscape(date('d-m-Y', strtotime($row['created_at']))) ?></td>
                        <td><a class="btn btn-sm btn-outline-primary" href="edit.php?id=<?= (int)$row['id'] ?>">Edit</a></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div></div>
    </div></div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
