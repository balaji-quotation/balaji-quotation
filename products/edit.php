<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/product_master.php';
erpRequireCompanyUser($conn);
erpRequire('products.edit');
if (!productMasterSchemaReady($conn)) { http_response_code(503); exit('Product Master database upgrade is required.'); }
$companyId = erpCurrentCompanyId();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$stmt = $conn->prepare('SELECT id, product_code, product_name, default_rate, description, custom_fields_json FROM products WHERE id = ? AND company_id = ? LIMIT 1');
$stmt->bind_param('ii', $id, $companyId);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$product) { http_response_code(404); exit('Product not found.'); }
$fields = productMasterFieldDefinitions($conn);
$values = productMasterDecodeValues($product['custom_fields_json'] ?? null);
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<div class="content-page"><div class="content"><div class="container-fluid">
    <div class="py-3 d-flex justify-content-between align-items-center"><div><h4 class="mb-0">Edit Product</h4><small class="text-muted">Product code: <?= $e($product['product_code']) ?></small></div><a href="index.php" class="btn btn-outline-secondary">Back</a></div>
    <?php if (($_GET['msg'] ?? '') === 'error'): ?><div class="alert alert-danger">Please check required fields and try again.</div><?php endif; ?>
    <form method="post" action="update.php" class="card"><div class="card-body">
        <input type="hidden" name="csrf_token" value="<?= $e($_SESSION['csrf_token']) ?>"><input type="hidden" name="id" value="<?= (int)$product['id'] ?>">
        <div class="row"><div class="col-md-6 mb-3"><label class="form-label">Product Name <span class="text-danger">*</span></label><input class="form-control" name="product_name" maxlength="255" value="<?= $e($product['product_name']) ?>" required></div>
        <div class="col-md-6 mb-3"><label class="form-label">Default Rate <small class="text-muted">(optional)</small></label><input class="form-control" type="number" min="0" step="0.0001" name="default_rate" value="<?= $e($product['default_rate']) ?>"></div>
        <div class="col-12 mb-3"><label class="form-label">Specifications</label><textarea class="form-control" name="description" rows="3" maxlength="5000"><?= $e($product['description']) ?></textarea></div>
        <?php foreach ($fields as $field): $key = $e($field['field_key']); $value = (string)($values[$field['field_key']] ?? ''); ?>
            <div class="col-md-6 mb-3"><label class="form-label"><?= $e($field['label']) ?><?= $field['is_required'] ? ' *' : '' ?></label>
            <?php if ($field['field_type'] === 'textarea'): ?><textarea class="form-control" name="custom_fields[<?= $key ?>]" rows="3" <?= $field['is_required'] ? 'required' : '' ?>><?= $e($value) ?></textarea>
            <?php elseif ($field['field_type'] === 'dropdown'): ?><select class="form-select" name="custom_fields[<?= $key ?>]" <?= $field['is_required'] ? 'required' : '' ?>><option value="">Select</option><?php foreach ($field['options'] as $option): ?><option value="<?= $e($option) ?>" <?= $value === $option ? 'selected' : '' ?>><?= $e($option) ?></option><?php endforeach; ?></select>
            <?php elseif ($field['field_type'] === 'checkbox'): ?><div class="form-check"><input class="form-check-input" type="checkbox" value="1" name="custom_fields[<?= $key ?>]" <?= $value === '1' ? 'checked' : '' ?>><label class="form-check-label">Yes</label></div>
            <?php else: ?><input class="form-control" type="<?= $field['field_type'] === 'number' || $field['field_type'] === 'date' ? $field['field_type'] : 'text' ?>" name="custom_fields[<?= $key ?>]" value="<?= $e($value) ?>" <?= $field['field_type'] === 'number' ? 'step="any"' : '' ?> <?= $field['is_required'] ? 'required' : '' ?>><?php endif; ?>
            </div>
        <?php endforeach; ?></div>
    </div><div class="card-footer d-flex gap-2"><button class="btn btn-primary">Save Changes</button><a href="index.php" class="btn btn-light">Cancel</a></div></form>
</div></div></div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
