<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/product_master.php';
erpRequireCompanyUser($conn);
erpRequire('products.view');
$companyId = erpCurrentCompanyId();
$ready = productMasterSchemaReady($conn);
$fields = $ready ? productMasterFieldDefinitions($conn) : [];
$search = trim((string)($_GET['search'] ?? ''));
$stmt = $conn->prepare('SELECT id, product_code, product_name, default_rate' . ($ready ? ', description, custom_fields_json' : '') . " FROM products WHERE company_id = ? AND (? = '' OR product_name LIKE CONCAT('%', ?, '%') OR product_code LIKE CONCAT('%', ?, '%')) ORDER BY id DESC");
$stmt->bind_param('isss', $companyId, $search, $search, $search);
$stmt->execute();
$result = $stmt->get_result();
$products = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<div class="content-page"><div class="content"><div class="container-fluid">
    <div class="py-3 d-flex justify-content-between align-items-center">
        <div><h4 class="mb-0">Product Master</h4><small class="text-muted">Product name, description and company product details</small></div>
        <?php if (erpCan('products.add') && $ready): ?><a class="btn btn-primary" href="add.php"><i class="ri-add-line"></i> Add Product</a><?php endif; ?>
    </div>
    <?php if (!$ready): ?><div class="alert alert-warning">Product Master upgrade is pending. Run the read-only preflight for <code>tools/migrate_product_master.php</code> before adding or editing products. Existing products are still preserved.</div><?php endif; ?>
    <?php if (($_GET['msg'] ?? '') === 'added'): ?><div class="alert alert-success">Product saved.</div><?php elseif (($_GET['msg'] ?? '') === 'updated'): ?><div class="alert alert-success">Product updated.</div><?php elseif (($_GET['msg'] ?? '') === 'error'): ?><div class="alert alert-danger">Could not save the product. Check required fields and try again.</div><?php endif; ?>
    <div class="card"><div class="card-body">
        <form method="get" class="row g-2 mb-3"><div class="col-md-5"><input class="form-control" name="search" value="<?= $e($search) ?>" placeholder="Search product name or code"></div><div class="col-auto"><button class="btn btn-outline-primary">Search</button></div><?php if ($search !== ''): ?><div class="col-auto"><a class="btn btn-outline-secondary" href="index.php">Clear</a></div><?php endif; ?></form>
        <div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Product Name</th><th>Description</th><?php foreach ($fields as $field): ?><th><?= $e($field['label']) ?></th><?php endforeach; ?><th>Default Rate</th><?php if (erpCan('products.edit')): ?><th>Action</th><?php endif; ?></tr></thead><tbody>
        <?php foreach ($products as $product): $values = $ready ? productMasterDecodeValues($product['custom_fields_json'] ?? null) : []; ?>
            <tr><td><strong><?= $e($product['product_name']) ?></strong><div class="small text-muted"><?= $e($product['product_code']) ?></div></td><td><?= nl2br($e($ready ? ($product['description'] ?? '') : '')) ?></td><?php foreach ($fields as $field): ?><td><?= $e($values[$field['field_key']] ?? '') ?></td><?php endforeach; ?><td><?= $e($product['default_rate'] ?? '') ?></td><?php if (erpCan('products.edit')): ?><td><a class="btn btn-sm btn-outline-primary" href="edit.php?id=<?= (int)$product['id'] ?>">Edit</a></td><?php endif; ?></tr>
        <?php endforeach; ?>
        <?php if (!$products): ?><tr><td colspan="<?= 3 + count($fields) + (erpCan('products.edit') ? 1 : 0) ?>" class="text-center text-muted py-4">No products found.</td></tr><?php endif; ?>
        </tbody></table></div>
        <div class="small text-muted">Existing product codes and legacy product details are retained in the database. This screen does not delete product records.</div>
    </div></div>
</div></div></div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
