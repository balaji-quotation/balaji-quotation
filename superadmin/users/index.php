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
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$stmt = $conn->prepare(
    "SELECT u.id, u.name, u.email, u.role, u.status, u.created_at,
            c.company_name, t.template_name, v.version_number
     FROM users u
     JOIN companies c ON c.id = u.company_id
     LEFT JOIN quotation_template_versions v ON v.id = u.assigned_template_version_id
     LEFT JOIN quotation_templates t ON t.id = v.template_id
     WHERE u.company_id IS NOT NULL
     ORDER BY c.company_name, u.created_at DESC, u.id DESC"
);
$stmt->execute();
$users = $stmt->get_result();
function adminUserEscape($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<div class="content-page"><div class="content"><div class="container-fluid">
    <div class="page-title-box d-flex justify-content-between align-items-center"><h4 class="page-title">Manage Users</h4><a class="btn btn-primary" href="add.php">Create company user</a></div>
    <?php if (isset($_GET['created']) || isset($_GET['updated'])) { ?><div class="alert alert-success">User account saved.</div><?php } ?>
    <div class="card"><div class="card-body table-responsive">
        <table class="table table-hover align-middle">
            <thead><tr><th>Name</th><th>Email</th><th>Company</th><th>Role</th><th>Template</th><th>Status</th><th>Created</th><th></th></tr></thead>
            <tbody>
            <?php while ($user = $users->fetch_assoc()) { ?>
                <tr>
                    <td><?= adminUserEscape($user['name']) ?></td>
                    <td><?= adminUserEscape($user['email']) ?></td>
                    <td><?= adminUserEscape($user['company_name'] ?? 'Balaji Webtech') ?></td>
                    <td><?= adminUserEscape($user['role']) ?></td>
                    <td><?= adminUserEscape($user['template_name'] ?? 'Unassigned') ?><?= !empty($user['version_number']) ? ' · v' . (int)$user['version_number'] : '' ?></td>
                    <td><?= adminUserEscape($user['status']) ?></td>
                    <td><?= adminUserEscape(date('d-m-Y', strtotime($user['created_at']))) ?></td>
                    <td><a class="btn btn-sm btn-outline-primary" href="edit.php?id=<?= (int)$user['id'] ?>">Edit</a></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div></div>
</div></div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
