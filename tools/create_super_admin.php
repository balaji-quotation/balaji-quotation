<?php
declare(strict_types=1);

/**
 * One-time Super Admin bootstrap after the tenant migration.
 *
 * Set SAAS_SUPERADMIN_NAME, SAAS_SUPERADMIN_EMAIL, and SAAS_SUPERADMIN_PASSWORD
 * in the process environment. Do not place values in source control or CLI args.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$name = trim((string)getenv('SAAS_SUPERADMIN_NAME'));
$email = strtolower(trim((string)getenv('SAAS_SUPERADMIN_EMAIL'));
$password = (string)getenv('SAAS_SUPERADMIN_PASSWORD');

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
    fwrite(STDERR, "Set SAAS_SUPERADMIN_NAME, a valid SAAS_SUPERADMIN_EMAIL, and a SAAS_SUPERADMIN_PASSWORD of at least 12 characters. Values are read from the process environment and never printed.\n");
    exit(2);
}

$required = ['users' => ['company_id']];
foreach ($required as $table => $columns) {
    $tableStmt = $conn->prepare(
        'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $tableStmt->bind_param('s', $table);
    $tableStmt->execute();
    $tableStmt->store_result();
    $exists = $tableStmt->num_rows > 0;
    $tableStmt->close();
    if (!$exists) {
        fwrite(STDERR, "Required tenant schema is missing. Run the reviewed migration first.\n");
        exit(3);
    }
    foreach ($columns as $column) {
        $columnStmt = $conn->prepare(
            'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
        );
        $columnStmt->bind_param('ss', $table, $column);
        $columnStmt->execute();
        $columnStmt->store_result();
        $exists = $columnStmt->num_rows > 0;
        $columnStmt->close();
        if (!$exists) {
            fwrite(STDERR, "Required tenant schema is incomplete. Run the reviewed migration first.\n");
            exit(3);
        }
    }
}

$check = $conn->prepare('SELECT id FROM users WHERE LOWER(email) = ? LIMIT 1');
$check->bind_param('s', $email);
$check->execute();
$check->store_result();
if ($check->num_rows > 0) {
    $check->close();
    fwrite(STDERR, "That email already belongs to an account. No account was changed.\n");
    exit(4);
}
$check->close();

$hash = password_hash($password, PASSWORD_DEFAULT);
if ($hash === false) {
    fwrite(STDERR, "Password hashing failed. No account was created.\n");
    exit(5);
}

$insert = $conn->prepare(
    "INSERT INTO users (name, email, password, role, status, company_id)
     VALUES (?, ?, ?, 'Super Admin', 'Active', NULL)"
);
$insert->bind_param('sss', $name, $email, $hash);
$insert->execute();
$insert->close();

echo "Super Admin account created. The password was not displayed or stored in the repository.\n";
