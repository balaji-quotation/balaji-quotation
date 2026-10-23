<?php
declare(strict_types=1);

/**
 * Enforce company-scoped quotation number uniqueness.
 * Default mode is a read-only preflight. Existing quotation rows are not changed.
 *
 * Usage:
 *   php tools/migrate_quotation_number_unique.php
 *   php tools/migrate_quotation_number_unique.php --apply --backup-verified --maintenance-confirmed
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

function quotationUniqueColumnExists(mysqli $db, string $column): bool
{
    $stmt = $db->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1');
    $table = 'quotations';
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    return $exists;
}

function quotationUniqueIndexDetails(mysqli $db): ?array
{
    $result = $db->query(
        "SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') AS columns_list,
                MAX(non_unique) AS non_unique
         FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = 'quotations'
           AND index_name = 'uq_quotations_company_number'
         HAVING COUNT(*) > 0"
    );
    return $result->fetch_assoc() ?: null;
}

if (!quotationUniqueColumnExists($conn, 'company_id') || !quotationUniqueColumnExists($conn, 'quotation_no')) {
    fwrite(STDERR, "Preflight failed: quotations.company_id or quotations.quotation_no is missing. Run the SaaS tenant migration first. No changes made.\n");
    exit(3);
}

$countResult = $conn->query('SELECT COUNT(*) AS n FROM quotations');
$rowCount = (int)($countResult->fetch_assoc()['n'] ?? 0);
$duplicateResult = $conn->query(
    "SELECT COUNT(*) AS n FROM (
        SELECT company_id, quotation_no
        FROM quotations
        WHERE company_id IS NOT NULL
        GROUP BY company_id, quotation_no
        HAVING COUNT(*) > 1
    ) AS duplicate_numbers"
);
$duplicateGroups = (int)($duplicateResult->fetch_assoc()['n'] ?? 0);
$index = quotationUniqueIndexDetails($conn);
$hasExpectedIndex = $index
    && (string)$index['columns_list'] === 'company_id,quotation_no'
    && (int)$index['non_unique'] === 0;

echo "Database: " . (string)($conn->query('SELECT DATABASE() AS db_name')->fetch_assoc()['db_name'] ?? '') . "\n";
echo "Quotation rows: {$rowCount}\n";
echo "Duplicate company/number groups: {$duplicateGroups}\n";
echo "Unique index: " . ($hasExpectedIndex ? 'already present' : 'will be added') . "\n";

if ($duplicateGroups > 0 && !$hasExpectedIndex) {
    fwrite(STDERR, "Preflight blocked: existing duplicate quotation numbers must be reviewed and corrected before applying this migration. No changes made.\n");
    exit(3);
}
if ($index && !$hasExpectedIndex) {
    fwrite(STDERR, "Preflight blocked: index name uq_quotations_company_number already exists with a different definition. No changes made.\n");
    exit(3);
}
if (!$apply) {
    echo "Read-only preflight complete. No database changes were made.\n";
    exit(0);
}

if (!$hasExpectedIndex) {
    $conn->query('ALTER TABLE quotations ADD UNIQUE KEY uq_quotations_company_number (company_id, quotation_no)');
}
$afterCount = (int)($conn->query('SELECT COUNT(*) AS n FROM quotations')->fetch_assoc()['n'] ?? 0);
if ($afterCount !== $rowCount) {
    fwrite(STDERR, "Postflight failed: quotation row count changed from {$rowCount} to {$afterCount}. Inspect the database before continuing.\n");
    exit(4);
}
if (!quotationUniqueIndexDetails($conn)) {
    fwrite(STDERR, "Postflight failed: unique quotation number index is missing.\n");
    exit(4);
}
echo "Company-scoped quotation number uniqueness is enforced. Existing rows are unchanged ({$afterCount}).\n";
