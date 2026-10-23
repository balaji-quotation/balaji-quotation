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
        <div><h4 class="mb-0">Product Master</h4><small class="text-muted">Product name, specifications and company product details</small></div>
        <?php if (erpCan('products.add') && $ready): ?><a class="btn btn-primary" href="add.php"><i class="ri-add-line"></i> Add Product</a><?php endif; ?>
    </div>
    <?php if (!$ready): ?><div class="alert alert-warning">Product Master upgrade is pending. Run the read-only preflight for <code>tools/migrate_product_master.php</code> before adding or editing products. Existing products are still preserved.</div><?php endif; ?>
    <?php if (($_GET['msg'] ?? '') === 'added'): ?><div class="alert alert-success">Product saved.</div><?php elseif (($_GET['msg'] ?? '') === 'updated'): ?><div class="alert alert-success">Product updated.</div><?php elseif (($_GET['msg'] ?? '') === 'deleted'): ?><div class="alert alert-success">Product deleted.</div><?php elseif (($_GET['msg'] ?? '') === 'quotation_linked'): ?><div class="alert alert-warning">This product is used by <?= (int)($_GET['count'] ?? 0) ?> quotation row(s), so it was kept to preserve quotation history.</div><?php elseif (($_GET['msg'] ?? '') === 'linked' || ($_GET['msg'] ?? '') === 'vendor_po_linked' || ($_GET['msg'] ?? '') === 'client_po_linked'): ?><div class="alert alert-warning">This product is linked to existing records and cannot be deleted.</div><?php elseif (($_GET['msg'] ?? '') === 'error'): ?><div class="alert alert-danger">Could not save the product. Check required fields and try again.</div><?php elseif (($_GET['msg'] ?? '') === 'db_error'): ?><div class="alert alert-danger">The product could not be changed because a database check failed. Please retry or contact support.</div><?php elseif (($_GET['msg'] ?? '') === 'delete_failed'): ?><div class="alert alert-danger">The product could not be deleted. Please retry.</div><?php elseif (($_GET['msg'] ?? '') === 'invalid_request'): ?><div class="alert alert-warning">The delete request was not valid. Refresh the page and try again.</div><?php elseif (($_GET['msg'] ?? '') === 'notfound'): ?><div class="alert alert-warning">This product was not found in your company.</div><?php endif; ?>
    <div class="card"><div class="card-body">
        <form method="get" class="row g-2 mb-3"><div class="col-md-5"><input class="form-control" name="search" value="<?= $e($search) ?>" placeholder="Search product name or code"></div><div class="col-auto"><button class="btn btn-outline-primary">Search</button></div><?php if ($search !== ''): ?><div class="col-auto"><a class="btn btn-outline-secondary" href="index.php">Clear</a></div><?php endif; ?></form>
        <div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Product Name</th><th>Specifications</th><?php foreach ($fields as $field): ?><th><?= $e($field['label']) ?></th><?php endforeach; ?><th>Default Rate</th><?php if (erpCan('products.edit') || erpCan('products.delete')): ?><th>Actions</th><?php endif; ?></tr></thead><tbody>
        <?php foreach ($products as $product): $values = $ready ? productMasterDecodeValues($product['custom_fields_json'] ?? null) : []; ?>
            <tr><td><strong><?= $e($product['product_name']) ?></strong><div class="small text-muted"><?= $e($product['product_code']) ?></div></td><td><?= nl2br($e($ready ? ($product['description'] ?? '') : '')) ?></td><?php foreach ($fields as $field): ?><td><?= $e($values[$field['field_key']] ?? '') ?></td><?php endforeach; ?><td><?= $e($product['default_rate'] ?? '') ?></td><?php if (erpCan('products.edit') || erpCan('products.delete')): ?><td class="text-nowrap"><?php if (erpCan('products.edit')): ?><a class="btn btn-sm btn-outline-primary me-1" href="edit.php?id=<?= (int)$product['id'] ?>">Edit</a><?php endif; ?><?php if (erpCan('products.delete')): ?><form method="post" action="delete.php" class="d-inline" onsubmit="return confirm('Delete this product? It will be kept if linked to quotations or other records.');"><input type="hidden" name="csrf_token" value="<?= $e($_SESSION['csrf_token']) ?>"><input type="hidden" name="id" value="<?= (int)$product['id'] ?>"><button type="submit" class="btn btn-sm btn-outline-danger">Delete</button></form><?php endif; ?></td><?php endif; ?></tr>
        <?php endforeach; ?>
        <?php if (!$products): ?><tr><td colspan="<?= 3 + count($fields) + ((erpCan('products.edit') || erpCan('products.delete')) ? 1 : 0) ?>" class="text-center text-muted py-4">No products found.</td></tr><?php endif; ?>
        </tbody></table></div>
        <div class="small text-muted">Products referenced by quotations or other records are protected from deletion to preserve history.</div>
    </div></div>
</div></div></div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
