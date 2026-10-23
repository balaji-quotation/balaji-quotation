<?php
declare(strict_types=1);

/**
 * Additive migration for the configurable Product Master.
 * Default mode is read-only preflight. Existing product rows are preserved;
 * this script adds two columns and one metadata table and widens product_name
 * to 255 characters when the legacy column is shorter.
 *
 * Usage:
 *   php tools/migrate_product_master.php
 *   php tools/migrate_product_master.php --apply --backup-verified --maintenance-confirmed
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
$backupVerified = in_array('--backup-verified', $argv, true);
$maintenanceConfirmed = in_array('--maintenance-confirmed', $argv, true);
if (($backupVerified || $maintenanceConfirmed) && !$apply) {
    fwrite(STDERR, "Safety flags require --apply.\n");
    exit(2);
}
if ($apply && (!$backupVerified || !$maintenanceConfirmed)) {
    fwrite(STDERR, "Refusing to apply: require --apply, --backup-verified, and --maintenance-confirmed after owner approval and a verified restorable backup.\n");
    exit(2);
}

function productMigrationTableExists(mysqli $db, string $table): bool
{
    $stmt = $db->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    return $exists;
}

function productMigrationColumnExists(mysqli $db, string $table, string $column): bool
{
    $stmt = $db->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    return $exists;
}

function productMigrationColumnDetails(mysqli $db, string $table, string $column): ?array
{
    $stmt = $db->prepare('SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE, COLUMN_DEFAULT, CHARACTER_SET_NAME, COLLATION_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1');
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $result = $stmt->get_result();
    $details = $result->fetch_assoc() ?: null;
    $stmt->close();
    return $details;
}

if (!productMigrationTableExists($conn, 'products')) {
    fwrite(STDERR, "Preflight failed: products table does not exist. No changes made.\n");
    exit(3);
}
if (!productMigrationColumnExists($conn, 'products', 'company_id')) {
    fwrite(STDERR, "Preflight failed: products.company_id is missing. Run the approved SaaS tenant migration first. No changes made.\n");
    exit(3);
}

$beforeResult = $conn->query('SELECT COUNT(*) AS n FROM products');
$beforeCount = (int)($beforeResult->fetch_assoc()['n'] ?? 0);
$databaseResult = $conn->query('SELECT DATABASE() AS db_name');
$databaseName = (string)($databaseResult->fetch_assoc()['db_name'] ?? '');

$missingColumns = [];
foreach (['description', 'custom_fields_json'] as $column) {
    if (!productMigrationColumnExists($conn, 'products', $column)) {
        $missingColumns[] = $column;
    }
}
$fieldTableExists = productMigrationTableExists($conn, 'product_master_fields');
$productNameColumn = productMigrationColumnDetails($conn, 'products', 'product_name');
if (!$productNameColumn || !in_array(strtolower((string)$productNameColumn['DATA_TYPE']), ['varchar', 'char'], true)) {
    fwrite(STDERR, "Preflight failed: products.product_name is missing or is not a character column. No changes made.\n");
    exit(3);
}
$productNameLength = (int)$productNameColumn['CHARACTER_MAXIMUM_LENGTH'];
$widenProductName = $productNameLength < 255;

echo "Database: {$databaseName}\n";
echo "Existing product rows: {$beforeCount}\n";
echo "Columns to add: " . ($missingColumns ? implode(', ', $missingColumns) : 'none') . "\n";
echo "product_name width: VARCHAR({$productNameLength})" . ($widenProductName ? ' -> VARCHAR(255)' : ' (already at least 255)') . "\n";
echo "Product field-definition table: " . ($fieldTableExists ? 'already present' : 'will be created') . "\n";
echo "No product rows will be deleted or rewritten. Existing product_name values and column nullability, character set, collation, and non-null default will be preserved.\n";

if (!$apply) {
    echo "Read-only preflight complete. No database changes were made.\n";
    exit(0);
}

foreach ($missingColumns as $column) {
    if ($column === 'description') {
        $conn->query('ALTER TABLE products ADD COLUMN description TEXT NULL');
    } elseif ($column === 'custom_fields_json') {
        $conn->query('ALTER TABLE products ADD COLUMN custom_fields_json LONGTEXT NULL');
    }
}

if ($widenProductName) {
    $charset = (string)($productNameColumn['CHARACTER_SET_NAME'] ?? '');
    $collation = (string)($productNameColumn['COLLATION_NAME'] ?? '');
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $charset) || !preg_match('/^[a-zA-Z0-9_]+$/', $collation)) {
        fwrite(STDERR, "Refusing to widen product_name: unsupported character set or collation metadata.\n");
        exit(3);
    }
    $nullability = strtoupper((string)$productNameColumn['IS_NULLABLE']) === 'YES' ? 'NULL' : 'NOT NULL';
    $default = $productNameColumn['COLUMN_DEFAULT'];
    $defaultSql = $default !== null ? " DEFAULT '" . $conn->real_escape_string((string)$default) . "'" : '';
    $conn->query("ALTER TABLE products MODIFY COLUMN product_name VARCHAR(255) CHARACTER SET {$charset} COLLATE {$collation} {$nullability}{$defaultSql}");
}

$conn->query(
    "CREATE TABLE IF NOT EXISTS product_master_fields (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        field_key VARCHAR(64) NOT NULL,
        label VARCHAR(100) NOT NULL,
        field_type VARCHAR(20) NOT NULL DEFAULT 'text',
        options_json LONGTEXT NULL,
        is_required TINYINT(1) NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT UNSIGNED NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_product_master_fields_key (field_key),
        KEY idx_product_master_fields_active_order (is_active, sort_order, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$afterResult = $conn->query('SELECT COUNT(*) AS n FROM products');
$afterCount = (int)($afterResult->fetch_assoc()['n'] ?? 0);
if ($afterCount !== $beforeCount) {
    fwrite(STDERR, "Postflight failed: product row count changed from {$beforeCount} to {$afterCount}. Inspect the database before continuing.\n");
    exit(4);
}
echo "Product Master schema migration completed. Existing product row count is unchanged ({$afterCount}).\n";
