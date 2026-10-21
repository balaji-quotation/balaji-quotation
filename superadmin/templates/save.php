<?php
declare(strict_types=1);
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: ../../login.php'); exit; }
require_once __DIR__ . '/../../includes/permissions.php';
erpRequire('superadmin.templates');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/quotation_template_runtime.php';
function templateFail(string $message): never {
    $_SESSION['template_error'] = $message;
    $id = (int)($_POST['template_id'] ?? 0);
    header('Location: edit.php' . ($id > 0 ? '?id=' . $id : ''));
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
$token = (string)($_POST['csrf_token'] ?? '');
if (empty($_SESSION['csrf_token']) || $token === '' || !hash_equals($_SESSION['csrf_token'], $token)) templateFail('Request expired. Reload and try again.');
$templateId = filter_var($_POST['template_id'] ?? 0, FILTER_VALIDATE_INT);
$templateId = $templateId ? (int)$templateId : 0;
$name = mb_substr(trim((string)($_POST['template_name'] ?? '')), 0, 150, 'UTF-8');
$description = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 2000, 'UTF-8');
$isActive = (int)($_POST['is_active'] ?? 0) === 1 ? 1 : 0;
$mode = (string)($_POST['calculation_mode'] ?? 'standard');
$formula = mb_substr(trim((string)($_POST['calculation_formula'] ?? '')), 0, 500, 'UTF-8');
$gstRate = filter_var($_POST['gst_rate'] ?? 0, FILTER_VALIDATE_FLOAT);
$gstEnabled = (int)($_POST['gst_enabled'] ?? 0) === 1;
$layout = (string)($_POST['print_layout'] ?? '');
$decimalPlaces = filter_var($_POST['decimal_places'] ?? 2, FILTER_VALIDATE_INT);
$roundingMode = (string)($_POST['rounding_mode'] ?? 'half_up');
if ($name === '' || !in_array($mode, ['standard','custom'], true) || $gstRate === false || $gstRate < 0 || $gstRate > 100 || !in_array($layout, ['Classic','Compact','Minimal','Modern'], true) || $decimalPlaces === false || $decimalPlaces < 0 || $decimalPlaces > 4 || !in_array($roundingMode, ['half_up','half_even','ceil','floor'], true)) templateFail('Check the template name and calculation, rounding, GST, and print settings.');
if ($mode === 'custom' && $formula === '') templateFail('Enter a formula when custom calculation is selected.');

