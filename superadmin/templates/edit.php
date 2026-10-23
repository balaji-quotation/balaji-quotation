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
        ['label'=>'Qty / EAU','unit'=>'','type'=>'number','align'=>'right','field_role'=>'qty'],
        ['label'=>'Rate','unit'=>'INR/PCS','type'=>'currency','align'=>'right','field_role'=>'rate']
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
    <form method="post" action="save.php" id="templateForm">
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
            <div class="col-12 mb-3">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <label class="form-label mb-0">Quotation item fields</label>
                    <button class="btn btn-outline-primary btn-sm" type="button" id="addTemplateColumn">+ Add Field</button>
                </div>
                <p class="small text-muted mt-1 mb-2">Set the columns this business needs, for example Description, Quantity, Unit Price and Total. These fields appear in quotations assigned to this template.</p>
                <div id="templateColumnBuilder" class="vstack gap-2"></div>
                <textarea class="d-none" name="columns_json" id="columnsJson" required><?= templateEditEscape(json_encode($config['columns'], JSON_UNESCAPED_UNICODE)) ?></textarea>
                <div id="columnBuilderError" class="small text-danger mt-1"></div>
                <small class="text-muted">Set Quantity and Rate roles for automatic calculations. Add an Auto Amount field to show each calculated line total. Existing quotations keep their saved template version.</small>
            </div>
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
<script>
document.addEventListener('DOMContentLoaded', function () {
    const builder = document.getElementById('templateColumnBuilder');
    const addButton = document.getElementById('addTemplateColumn');
    const jsonField = document.getElementById('columnsJson');
    const form = document.getElementById('templateForm');
    const error = document.getElementById('columnBuilderError');
    const initial = <?= json_encode(array_values($config['columns'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    let columns = Array.isArray(initial) ? initial.map(function (column) {
        return {
            label: String(column.label || ''),
            unit: String(column.unit || ''),
            type: ['text', 'number', 'currency', 'textarea', 'auto_amount'].includes(column.type) ? column.type : 'text',
            field_role: ['none', 'qty', 'rate', 'amount', 'auto_amount'].includes(column.field_role) ? column.field_role : 'none',
            align: ['left', 'center', 'right'].includes(column.align) ? column.align : 'left'
        };
    }) : [];

    function render() {
        builder.replaceChildren();
        columns.forEach(function (column, index) {
            const row = document.createElement('div');
            row.className = 'row g-2 align-items-end border rounded p-2 mx-0';
            row.innerHTML =
                '<div class="col-lg-4 col-md-12"><label class="form-label small">Field name</label><input class="form-control form-control-sm js-field-label" maxlength="100" required></div>' +
                '<div class="col-lg-2 col-md-4"><label class="form-label small">Unit (optional)</label><input class="form-control form-control-sm js-field-unit" maxlength="30" placeholder="kg, PCS, ₹/PCS"></div>' +
                '<div class="col-lg-2 col-md-3"><label class="form-label small">Input type</label><select class="form-select form-select-sm js-field-type"><option value="text">Text</option><option value="number">Number</option><option value="currency">Price / Currency</option><option value="textarea">Long text</option><option value="auto_amount">Auto Amount</option></select></div>' +
                '<div class="col-lg-2 col-md-3"><label class="form-label small">Calculation role</label><select class="form-select form-select-sm js-field-role"><option value="none">Normal field</option><option value="qty">Quantity</option><option value="rate">Rate</option><option value="amount">Manual Amount</option><option value="auto_amount">Auto Amount</option></select></div>' +
                '<div class="col-lg-2 col-md-3"><label class="form-label small">Alignment</label><select class="form-select form-select-sm js-field-align"><option value="left">Left</option><option value="center">Center</option><option value="right">Right</option></select></div>' +
                '<div class="col-lg-2 col-md-2"><button type="button" class="btn btn-outline-danger btn-sm w-100 js-remove-field">Remove</button></div>';
            row.querySelector('.js-field-label').value = column.label;
            row.querySelector('.js-field-unit').value = column.unit;
            row.querySelector('.js-field-type').value = column.type;
            row.querySelector('.js-field-role').value = column.field_role;
            row.querySelector('.js-field-type').addEventListener('change', function () { if (this.value === 'auto_amount') row.querySelector('.js-field-role').value = 'auto_amount'; else if (row.querySelector('.js-field-role').value === 'auto_amount') row.querySelector('.js-field-role').value = 'none'; });
            row.querySelector('.js-field-role').addEventListener('change', function () { const type = row.querySelector('.js-field-type'); if (this.value === 'auto_amount') type.value = 'auto_amount'; else if (type.value === 'auto_amount') type.value = 'number'; });
            row.querySelector('.js-field-align').value = column.align;
            row.querySelector('.js-remove-field').addEventListener('click', function () {
                columns.splice(index, 1);
                render();
            });
            builder.appendChild(row);
        });
    }

    addButton.addEventListener('click', function () {
        if (columns.length >= 20) {
            error.textContent = 'A quotation template can have up to 20 item fields.';
            return;
        }
        error.textContent = '';
        columns.push({label: '', unit: '', type: 'text', field_role: 'none', align: 'left'});
        render();
        builder.lastElementChild.querySelector('.js-field-label').focus();
    });

    form.addEventListener('submit', function (event) {
        error.textContent = '';
        const rows = Array.from(builder.children);
        const values = rows.map(function (row) {
            return {
                label: row.querySelector('.js-field-label').value.trim(),
                unit: row.querySelector('.js-field-unit').value.trim(),
                type: row.querySelector('.js-field-type').value,
                field_role: row.querySelector('.js-field-role').value,
                align: row.querySelector('.js-field-align').value
            };
        });
        if (!values.length || values.some(function (field) { return field.label === ''; })) {
            event.preventDefault();
            error.textContent = 'Add at least one field and give each field a name.';
            return;
        }
        jsonField.value = JSON.stringify(values);
    });

    render();
});
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
