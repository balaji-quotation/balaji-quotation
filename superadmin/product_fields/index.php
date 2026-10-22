<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: ../../login.php'); exit; }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/product_master.php';
erpRequire('superadmin.product_fields');
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
if (!productMasterSchemaReady($conn)) { http_response_code(503); exit('Product Master upgrade is pending. Run the read-only preflight for tools/migrate_product_master.php first.'); }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) { http_response_code(400); exit('Invalid request token.'); }
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'toggle') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
        $active = (int)($_POST['active'] ?? 0) === 1 ? 1 : 0;
        $stmt = $conn->prepare('UPDATE product_master_fields SET is_active = ? WHERE id = ?');
        $stmt->bind_param('ii', $active, $id);
        $stmt->execute();
        $stmt->close();
        header('Location: index.php?msg=saved'); exit;
    }
    if ($action === 'add') {
        $label = trim((string)($_POST['label'] ?? ''));
        $type = (string)($_POST['field_type'] ?? 'text');
        $required = isset($_POST['is_required']) ? 1 : 0;
        $allowedTypes = ['text', 'textarea', 'number', 'date', 'dropdown', 'checkbox'];
        $options = [];
        if ($type === 'dropdown') {
            $options = array_values(array_unique(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)($_POST['options'] ?? '')) ?: []), static fn($v) => $v !== '')));
        }
        if ($label === '' || mb_strlen($label) > 100 || !in_array($type, $allowedTypes, true) || ($type === 'dropdown' && !$options)) {
            $error = 'Enter a field name and valid field type. Dropdown fields need at least one option.';
        } else {
            $base = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '_', $label), '_'));
            $key = substr($base ?: 'field', 0, 48) . '_' . bin2hex(random_bytes(4));
            $optionsJson = $options ? json_encode($options, JSON_UNESCAPED_UNICODE) : null;
            $orderResult = $conn->query('SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM product_master_fields');
            $order = (int)($orderResult->fetch_assoc()['n'] ?? 1);
            $stmt = $conn->prepare('INSERT INTO product_master_fields (field_key, label, field_type, options_json, is_required, is_active, sort_order) VALUES (?, ?, ?, ?, ?, 1, ?)');
            $stmt->bind_param('ssssii', $key, $label, $type, $optionsJson, $required, $order);
            $stmt->execute();
            $stmt->close();
            header('Location: index.php?msg=saved'); exit;
        }
    }
}
$fields = productMasterFieldDefinitions($conn, true);
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/superadmin_sidebar.php';
?>
<div class="content-page"><div class="content"><div class="container-fluid">
    <div class="py-3"><h4 class="mb-0">Product Master Fields</h4><small class="text-muted">Add extra fields shown in every company’s Product Master. Existing product information is retained.</small></div>
    <?php if ($error !== ''): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php elseif (($_GET['msg'] ?? '') === 'saved'): ?><div class="alert alert-success">Product field settings saved.</div><?php endif; ?>
    <div class="card"><div class="card-header"><strong>Add Extra Field</strong></div><div class="card-body">
        <form method="post"><input type="hidden" name="csrf_token" value="<?= $e($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="add">
            <div class="row align-items-end"><div class="col-md-4 mb-3"><label class="form-label">Field Name</label><input class="form-control" name="label" maxlength="100" required placeholder="e.g. Finish"></div>
            <div class="col-md-3 mb-3"><label class="form-label">Field Type</label><select class="form-select" name="field_type" id="fieldType"><option value="text">Short text</option><option value="textarea">Long text</option><option value="number">Number</option><option value="date">Date</option><option value="dropdown">Dropdown</option><option value="checkbox">Yes / No</option></select></div>
            <div class="col-md-4 mb-3" id="optionsWrap" style="display:none"><label class="form-label">Dropdown Options</label><textarea class="form-control" name="options" rows="2" placeholder="One option per line"></textarea></div>
            <div class="col-md-2 mb-3 form-check"><input class="form-check-input" type="checkbox" name="is_required" id="requiredField"><label class="form-check-label" for="requiredField">Required</label></div>
            <div class="col-md-2 mb-3"><button class="btn btn-primary">Add Field</button></div></div>
        </form>
    </div></div>
    <div class="card"><div class="card-header"><strong>Configured Fields</strong></div><div class="table-responsive"><table class="table mb-0"><thead><tr><th>Field</th><th>Type</th><th>Options</th><th>Required</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($fields as $field): ?><tr><td><?= $e($field['label']) ?></td><td><?= $e(ucfirst($field['field_type'])) ?></td><td><?= $e(implode(', ', $field['options'])) ?></td><td><?= $field['is_required'] ? 'Yes' : 'No' ?></td><td><?= $field['is_active'] ? 'Active' : 'Inactive' ?></td><td><form method="post"><input type="hidden" name="csrf_token" value="<?= $e($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$field['id'] ?>"><input type="hidden" name="active" value="<?= $field['is_active'] ? 0 : 1 ?>"><button class="btn btn-sm btn-outline-secondary"><?= $field['is_active'] ? 'Deactivate' : 'Activate' ?></button></form></td></tr><?php endforeach; ?>
    <?php if (!$fields): ?><tr><td colspan="6" class="text-center text-muted py-4">No extra fields yet.</td></tr><?php endif; ?>
    </tbody></table></div><div class="card-footer small text-muted">Deactivating hides a field from new forms but keeps all saved product values. Fields and their values are never deleted here.</div></div>
</div></div></div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var type = document.getElementById('fieldType');
    var wrap = document.getElementById('optionsWrap');
    function updateOptions() { wrap.style.display = type.value === 'dropdown' ? '' : 'none'; }
    type.addEventListener('change', updateOptions); updateOptions();
});
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
