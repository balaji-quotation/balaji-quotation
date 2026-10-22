<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}
require_once __DIR__ . '/../../includes/permissions.php';
erpRequire('companies.add');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/quotation_numbering.php';
require_once __DIR__ . '/../../includes/company_settings_sync.php';
require_once __DIR__ . '/../../includes/company_logo_upload.php';

function companyPost(string $key, int $limit = 255): string {
    $value = trim((string)($_POST[$key] ?? ''));
    return mb_substr($value, 0, $limit, 'UTF-8');
}
function companySaveFail(string $message): never {
    $_SESSION['company_error'] = $message;
    header('Location: add.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}
$token = (string)($_POST['csrf_token'] ?? '');
if (empty($_SESSION['csrf_token']) || $token === '' || !hash_equals($_SESSION['csrf_token'], $token)) {
    companySaveFail('Request expired. Please try again.');
}

$companyName = companyPost('company_name');
$contactPerson = companyPost('contact_person', 150);
$email = strtolower(companyPost('company_email', 150));
$mobile = companyPost('mobile', 30);
$alternateMobile = companyPost('alternate_mobile', 30);
$gstin = strtoupper(companyPost('gstin', 30));
$address = companyPost('billing_address');
$city = companyPost('city', 100);
$state = companyPost('state', 100);
$pincode = companyPost('pincode', 20);
$quotationNumberFormat = companyPost('quotation_prefix', 100);
if ($quotationNumberFormat === '') {
    $quotationNumberFormat = 'QTN/{FY}/{SEQ:4}';
}
$adminName = companyPost('admin_name', 100);
$adminEmail = strtolower(companyPost('admin_email', 100));
$adminPassword = (string)($_POST['admin_password'] ?? '');
$adminTemplateVersionId = filter_var($_POST['admin_template_version_id'] ?? null, FILTER_VALIDATE_INT);

if ($companyName === '' || $adminName === '' || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
    companySaveFail('Company name, administrator name, and a valid administrator email are required.');
}
if (!quotationNumberPatternIsValid($quotationNumberFormat)) {
    companySaveFail('Use a valid quotation number pattern with a sequence token, such as QTN/{FY}/{SEQ:4}.');
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    companySaveFail('Enter a valid company email address.');
}
if (strlen($adminPassword) < 12) {
    companySaveFail('Administrator password must be at least 12 characters.');
}

$templateCheck = $conn->prepare(
    "SELECT v.config_json, t.template_name, t.description
     FROM quotation_template_versions v
     JOIN quotation_templates t ON t.id = v.template_id
     WHERE v.id = ? AND t.is_active = 1
       AND v.version_number = (
           SELECT MAX(v2.version_number)
           FROM quotation_template_versions v2
           WHERE v2.template_id = t.id
       )
     LIMIT 1"
);
$templateCheck->bind_param('i', $adminTemplateVersionId);
$templateCheck->execute();
$sourceTemplate = $templateCheck->get_result()->fetch_assoc();
$templateCheck->close();
if (!$sourceTemplate) companySaveFail('Choose the latest version of an active quotation template.');

$check = $conn->prepare('SELECT id FROM users WHERE LOWER(email) = ? LIMIT 1');
$check->bind_param('s', $adminEmail);
$check->execute();
$check->store_result();
$duplicate = $check->num_rows > 0;
$check->close();
if ($duplicate) {
    companySaveFail('That administrator email is already in use.');
}

$hash = password_hash($adminPassword, PASSWORD_DEFAULT);
if ($hash === false) {
    companySaveFail('Could not secure the administrator password.');
}
$slug = 'company-' . bin2hex(random_bytes(8));
try {
    $newLogoPath = storeCompanyLogoUpload($_FILES['company_logo'] ?? []);
} catch (Throwable $error) {
    companySaveFail($error->getMessage());
}
$logoPath = $newLogoPath ?? '';

try {
    $conn->begin_transaction();
    $companyInsert = $conn->prepare(
        "INSERT INTO companies
            (company_name, slug, contact_person, email, mobile, alternate_mobile, gstin, billing_address, city, state, pincode, logo_path, status)
         VALUES (?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), 'Active')"
    );
    $companyInsert->bind_param(
        'ssssssssssss',
        $companyName, $slug, $contactPerson, $email, $mobile, $alternateMobile,
        $gstin, $address, $city, $state, $pincode, $logoPath
    );
    $companyInsert->execute();
    $companyId = (int)$conn->insert_id;
    $companyInsert->close();

    syncQuotationCompanySettings(
        $conn,
        $companyId,
        $companyName,
        $email,
        $mobile,
        $alternateMobile,
        $gstin,
        $address,
        $city,
        $state,
        $pincode,
        $quotationNumberFormat
    );

    // Copy the selected approved template into the new company so template assignments remain tenant-scoped.
    $templateKey = 'tpl-' . bin2hex(random_bytes(12));
    $createdBy = (int)$_SESSION['user_id'];
    $templateInsert = $conn->prepare(
        "INSERT INTO quotation_templates (company_id, template_key, template_name, description, is_active, created_by)
         VALUES (?, ?, ?, NULLIF(?, ''), 1, ?)"
    );
    $templateDescription = (string)($sourceTemplate['description'] ?? '');
    $templateInsert->bind_param('isssi', $companyId, $templateKey, $sourceTemplate['template_name'], $templateDescription, $createdBy);
    $templateInsert->execute();
    $companyTemplateId = (int)$conn->insert_id;
    $templateInsert->close();

    $versionInsert = $conn->prepare(
        'INSERT INTO quotation_template_versions (template_id, version_number, config_json, created_by) VALUES (?, 1, ?, ?)'
    );
    $versionInsert->bind_param('isi', $companyTemplateId, $sourceTemplate['config_json'], $createdBy);
    $versionInsert->execute();
    $companyTemplateVersionId = (int)$conn->insert_id;
    $versionInsert->close();

    $userInsert = $conn->prepare(
        "INSERT INTO users (name, email, password, role, status, company_id, assigned_template_version_id)
         VALUES (?, ?, ?, 'Administrator', 'Active', ?, ?)"
    );
    $userInsert->bind_param('sssii', $adminName, $adminEmail, $hash, $companyId, $companyTemplateVersionId);
    $userInsert->execute();
    $userInsert->close();
    $conn->commit();
} catch (Throwable $error) {
    $conn->rollback();
    deleteCompanyLogo($newLogoPath);
    error_log('Company creation failed: ' . $error->getMessage());
    companySaveFail('The company could not be created. Check that the administrator email is unique and try again.');
}

$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
header('Location: index.php?created=1');
exit;
