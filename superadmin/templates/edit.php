<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: ../../login.php'); exit; }
require_once __DIR__ . '/../../includes/permissions.php';
erpRequire('superadmin.templates');
require_once __DIR__ . '/../../includes/db.php';
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$template = null;
$companies = $conn->query('SELECT id, company_name FROM companies ORDER BY company_name');
$config = [
    'columns' => [
        ['label'=>'Part No. / Drawing No.','unit'=>'','type'=>'text','align'=>'left'],
        ['label'=>'Description','unit'=>'','type'=>'text','align'=>'left'],
        ['label'=>'Material / Grade','unit'=>'','type'=>'text','align'=>'left'],
        ['label'=>'Qty / EAU','unit'=>'','type'=>'number','align'=>'right'],
        ['label'=>'Rate','unit'=>'INR/PCS','type'=>'currency','align'=>'right']
    ],
    'commercial_terms' => [],
    'general_terms' => [],
    'calculation' => ['mode'=>'standard','formula'=>''],
    'rounding' => ['decimal_places'=>2,'mode'=>'half_up'],
    'tax' => ['gst_enabled'=>false,'gst_rate'=>0],
    'totals' => ['show_line_amount'=>true,'show_subtotal'=>true,'show_tax'=>false,'show_grand_total'=>true],
    'print_layout' => 'Classic'
];
if ($id) {
    $stmt = $conn->prepare('SELECT t.id, t.company_id, c.company_name, t.template_name, t.description, t.is_active FROM quotation_templates t JOIN companies c ON c.id = t.company_id WHERE t.id = ? LIMIT 1');
    $stmt->bind_param('i', $id); $stmt->execute(); $template = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$template) { header('Location: index.php'); exit; }
    $stmt = $conn->prepare('SELECT config_json, version_number FROM quotation_template_versions WHERE template_id = ? ORDER BY version_number DESC LIMIT 1');
    $stmt->bind_param('i', $id); $stmt->execute(); $version = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if ($version) {
        $decoded = json_decode($version['config_json'], true);
        if (is_array($decoded)) $config = array_merge($config, $decoded);
    }
}
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/superadmin_sidebar.php';
function templateEditEscape($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$err = $_SESSION['template_error'] ?? ''; unset($_SESSION['template_error']);
?>
<div class="content-page"><div class="content"><div class="container-fluid">
    <div class="page-title-box"><h4 class="page-title"><?= $template ? 'Edit quotation template' : 'Create quotation template' ?></h4></div>
    <?php if ($err !== '') { ?><div class="alert alert-danger"><?= templateEditEscape($err) ?></div><?php } ?>
    <form method="post" action="save.php">
        <input type="hidden" name="csrf_token" value="<?= templateEditEscape($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="template_id" value="<?= (int)($template['id'] ?? 0) ?>">
        <?php if ($template) { ?>
            <div class="card mb-3"><div class="card-body">
                <label class="form-label">Company</label>
                <input class="form-control" value="<?= templateEditEscape($template['company_name']) ?>" readonly>
                <small class="text-muted">A template stays with its company after creation.</small>
            </div></div>
        <?php } else { ?>
            <div class="card mb-3"><div class="card-body">
                <label class="form-label" for="template_company_id">Company *</label>
                <select class="form-select" id="template_company_id" name="company_id" required>
                    <option value="">Choose company</option>
                    <?php while ($company = $companies->fetch_assoc()) { ?>
                        <option value="<?= (int)$company['id'] ?>"><?= templateEditEscape($company['company_name']) ?></option>
                    <?php } ?>
                </select>
            </div></div>
        <?php } ?>
        <div class="card"><div class="card-body"><div class="row">
            <div class="col-md-6 mb-3"><label class="form-label">Template name *</label><input class="form-control" name="template_name" maxlength="150" required value="<?= templateEditEscape($template['template_name'] ?? '') ?>"></div>
            <div class="col-md-6 mb-3"><label class="form-label">Status</label><select name="is_active" class="form-select"><option value="1" <?= !isset($template['is_active']) || $template['is_active'] ? 'selected' : '' ?>>Active</option><option value="0" <?= isset($template['is_active']) && !$template['is_active'] ? 'selected' : '' ?>>Inactive</option></select></div>
            <div class="col-12 mb-3"><label class="form-label">Description</label><input class="form-control" name="description" maxlength="2000" value="<?= templateEditEscape($template['description'] ?? '') ?>"></div>
            <div class="col-12 mb-3"><label class="form-label">Quotation columns (JSON array)</label><textarea class="form-control font-monospace" name="columns_json" rows="8" required><?= templateEditEscape(json_encode($config['columns'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></textarea><small>Each item: label, unit, type (text/number/currency/textarea), align (left/center/right). Standard totals recognize Qty/Quantity and Rate/Price labels, or an Amount label.</small></div>
            <div class="col-md-6 mb-3"><label class="form-label">Default commercial terms (JSON array)</label><textarea class="form-control font-monospace" name="commercial_terms_json" rows="8" required><?= templateEditEscape(json_encode($config['commercial_terms'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></textarea></div>
            <div class="col-md-6 mb-3"><label class="form-label">Default general terms (JSON array of strings)</label><textarea class="form-control font-monospace" name="general_terms_json" rows="8" required><?= templateEditEscape(json_encode($config['general_terms'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></textarea></div>
            <div class="col-md-3 mb-3"><label class="form-label">Calculation mode</label><select name="calculation_mode" class="form-select"><option value="standard" <?= ($config['calculation']['mode'] ?? '') === 'standard' ? 'selected' : '' ?>>Standard</option><option value="custom" <?= ($config['calculation']['mode'] ?? '') === 'custom' ? 'selected' : '' ?>>Custom formula</option></select></div>
            <div class="col-md-9 mb-3"><label class="form-label">Custom formula</label><input class="form-control" name="calculation_formula" maxlength="500" value="<?= templateEditEscape($config['calculation']['formula'] ?? '') ?>" placeholder="qty * rate"><small>Use qty, rate, amount, numbers, + - * /, and parentheses.</small></div>
            <div class="col-md-3 mb-3"><label class="form-label">Decimal places</label><select name="decimal_places" class="form-select"><?php foreach ([0,1,2,3,4] as $places) { ?><option value="<?= $places ?>" <?= (int)($config['rounding']['decimal_places'] ?? 2) === $places ? 'selected' : '' ?>><?= $places ?></option><?php } ?></select></div>
            <div class="col-md-3 mb-3"><label class="form-label">Rounding</label><select name="rounding_mode" class="form-select"><?php foreach (['half_up'=>'Half up','half_even'=>'Half even','ceil'=>'Round up','floor'=>'Round down'] as $key=>$label) { ?><option value="<?= $key ?>" <?= ($config['rounding']['mode'] ?? 'half_up') === $key ? 'selected' : '' ?>><?= templateEditEscape($label) ?></option><?php } ?></select></div>
            <div class="col-md-3 mb-3"><label class="form-label">GST rate (%)</label><input class="form-control" type="number" min="0" max="100" step="0.01" name="gst_rate" value="<?= templateEditEscape($config['tax']['gst_rate'] ?? 0) ?>"></div>
            <div class="col-md-3 mb-3"><label class="form-label">GST</label><select name="gst_enabled" class="form-select"><option value="0" <?= empty($config['tax']['gst_enabled']) ? 'selected' : '' ?>>Off</option><option value="1" <?= !empty($config['tax']['gst_enabled']) ? 'selected' : '' ?>>On</option></select></div>
            <div class="col-md-2 mb-3"><label class="form-label">Show line amount</label><select name="show_line_amount" class="form-select"><option value="1" <?= !empty($config['totals']['show_line_amount'] ?? true) ? 'selected' : '' ?>>Yes</option><option value="0" <?= array_key_exists('show_line_amount', $config['totals']) && empty($config['totals']['show_line_amount']) ? 'selected' : '' ?>>No</option></select></div>
            <div class="col-md-2 mb-3"><label class="form-label">Show subtotal</label><select name="show_subtotal" class="form-select"><option value="1" <?= !empty($config['totals']['show_subtotal']) ? 'selected' : '' ?>>Yes</option><option value="0" <?= empty($config['totals']['show_subtotal']) ? 'selected' : '' ?>>No</option></select></div>
            <div class="col-md-2 mb-3"><label class="form-label">Show tax amount</label><select name="show_tax" class="form-select"><option value="1" <?= !empty($config['totals']['show_tax'] ?? $config['totals']['show_total'] ?? false) ? 'selected' : '' ?>>Yes</option><option value="0" <?= empty($config['totals']['show_tax'] ?? $config['totals']['show_total'] ?? false) ? 'selected' : '' ?>>No</option></select></div>
            <div class="col-md-2 mb-3"><label class="form-label">Show grand total</label><select name="show_grand_total" class="form-select"><option value="1" <?= !empty($config['totals']['show_grand_total']) ? 'selected' : '' ?>>Yes</option><option value="0" <?= empty($config['totals']['show_grand_total']) ? 'selected' : '' ?>>No</option></select></div>
            <div class="col-md-4 mb-3"><label class="form-label">Print layout</label><select name="print_layout" class="form-select"><?php foreach (['Classic','Compact','Minimal','Modern'] as $layout) { ?><option value="<?= $layout ?>" <?= ($config['print_layout'] ?? 'Classic') === $layout ? 'selected' : '' ?>><?= $layout ?></option><?php } ?></select></div>
        </div><p class="text-muted">Saving an existing template creates a new version. Previously issued quotations keep their version snapshot.</p><button class="btn btn-primary" type="submit">Save template version</button> <a class="btn btn-light" href="index.php">Cancel</a></div></div>
    </form>
</div></div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
