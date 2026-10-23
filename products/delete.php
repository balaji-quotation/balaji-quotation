<?php

session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/product_master.php';
erpRequireCompanyUser($conn);
erpRequire('products.delete');
$companyId = erpCurrentCompanyId();

try {


// =====================================================
// ONLY POST REQUEST
// =====================================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php?msg=invalid_request");
    exit;
}


// =====================================================
// CSRF CHECK
// =====================================================

$csrf_token =
    $_POST['csrf_token'] ?? '';

if (
    empty($_SESSION['csrf_token']) ||
    empty($csrf_token) ||
    !hash_equals(
        $_SESSION['csrf_token'],
        $csrf_token
    )
) {
    header("Location: index.php?msg=invalid_request");
    exit;
}


// =====================================================
// PRODUCT ID
// =====================================================

$id = intval($_POST['id'] ?? 0);

if ($id <= 0) {
    header("Location: index.php?msg=invalid");
    exit;
}


// =====================================================
// GET PRODUCT
// =====================================================

$product_stmt = mysqli_prepare(
    $conn,
    "
    SELECT
        id,
        drawing_file,
        product_image
    FROM products
    WHERE id = ?
      AND company_id = ?
    LIMIT 1
    "
);

if (!$product_stmt) {

    error_log(
        "Product delete check prepare error: " .
        mysqli_error($conn)
    );

    header("Location: index.php?msg=db_error");
    exit;
}

mysqli_stmt_bind_param(
    $product_stmt,
    "ii",
    $id,
    $companyId
);

mysqli_stmt_execute($product_stmt);

$product_result =
    mysqli_stmt_get_result($product_stmt);

$product =
    mysqli_fetch_assoc($product_result);

mysqli_stmt_close($product_stmt);

if (!$product) {
    header("Location: index.php?msg=notfound");
    exit;
}


// Preserve issued quotation snapshots and product references.
$quotation_stmt = mysqli_prepare(
    $conn,
    "SELECT COUNT(*) AS total FROM quotation_rows WHERE product_id = ?"
);
if (!$quotation_stmt) {
    error_log("Product quotation check prepare error: " . mysqli_error($conn));
    header("Location: index.php?msg=db_error");
    exit;
}
mysqli_stmt_bind_param($quotation_stmt, "i", $id);
mysqli_stmt_execute($quotation_stmt);
$quotation_result = mysqli_stmt_get_result($quotation_stmt);
$quotation_row = mysqli_fetch_assoc($quotation_result);
mysqli_stmt_close($quotation_stmt);
$quotation_count = (int)($quotation_row['total'] ?? 0);
if ($quotation_count > 0) {
    header("Location: index.php?msg=quotation_linked&count=" . $quotation_count);
    exit;
}


// =====================================================
// CHECK OPTIONAL PRODUCT REFERENCES
// =====================================================

// Some legacy modules are not installed in every quotation database.
// Check only tables that exist so a missing optional module cannot block
// deleting an otherwise unused product.
$optionalReferenceTables = [
    'product_operations' => 'linked',
    'vendor_po_items' => 'vendor_po_linked',
    'client_po_items' => 'client_po_linked',
];

foreach ($optionalReferenceTables as $table => $message) {
    if (!productMasterTableExists($conn, $table)) {
        continue;
    }

    $reference_stmt = mysqli_prepare(
        $conn,
        "SELECT COUNT(*) AS total FROM `" . $table . "` WHERE product_id = ?"
    );

    if (!$reference_stmt) {
        error_log("Product reference check prepare error for " . $table . ": " . mysqli_error($conn));
        header("Location: index.php?msg=db_error");
        exit;
    }

    mysqli_stmt_bind_param($reference_stmt, "i", $id);
    mysqli_stmt_execute($reference_stmt);
    $reference_result = mysqli_stmt_get_result($reference_stmt);
    $reference_row = mysqli_fetch_assoc($reference_result);
    mysqli_stmt_close($reference_stmt);

    if ((int)($reference_row['total'] ?? 0) > 0) {
        header("Location: index.php?msg=" . $message);
        exit;
    }
}


// =====================================================
// DELETE PRODUCT
// =====================================================

$delete_stmt =
    mysqli_prepare(
        $conn,
        "
        DELETE FROM products
        WHERE id = ?
          AND company_id = ?
        LIMIT 1
        "
    );

if (!$delete_stmt) {

    error_log(
        "Product delete prepare error: " .
        mysqli_error($conn)
    );

    header("Location: index.php?msg=db_error");
    exit;
}

mysqli_stmt_bind_param(
    $delete_stmt,
    "ii",
    $id,
    $companyId
);

if (!mysqli_stmt_execute($delete_stmt)) {

    error_log(
        "Product delete failed: " .
        mysqli_stmt_error($delete_stmt)
    );

    mysqli_stmt_close($delete_stmt);

    header("Location: index.php?msg=delete_failed");
    exit;
}

mysqli_stmt_close($delete_stmt);


// =====================================================
// DELETE FILES AFTER DB DELETE
// =====================================================

$drawing_file =
    $product['drawing_file'] ?? '';

$product_image =
    $product['product_image'] ?? '';

if ($drawing_file !== '') {

    $drawing_path =
        __DIR__ .
        "/../uploads/drawings/" .
        basename($drawing_file);

    if (is_file($drawing_path)) {
        @unlink($drawing_path);
    }
}

if ($product_image !== '') {

    $product_image_path =
        __DIR__ .
        "/../uploads/products/" .
        basename($product_image);

    if (is_file($product_image_path)) {
        @unlink($product_image_path);
    }
}


// =====================================================
// SUCCESS
// =====================================================

header("Location: index.php?msg=deleted");
exit;
} catch (Throwable $error) {
    error_log("Product delete failed: " . $error->getMessage());
    header("Location: index.php?msg=db_error");
    exit;
}

?>