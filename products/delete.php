<?php

session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . "/../includes/db.php";


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
    "i",
    $id
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


// =====================================================
// CHECK PRODUCT OPERATIONS
// =====================================================

$operation_stmt =
    mysqli_prepare(
        $conn,
        "
        SELECT COUNT(*) AS total
        FROM product_operations
        WHERE product_id = ?
        "
    );

if (!$operation_stmt) {

    error_log(
        "Product operation check error: " .
        mysqli_error($conn)
    );

    header("Location: index.php?msg=db_error");
    exit;
}

mysqli_stmt_bind_param(
    $operation_stmt,
    "i",
    $id
);

mysqli_stmt_execute($operation_stmt);

$operation_result =
    mysqli_stmt_get_result($operation_stmt);

$operation_row =
    mysqli_fetch_assoc($operation_result);

mysqli_stmt_close($operation_stmt);

$total_operations =
    intval($operation_row['total'] ?? 0);

if ($total_operations > 0) {

    header(
        "Location: index.php?msg=linked&count=" .
        $total_operations
    );

    exit;
}


// =====================================================
// CHECK VENDOR PO ITEMS
// =====================================================

$vendor_po_stmt =
    mysqli_prepare(
        $conn,
        "
        SELECT COUNT(*) AS total
        FROM vendor_po_items
        WHERE product_id = ?
        "
    );

if (!$vendor_po_stmt) {

    error_log(
        "Vendor PO check error: " .
        mysqli_error($conn)
    );

    header("Location: index.php?msg=db_error");
    exit;
}

mysqli_stmt_bind_param(
    $vendor_po_stmt,
    "i",
    $id
);

mysqli_stmt_execute($vendor_po_stmt);

$vendor_po_result =
    mysqli_stmt_get_result($vendor_po_stmt);

$vendor_po_row =
    mysqli_fetch_assoc($vendor_po_result);

mysqli_stmt_close($vendor_po_stmt);

$total_vendor_po =
    intval($vendor_po_row['total'] ?? 0);

if ($total_vendor_po > 0) {

    header(
        "Location: index.php?msg=vendor_po_linked&count=" .
        $total_vendor_po
    );

    exit;
}


// =====================================================
// CHECK CLIENT PO ITEMS
// =====================================================

$client_po_stmt =
    mysqli_prepare(
        $conn,
        "
        SELECT COUNT(*) AS total
        FROM client_po_items
        WHERE product_id = ?
        "
    );

if (!$client_po_stmt) {

    error_log(
        "Client PO check error: " .
        mysqli_error($conn)
    );

    header("Location: index.php?msg=db_error");
    exit;
}

mysqli_stmt_bind_param(
    $client_po_stmt,
    "i",
    $id
);

mysqli_stmt_execute($client_po_stmt);

$client_po_result =
    mysqli_stmt_get_result($client_po_stmt);

$client_po_row =
    mysqli_fetch_assoc($client_po_result);

mysqli_stmt_close($client_po_stmt);

$total_client_po =
    intval($client_po_row['total'] ?? 0);

if ($total_client_po > 0) {

    header(
        "Location: index.php?msg=client_po_linked&count=" .
        $total_client_po
    );

    exit;
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
    "i",
    $id
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

?>