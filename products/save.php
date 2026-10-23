<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/product_master.php';
erpRequireCompanyUser($conn);
erpRequire('products.add');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) { http_response_code(400); exit('Invalid request.'); }
if (!productMasterSchemaReady($conn)) { http_response_code(503); exit('Product Master database upgrade is required.'); }
$companyId = erpCurrentCompanyId();
$name = trim((string)($_POST['product_name'] ?? ''));
$description = trim((string)($_POST['description'] ?? ''));
$rateInput = trim((string)($_POST['default_rate'] ?? ''));
if ($name === '' || mb_strlen($name) > 255 || mb_strlen($description) > 5000 || ($rateInput !== '' && (!is_numeric($rateInput) || (float)$rateInput < 0))) { header('Location: add.php?msg=error'); exit; }
try { $custom = productMasterNormalizeCustomFields($conn, is_array($_POST['custom_fields'] ?? null) ? $_POST['custom_fields'] : []); }
catch (InvalidArgumentException $e) { header('Location: add.php?msg=error'); exit; }
$rate = $rateInput === '' ? 0.0 : (float)$rateInput;
$code = 'PM-' . date('ymdHis') . '-' . strtoupper(bin2hex(random_bytes(6)));
$customJson = json_encode($custom, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$stmt = $conn->prepare("INSERT INTO products (company_id, product_code, product_name, default_rate, part_number, drawing_number, revision_no, pricing_type, unit, weight_per_piece, material, quantity, priority, status, drawing_file, product_image, remarks, description, custom_fields_json) VALUES (?, ?, ?, ?, '', '', '00', 'PCS', 'PCS', 0, '', 0, 'Low', 'Received', '', '', '', ?, ?)");
$stmt->bind_param('issdss', $companyId, $code, $name, $rate, $description, $customJson);
$stmt->execute();
$stmt->close();
header('Location: index.php?msg=added');
exit;
