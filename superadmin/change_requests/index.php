<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}
require_once __DIR__ . '/../../includes/permissions.php';
erpRequire('superadmin.change_requests');
require_once __DIR__ . '/../../includes/db.php';
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$stmt = $conn->prepare(
    "SELECT r.id, r.title, r.details, r.status, r.admin_note, r.created_at,
            c.company_name, u.name AS requester_name
     FROM template_change_requests r
     JOIN companies c ON c.id = r.company_id
     LEFT JOIN users u ON u.id = r.requested_by AND u.company_id = r.company_id
     ORDER BY FIELD(r.status, 'Pending','In Review','Approved','Rejected','Completed'),
              r.created_at ASC, r.id ASC
     LIMIT 300"
);
$stmt->execute();
$requests = $stmt->get_result();
function adminRequestEscape($value): string {
    return htmlspecialchars((string)($value ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/superadmin_sidebar.php';
?>
<div class="content-page"><div class="content"><div class="container-fluid">
    <div class="page-title-box"><h4 class="page-title">Template Change Requests</h4></div>
    <?php while ($request = $requests->fetch_assoc()) { ?>
        <div class="card"><div class="card-body">
            <div class="d-flex justify-content-between"><div><h5><?= adminRequestEscape($request['title']) ?></h5><div class="text-muted"><?= adminRequestEscape($request['company_name']) ?> · <?= adminRequestEscape($request['requester_name']) ?> · <?= adminRequestEscape(date('d-m-Y H:i', strtotime($request['created_at']))) ?></div></div><span class="badge bg-secondary"><?= adminRequestEscape($request['status']) ?></span></div>
            <p class="mt-3"><?= nl2br(adminRequestEscape($request['details'])) ?></p>
            <form method="post" action="update.php" class="row g-2">
                <input type="hidden" name="csrf_token" value="<?= adminRequestEscape($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="id" value="<?= (int)$request['id'] ?>">
                <div class="col-md-3"><select class="form-select" name="status"><?php foreach (['Pending','In Review','Approved','Rejected','Completed'] as $status) { ?><option value="<?= $status ?>" <?= $request['status'] === $status ? 'selected' : '' ?>><?= $status ?></option><?php } ?></select></div>
                <div class="col-md-7"><input class="form-control" name="admin_note" maxlength="2000" value="<?= adminRequestEscape($request['admin_note']) ?>" placeholder="Note visible to the company"></div>
                <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Update</button></div>
            </form>
        </div></div>
    <?php } ?>
</div></div></div>
<?php $stmt->close(); include __DIR__ . '/../../includes/footer.php'; ?>
