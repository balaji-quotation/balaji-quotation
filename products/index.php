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
<style>
.product-master-page .product-master-heading { color:#203d68; font-weight:700; }
.product-master-page .product-master-card { border:0; border-radius:14px; box-shadow:0 5px 22px rgba(31,45,61,.07); overflow:hidden; }
.product-master-page .product-master-toolbar { padding:18px 20px; background:#f8fafd; border-bottom:1px solid #e6ebf2; }
.product-master-page .product-master-table-wrap { width:100%; overflow:auto; }
.product-master-page .product-master-table { min-width:760px; margin:0; border-collapse:separate; border-spacing:0; }
.product-master-page .product-master-table thead th { position:sticky; top:0; z-index:2; padding:14px 13px; background:#203d68; color:#fff; border-color:#405985; font-size:12px; font-weight:700; white-space:nowrap; }
.product-master-page .product-master-table tbody td { padding:13px; vertical-align:middle; border-color:#e7ecf2; background:#fff; }
.product-master-page .product-master-table tbody tr:hover td { background:#f4f8fe; }
.product-master-page .product-name { color:#26374a; font-size:13px; font-weight:700; }
.product-master-page .product-code { display:inline-block; margin-top:4px; color:#718096; font-size:11px; }
.product-master-page .product-specifications { min-width:180px; max-width:340px; color:#526173; white-space:normal; line-height:1.5; }
.product-master-page .product-rate { color:#173b6d; font-weight:700; text-align:right; white-space:nowrap; }
.product-master-page .product-actions { min-width:130px; text-align:center; white-space:nowrap; }
.product-master-page .product-actions .btn { min-width:62px; border-radius:7px; font-weight:600; }
.product-master-page .product-empty { padding:42px 16px!important; color:#718096; text-align:center; }
@media(max-width:767.98px) {
  .product-master-page .product-master-toolbar { padding:14px; }
  .product-master-page .product-master-table { min-width:680px; }
  .product-master-page .product-master-table tbody td { padding:10px; }
}
</style>
<div class="content-page product-master-page"><div class="content"><div class="container-fluid">
    <div class="py-3 d-flex justify-content-between align-items-center">
        <div><h4 class="mb-0 product-master-heading">Product Master</h4><small class="text-muted">Product name, specifications and company product details</small></div>

    </div>
    <?php if (!$ready): ?><div class="alert alert-warning">Product Master upgrade is pending. Run the read-only preflight for <code>tools/migrate_product_master.php</code> before adding or editing products. Existing products are still preserved.</div><?php endif; ?>
    <?php if (($_GET['msg'] ?? '') === 'added'): ?><div class="alert alert-success">Product saved.</div><?php elseif (($_GET['msg'] ?? '') === 'updated'): ?><div class="alert alert-success">Product updated.</div><?php elseif (($_GET['msg'] ?? '') === 'deleted'): ?><div class="alert alert-success">Product deleted.</div><?php elseif (($_GET['msg'] ?? '') === 'quotation_linked'): ?><div class="alert alert-warning">This product is used by <?= (int)($_GET['count'] ?? 0) ?> quotation row(s), so it was kept to preserve quotation history.</div><?php elseif (($_GET['msg'] ?? '') === 'linked' || ($_GET['msg'] ?? '') === 'vendor_po_linked' || ($_GET['msg'] ?? '') === 'client_po_linked'): ?><div class="alert alert-warning">This product is linked to existing records and cannot be deleted.</div><?php elseif (($_GET['msg'] ?? '') === 'error'): ?><div class="alert alert-danger">Could not save the product. Check required fields and try again.</div><?php elseif (($_GET['msg'] ?? '') === 'db_error'): ?><div class="alert alert-danger">The product could not be changed because a database check failed. Please retry or contact support.</div><?php elseif (($_GET['msg'] ?? '') === 'delete_failed'): ?><div class="alert alert-danger">The product could not be deleted. Please retry.</div><?php elseif (($_GET['msg'] ?? '') === 'invalid_request'): ?><div class="alert alert-warning">The delete request was not valid. Refresh the page and try again.</div><?php elseif (($_GET['msg'] ?? '') === 'notfound'): ?><div class="alert alert-warning">This product was not found in your company.</div><?php endif; ?>
    <div class="card product-master-card"><div class="product-master-toolbar d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div><strong><?= count($products) ?></strong> <span class="text-muted">product(s)</span></div>
        <form method="get" class="row g-2 align-items-center mb-0"><div class="col"><input class="form-control" name="search" value="<?= $e($search) ?>" placeholder="Search product name or code"></div><div class="col-auto"><button class="btn btn-outline-primary"><i class="ri-search-line"></i> Search</button></div><?php if ($search !== ''): ?><div class="col-auto"><a class="btn btn-outline-secondary" href="index.php">Clear</a></div><?php endif; ?></form>
    </div><div class="card-body p-0"><div class="product-master-table-wrap"><table class="table table-hover align-middle product-master-table"><thead><tr><th>Product Name</th><th>Specifications</th><?php foreach ($fields as $field): ?><th><?= $e($field['label']) ?></th><?php endforeach; ?><th>Default Rate</th><?php if (erpCan('products.edit') || erpCan('products.delete')): ?><th>Actions</th><?php endif; ?></tr></thead><tbody>
        <?php foreach ($products as $product): $values = $ready ? productMasterDecodeValues($product['custom_fields_json'] ?? null) : []; ?>
            <tr><td><div class="product-name"><?= $e($product['product_name']) ?></div><span class="product-code"><?= $e($product['product_code']) ?></span></td><td><div class="product-specifications"><?= nl2br($e($ready ? ($product['description'] ?? '') : '')) ?></div></td><?php foreach ($fields as $field): ?><td><?= $e($values[$field['field_key']] ?? '') ?></td><?php endforeach; ?><td class="product-rate"><?= $e($product['default_rate'] ?? '') ?></td><?php if (erpCan('products.edit') || erpCan('products.delete')): ?><td class="product-actions"><?php if (erpCan('products.edit')): ?><a class="btn btn-sm btn-outline-primary me-1" href="edit.php?id=<?= (int)$product['id'] ?>">Edit</a><?php endif; ?><?php if (erpCan('products.delete')): ?><form method="post" action="delete.php" class="d-inline" onsubmit="return confirm('Delete this product? It will be kept if linked to quotations or other records.');"><input type="hidden" name="csrf_token" value="<?= $e($_SESSION['csrf_token']) ?>"><input type="hidden" name="id" value="<?= (int)$product['id'] ?>"><button type="submit" class="btn btn-sm btn-outline-danger">Delete</button></form><?php endif; ?></td><?php endif; ?></tr>
        <?php endforeach; ?>
        <?php if (!$products): ?><tr><td colspan="<?= 3 + count($fields) + ((erpCan('products.edit') || erpCan('products.delete')) ? 1 : 0) ?>" class="product-empty">No products found.</td></tr><?php endif; ?>
        </tbody></table></div>
        <div class="small text-muted">Products referenced by quotations or other records are protected from deletion to preserve history.</div>
    </div></div>
</div></div></div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
