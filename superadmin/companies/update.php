<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}
require_once __DIR__ . '/../../includes/permissions.php';
erpRequire('companies.edit');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/quotation_numbering.php';
require_once __DIR__ . '/../../includes/company_settings_sync.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}
$token = (string)($_POST['csrf_token'] ?? '');
if (empty($_SESSION['csrf_token']) || $token === '' || !hash_equals($_SESSION['csrf_token'], $token)) {
    $_SESSION['company_error'] = 'Request expired. Please try again.';
    header('Location: index.php');
    exit;
}
$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
$name = trim((string)($_POST['company_name'] ?? ''));
$contact = trim((string)($_POST['contact_person'] ?? ''));
$email = strtolower(trim((string)($_POST['company_email'] ?? '')));
$mobile = trim((string)($_POST['mobile'] ?? ''));
$alternateMobile = trim((string)($_POST['alternate_mobile'] ?? ''));
$gstin = strtoupper(trim((string)($_POST['gstin'] ?? '')));
$address = trim((string)($_POST['billing_address'] ?? ''));
$city = trim((string)($_POST['city'] ?? ''));
$state = trim((string)($_POST['state'] ?? ''));
$pincode = trim((string)($_POST['pincode'] ?? ''));
$quotationNumberFormat = trim((string)($_POST['quotation_prefix'] ?? ''));
$status = trim((string)($_POST['status'] ?? ''));
$allowedStatus = ['Active', 'Inactive', 'Suspended'];

if (!$id || $id < 1 || $name === '' || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) || !in_array($status, $allowedStatus, true) || !quotationNumberPatternIsValid($quotationNumberFormat)) {
    $_SESSION['company_error'] = 'Check the company name, email, status, and quotation number format.';
    header('Location: ' . ($id ? 'edit.php?id=' . $id : 'index.php'));
    exit;
}
$contact = mb_substr($contact, 0, 150, 'UTF-8');
$name = mb_substr($name, 0, 255, 'UTF-8');
$email = mb_substr($email, 0, 150, 'UTF-8');
$mobile = mb_substr($mobile, 0, 30, 'UTF-8');
$alternateMobile = mb_substr($alternateMobile, 0, 30, 'UTF-8');
$gstin = mb_substr($gstin, 0, 30, 'UTF-8');
$city = mb_substr($city, 0, 100, 'UTF-8');
$state = mb_substr($state, 0, 100, 'UTF-8');
$pincode = mb_substr($pincode, 0, 20, 'UTF-8');
$quotationNumberFormat = mb_substr($quotationNumberFormat, 0, 100, 'UTF-8');

try {
    $conn->begin_transaction();
$stmt = $conn->prepare(
    "UPDATE companies
     SET company_name = ?, contact_person = NULLIF(?, ''), email = NULLIF(?, ''),
         mobile = NULLIF(?, ''), alternate_mobile = NULLIF(?, ''), gstin = NULLIF(?, ''),
         billing_address = NULLIF(?, ''), city = NULLIF(?, ''), state = NULLIF(?, ''),
         pincode = NULLIF(?, ''), status = ?
     WHERE id = ? LIMIT 1"
);
$stmt->bind_param(
    'sssssssssssi',
    $name, $contact, $email, $mobile, $alternateMobile, $gstin,
    $address, $city, $state, $pincode, $status, $id
);
$stmt->execute();
$stmt->close();

    syncQuotationCompanySettings(
        $conn,
        (int)$id,
        $name,
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
    $conn->commit();
} catch (Throwable $error) {
    $conn->rollback();
    error_log('Company update failed: ' . $error->getMessage());
    $_SESSION['company_error'] = 'The company could not be updated. Please review the details and retry.';
    header('Location: edit.php?id=' . (int)$id);
    exit;
}
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
header('Location: index.php?updated=1');
exit;
