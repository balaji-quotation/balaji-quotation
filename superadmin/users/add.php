<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: ../../login.php'); exit; }
require_once __DIR__ . '/../../includes/permissions.php';
erpRequire('superadmin.users');
require_once __DIR__ . '/../../includes/db.php';
$companies = $conn->query("SELECT id, company_name, subscription_plan FROM companies WHERE status = 'Active' ORDER BY company_name");
$companyRows = $companies->fetch_all(MYSQLI_ASSOC);
$templates = $conn->query(
    "SELECT t.company_id, c.company_name, t.template_name, v.id AS version_id, v.version_number, v.config_json
     FROM quotation_templates t
     JOIN companies c ON c.id = t.company_id
     JOIN quotation_template_versions v ON v.template_id = t.id
     WHERE t.is_active = 1
       AND v.version_number = (SELECT MAX(v2.version_number) FROM quotation_template_versions v2 WHERE v2.template_id = t.id)
     ORDER BY c.company_name, t.template_name"
);
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
include __DIR__ . '/../../includes/header.php'; include __DIR__ . '/../../includes/superadmin_sidebar.php';
function adminAddEscape($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$error = $_SESSION['admin_user_error'] ?? ''; unset($_SESSION['admin_user_error']);
?>
<div class="content-page"><div class="content"><div class="container-fluid">
    <div class="page-title-box d-flex justify-content-between align-items-center flex-wrap gap-2"><div><h4 class="page-title">Create Company User</h4><p class="text-muted mb-0">Type the company name here. For a new company, choose a starting template and plan; its profile and trial or paid dates can be completed later.</p></div><a class="btn btn-outline-primary" href="../templates/index.php">Configure Client Quotation Fields</a></div>
    <?php if ($error !== '') { ?><div class="alert alert-danger"><?= adminAddEscape($error) ?></div><?php } ?>
    <form method="post" action="save.php" autocomplete="off" id="userForm"><input type="hidden" name="csrf_token" value="<?= adminAddEscape($_SESSION['csrf_token']) ?>">
        <div class="card"><div class="card-body"><div class="row">
            <div class="col-md-6 mb-3"><label class="form-label" for="company_name">Company name *</label><input class="form-control" type="text" name="company_name" id="company_name" maxlength="255" autocomplete="organization" placeholder="Type the company name" required><small class="text-muted" id="companyNameHelp">Type a company name. If it is new, this form will create its company record.</small></div>
            <div class="col-md-6 mb-3 d-none" id="newCompanyPlanWrap"><label class="form-label" for="new_company_plan">Plan for new company *</label><select class="form-select" name="new_company_plan" id="new_company_plan"><option value="Business">Business — 1 user</option><option value="Premium">Premium — 2 users</option></select><small class="text-muted">Trial or paid dates can be set later in Subscriptions.</small></div>
            <div class="col-12 mb-3"><label class="form-label">Assigned template version *</label><select name="template_version_id" id="template_version_id" class="form-select" required disabled><option value="">Choose company first</option><?php while ($template = $templates->fetch_assoc()) { $fieldConfig = json_decode((string)$template['config_json'], true); $fieldLabels = array_values(array_filter(array_map(static fn($field) => (string)($field['label'] ?? ''), is_array($fieldConfig['columns'] ?? null) ? $fieldConfig['columns'] : []))); ?><option value="<?= (int)$template['version_id'] ?>" data-company-id="<?= (int)$template['company_id'] ?>" data-company-name="<?= adminAddEscape($template['company_name']) ?>" data-config="<?= adminAddEscape((string)$template['config_json']) ?>"><?= adminAddEscape($template['company_name']) ?> · <?= adminAddEscape($template['template_name']) ?> · v<?= (int)$template['version_number'] ?></option><?php } ?></select><small class="text-muted">Choose the user's table fields and calculation roles below. Changes apply to this user's new quotations.</small><div class="small text-muted mt-1">Set Quantity and Rate roles for automatic calculation. Use Auto Amount as both the input type and calculation role for a calculated amount column.</div><div id="quotationFieldBuilder" class="vstack gap-2 mt-2"></div><div id="quotationFieldError" class="small text-danger mt-1"></div><button class="btn btn-outline-primary btn-sm mt-2" type="button" id="addQuotationField">+ Add quotation field</button><textarea class="d-none" name="quotation_columns_json" id="quotationColumnsJson"></textarea></div>
            <div class="col-md-6 mb-3"><label class="form-label">Quotation print design *</label><select class="form-select" name="print_layout" id="print_layout" required><option value="Classic">Classic</option><option value="Compact">Compact</option><option value="Minimal">Minimal</option><option value="Modern">Modern</option></select><small class="text-muted">This design is saved for this user's quotations. Earlier quotations keep their saved design.</small></div>
            <div class="col-md-4 mb-3"><label class="form-label">Full name *</label><input class="form-control" name="name" maxlength="100" required></div>
            <div class="col-md-4 mb-3"><label class="form-label">Login email *</label><input class="form-control" name="email" type="email" maxlength="100" required></div>
            <div class="col-md-4 mb-3"><label class="form-label">Temporary password *</label><input class="form-control" name="password" type="password" minlength="12" required autocomplete="new-password"></div>
            <div class="col-md-6 mb-3"><label class="form-label">Role</label><select class="form-select" name="role"><?php foreach (['Administrator','Manager','Production','Quality','Dispatch','Accounts','User'] as $role) { ?><option value="<?= $role ?>" <?= $role === 'User' ? 'selected' : '' ?>><?= $role ?></option><?php } ?></select><small class="text-muted">Module access below takes priority over role defaults.</small></div>
        </div></div></div>
        <?php require_once __DIR__ . '/../../includes/user_permissions.php'; ?>
        <div class="card mt-3"><div class="card-header"><h5 class="mb-0">Modules and actions for this user</h5><small class="text-muted">Select only the access this user needs. Quotation fields come from the assigned template above.</small></div><div class="card-body"><div class="row">
            <?php foreach (erpUserPermissionOptions() as $module => $permissions) { ?>
                <div class="col-lg-4 col-md-6 mb-3"><div class="border rounded p-3 h-100"><strong><?= adminAddEscape($module) ?></strong>
                    <?php foreach ($permissions as $key => $label) { $permissionId = 'permission_' . str_replace('.', '_', $key); ?>
                        <div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="<?= adminAddEscape($permissionId) ?>" name="permissions[<?= adminAddEscape($key) ?>]" value="1"><label class="form-check-label" for="<?= adminAddEscape($permissionId) ?>"><?= adminAddEscape($label) ?></label></div>
                    <?php } ?>
                </div></div>
            <?php } ?>
        </div></div></div>
        <button class="btn btn-primary" type="submit">Create user</button> <a class="btn btn-light" href="index.php">Cancel</a>
    </form>
</div></div></div>
<script>
(function () {
    var companyInput = document.getElementById('company_name');
    var companyNameHelp = document.getElementById('companyNameHelp');
    var companyRows = <?= json_encode(array_map(static fn($company) => ['id' => (int)$company['id'], 'name' => (string)$company['company_name']], $companyRows), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var newCompanyPlanWrap = document.getElementById('newCompanyPlanWrap');
    var newCompanyPlan = document.getElementById('new_company_plan');
    var templateSelect = document.getElementById('template_version_id');
    var fieldBuilder = document.getElementById('quotationFieldBuilder');
    var fieldJson = document.getElementById('quotationColumnsJson');
    var layoutSelect = document.getElementById('print_layout');
    var form = document.getElementById('userForm');
    var errorBox = document.getElementById('quotationFieldError');
    if (!companyInput || !templateSelect || !fieldBuilder || !fieldJson || !form) return;
    var options = Array.prototype.slice.call(templateSelect.options);

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

    function filterTemplates() {
        var typedName = companyInput.value.trim().toLocaleLowerCase();
        var matches = companyRows.filter(function (company) {
            return company.name.trim().toLocaleLowerCase() === typedName;
        });
        var companyId = matches.length === 1 ? String(matches[0].id) : '';
        var existingCompany = !!companyId;
        var newCompany = typedName !== '' && matches.length === 0;
        templateSelect.value = '';
        templateSelect.disabled = !existingCompany && !newCompany;
        fieldBuilder.replaceChildren();
        options.forEach(function (option) {
            if (!option.value) return;
            var allowed = newCompany || option.getAttribute('data-company-id') === companyId;
            option.hidden = !allowed;
            option.disabled = !allowed;
        });
        templateSelect.options[0].textContent = existingCompany ? 'Choose this company template' : (newCompany ? 'Choose a starting template' : 'Type company name first');
        newCompanyPlanWrap.classList.toggle('d-none', !newCompany);
        newCompanyPlan.required = newCompany;
        if (!typedName) {
            companyNameHelp.textContent = 'Type a company name. If it is new, this form will create its company record.';
            companyNameHelp.classList.remove('text-danger');
        } else if (matches.length > 1) {
            companyNameHelp.textContent = 'More than one active company has this name. Rename one in Manage Companies first.';
            companyNameHelp.classList.add('text-danger');
            templateSelect.disabled = true;
        } else if (existingCompany) {
            companyNameHelp.textContent = 'Existing company found. Its own quotation templates are available.';
            companyNameHelp.classList.remove('text-danger');
        } else {
            companyNameHelp.textContent = 'New company: choose a starting template and plan. The company is created when you save this user.';
            companyNameHelp.classList.remove('text-danger');
        }
    }
    companyInput.addEventListener('input', filterTemplates);
    templateSelect.addEventListener('change', renderTemplateSettings);
    filterTemplates();
})();
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
