<?php
declare(strict_types=1);

/**
 * Additive SaaS tenant migration.
 *
 * Safe default is a read-only preflight. Never run against production without
 * explicit owner approval and a separately verified restorable backup.
 *
 * Usage:
 *   php tools/migrate_multicompany.php
 *   php tools/migrate_multicompany.php --apply --backup-verified --maintenance-confirmed
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

$apply = in_array('--apply', $argv, true);
$backupVerified = in_array('--backup-verified', $argv, true);
$maintenanceConfirmed = in_array('--maintenance-confirmed', $argv, true);
$allowedArgs = ['--apply', '--backup-verified', '--maintenance-confirmed'];
foreach (array_slice($argv, 1) as $arg) {
    if (!in_array($arg, $allowedArgs, true)) {
        fwrite(STDERR, "Unknown option: {$arg}\n");
        exit(2);
    }
}
if (($backupVerified || $maintenanceConfirmed) && !$apply) {
    fwrite(STDERR, "Apply-only safety flags require --apply.\\n");
    exit(2);
}
if ($apply && (!$backupVerified || !$maintenanceConfirmed)) {
    fwrite(STDERR, "Refusing to apply: require --backup-verified and --maintenance-confirmed after owner approval, a verified restorable backup, and maintenance mode.\\n");
    exit(2);
}

function migrationTableExists(mysqli $db, string $table): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    return $exists;
}

function migrationColumnExists(mysqli $db, string $table, string $column): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    return $exists;
}

function migrationCount(mysqli $db, string $table): int
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        throw new RuntimeException('Unexpected table identifier.');
    }
    $result = $db->query("SELECT COUNT(*) AS n FROM `{$table}`");
    $row = $result->fetch_assoc();
    return (int)($row['n'] ?? 0);
}

function migrationAddTenantColumn(mysqli $db, string $table): void
{
    if (!migrationColumnExists($db, $table, 'company_id')) {
        $db->query("ALTER TABLE `{$table}` ADD COLUMN company_id INT UNSIGNED NULL");
    }
    $index = 'idx_' . $table . '_company_id';
    $stmt = $db->prepare(
        'SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?'
    );
    $stmt->bind_param('ss', $table, $index);
    $stmt->execute();
    $stmt->store_result();
    $hasIndex = $stmt->num_rows > 0;
    $stmt->close();
    if (!$hasIndex) {
        $db->query("ALTER TABLE `{$table}` ADD INDEX `{$index}` (company_id)");
    }
}

$requiredTables = [
    'users', 'clients', 'products', 'quotations', 'quotation_term_sets',
    'quotation_company_settings', 'activity_log'
];
$missing = [];
foreach ($requiredTables as $table) {
    if (!migrationTableExists($conn, $table)) {
        $missing[] = $table;
    }
}
if ($missing) {
    fwrite(STDERR, 'Preflight failed; required legacy tables are missing: ' . implode(', ', $missing) . "\n");
    exit(3);
}
if (!migrationColumnExists($conn, 'clients', 'alternate_mobile')) {
    echo "Preflight note: clients.alternate_mobile will be added as a nullable field. Existing customer rows are not rewritten.\n";
}

if (!migrationColumnExists($conn, 'quotation_company_settings', 'company_name')) {
    fwrite(STDERR, "Preflight failed; quotation_company_settings.company_name is missing.\n");
    exit(3);
}

$legacyResult = $conn->query(
    'SELECT company_name, company_address, gst_no, phone, email, website FROM quotation_company_settings WHERE id = 1 LIMIT 1'
);
$legacy = $legacyResult->fetch_assoc();
if (!$legacy || trim((string)($legacy['company_name'] ?? '')) === '') {
    fwrite(STDERR, "Preflight failed; no named legacy company settings row (id=1) exists. Resolve the initial-company mapping before migration.\n");
    exit(3);
}

$databaseResult = $conn->query('SELECT DATABASE() AS db_name, VERSION() AS db_version');
$dbInfo = $databaseResult->fetch_assoc();
if (empty($dbInfo['db_name'])) {
    fwrite(STDERR, "Preflight failed; no database is selected.\n");
    exit(3);
}

echo "Multicompany SaaS migration preflight\n";
echo 'Database: ' . $dbInfo['db_name'] . "\n";
echo 'MySQL/MariaDB: ' . $dbInfo['db_version'] . "\n";
echo "Initial company source: quotation_company_settings row 1 (company details are not printed)\n";
echo "Existing tenant mapping: all current rows in the selected tenant tables map to the initial company.\n";
echo "Legacy tables and primary keys will be retained. No quotation rows, IDs, numbers, or revision links are rewritten.\n";
echo "Current database table row counts (metadata only; no row contents):\\n";
$tableList = $conn->query(
    "SELECT table_name FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'
     ORDER BY table_name"
);
while ($tableRow = $tableList->fetch_assoc()) {
    $tableName = (string)$tableRow['table_name'];
    if (!preg_match('/^[A-Za-z0-9_]+$/', $tableName)) {
        throw new RuntimeException('Unexpected table name in metadata.');
    }
    echo '  ' . $tableName . ': ' . migrationCount($conn, $tableName) . "\\n";
}
echo "Planned tenant columns: users, clients, products, quotations, quotation_term_sets, quotation_company_settings, activity_log.\\n";
echo "No application writes may run concurrently with apply; use a maintenance window and verified restorable backup.\\n";
if (!$apply) {
    echo "DRY RUN ONLY. No schema or data changes were made.\n";
    echo "After code review, a successful restored-backup dry run, and explicit owner approval, run with --apply --backup-verified --maintenance-confirmed.\n";
    exit(0);
}

try {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS companies (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_name VARCHAR(255) NOT NULL,
            slug VARCHAR(100) NOT NULL,
            contact_person VARCHAR(150) DEFAULT NULL,
            email VARCHAR(150) DEFAULT NULL,
            mobile VARCHAR(30) DEFAULT NULL,
            alternate_mobile VARCHAR(30) DEFAULT NULL,
            gstin VARCHAR(30) DEFAULT NULL,
            billing_address TEXT DEFAULT NULL,
            city VARCHAR(100) DEFAULT NULL,
            state VARCHAR(100) DEFAULT NULL,
            pincode VARCHAR(20) DEFAULT NULL,
            status ENUM('Active','Inactive','Suspended') NOT NULL DEFAULT 'Active',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_companies_slug (slug),
            KEY idx_companies_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $stmt = $conn->prepare(
        "INSERT INTO companies
            (company_name, slug, billing_address, gstin, mobile, email, status)
         SELECT company_name, 'legacy-company', company_address, gst_no, phone, email, 'Active'
         FROM quotation_company_settings
         WHERE id = 1
         ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)"
    );
    $stmt->execute();
    $stmt->close();

    $conn->query(
        "CREATE TABLE IF NOT EXISTS template_change_requests (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id INT UNSIGNED NOT NULL,
            requested_by INT NOT NULL,
            title VARCHAR(200) NOT NULL,
            details TEXT NOT NULL,
            status ENUM('Pending','In Review','Approved','Rejected','Completed') NOT NULL DEFAULT 'Pending',
            admin_note TEXT DEFAULT NULL,
            reviewed_by INT DEFAULT NULL,
            reviewed_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_template_requests_company (company_id, status, created_at),
            KEY idx_template_requests_status (status),
            CONSTRAINT fk_template_requests_company
                FOREIGN KEY (company_id) REFERENCES companies (id)
                ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    if (!migrationColumnExists($conn, 'clients', 'alternate_mobile')) {
        $conn->query("ALTER TABLE clients ADD COLUMN alternate_mobile VARCHAR(30) DEFAULT NULL");
    }

    foreach ($requiredTables as $table) {
        migrationAddTenantColumn($conn, $table);
    }

    $companyResult = $conn->query(
        "SELECT id FROM companies WHERE slug = 'legacy-company' LIMIT 1"
    );
    $legacyCompanyId = (int)($companyResult->fetch_assoc()['id'] ?? 0);
    if ($legacyCompanyId <= 0) {
        throw new RuntimeException('Initial company row was not created.');
    }

    // MySQL DDL auto-commits. Begin the data-mapping transaction only after all
    // additive DDL has completed so the backfill itself can roll back atomically.
    $conn->begin_transaction();
    $stmt = $conn->prepare(
        "UPDATE users SET company_id = ? WHERE company_id IS NULL"
    );
    $stmt->bind_param('i', $legacyCompanyId);
    $stmt->execute();
    $stmt->close();
    foreach (['clients', 'products', 'quotations', 'quotation_term_sets', 'quotation_company_settings', 'activity_log'] as $table) {
        $stmt = $conn->prepare("UPDATE `{$table}` SET company_id = ? WHERE company_id IS NULL");
        $stmt->bind_param('i', $legacyCompanyId);
        $stmt->execute();
        $stmt->close();
    }

    foreach (array_merge(['users'], array_values(array_diff($requiredTables, ['users']))) as $table) {
        $check = $conn->query("SELECT COUNT(*) AS n FROM `{$table}` WHERE company_id IS NULL");
        $nullOwners = (int)($check->fetch_assoc()['n'] ?? 0);
        if ($nullOwners !== 0) {
            throw new RuntimeException("Tenant backfill incomplete for table {$table}.");
        }
    }

    $conn->commit();
} catch (Throwable $error) {
    $conn->rollback();
    fwrite(STDERR, "Migration stopped with an error. MySQL may commit DDL implicitly; rerun the read-only preflight and inspect the schema before retrying. Error: " . $error->getMessage() . "\n");
    exit(4);
}

echo "Migration statements completed. Run the documented before/after integrity checks before enabling the SaaS routes.\n";
