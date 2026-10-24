<?php
declare(strict_types=1);

/**
 * Allow quotations to store a typed customer name without requiring a Customer
 * Master record. Existing customer links and quotation rows remain unchanged.
 *
 * Read-only preflight:
 *   php tools/migrate_quotation_client_name.php
 *
 * Apply after verifying a restorable database backup:
 *   php tools/migrate_quotation_client_name.php --apply --backup-verified --maintenance-confirmed
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
    fwrite(STDERR, "Refusing to apply: require --apply, --backup-verified, and --maintenance-confirmed after verifying a restorable backup.\n");
    exit(2);
}

function quotationClientMigrationColumn(mysqli $db, string $column): ?array
{
    $stmt = $db->prepare(
        'SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
         FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
         LIMIT 1'
    );
    $table = 'quotations';
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $details = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $details;
}

$tableCheck = $conn->prepare(
    'SELECT 1 FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
);
$tableName = 'quotations';
$tableCheck->bind_param('s', $tableName);
$tableCheck->execute();
$tableCheck->store_result();
$tableExists = $tableCheck->num_rows === 1;
$tableCheck->close();
if (!$tableExists) {
    fwrite(STDERR, "Preflight failed: quotations table does not exist. No changes made.\n");
    exit(3);
}

$tenantCheck = $conn->prepare(
    'SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1'
);
$tenantTable = 'quotations';
$tenantColumn = 'company_id';
$tenantCheck->bind_param('ss', $tenantTable, $tenantColumn);
$tenantCheck->execute();
$tenantCheck->store_result();
$hasTenantColumn = $tenantCheck->num_rows === 1;
$tenantCheck->close();
if (!$hasTenantColumn) {
    fwrite(STDERR, "Preflight failed: quotations.company_id is missing. Run the SaaS tenant migration first. No changes made.\n");
    exit(3);
}

$clientId = quotationClientMigrationColumn($conn, 'client_id');
if (!$clientId || !preg_match('/^(?:tinyint|smallint|mediumint|int|bigint)(?:\\(\\d+\\))?(?: unsigned)?$/i', (string)$clientId['COLUMN_TYPE'])) {
    fwrite(STDERR, "Preflight failed: quotations.client_id is missing or has an unsupported type. No changes made.\n");
    exit(3);
}

$nameColumn = quotationClientMigrationColumn($conn, 'client_name_override');
$nameColumnExists = $nameColumn !== null;
$makeNullable = strtoupper((string)$clientId['IS_NULLABLE']) !== 'YES';
$countResult = $conn->query('SELECT COUNT(*) AS row_count FROM quotations');
$beforeRows = (int)($countResult->fetch_assoc()['row_count'] ?? 0);
$dbResult = $conn->query('SELECT DATABASE() AS database_name');
$databaseName = (string)($dbResult->fetch_assoc()['database_name'] ?? '');

echo "Quotation typed-customer migration preflight\n";
echo "Database: {$databaseName}\n";
echo "Quotation rows: {$beforeRows}\n";
echo "client_name_override column: " . ($nameColumnExists ? 'already present' : 'will be added as nullable VARCHAR(150)') . "\n";
echo "quotations.client_id: " . ($makeNullable ? 'will become nullable; existing type is preserved' : 'already nullable') . "\n";
echo "No quotation rows, IDs, numbers, or existing customer links will be rewritten.\n";
if (!$apply) {
    echo "Read-only preflight complete. No database changes were made.\n";
    exit(0);
}

if (!$nameColumnExists) {
    $conn->query('ALTER TABLE quotations ADD COLUMN client_name_override VARCHAR(150) NULL DEFAULT NULL AFTER client_id');
}
if ($makeNullable) {
    $safeType = strtoupper((string)$clientId['COLUMN_TYPE']);
    $conn->query("ALTER TABLE quotations MODIFY COLUMN client_id {$safeType} NULL DEFAULT NULL");
}

$nameCheck = quotationClientMigrationColumn($conn, 'client_name_override');
$idCheck = quotationClientMigrationColumn($conn, 'client_id');
if (!$nameCheck || !$idCheck || strtoupper((string)$idCheck['IS_NULLABLE']) !== 'YES') {
    fwrite(STDERR, "Migration verification failed. Re-run the script after inspecting the database; no quotation rows were rewritten.\n");
    exit(4);
}
$afterResult = $conn->query('SELECT COUNT(*) AS row_count FROM quotations');
$afterRows = (int)($afterResult->fetch_assoc()['row_count'] ?? 0);
if ($afterRows !== $beforeRows) {
    fwrite(STDERR, "Migration verification failed: quotation row count changed unexpectedly.\n");
    exit(4);
}
echo "Quotation typed-customer schema migration completed. Existing quotation row count is unchanged ({$afterRows}).\n";
?>