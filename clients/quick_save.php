<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');

function quickClientReply(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    quickClientReply(401, ['success' => false, 'message' => 'Please sign in again.']);
}
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/permissions.php';
erpRequireCompanyUser($conn);
erpRequire('clients.add');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    quickClientReply(405, ['success' => false, 'message' => 'Invalid request method.']);
}
$token = (string)($_POST['csrf_token'] ?? '');
if (empty($_SESSION['csrf_token']) || $token === '' || !hash_equals($_SESSION['csrf_token'], $token)) {
    quickClientReply(400, ['success' => false, 'message' => 'Request expired. Reload the quotation page.']);
}

$companyName = trim((string)($_POST['company_name'] ?? ''));
$address = trim((string)($_POST['address'] ?? ''));
$mobile = trim((string)($_POST['mobile'] ?? ''));
$alternateMobile = trim((string)($_POST['alternate_mobile'] ?? ''));
$email = strtolower(trim((string)($_POST['email'] ?? '')));
if ($companyName === '' || $address === '' || ($mobile === '' && $alternateMobile === '')) {
    quickClientReply(422, ['success' => false, 'message' => 'Name, billing address, and mobile or alternate mobile are required.']);
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    quickClientReply(422, ['success' => false, 'message' => 'Enter a valid email address.']);
}
$companyId = erpCurrentCompanyId();
$stmt = $conn->prepare(
    "INSERT INTO clients (company_id, company_name, email, mobile, alternate_mobile, address, status)
     VALUES (?, ?, NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), ?, 'Active')"
);
$stmt->bind_param('isssss', $companyId, $companyName, $email, $mobile, $alternateMobile, $address);
$stmt->execute();
$id = (int)$conn->insert_id;
$stmt->close();
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
quickClientReply(201, ['success' => true, 'id' => $id, 'company_name' => $companyName]);
?>