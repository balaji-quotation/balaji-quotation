<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

include("../includes/db.php");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: add.php");
    exit;
}

function userClean($value)
{
    return trim((string)($value ?? ''));
}

function userFail($message, $old = [])
{
    $_SESSION['users_error'] = $message;
    $_SESSION['users_old']   = $old;

    header("Location: add.php");
    exit;
}

$name = userClean($_POST['name'] ?? '');
$email = strtolower(userClean($_POST['email'] ?? ''));
$password = (string)($_POST['password'] ?? '');
$confirm_password = (string)($_POST['confirm_password'] ?? '');
$role = userClean($_POST['role'] ?? 'User');
$status = userClean($_POST['status'] ?? 'Active');

$old = [
    'name'   => $name,
    'email'  => $email,
    'role'   => $role,
    'status' => $status
];

// =====================================================
// VALIDATION
// =====================================================

if ($name === '') {
    userFail('Full Name is required.', $old);
}

if ($email === '') {
    userFail('Email / Login ID is required.', $old);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    userFail('Please enter a valid Email address.', $old);
}

if (strlen($password) < 6) {
    userFail('Password must be at least 6 characters.', $old);
}

if ($password !== $confirm_password) {
    userFail('Password and Confirm Password do not match.', $old);
}

$allowed_roles = [
    'Administrator',
    'Manager',
    'User'
];

if (!in_array($role, $allowed_roles, true)) {
    $role = 'User';
    $old['role'] = $role;
}

$allowed_status = [
    'Active',
    'Inactive'
];

if (!in_array($status, $allowed_status, true)) {
    $status = 'Active';
    $old['status'] = $status;
}

// =====================================================
// DUPLICATE EMAIL CHECK
// Email is the Login ID.
// =====================================================

$check_stmt = mysqli_prepare(
    $conn,
    "SELECT id FROM users WHERE email = ? LIMIT 1"
);

if (!$check_stmt) {
    userFail(
        'Email Check Error: ' . mysqli_error($conn),
        $old
    );
}

mysqli_stmt_bind_param(
    $check_stmt,
    "s",
    $email
);

mysqli_stmt_execute($check_stmt);
mysqli_stmt_store_result($check_stmt);

if (mysqli_stmt_num_rows($check_stmt) > 0) {
    mysqli_stmt_close($check_stmt);

    userFail(
        'This Email / Login ID is already registered.',
        $old
    );
}

mysqli_stmt_close($check_stmt);

// =====================================================
// SECURE PASSWORD HASH
// =====================================================

$password_hash = password_hash(
    $password,
    PASSWORD_DEFAULT
);

if ($password_hash === false) {
    userFail(
        'Password could not be secured.',
        $old
    );
}

// =====================================================
// INSERT USER
// users table columns:
// id, name, email, password, role, status,
// created_at, updated_at
// =====================================================

$insert_stmt = mysqli_prepare(
    $conn,
    "
    INSERT INTO users
    (
        name,
        email,
        password,
        role,
        status
    )
    VALUES
    (?, ?, ?, ?, ?)
    "
);

if (!$insert_stmt) {
    userFail(
        'Database Error: ' . mysqli_error($conn),
        $old
    );
}

mysqli_stmt_bind_param(
    $insert_stmt,
    "sssss",
    $name,
    $email,
    $password_hash,
    $role,
    $status
);

if (!mysqli_stmt_execute($insert_stmt)) {
    $error_no = mysqli_stmt_errno($insert_stmt);
    $error_message = mysqli_stmt_error($insert_stmt);

    mysqli_stmt_close($insert_stmt);

    if ($error_no === 1062) {
        userFail(
            'This Email / Login ID is already registered.',
            $old
        );
    }

    userFail(
        'Database Error: ' . $error_message,
        $old
    );
}

mysqli_stmt_close($insert_stmt);

unset(
    $_SESSION['users_error'],
    $_SESSION['users_old']
);

header("Location: index.php?msg=added");
exit;
?>
