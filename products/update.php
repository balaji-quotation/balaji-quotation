<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/product_master.php';
erpRequireCompanyUser($conn);
erpRequire('products.edit');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) { http_response_code(400); exit('Invalid request.'); }
if (!productMasterSchemaReady($conn)) { http_response_code(503); exit('Product Master database upgrade is required.'); }
$companyId = erpCurrentCompanyId();
$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
$name = trim((string)($_POST['product_name'] ?? ''));
$description = trim((string)($_POST['description'] ?? ''));
$rateInput = trim((string)($_POST['default_rate'] ?? ''));
if ($id <= 0 || $name === '' || mb_strlen($name) > 255 || mb_strlen($description) > 5000 || ($rateInput !== '' && (!is_numeric($rateInput) || (float)$rateInput < 0))) { header('Location: index.php?msg=error'); exit; }
$check = $conn->prepare('SELECT custom_fields_json FROM products WHERE id = ? AND company_id = ? LIMIT 1');
$check->bind_param('ii', $id, $companyId);
$check->execute();
$old = $check->get_result()->fetch_assoc();
$check->close();
if (!$old) { http_response_code(404); exit('Product not found.'); }
$existing = productMasterDecodeValues($old['custom_fields_json'] ?? null);
try { $custom = productMasterNormalizeCustomFields($conn, is_array($_POST['custom_fields'] ?? null) ? $_POST['custom_fields'] : [], $existing); }
catch (InvalidArgumentException $e) { header('Location: edit.php?id=' . $id . '&msg=error'); exit; }
$rate = $rateInput === '' ? 0.0 : (float)$rateInput;
$customJson = json_encode($custom, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$update = $conn->prepare('UPDATE products SET product_name = ?, description = ?, default_rate = ?, custom_fields_json = ? WHERE id = ? AND company_id = ?');
$update->execute([$name, $description, $rate, $customJson, $id, $companyId]);
$update->close();
header('Location: index.php?msg=updated');
exit;