$columns = json_decode((string)($_POST['columns_json'] ?? ''), true);
$commercial = json_decode((string)($_POST['commercial_terms_json'] ?? ''), true);
$general = json_decode((string)($_POST['general_terms_json'] ?? ''), true);
if (!is_array($columns) || count($columns) < 1 || count($columns) > 20 || !is_array($commercial) || !array_is_list($commercial) || !is_array($general) || !array_is_list($general)) templateFail('Columns and terms must be valid JSON arrays.');
$cleanColumns = [];
foreach ($columns as $column) {
    if (!is_array($column)) templateFail('Each column must be an object.');
    $label = mb_substr(trim((string)($column['label'] ?? '')), 0, 100, 'UTF-8');
    $unit = mb_substr(trim((string)($column['unit'] ?? '')), 0, 30, 'UTF-8');
    $type = (string)($column['type'] ?? 'text');
    $align = (string)($column['align'] ?? 'left');
    if ($label === '' || !in_array($type, ['text','number','currency','textarea'], true) || !in_array($align, ['left','center','right'], true)) templateFail('Check each column label, type, and alignment.');
    $cleanColumns[] = ['label'=>$label,'unit'=>$unit,'type'=>$type,'align'=>$align];
}
$cleanCommercial = [];
foreach ($commercial as $term) {
    if (!is_array($term)) templateFail('Each commercial term must be an object with particular and value.');
    $particular = mb_substr(trim((string)($term['particular'] ?? '')), 0, 150, 'UTF-8');
    $value = mb_substr(trim((string)($term['value'] ?? '')), 0, 2000, 'UTF-8');
    if ($particular !== '' || $value !== '') $cleanCommercial[] = ['particular'=>$particular,'value'=>$value];
}
$cleanGeneral = [];
foreach ($general as $term) {
    if (!is_string($term)) templateFail('General terms must be text entries.');
    $term = mb_substr(trim($term), 0, 3000, 'UTF-8');
    if ($term !== '') $cleanGeneral[] = $term;
}
if (count($cleanGeneral) > 50 || count($cleanCommercial) > 50) templateFail('A template may contain up to 50 default terms of each type.');
if ($mode === 'custom') {
    $sample = quotationTemplateEvaluateFormula($formula, ['qty'=>2.0,'rate'=>3.0,'amount'=>6.0]);
    if ($sample === null) templateFail('Formula syntax is invalid. Use qty, rate, amount, numbers, + - * /, and parentheses.');
    $roles = [];
    foreach ($cleanColumns as $column) {
        $role = quotationTemplateColumnRole($column);
        if ($role !== null) $roles[$role] = true;
    }
    if (preg_match('/\\b(qty|quantity)\\b/i', $formula) && empty($roles['qty'])) templateFail('Add a Qty or Quantity column for this formula.');
    if (preg_match('/\\b(rate|price)\\b/i', $formula) && empty($roles['rate'])) templateFail('Add a Rate or Price column for this formula.');
    if (preg_match('/\\bamount\\b/i', $formula) && empty($roles['amount']) && (empty($roles['qty']) || empty($roles['rate']))) templateFail('Add an Amount column or both Qty and Rate columns for this formula.');
}
$config = [
    'columns'=>$cleanColumns,
    'commercial_terms'=>$cleanCommercial,
    'general_terms'=>$cleanGeneral,
    'calculation'=>['mode'=>$mode,'formula'=>$formula],
    'rounding'=>['decimal_places'=>(int)$decimalPlaces,'mode'=>$roundingMode],
    'tax'=>['gst_enabled'=>$gstEnabled,'gst_rate'=>round((float)$gstRate, 2)],
    'totals'=>[
        'show_subtotal'=>(int)($_POST['show_subtotal'] ?? 0) === 1,
        'show_tax'=>(int)($_POST['show_tax'] ?? 0) === 1,
        'show_grand_total'=>(int)($_POST['show_grand_total'] ?? 0) === 1
    ],
    'print_layout'=>$layout
];
$configJson = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($configJson === false) templateFail('Template settings could not be encoded.');

try {
    $conn->begin_transaction();
    if ($templateId > 0) {
        $stmt = $conn->prepare("UPDATE quotation_templates SET template_name = ?, description = NULLIF(?, ''), is_active = ? WHERE id = ?");
        $stmt->bind_param('ssii', $name, $description, $isActive, $templateId);
        $stmt->execute(); $stmt->close();
        $check = $conn->prepare('SELECT id FROM quotation_templates WHERE id = ? LIMIT 1');
        $check->bind_param('i', $templateId); $check->execute(); $exists = $check->get_result()->fetch_assoc(); $check->close();
        if (!$exists) throw new RuntimeException('Template not found.');
    } else {
        $key = 'tpl-' . bin2hex(random_bytes(12));
        $stmt = $conn->prepare("INSERT INTO quotation_templates (template_key, template_name, description, is_active, created_by) VALUES (?, ?, NULLIF(?, ''), ?, ?)");
        $adminId = (int)$_SESSION['user_id'];
        $stmt->bind_param('sssii', $key, $name, $description, $isActive, $adminId);
        $stmt->execute(); $templateId = (int)$conn->insert_id; $stmt->close();
    }
    $versionQuery = $conn->prepare('SELECT COALESCE(MAX(version_number), 0) + 1 AS next_version FROM quotation_template_versions WHERE template_id = ?');
    $versionQuery->bind_param('i', $templateId); $versionQuery->execute();
    $nextVersion = (int)($versionQuery->get_result()->fetch_assoc()['next_version'] ?? 1); $versionQuery->close();
    $adminId = (int)$_SESSION['user_id'];
    $versionInsert = $conn->prepare('INSERT INTO quotation_template_versions (template_id, version_number, config_json, created_by) VALUES (?, ?, ?, ?)');
    $versionInsert->bind_param('iisi', $templateId, $nextVersion, $configJson, $adminId);
    $versionInsert->execute(); $versionInsert->close();
    $conn->commit();
} catch (Throwable $error) {
    $conn->rollback();
    error_log('Template save failed: ' . $error->getMessage());
    templateFail('Template could not be saved. Review the inputs and try again.');
}
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
header('Location: index.php?saved=1');
exit;
?>