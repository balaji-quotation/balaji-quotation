<?php
declare(strict_types=1);

/**
 * Additive migration for per-user module access. Existing users are untouched;
 * users without explicit rows continue using the legacy role permission map.
 *
 * Usage:
 *   php tools/migrate_user_permissions.php
 *   php tools/migrate_user_permissions.php --apply --backup-verified --maintenance-confirmed
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (PHP_VERSION_ID < 80200) {
    fwrite(STDERR, "PHP 8.2 or later is required.\n");
    exit(2);
}
require_once __DIR__ . '/../includes/db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$allowedArgs = ['--apply', '--backup-verified', '--maintenance-confirmed'];
foreach (array_slice($argv, 1) as $arg) {
    if (!in_array($arg, $allowedArgs, true)) {
        fwrite(STDERR, "Unknown option: {$arg}\n");
        exit(2);
    }
}
$apply = in_array('--apply', $argv, true);
if (($apply && (!in_array('--backup-verified', $argv, true) || !in_array('--maintenance-confirmed', $argv, true)))
    || (!$apply && (in_array('--backup-verified', $argv, true) || in_array('--maintenance-confirmed', $argv, true)))) {
    fwrite(STDERR, "Apply requires --apply, --backup-verified, and --maintenance-confirmed.\n");
    exit(2);
}
$usersExists = $conn->query(
    "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'users' LIMIT 1"
)->num_rows > 0;
if (!$usersExists) {
    fwrite(STDERR, "Preflight failed: users table is missing. No changes made.\n");
    exit(3);
}
$tableExists = $conn->query(
    "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'user_permission_overrides' LIMIT 1"
)->num_rows > 0;

echo "Database: " . $conn->query('SELECT DATABASE() AS db')->fetch_assoc()['db'] . "\n";
echo "Existing user permission table: " . ($tableExists ? 'present' : 'will be created') . "\n";
echo "Existing users and permissions will not be rewritten.\n";
if (!$apply) {
    echo "Read-only preflight complete. No database changes were made.\n";
    exit(0);
}
$conn->query(
    "CREATE TABLE IF NOT EXISTS user_permission_overrides (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT UNSIGNED NOT NULL,
        permission_key VARCHAR(100) NOT NULL,
        allowed TINYINT(1) NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_user_permission (user_id, permission_key),
        KEY idx_user_permission_key (permission_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
echo "Per-user permission table is ready. Existing users and role settings are unchanged.\n";
?>