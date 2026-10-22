<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: ../../login.php'); exit; }
require_once __DIR__ . '/../../includes/permissions.php';
erpRequire('superadmin.templates');
require_once __DIR__ . '/../../includes/db.php';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/superadmin_sidebar.php';
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$result = $conn->query(
    "SELECT t.id, c.company_name, t.template_name, t.description, t.is_active, t.updated_at,
            COUNT(v.id) AS version_count, MAX(v.version_number) AS current_version
     FROM quotation_templates t
     JOIN companies c ON c.id = t.company_id
     LEFT JOIN quotation_template_versions v ON v.template_id = t.id
     GROUP BY t.id, c.company_name
     ORDER BY c.company_name, t.template_name"
);
function templateEscape($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?>
<div class="content-page"><div class="content"><div class="container-fluid">
    <div class="page-title-box d-flex justify-content-between align-items-center flex-wrap gap-2"><div><h4 class="page-title">Quotation Templates</h4><p class="text-muted">Templates are versioned. Existing quotations keep their saved version.</p></div><div class="d-flex gap-2"><a class="btn btn-outline-primary" href="../product_fields/index.php">Product Master Fields</a><a class="btn btn-primary" href="edit.php">Create template</a></div></div>
    <?php if (isset($_GET['saved'])) { ?><div class="alert alert-success">Template version saved.</div><?php } ?>
    <div class="card"><div class="card-body table-responsive"><table class="table table-striped align-middle">
        <thead><tr><th>Company</th><th>Name</th><th>Current version</th><th>Versions</th><th>Status</th><th>Updated</th><th></th></tr></thead><tbody>
        <?php while ($row = $result->fetch_assoc()) { ?>
            <tr><td><?= templateEscape($row['company_name']) ?></td><td><?= templateEscape($row['template_name']) ?></td><td>v<?= (int)$row['current_version'] ?></td><td><?= (int)$row['version_count'] ?></td><td><?= $row['is_active'] ? 'Active' : 'Inactive' ?></td><td><?= templateEscape($row['updated_at']) ?></td><td><a class="btn btn-sm btn-outline-primary" href="edit.php?id=<?= (int)$row['id'] ?>">Edit / new version</a></td></tr>
        <?php } ?>
        </tbody>
    </table></div></div>
</div></div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
