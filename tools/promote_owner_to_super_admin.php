<?php
declare(strict_types=1);

/**
 * One-time, local CLI bootstrap for the product owner's Super Admin account.
 *
 * This script never runs over HTTP, changes only the explicitly supplied
 * active Administrator account, and refuses to run if a Super Admin already
 * exists. It does not change passwords or print database/customer data.
 *
 * Usage:
 *   php tools/promote_owner_to_super_admin.php --email=owner@example.com
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (PHP_VERSION_ID < 80200) {
    fwrite(STDERR, "PHP 8.2 or later is required.\n");
    exit(2);
}

$options = getopt('', ['email:']);
$email = trim((string)($options['email'] ?? ''));
if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "Usage: php tools/promote_owner_to_super_admin.php --email=owner@example.com\n");
    exit(2);
}

fwrite(STDOUT, "This will promote the active Administrator account {$email} to Super Admin.\n");
fwrite(STDOUT, "Type exactly PROMOTE {$email} to continue: ");
$confirmation = fgets(STDIN);
if ($confirmation === false || trim($confirmation) !== "PROMOTE {$email}") {
    fwrite(STDERR, "Confirmation did not match. No changes were made.\n");
    exit(2);
}

require_once __DIR__ . '/../includes/db.php';
if (!isset($conn) || !$conn instanceof mysqli) {
    fwrite(STDERR, "Database connection is unavailable. Check the local includes/db.php configuration.\n");
    exit(3);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function ownerBootstrapColumnExists(mysqli $db, string $column): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1'
    );
    $table = 'users';
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows === 1;
    $stmt->close();
    return $exists;
}

try {
    if (!ownerBootstrapColumnExists($conn, 'email')
        || !ownerBootstrapColumnExists($conn, 'role')
        || !ownerBootstrapColumnExists($conn, 'status')) {
        throw new RuntimeException('The users table is missing a required column.');
    }

    $hasCompanyId = ownerBootstrapColumnExists($conn, 'company_id');

    $conn->begin_transaction();

    $existingSuperAdmins = $conn->query(
        "SELECT id FROM users WHERE role = 'Super Admin' LIMIT 1 FOR UPDATE"
    );
    if ($existingSuperAdmins->num_rows > 0) {
        $conn->rollback();
        throw new RuntimeException('A Super Admin already exists. Bootstrap stopped without changes.');
    }

    $stmt = $conn->prepare(
        'SELECT id, role, status FROM users WHERE LOWER(email) = LOWER(?) LIMIT 2 FOR UPDATE'
    );
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows !== 1) {
        $stmt->close();
        $conn->rollback();
        throw new RuntimeException('No unique account matches that email. No changes were made.');
    }

    $user = $result->fetch_assoc();
    $stmt->close();

    $role = trim((string)$user['role']);
    $status = trim((string)$user['status']);
    if (!in_array(strtolower($role), ['administrator', 'admin'], true) || strcasecmp($status, 'Active') !== 0) {
        $conn->rollback();
        throw new RuntimeException('The account must be an active Administrator. No changes were made.');
    }

    if ($hasCompanyId) {
        $update = $conn->prepare(
            "UPDATE users SET role = 'Super Admin', company_id = NULL WHERE id = ? AND role IN ('Administrator', 'Admin') AND status = 'Active'"
        );
    } else {
        $update = $conn->prepare(
            "UPDATE users SET role = 'Super Admin' WHERE id = ? AND role IN ('Administrator', 'Admin') AND status = 'Active'"
        );
    }
    $id = (int)$user['id'];
    $update->bind_param('i', $id);
    $update->execute();
    $changed = $update->affected_rows;
    $update->close();

    if ($changed !== 1) {
        $conn->rollback();
        throw new RuntimeException('The account was not updated. No changes were committed.');
    }

    $conn->commit();
    fwrite(STDOUT, "Owner account promoted to Super Admin. Log out and sign in again to refresh the session.\n");
} catch (Throwable $error) {
    if ($conn->errno === 0) {
        // Keep the transaction closed after an exception where possible.
        try {
            $conn->rollback();
        } catch (Throwable $ignored) {
        }
    }
    fwrite(STDERR, "Bootstrap failed: " . $error->getMessage() . "\n");
    exit(1);
}
