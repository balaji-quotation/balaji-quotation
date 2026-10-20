<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/permissions.php';
erpRequireCompanyUser($conn);
$userId = (int)$_SESSION['user_id'];
$stmt = $conn->prepare('SELECT name, email, role, status, created_at FROM users WHERE id = ? AND company_id = ? LIMIT 1');
$companyId = erpCurrentCompanyId();
$stmt->bind_param('ii', $userId, $companyId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$user) {
    http_response_code(404);
    exit('User profile not found.');
}
function myProfileEscape($value): string {
    return htmlspecialchars((string)($value ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<div class="content-page"><div class="content"><div class="container-fluid">
    <div class="page-title-box"><h4 class="page-title">My Profile</h4></div>
    <div class="card"><div class="card-body"><dl class="row mb-0">
        <dt class="col-sm-3">Name</dt><dd class="col-sm-9"><?= myProfileEscape($user['name']) ?></dd>
        <dt class="col-sm-3">Email</dt><dd class="col-sm-9"><?= myProfileEscape($user['email']) ?></dd>
        <dt class="col-sm-3">Role</dt><dd class="col-sm-9"><?= myProfileEscape($user['role']) ?></dd>
        <dt class="col-sm-3">Status</dt><dd class="col-sm-9"><?= myProfileEscape($user['status']) ?></dd>
    </dl></div></div>
</div></div></div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
