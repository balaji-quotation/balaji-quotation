<?php

session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

include("../includes/db.php");
require_once __DIR__ . "/../includes/permissions.php";
erpRequireCompanyUser($conn);
erpRequire('clients.edit');
$companyId = erpCurrentCompanyId();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(400);
    exit('Invalid request.');
}


// =====================================================
// GET FORM DATA
// =====================================================

$id = intval(
    $_POST['id'] ?? 0
);

$company_name = trim(
    $_POST['company_name'] ?? ''
);

$contact_person = trim(
    $_POST['contact_person'] ?? ''
);

$gstin = strtoupper(
    trim($_POST['gstin'] ?? '')
);

$email = trim(
    $_POST['email'] ?? ''
);

$mobile = trim(
    $_POST['mobile'] ?? ''
);

$address = trim(
    $_POST['address'] ?? ''
);

$status = trim(
    $_POST['status'] ?? 'Active'
);


// =====================================================
// VALIDATION
// =====================================================

if ($id <= 0) {
    die("Invalid Client ID.");
}

if ($company_name === '') {
    die("Company Name is required.");
}

if (
    $gstin !== '' &&
    strlen($gstin) !== 15
) {
    die("GSTIN must be 15 characters.");
}


// =====================================================
// UPDATE CLIENT
// =====================================================

$sql = "
    UPDATE clients
    SET
        company_name = ?,
        contact_person = ?,
        gstin = ?,
        email = ?,
        mobile = ?,
        address = ?,
        status = ?
    WHERE id = ?
      AND company_id = ?
";


$stmt = mysqli_prepare(
    $conn,
    $sql
);

if (!$stmt) {

    die(
        "Database Error: " .
        mysqli_error($conn)
    );
}


mysqli_stmt_bind_param(
    $stmt,
    "sssssssii",
    $company_name,
    $contact_person,
    $gstin,
    $email,
    $mobile,
    $address,
    $status,
    $id,
    $companyId
);


// =====================================================
// EXECUTE
// =====================================================

if (mysqli_stmt_execute($stmt)) {

    mysqli_stmt_close($stmt);

    header(
        "Location: index.php?msg=updated"
    );

    exit;

} else {

    $error =
        mysqli_stmt_error($stmt);

    mysqli_stmt_close($stmt);

    die(
        "Client Update Error: " .
        $error
    );
}

?>