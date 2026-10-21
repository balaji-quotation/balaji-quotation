<?php

session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

include("../includes/db.php");
require_once __DIR__ . "/../includes/permissions.php";
erpRequireCompanyUser($conn);
erpRequire('clients.add');
$companyId = erpCurrentCompanyId();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(400);
    exit('Invalid request.');
}


/*
|--------------------------------------------------------------------------
| GET FORM DATA
|--------------------------------------------------------------------------
*/

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

$mobile = trim($_POST['mobile'] ?? '');
$alternate_mobile = trim($_POST['alternate_mobile'] ?? '');

$address = trim(
    $_POST['address'] ?? ''
);

$status = trim(
    $_POST['status'] ?? 'Active'
);


/*
|--------------------------------------------------------------------------
| BASIC VALIDATION
|--------------------------------------------------------------------------
*/

if ($company_name === '' || mb_strlen($company_name, 'UTF-8') > 150) {
    die("Company Name is required and must be 150 characters or fewer.");
}
if ($address === '') {
    die("Complete Address is required.");
}
if ($mobile === '' && $alternate_mobile === '') {
    die("Mobile or Alternate Mobile is required.");
}
if (strlen($mobile) > 20 || strlen($alternate_mobile) > 30) {
    die("Mobile must be 20 characters or fewer and Alternate Mobile 30 characters or fewer.");
}
if ($gstin !== '' && strlen($gstin) !== 15) {
    die("GSTIN must be 15 characters.");
}
if ($email !== '' && (strlen($email) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
    die("Enter a valid email address of 100 characters or fewer.");
}
if (!in_array($status, ['Active', 'Inactive'], true)) {
    die("Invalid client status.");
}


/*
|--------------------------------------------------------------------------
| INSERT CLIENT
|--------------------------------------------------------------------------
*/

$sql = "
    INSERT INTO clients
    (
        company_id,
        company_name,
        contact_person,
        gstin,
        email,
        mobile,
        alternate_mobile,
        address,
        status
    )
    VALUES
    (?, ?, ?, ?, ?, ?, ?, ?, ?)
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
    "issssssss",
    $companyId,
    $company_name,
    $contact_person,
    $gstin,
    $email,
    $mobile,
    $alternate_mobile,
    $address,
    $status
);


/*
|--------------------------------------------------------------------------
| SAVE
|--------------------------------------------------------------------------
*/

if (mysqli_stmt_execute($stmt)) {

    header(
        "Location: index.php?msg=added"
    );

    exit;

} else {

    die(
        "Client Save Error: " .
        mysqli_stmt_error($stmt)
    );
}


mysqli_stmt_close($stmt);

?>