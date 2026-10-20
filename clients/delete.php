<?php

session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/permissions.php";
erpRequireCompanyUser($conn);
erpRequire('clients.delete');
$companyId = erpCurrentCompanyId();


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

$csrf_token = $_POST['csrf_token'] ?? '';

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
// CLIENT ID
// =====================================================

$id = intval($_POST['id'] ?? 0);

if ($id <= 0) {
    header("Location: index.php?msg=invalid");
    exit;
}


// =====================================================
// CHECK CLIENT EXISTS
// =====================================================

$client_stmt = mysqli_prepare(
    $conn,
    "
    SELECT id
    FROM clients
    WHERE id = ?
      AND company_id = ?
    LIMIT 1
    "
);

if (!$client_stmt) {

    error_log(
        "Client delete check error: " .
        mysqli_error($conn)
    );

    header("Location: index.php?msg=db_error");
    exit;
}

mysqli_stmt_bind_param(
    $client_stmt,
    "ii",
    $id,
    $companyId
);

mysqli_stmt_execute($client_stmt);

$client_result =
    mysqli_stmt_get_result($client_stmt);

$client =
    mysqli_fetch_assoc($client_result);

mysqli_stmt_close($client_stmt);

if (!$client) {
    header("Location: index.php?msg=notfound");
    exit;
}


// =====================================================
// CHECK CLIENT PO LINK
// =====================================================

$po_stmt = mysqli_prepare(
    $conn,
    "
    SELECT COUNT(*) AS total
    FROM client_po
    WHERE client_id = ?
    "
);

if (!$po_stmt) {

    error_log(
        "Client PO link check error: " .
        mysqli_error($conn)
    );

    header("Location: index.php?msg=db_error");
    exit;
}

mysqli_stmt_bind_param(
    $po_stmt,
    "i",
    $id
);

mysqli_stmt_execute($po_stmt);

$po_result =
    mysqli_stmt_get_result($po_stmt);

$po_row =
    mysqli_fetch_assoc($po_result);

mysqli_stmt_close($po_stmt);

$total_po =
    intval($po_row['total'] ?? 0);

if ($total_po > 0) {

    header(
        "Location: index.php?msg=linked&count=" .
        $total_po
    );

    exit;
}


// =====================================================
// DELETE CLIENT
// =====================================================

$delete_stmt = mysqli_prepare(
    $conn,
    "
    DELETE FROM clients
    WHERE id = ?
      AND company_id = ?
    LIMIT 1
    "
);

if (!$delete_stmt) {

    error_log(
        "Client delete prepare error: " .
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
        "Client delete failed: " .
        mysqli_stmt_error($delete_stmt)
    );

    mysqli_stmt_close($delete_stmt);

    header("Location: index.php?msg=delete_failed");
    exit;
}

mysqli_stmt_close($delete_stmt);


// =====================================================
// SUCCESS
// =====================================================

header("Location: index.php?msg=deleted");
exit;

?>