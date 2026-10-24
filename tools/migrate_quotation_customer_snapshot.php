<?php
declare(strict_types=1);

/**
 * Store quotation-specific customer details so edits to Customer Master do not
 * change previously created quotations.
 *
 * Read-only preflight:
 *   php tools/migrate_quotation_customer_snapshot.php
 *
 * Apply after verifying a restorable database backup:
 *   php tools/migrate_quotation_customer_snapshot.php --apply --backup-verified --maintenance-confirmed
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
    fwrite(STDERR, "Refusing to apply: verify a restorable database backup and pass all three safety flags.\n");
    exit(2);
}

$table = 'quotations';
$check = $conn->prepare(
    'SELECT 1 FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
);
$check->bind_param('s', $table);
$check->execute();
$check->store_result();
$tableExists = $check->num_rows === 1;
$check->close();
if (!$tableExists) {
    fwrite(STDERR, "Preflight failed: quotations table was not found. No changes made.\n");
    exit(3);
}

$column = 'client_details_snapshot';
$columnCheck = $conn->prepare(
    'SELECT COLUMN_TYPE FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1'
);
$columnCheck->bind_param('ss', $table, $column);
$columnCheck->execute();
$columnDetails = $columnCheck->get_result()->fetch_assoc() ?: null;
$columnCheck->close();

$countResult = $conn->query('SELECT COUNT(*) AS row_count FROM quotations');
$beforeRows = (int)($countResult->fetch_assoc()['row_count'] ?? 0);
$dbResult = $conn->query('SELECT DATABASE() AS database_name');
$databaseName = (string)($dbResult->fetch_assoc()['database_name'] ?? '');

echo "Quotation customer-details snapshot migration preflight\n";
echo "Database: {$databaseName}\n";
echo "Quotation rows: {$beforeRows}\n";
echo "client_details_snapshot: " . ($columnDetails ? 'already present' : 'will be added as nullable LONGTEXT') . "\n";
echo "Existing quotation rows will remain unchanged; their snapshot stays NULL and continues to use linked Customer Master details.\n";
if (!$apply) {
    echo "Read-only preflight complete. No database changes were made.\n";
    exit(0);
}

if (!$columnDetails) {
    $conn->query('ALTER TABLE quotations ADD COLUMN client_details_snapshot LONGTEXT NULL DEFAULT NULL AFTER client_name_override');
}

$verify = $conn->prepare(
    'SELECT 1 FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1'
);
$verify->bind_param('ss', $table, $column);
$verify->execute();
$verify->store_result();
$columnExists = $verify->num_rows === 1;
$verify->close();
$afterResult = $conn->query('SELECT COUNT(*) AS row_count FROM quotations');
$afterRows = (int)($afterResult->fetch_assoc()['row_count'] ?? 0);
if (!$columnExists || $afterRows !== $beforeRows) {
    fwrite(STDERR, "Migration verification failed. Existing quotation rows were not intentionally modified.\n");
    exit(4);
}
echo "Quotation customer-details snapshot migration completed. Existing quotation row count is unchanged ({$afterRows}).\n";
?>