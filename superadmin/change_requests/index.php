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
    "SELECT r.id, r.company_id, r.applied_template_version_id, r.title, r.details, r.status, r.admin_note, r.created_at,
            c.company_name, u.name AS requester_name, t.template_name AS applied_template_name,
            v.version_number AS applied_version_number
     FROM template_change_requests r
     JOIN companies c ON c.id = r.company_id
     LEFT JOIN users u ON u.id = r.requested_by AND u.company_id = r.company_id
     LEFT JOIN quotation_template_versions v ON v.id = r.applied_template_version_id
     LEFT JOIN quotation_templates t ON t.id = v.template_id
     ORDER BY FIELD(r.status, 'Pending','In Review','Approved','Rejected','Completed'),
              r.created_at ASC, r.id ASC
     LIMIT 300"
);
$stmt->execute();
$requests = $stmt->get_result();
$templateResult = $conn->query(
    "SELECT t.company_id, t.template_name, v.id AS version_id, v.version_number
     FROM quotation_templates t
     JOIN quotation_template_versions v ON v.template_id = t.id
     WHERE t.is_active = 1
       AND v.version_number = (SELECT MAX(v2.version_number) FROM quotation_template_versions v2 WHERE v2.template_id = t.id)
     ORDER BY t.template_name"
);
$templatesByCompany = [];
while ($templateVersion = $templateResult->fetch_assoc()) {
    $templatesByCompany[(int)$templateVersion['company_id']][] = $templateVersion;
}
function adminRequestEscape($value): string {
    return htmlspecialchars((string)($value ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/superadmin_sidebar.php';
?>
<div class="content-page"><div class="content"><div class="container-fluid">
    <div class="page-title-box"><h4 class="page-title">Template Change Requests</h4></div>
    <?php if ($requests->num_rows === 0) { ?>
        <div class="card"><div class="card-body text-center py-5">
            <h5 class="mb-2">No template change requests yet</h5>
            <p class="text-muted mb-0">Requests submitted by company users will appear here for review.</p>
        </div></div>
    <?php } else { while ($request = $requests->fetch_assoc()) { ?>
        <div class="card"><div class="card-body">
            <div class="d-flex justify-content-between"><div><h5><?= adminRequestEscape($request['title']) ?></h5><div class="text-muted"><?= adminRequestEscape($request['company_name']) ?> · <?= adminRequestEscape($request['requester_name']) ?> · <?= adminRequestEscape(date('d-m-Y H:i', strtotime($request['created_at']))) ?></div></div><span class="badge bg-secondary"><?= adminRequestEscape($request['status']) ?></span></div>
            <p class="mt-3"><?= nl2br(adminRequestEscape($request['details'])) ?></p>
            <?php if (!empty($request['applied_template_version_id'])) { ?><div class="small text-success mb-2">Applied: <?= adminRequestEscape($request['applied_template_name']) ?> · v<?= (int)$request['applied_version_number'] ?></div><?php } ?>
            <form method="post" action="update.php" class="row g-2 request-review-form">
                <input type="hidden" name="csrf_token" value="<?= adminRequestEscape($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="id" value="<?= (int)$request['id'] ?>">
                <div class="col-md-2"><select class="form-select request-status" name="status"><?php foreach (['Pending','In Review','Approved','Rejected','Completed'] as $status) { ?><option value="<?= $status ?>" <?= $request['status'] === $status ? 'selected' : '' ?>><?= $status ?></option><?php } ?></select></div>
                <div class="col-md-4"><select class="form-select request-applied-version" name="applied_template_version_id"><option value="">Choose company template version</option><?php foreach (($templatesByCompany[(int)$request['company_id']] ?? []) as $templateVersion) { ?><option value="<?= (int)$templateVersion['version_id'] ?>" <?= (int)($request['applied_template_version_id'] ?? 0) === (int)$templateVersion['version_id'] ? 'selected' : '' ?>><?= adminRequestEscape($templateVersion['template_name']) ?> · v<?= (int)$templateVersion['version_number'] ?></option><?php } ?></select></div>
                <div class="col-md-4"><input class="form-control" name="admin_note" maxlength="2000" value="<?= adminRequestEscape($request['admin_note']) ?>" placeholder="Note visible to the company"></div>
                <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Update</button></div>
                <div class="col-12"><small class="text-muted">Create a template version first. Approving applies it to users assigned to that template; previous quotations keep their saved version.</small></div>
            </form>
        </div></div>
    <?php } } ?>
</div></div></div>
<script>
document.querySelectorAll('.request-review-form').forEach(function (form) {
    var status = form.querySelector('.request-status');
    var version = form.querySelector('.request-applied-version');
    function syncRequired() {
        version.required = status.value === 'Approved';
    }
    status.addEventListener('change', syncRequired);
    syncRequired();
});
</script>
<?php $stmt->close(); include __DIR__ . '/../../includes/footer.php'; ?>
