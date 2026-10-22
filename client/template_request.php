<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/permissions.php';
erpRequireCompanyUser($conn);
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$error = $_SESSION['template_request_error'] ?? '';
unset($_SESSION['template_request_error']);
$stmt = $conn->prepare(
    'SELECT r.id, r.title, r.status, r.created_at, r.admin_note, t.template_name AS applied_template_name, v.version_number AS applied_version_number
     FROM template_change_requests r
     LEFT JOIN quotation_template_versions v ON v.id = r.applied_template_version_id
     LEFT JOIN quotation_templates t ON t.id = v.template_id
     WHERE r.company_id = ? ORDER BY r.created_at DESC, r.id DESC LIMIT 100'
);
$companyId = erpCurrentCompanyId();
$stmt->bind_param('i', $companyId);
$stmt->execute();
$requests = $stmt->get_result();
function templateRequestEscape($value): string {
    return htmlspecialchars((string)($value ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<div class="content-page"><div class="content"><div class="container-fluid">
    <div class="page-title-box"><h4 class="page-title">Template Change Request</h4></div>
    <?php if ($error !== '') { ?><div class="alert alert-danger"><?= templateRequestEscape($error) ?></div><?php } ?>
    <div class="card"><div class="card-body">
        <form method="post" action="save_template_request.php">
            <input type="hidden" name="csrf_token" value="<?= templateRequestEscape($_SESSION['csrf_token']) ?>">
            <div class="mb-3"><label class="form-label">Request title *</label><input class="form-control" name="title" maxlength="200" required></div>
            <div class="mb-3"><label class="form-label">Describe the template change *</label><textarea class="form-control" name="details" rows="4" maxlength="5000" required></textarea></div>
            <button class="btn btn-primary" type="submit">Submit request</button>
        </form>
    </div></div>
    <div class="card"><div class="card-body table-responsive"><h5>Previous requests</h5>
        <table class="table"><thead><tr><th>Title</th><th>Status</th><th>Applied version</th><th>Submitted</th><th>Admin note</th></tr></thead><tbody>
        <?php if ($requests->num_rows === 0) { ?>
            <tr><td colspan="5" class="text-center text-muted py-4">No change requests submitted yet.</td></tr>
        <?php } else { while ($request = $requests->fetch_assoc()) { ?>
            <tr><td><?= templateRequestEscape($request['title']) ?></td><td><?= templateRequestEscape($request['status']) ?></td><td><?= !empty($request['applied_template_name']) ? templateRequestEscape($request['applied_template_name']) . ' · v' . (int)$request['applied_version_number'] : '—' ?></td><td><?= templateRequestEscape(date('d-m-Y', strtotime($request['created_at']))) ?></td><td><?= templateRequestEscape($request['admin_note']) ?></td></tr>
        <?php } } ?>
        </tbody></table>
    </div></div>
</div></div></div>
<?php
$stmt->close();
include __DIR__ . '/../includes/footer.php';
?>
