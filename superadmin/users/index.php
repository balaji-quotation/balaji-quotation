<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}
require_once __DIR__ . '/../../includes/permissions.php';
erpRequire('superadmin.users');
require_once __DIR__ . '/../../includes/db.php';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/superadmin_sidebar.php';

$stmt = $conn->prepare(
    "SELECT u.id, u.name, u.email, u.role, u.status, u.created_at,
            c.company_name
     FROM users u
     LEFT JOIN companies c ON c.id = u.company_id
     ORDER BY u.created_at DESC, u.id DESC"
);
$stmt->execute();
$users = $stmt->get_result();
function adminUserEscape($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<div class="content-page"><div class="content"><div class="container-fluid">
    <div class="page-title-box"><h4 class="page-title">Manage Users</h4></div>
    <div class="card"><div class="card-body table-responsive">
        <table class="table table-hover align-middle">
            <thead><tr><th>Name</th><th>Email</th><th>Company</th><th>Role</th><th>Status</th><th>Created</th></tr></thead>
            <tbody>
            <?php while ($user = $users->fetch_assoc()) { ?>
                <tr>
                    <td><?= adminUserEscape($user['name']) ?></td>
                    <td><?= adminUserEscape($user['email']) ?></td>
                    <td><?= adminUserEscape($user['company_name'] ?? 'Balaji Webtech') ?></td>
                    <td><?= adminUserEscape($user['role']) ?></td>
                    <td><?= adminUserEscape($user['status']) ?></td>
                    <td><?= adminUserEscape(date('d-m-Y', strtotime($user['created_at']))) ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div></div>
</div></div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
