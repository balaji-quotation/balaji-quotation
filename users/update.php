<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

require_once("../includes/permissions.php");
erpRequire('users.edit');

include("../includes/db.php");


// =====================================================
// ONLY POST REQUEST
// =====================================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit;
}


// =====================================================
// GET FORM DATA
// =====================================================

$id = intval($_POST['id'] ?? 0);

$name = trim($_POST['name'] ?? '');
$email = strtolower(trim($_POST['email'] ?? ''));
$role = trim($_POST['role'] ?? 'User');
$status = trim($_POST['status'] ?? 'Active');

$password = $_POST['password'] ?? '';
$confirm_password = $_POST['confirm_password'] ?? '';


// =====================================================
// VALIDATION
// =====================================================

if ($id <= 0) {
    $_SESSION['user_error'] = "Invalid user ID.";
    header("Location: index.php");
    exit;
}

if ($name === '') {
    $_SESSION['user_error'] = "Full Name is required.";
    header("Location: edit.php?id=" . $id);
    exit;
}

if ($email === '') {
    $_SESSION['user_error'] = "Email / Login ID is required.";
    header("Location: edit.php?id=" . $id);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['user_error'] = "Please enter a valid email address.";
    header("Location: edit.php?id=" . $id);
    exit;
}


// =====================================================
// ALLOWED ROLE / STATUS
// =====================================================

$allowed_roles = [
    'Administrator',
    'Manager',
    'Production',
    'Quality',
    'Dispatch',
    'Accounts',
    'User'
];

if (!in_array($role, $allowed_roles, true)) {
    $role = 'User';
}

$allowed_status = [
    'Active',
    'Inactive'
];

if (!in_array($status, $allowed_status, true)) {
    $status = 'Active';
}


// =====================================================
// PASSWORD VALIDATION
// Blank password = keep old password
// =====================================================

if ($password !== '' || $confirm_password !== '') {

    if (strlen($password) < 6) {
        $_SESSION['user_error'] =
            "New password must be at least 6 characters.";

        header("Location: edit.php?id=" . $id);
        exit;
    }

    if ($password !== $confirm_password) {
        $_SESSION['user_error'] =
            "New Password and Confirm Password do not match.";

        header("Location: edit.php?id=" . $id);
        exit;
    }
}


// =====================================================
// CHECK USER EXISTS
// =====================================================

$check_user = mysqli_prepare(
    $conn,
    "
    SELECT id
    FROM users
    WHERE id = ?
    LIMIT 1
    "
);

if (!$check_user) {
    die("Database Error: " . mysqli_error($conn));
}

mysqli_stmt_bind_param(
    $check_user,
    "i",
    $id
);

mysqli_stmt_execute($check_user);

$user_result =
    mysqli_stmt_get_result(
        $check_user
    );

if (mysqli_num_rows($user_result) === 0) {

    mysqli_stmt_close($check_user);

    $_SESSION['user_error'] =
        "User not found.";

    header("Location: index.php");
    exit;
}

mysqli_stmt_close($check_user);


// =====================================================
// CHECK DUPLICATE EMAIL
// Another user cannot use same email
// =====================================================

$check_email = mysqli_prepare(
    $conn,
    "
    SELECT id
    FROM users
    WHERE LOWER(email) = ?
      AND id != ?
    LIMIT 1
    "
);

if (!$check_email) {
    die("Database Error: " . mysqli_error($conn));
}

mysqli_stmt_bind_param(
    $check_email,
    "si",
    $email,
    $id
);

mysqli_stmt_execute($check_email);

$email_result =
    mysqli_stmt_get_result(
        $check_email
    );

if (mysqli_num_rows($email_result) > 0) {

    mysqli_stmt_close($check_email);

    $_SESSION['user_error'] =
        "This Email / Login ID is already used by another user.";

    header("Location: edit.php?id=" . $id);
    exit;
}

mysqli_stmt_close($check_email);


// =====================================================
// UPDATE USER
// =====================================================

if ($password !== '') {

    // Password change requested
    $password_hash =
        password_hash(
            $password,
            PASSWORD_DEFAULT
        );

    if ($password_hash === false) {

        $_SESSION['user_error'] =
            "Password could not be secured.";

        header("Location: edit.php?id=" . $id);
        exit;
    }

    $stmt = mysqli_prepare(
        $conn,
        "
        UPDATE users
        SET
            name = ?,
            email = ?,
            role = ?,
            status = ?,
            password = ?
        WHERE id = ?
        "
    );

    if (!$stmt) {
        die("Database Error: " . mysqli_error($conn));
    }

    mysqli_stmt_bind_param(
        $stmt,
        "sssssi",
        $name,
        $email,
        $role,
        $status,
        $password_hash,
        $id
    );

} else {

    // Keep current password
    $stmt = mysqli_prepare(
        $conn,
        "
        UPDATE users
        SET
            name = ?,
            email = ?,
            role = ?,
            status = ?
        WHERE id = ?
        "
    );

    if (!$stmt) {
        die("Database Error: " . mysqli_error($conn));
    }

    mysqli_stmt_bind_param(
        $stmt,
        "ssssi",
        $name,
        $email,
        $role,
        $status,
        $id
    );
}


// =====================================================
// EXECUTE UPDATE
// =====================================================

if (mysqli_stmt_execute($stmt)) {

    mysqli_stmt_close($stmt);

    header("Location: index.php?msg=updated");
    exit;

} else {

    $error =
        mysqli_stmt_error(
            $stmt
        );

    mysqli_stmt_close($stmt);

    $_SESSION['user_error'] =
        "Database Error: " . $error;

    header("Location: edit.php?id=" . $id);
    exit;
}
?>