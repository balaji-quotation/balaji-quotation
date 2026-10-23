<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: ../../login.php'); exit; }
require_once __DIR__ . '/../../includes/permissions.php';
erpRequire('superadmin.users');
require_once __DIR__ . '/../../includes/db.php';
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$id || $id < 1) { header('Location: index.php'); exit; }
$stmt = $conn->prepare("SELECT id, company_id, name, email, role, status, assigned_template_version_id FROM users WHERE id = ? AND company_id IS NOT NULL LIMIT 1");
$stmt->bind_param('i', $id); $stmt->execute(); $user = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$user) { header('Location: index.php'); exit; }
$templateQuery = $conn->prepare("SELECT t.template_name, v.id AS version_id, v.version_number, v.config_json FROM quotation_templates t JOIN quotation_template_versions v ON v.template_id=t.id WHERE t.company_id = ? AND ((t.is_active=1 AND v.version_number=(SELECT MAX(v2.version_number) FROM quotation_template_versions v2 WHERE v2.template_id=t.id)) OR v.id = ?) ORDER BY t.template_name, v.version_number DESC");
$assignedTemplateVersionId = (int)($user['assigned_template_version_id'] ?? 0);
$templateQuery->bind_param('ii', $user['company_id'], $assignedTemplateVersionId);
$templateQuery->execute();
$templates = $templateQuery->get_result();
$templateQuery->close();
require_once __DIR__ . '/../../includes/user_permissions.php';
$permissionSelection = erpUserPermissionSelection($conn, (int)$user['id'], (string)$user['role']);
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
include __DIR__ . '/../../includes/header.php'; include __DIR__ . '/../../includes/superadmin_sidebar.php';
function adminEditEscape($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$error = $_SESSION['admin_user_error'] ?? ''; unset($_SESSION['admin_user_error']);
?>
<div class="content-page"><div class="content"><div class="container-fluid">
    <div class="page-title-box"><h4 class="page-title">Edit Company User</h4></div>
    <?php if ($error !== '') { ?><div class="alert alert-danger"><?= adminEditEscape($error) ?></div><?php } ?>
    <form method="post" action="update.php" autocomplete="off" id="userForm"><input type="hidden" name="csrf_token" value="<?= adminEditEscape($_SESSION['csrf_token']) ?>"><input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
        <div class="card"><div class="card-body"><div class="row">
            <div class="col-md-6 mb-3"><label class="form-label">Name *</label><input class="form-control" name="name" required maxlength="100" value="<?= adminEditEscape($user['name']) ?>"></div>
            <div class="col-md-6 mb-3"><label class="form-label">Login email *</label><input class="form-control" type="email" name="email" required maxlength="100" value="<?= adminEditEscape($user['email']) ?>"></div>
            <div class="col-md-6 mb-3"><label class="form-label">Role</label><select class="form-select" name="role"><?php foreach (['Administrator','Manager','Production','Quality','Dispatch','Accounts','User'] as $role) { ?><option value="<?= $role ?>" <?= $user['role'] === $role ? 'selected' : '' ?>><?= $role ?></option><?php } ?></select></div>
            <div class="col-md-6 mb-3"><label class="form-label">Status</label><select class="form-select" name="status"><?php foreach (['Active','Inactive'] as $status) { ?><option value="<?= $status ?>" <?= $user['status'] === $status ? 'selected' : '' ?>><?= $status ?></option><?php } ?></select></div>
            <div class="col-12 mb-3"><label class="form-label">Assigned template *</label><select class="form-select" name="template_version_id" id="template_version_id" required><?php while ($template = $templates->fetch_assoc()) { $fieldConfig = json_decode((string)$template['config_json'], true); $fieldLabels = array_values(array_filter(array_map(static fn($field) => (string)($field['label'] ?? ''), is_array($fieldConfig['columns'] ?? null) ? $fieldConfig['columns'] : []))); ?><option value="<?= (int)$template['version_id'] ?>" data-config="<?= adminEditEscape((string)$template['config_json']) ?>" <?= (int)$user['assigned_template_version_id'] === (int)$template['version_id'] ? 'selected' : '' ?>><?= adminEditEscape($template['template_name']) ?> · v<?= (int)$template['version_number'] ?></option><?php } ?></select><small class="text-muted">Choose the user's table fields and calculation roles below. Changes apply to this user's new quotations.</small><div class="small text-muted mt-1">Set Quantity and Rate roles for automatic calculation. Use Auto Amount as both the input type and calculation role for a calculated amount column.</div><div id="quotationFieldBuilder" class="vstack gap-2 mt-2"></div><div id="quotationFieldError" class="small text-danger mt-1"></div><button class="btn btn-outline-primary btn-sm mt-2" type="button" id="addQuotationField">+ Add quotation field</button><textarea class="d-none" name="quotation_columns_json" id="quotationColumnsJson"></textarea></div>
            <div class="col-md-6 mb-3"><label class="form-label">Quotation print design *</label><select class="form-select" name="print_layout" id="print_layout" required><option value="Classic">Classic</option><option value="Compact">Compact</option><option value="Minimal">Minimal</option><option value="Modern">Modern</option></select><small class="text-muted">This design applies to new quotations; earlier quotations keep their saved design.</small></div>
            <div class="col-md-6 mb-3"><label class="form-label">Reset password</label><input class="form-control" type="password" name="password" minlength="12" autocomplete="new-password"><small class="text-muted">Leave blank to keep the current password.</small></div>
        </div></div></div>
        <div class="card mt-3"><div class="card-header"><h5 class="mb-0">Modules and actions for this user</h5><small class="text-muted">Unchecked actions are blocked, including direct page access. Quotation fields come from the assigned template above.</small></div><div class="card-body"><div class="row">
            <?php foreach (erpUserPermissionOptions() as $module => $permissions) { ?>
                <div class="col-lg-4 col-md-6 mb-3"><div class="border rounded p-3 h-100"><strong><?= adminEditEscape($module) ?></strong>
                    <?php foreach ($permissions as $key => $label) { $permissionId = 'permission_' . str_replace('.', '_', $key); ?>
                        <div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="<?= adminEditEscape($permissionId) ?>" name="permissions[<?= adminEditEscape($key) ?>]" value="1" <?= !empty($permissionSelection[$key]) ? 'checked' : '' ?>><label class="form-check-label" for="<?= adminEditEscape($permissionId) ?>"><?= adminEditEscape($label) ?></label></div>
                    <?php } ?>
                </div></div>
            <?php } ?>
        </div></div></div>
        <button class="btn btn-primary" type="submit">Save user</button> <a class="btn btn-light" href="index.php">Cancel</a>
    </form>
</div></div></div>
<script>
(function () {
    var companySelect = null;
    var templateSelect = document.getElementById('template_version_id');
    var fieldBuilder = document.getElementById('quotationFieldBuilder');
    var fieldJson = document.getElementById('quotationColumnsJson');
    var layoutSelect = document.getElementById('print_layout');
    var form = document.getElementById('userForm');
    var errorBox = document.getElementById('quotationFieldError');
    if (!templateSelect || !fieldBuilder || !fieldJson || !form) return;


    function makeControl(className, labelText, value, options) {
        var wrapper = document.createElement('div');
        wrapper.className = className;
        var label = document.createElement('label');
        label.className = 'form-label small mb-1';
        label.textContent = labelText;
        wrapper.appendChild(label);
        var control;
        if (options) {
            control = document.createElement('select');
            control.className = 'form-select form-select-sm';
            options.forEach(function (option) {
                var item = document.createElement('option');
                item.value = option.value;
                item.textContent = option.label;
                control.appendChild(item);
            });
        } else {
            control = document.createElement('input');
            control.type = 'text';
            control.maxLength = labelText === 'Field name' ? 100 : 30;
            control.className = 'form-control form-control-sm';
        }
        control.value = value || '';
        wrapper.appendChild(control);
        return {wrapper: wrapper, control: control};
    }

    function addFieldRow(column) {
        column = column || {};
        var row = document.createElement('div');
        row.className = 'row g-2 align-items-end border rounded p-2 mx-0';
        row.setAttribute('data-field-row', '1');
        var name = makeControl('col-xl-3 col-md-6', 'Field name', column.label, null);
        name.control.classList.add('js-field-label');
        name.control.required = true;
        var unit = makeControl('col-xl-2 col-md-6', 'Unit (optional)', column.unit, null);
        unit.control.classList.add('js-field-unit');
        var type = makeControl('col-xl-2 col-md-4', 'Input type', column.type || 'text', [
            {value:'text',label:'Text'}, {value:'number',label:'Number'},
            {value:'currency',label:'Price / Currency'}, {value:'textarea',label:'Long text'}, {value:'auto_amount',label:'Auto Amount'}
        ]);
        type.control.classList.add('js-field-type');
        var role = makeControl('col-xl-2 col-md-4', 'Calculation role', column.field_role || 'none', [
            {value:'none',label:'Normal field'}, {value:'qty',label:'Quantity'},
            {value:'rate',label:'Rate'}, {value:'amount',label:'Manual Amount'}, {value:'auto_amount',label:'Auto Amount'}
        ]);
        role.control.classList.add('js-field-role');
        type.control.addEventListener('change', function () { if (this.value === 'auto_amount') role.control.value = 'auto_amount'; else if (role.control.value === 'auto_amount') role.control.value = 'none'; });
        role.control.addEventListener('change', function () { if (this.value === 'auto_amount') type.control.value = 'auto_amount'; else if (type.control.value === 'auto_amount') type.control.value = 'number'; });
        var align = makeControl('col-xl-1 col-md-4', 'Align', column.align || 'left', [
            {value:'left',label:'Left'}, {value:'center',label:'Center'}, {value:'right',label:'Right'}
        ]);
        align.control.classList.add('js-field-align');
        row.appendChild(name.wrapper);
        row.appendChild(unit.wrapper);
        row.appendChild(type.wrapper);
        row.appendChild(role.wrapper);
        row.appendChild(align.wrapper);

        var actions = document.createElement('div');
        actions.className = 'col-xl-2 col-md-4 d-flex gap-1';
        function actionButton(label, title, handler) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-outline-secondary btn-sm';
            button.textContent = label;
            button.title = title;
            button.addEventListener('click', handler);
            actions.appendChild(button);
            return button;
        }
        actionButton('↑', 'Move field up', function () {
            var previous = row.previousElementSibling;
            if (previous) fieldBuilder.insertBefore(row, previous);
            refreshMoves();
        });
        actionButton('↓', 'Move field down', function () {
            var next = row.nextElementSibling;
            if (next) fieldBuilder.insertBefore(next, row);
            refreshMoves();
        });
        actionButton('Remove', 'Remove field', function () {
            row.remove();
            refreshMoves();
        });
        row.appendChild(actions);
        fieldBuilder.appendChild(row);
        refreshMoves();
    }

    function refreshMoves() {
        var rows = Array.prototype.slice.call(fieldBuilder.querySelectorAll('[data-field-row]'));
        rows.forEach(function (row, index) {
            var buttons = row.querySelectorAll('button');
            buttons[0].disabled = index === 0;
            buttons[1].disabled = index === rows.length - 1;
        });
    }

    function renderTemplateSettings() {
        var selected = templateSelect.options[templateSelect.selectedIndex];
        fieldBuilder.replaceChildren();
        if (!selected || !selected.value) return;
        try {
            var config = JSON.parse(selected.getAttribute('data-config') || '{}');
            var columns = Array.isArray(config.columns) ? config.columns : [];
            columns.forEach(addFieldRow);
            if (layoutSelect && ['Classic','Compact','Minimal','Modern'].includes(config.print_layout)) {
                layoutSelect.value = config.print_layout;
            }
        } catch (e) {
            fieldBuilder.textContent = 'Template fields could not be loaded.';
        }
    }

    document.getElementById('addQuotationField').addEventListener('click', function () {
        if (fieldBuilder.querySelectorAll('[data-field-row]').length >= 20) {
            errorBox.textContent = 'You can add up to 20 quotation table fields.';
            return;
        }
        errorBox.textContent = '';
        addFieldRow({label:'', unit:'', type:'text', field_role:'none', align:'left'});
        fieldBuilder.lastElementChild.querySelector('.js-field-label').focus();
    });

    form.addEventListener('submit', function (event) {
        var rows = Array.prototype.slice.call(fieldBuilder.querySelectorAll('[data-field-row]'));
        var columns = rows.map(function (row) {
            return {
                label: row.querySelector('.js-field-label').value.trim(),
                unit: row.querySelector('.js-field-unit').value.trim(),
                type: row.querySelector('.js-field-type').value,
                field_role: row.querySelector('.js-field-role').value,
                align: row.querySelector('.js-field-align').value
            };
        });
        if (!columns.length || columns.some(function (column) { return !column.label; })) {
            event.preventDefault();
            errorBox.textContent = 'Add at least one field and give every field a name.';
            return;
        }
        fieldJson.value = JSON.stringify(columns);
    });

    templateSelect.addEventListener('change', renderTemplateSettings);
    renderTemplateSettings();
})();
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
