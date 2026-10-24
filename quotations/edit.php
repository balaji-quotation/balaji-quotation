<?php

session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}


// =====================================================
// CSRF TOKEN
// =====================================================

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}


include("../includes/db.php");
require_once __DIR__ . '/../includes/permissions.php';
erpRequireCompanyUser($conn);
erpRequire('quotations.edit');
$companyId = erpCurrentCompanyId();
$userId = (int)$_SESSION['user_id'];
include("../includes/header.php");
include("../includes/sidebar.php");


// =====================================================
// QUOTATION ID
// =====================================================

$quotation_id = intval($_GET['id'] ?? 0);

if ($quotation_id <= 0) {
    die("Invalid quotation ID.");
}


// =====================================================
// LOAD MASTER
// =====================================================

$master_sql = "
    SELECT q.*,
           COALESCE(NULLIF(q.client_name_override, ''), c.company_name, '') AS client_name,
        c.contact_person AS master_contact_person,
        c.gstin AS master_gstin,
        c.mobile AS master_mobile,
        c.alternate_mobile AS master_alternate_mobile,
        c.email AS master_email,
        c.address AS master_address
    FROM quotations q
    LEFT JOIN clients c ON c.id = q.client_id AND c.company_id = q.company_id
    WHERE q.id = ?
      AND q.company_id = ?
      AND q.created_by = ?
    LIMIT 1
";

$master_stmt = mysqli_prepare($conn, $master_sql);

if (!$master_stmt) {
    die("Quotation Load Error: " . mysqli_error($conn));
}

mysqli_stmt_bind_param($master_stmt, "iii", $quotation_id, $companyId, $userId);
mysqli_stmt_execute($master_stmt);

$master_result = mysqli_stmt_get_result($master_stmt);
$quotation = mysqli_fetch_assoc($master_result);
$clientDetailsSnapshot = json_decode((string)($quotation['client_details_snapshot'] ?? ''), true);
if (!is_array($clientDetailsSnapshot)) {
    $clientDetailsSnapshot = [];
}

mysqli_stmt_close($master_stmt);

if (!$quotation) {
    die("Quotation not found.");
}
$templateConfig = json_decode((string)($quotation['template_config_snapshot'] ?? ''), true);
if (!is_array($templateConfig)) $templateConfig = [];
$calculationConfig = is_array($templateConfig['calculation'] ?? null) ? $templateConfig['calculation'] : ['mode'=>'standard','formula'=>''];
$roundingConfig = is_array($templateConfig['rounding'] ?? null) ? $templateConfig['rounding'] : ['decimal_places'=>2,'mode'=>'half_up'];
$taxConfig = is_array($templateConfig['tax'] ?? null) ? $templateConfig['tax'] : ['gst_enabled'=>false,'gst_rate'=>0];
$totalsConfig = is_array($templateConfig['totals'] ?? null) ? $templateConfig['totals'] : ['show_line_amount'=>true,'show_subtotal'=>true,'show_tax'=>false,'show_grand_total'=>true];


// =====================================================
// CLIENTS
// Full client master details for live preview
// =====================================================

$clients = [];

$client_sql = "
    SELECT
        id,
        company_name,
        contact_person,
        gstin,
        mobile,
        alternate_mobile,
        email,
        address
    FROM clients
    WHERE company_id = $companyId
    ORDER BY company_name ASC
";

$client_result = mysqli_query($conn, $client_sql);

if (!$client_result) {
    die("Client Load Error: " . mysqli_error($conn));
}

while ($client = mysqli_fetch_assoc($client_result)) {
    $clients[] = $client;
}


// =====================================================
// LOAD DYNAMIC COLUMNS
// =====================================================

$columns = [];

$column_sql = "
    SELECT
        column_key,
        column_label,
        unit_text,
        data_type,
        text_align,
        sort_order
    FROM quotation_columns
    WHERE
        quotation_id = ?
        AND is_visible = 1
    ORDER BY
        sort_order ASC,
        id ASC
";

$column_stmt = mysqli_prepare($conn, $column_sql);

if (!$column_stmt) {
    die("Quotation Column Load Error: " . mysqli_error($conn));
}

mysqli_stmt_bind_param($column_stmt, "i", $quotation_id);
mysqli_stmt_execute($column_stmt);

$column_result = mysqli_stmt_get_result($column_stmt);

while ($column = mysqli_fetch_assoc($column_result)) {
    $columnIndex = count($columns);
    $savedTemplateColumn = is_array($templateConfig['columns'][$columnIndex] ?? null) ? $templateConfig['columns'][$columnIndex] : [];
    $columns[] = [
        'key'   => $column['column_key'],
        'label' => $column['column_label'],
        'unit'  => $column['unit_text'] ?? '',
        'type'  => $savedTemplateColumn['type'] ?? ($column['data_type'] ?? 'text'),
        'field_role' => $savedTemplateColumn['field_role'] ?? 'none',
        'align' => $column['text_align'] ?? 'left'
    ];
}

mysqli_stmt_close($column_stmt);


// Fallback only if an old quotation has no dynamic columns.
if (count($columns) === 0) {

    $columns = [
        [
            'key' => 'col_1',
            'label' => 'Part No. / Drawing No.',
            'unit' => '',
            'type' => 'text',
            'align' => 'left'
        ],
        [
            'key' => 'col_2',
            'label' => 'Description',
            'unit' => '',
            'type' => 'text',
            'align' => 'left'
        ],
        [
            'key' => 'col_3',
            'label' => 'Material / Grade',
            'unit' => '',
            'type' => 'text',
            'align' => 'left'
        ],
        [
            'key' => 'col_4',
            'label' => 'Qty / EAU',
            'unit' => '',
            'type' => 'number',
            'align' => 'right'
        ],
        [
            'key' => 'col_5',
            'label' => 'Rate',
            'unit' => '₹/PCS',
            'type' => 'currency',
            'align' => 'right'
        ]
    ];
}


// =====================================================
// LOAD ROWS
// Convert saved row_data by column key into indexed values
// expected by row_data[rowIndex][columnIndex]
// =====================================================

$rows = [];

$row_sql = "
    SELECT row_data
    FROM quotation_rows
    WHERE quotation_id = ?
    ORDER BY
        sort_order ASC,
        id ASC
";

$row_stmt = mysqli_prepare($conn, $row_sql);

if (!$row_stmt) {
    die("Quotation Row Load Error: " . mysqli_error($conn));
}

mysqli_stmt_bind_param($row_stmt, "i", $quotation_id);
mysqli_stmt_execute($row_stmt);

$row_result = mysqli_stmt_get_result($row_stmt);

while ($row = mysqli_fetch_assoc($row_result)) {

    $decoded = json_decode(
        $row['row_data'] ?? '{}',
        true
    );

    if (!is_array($decoded)) {
        $decoded = [];
    }

    $indexed_row = [];

    foreach ($columns as $column) {
        $indexed_row[] =
            $decoded[$column['key']] ?? '';
    }

    $rows[] = $indexed_row;
}

mysqli_stmt_close($row_stmt);

if (count($rows) === 0) {
    $rows[] = array_fill(0, count($columns), '');
}


// =====================================================
// COMMERCIAL TERMS
// =====================================================

$commercial_terms = [];

$commercial_sql = "
    SELECT
        particular,
        term_value
    FROM quotation_commercial_terms
    WHERE
        quotation_id = ?
        AND is_visible = 1
    ORDER BY
        sort_order ASC,
        id ASC
";

$commercial_stmt = mysqli_prepare($conn, $commercial_sql);

if (!$commercial_stmt) {
    die("Commercial Terms Load Error: " . mysqli_error($conn));
}

mysqli_stmt_bind_param($commercial_stmt, "i", $quotation_id);
mysqli_stmt_execute($commercial_stmt);

$commercial_result = mysqli_stmt_get_result($commercial_stmt);

while ($term = mysqli_fetch_assoc($commercial_result)) {
    $commercial_terms[] = $term;
}

mysqli_stmt_close($commercial_stmt);


// =====================================================
// GENERAL TERMS
// =====================================================

$general_terms = [];

$general_sql = "
    SELECT condition_text
    FROM quotation_general_terms
    WHERE
        quotation_id = ?
        AND is_visible = 1
    ORDER BY
        sort_order ASC,
        id ASC
";

$general_stmt = mysqli_prepare($conn, $general_sql);

if (!$general_stmt) {
    die("General Terms Load Error: " . mysqli_error($conn));
}

mysqli_stmt_bind_param($general_stmt, "i", $quotation_id);
mysqli_stmt_execute($general_stmt);

$general_result = mysqli_stmt_get_result($general_stmt);

while ($term = mysqli_fetch_assoc($general_result)) {
    $general_terms[] = $term['condition_text'];
}

mysqli_stmt_close($general_stmt);


// =====================================================
// MESSAGE
// =====================================================

$error_message = $_SESSION['quotation_error'] ?? '';
unset($_SESSION['quotation_error']);

?>

<style>

.quote-card {
    border: 0;
    border-radius: 14px;
    box-shadow: 0 6px 24px rgba(31,45,61,.06);
}

.quote-title {
    font-weight: 700;
    color: #26374a;
}

.quote-subtitle {
    font-size: 11px;
    color: #7b8794;
}

.quote-section-title {
    font-size: 15px;
    font-weight: 700;
    color: #233f6b;
}

.quote-section-note {
    font-size: 10px;
    color: #7b8794;
}

.quote-form-label {
    font-size: 11px;
    font-weight: 700;
    color: #425466;
    margin-bottom: 5px;
}

.column-editor {
    background: #f8fafc;
    border: 1px solid #e2e7ec;
    border-radius: 10px;
    padding: 10px;
    min-width: 205px;
    position: relative;
}

.column-editor-title {
    font-size: 10px;
    font-weight: 700;
    color: #6c7785;
    text-transform: uppercase;
    margin-bottom: 7px;
}

.column-remove {
    position: absolute;
    right: 7px;
    top: 6px;
    border: 0;
    background: transparent;
    color: #dc3545;
    font-size: 18px;
    line-height: 1;
}

.column-config-wrap {
    display: flex;
    gap: 8px;
    overflow-x: auto;
    padding-bottom: 5px;
}

.quote-table {
    font-size: 12px;
    min-width: 900px;
    margin-bottom: 0;
    border-color: #e1e7ef;
    border-collapse: separate;
    border-spacing: 0;
}

.quote-table-wrap {
    border: 1px solid #e1e7ef;
    border-radius: 11px;
    overflow-x: auto;
    background: #fff;
}

.quote-table thead th {
    position: sticky;
    top: 0;
    z-index: 2;
    background: #203d68 !important;
    color: #fff !important;
    text-align: center;
    vertical-align: middle;
    font-size: 12px;
    line-height: 1.35;
    padding: 13px 11px;
    white-space: nowrap;
}

.quote-table tbody td {
    vertical-align: middle;
    padding: 12px 10px;
    background: #fff;
}

.quote-table tbody tr:hover td {
    background: #f8fbff;
}

.quote-table .form-control,
.quote-table .form-select {
    font-size: 13px;
    min-width: 105px;
    min-height: 39px;
    border-color: #d8e0ea;
    border-radius: 7px;
}

.quote-table textarea.form-control {
    min-height: 58px;
}

.quote-table .line-amount-value,
.quote-table [data-auto-amount-display] {
    color: #173b6d;
    font-size: 13px;
    font-weight: 700;
    white-space: nowrap;
}

.quote-table tfoot th {
    background: #f3f7fd;
    color: #233f6b;
    padding: 11px;
    border-color: #e1e7ef;
}

.quote-table-wrap + .small {
    margin-top: 8px;
}

#addRowBtn {
    min-height: 38px;
    border-radius: 8px;
    font-weight: 600;
}

.quote-section-title {
    font-size: 16px;
}

.quote-section-note {
    font-size: 12px;
    line-height: 1.45;
}

.quote-form-label {
    font-size: 12px;
}

.quote-table tbody td:first-child {
    color: #526173;
    font-weight: 700;
}

.quote-table-wrap::-webkit-scrollbar {
    height: 9px;
}

.quote-table-wrap::-webkit-scrollbar-thumb {
    background: #c8d3e0;
    border-radius: 10px;
}

@media (max-width: 767.98px) {
    .quote-card .card-body {
        padding: 14px;
    }

    .quote-table {
        min-width: 850px;
    }

    #addRowBtn {
        width: 100%;
    }
}

.term-table {
    font-size: 11px;
}

.term-table thead th {
    background: #4a5a6b !important;
    color: #fff !important;
    vertical-align: middle;
}

.term-table textarea,
.term-table input {
    font-size: 11px;
}

.general-condition-row {
    display: flex;
    align-items: flex-start;
    gap: 7px;
    margin-bottom: 7px;
}

.general-condition-number {
    width: 28px;
    min-width: 28px;
    height: 31px;
    border-radius: 6px;
    background: #eef2f6;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
    font-weight: 700;
}

.client-detail-box {
    background: #f8fafc;
    border: 1px solid #e1e7ed;
    border-radius: 9px;
    padding: 10px;
    display: none;
}

.client-detail-label {
    font-size: 9px;
    color: #7b8794;
    text-transform: uppercase;
    font-weight: 700;
}

.client-detail-value {
    font-size: 11px;
    color: #26374a;
    font-weight: 600;
    overflow-wrap: anywhere;
}

.customer-options summary { cursor:pointer; color:#315b91; font-size:12px; font-weight:600; }
.client-information-panel { background:#f8fbff; border:1px solid #e2eaf4; border-radius:10px; padding:14px; }
.customer-options select { max-width:100%; }
#toggleQuickClient { text-decoration:none; }

/* Shared quotation item table layout */
.quote-table-wrap {
    width: 100%;
    max-width: 100%;
    overflow: auto;
    overscroll-behavior-inline: contain;
    border: 1px solid #dce4ee;
    border-radius: 10px;
    background: #fff;
}
.quote-corporate-table {
    width: max-content;
    min-width: 980px;
    margin: 0;
    border: 0;
    border-collapse: separate;
    border-spacing: 0;
    font-size: 12px;
}
.quote-corporate-table th,
.quote-corporate-table td {
    border-right: 1px solid #e2e8f0;
    border-bottom: 1px solid #e2e8f0;
}
.quote-corporate-table thead th {
    position: sticky;
    top: 0;
    z-index: 2;
    padding: 12px 10px;
    background: #203d68 !important;
    color: #fff !important;
    border-color: #405985 !important;
    font-size: 12px;
    font-weight: 700;
    line-height: 1.35;
    text-align: center;
    vertical-align: middle;
    white-space: nowrap;
}
.quote-corporate-table thead th:first-child {
    left: 0;
    z-index: 4;
}
.quote-corporate-table tbody td {
    padding: 10px;
    background: #fff;
    vertical-align: middle;
}
.quote-corporate-table tbody tr:nth-child(even) td {
    background: #f8fafd;
}
.quote-corporate-table tbody tr:hover td {
    background: #f0f6ff;
}
.quote-corporate-table tbody td:first-child {
    position: sticky;
    left: 0;
    z-index: 1;
    min-width: 48px;
    background: #f3f7fc;
    color: #526173;
    text-align: center;
    font-weight: 700;
}
.quote-corporate-table .form-control,
.quote-corporate-table .form-select {
    min-width: 105px;
    min-height: 39px;
    border: 1px solid #d5deea;
    border-radius: 7px;
    font-size: 13px;
}
.quote-corporate-table textarea.form-control {
    min-width: 160px;
    min-height: 58px;
}
.quote-corporate-table .form-control:focus,
.quote-corporate-table .form-select:focus {
    border-color: #79a5e3;
    box-shadow: 0 0 0 .18rem rgba(61, 130, 220, .13);
}
.quote-corporate-table .line-amount-value,
.quote-corporate-table [data-auto-amount-display] {
    min-width: 100px;
    color: #173b6d;
    font-size: 13px;
    font-weight: 700;
    text-align: right;
    white-space: nowrap;
}
.quote-corporate-table tfoot th {
    padding: 11px;
    background: #edf4fd;
    color: #233f6b;
    border-color: #dce4ee;
}
.quote-corporate-table td:last-child,
.quote-corporate-table th:last-child {
    min-width: 58px;
    text-align: center;
}
.quote-table-wrap::-webkit-scrollbar {
    height: 9px;
}
.quote-table-wrap::-webkit-scrollbar-thumb {
    background: #bac8d9;
    border-radius: 9px;
}
@media (max-width: 767.98px) {
    .quote-corporate-table {
        min-width: 850px;
    }
    .quote-corporate-table tbody td {
        padding: 8px;
    }
}

</style>


<div class="content-page">

<div class="content">

<div class="container-fluid">


<!-- HEADER -->

<div class="card quote-card mt-2 mb-2">

<div class="card-body py-2 px-3">

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2">

    <div>
        <h4 class="quote-title mb-1">
            Edit Quotation
        </h4>

        <div class="quote-subtitle">
            <?= htmlspecialchars(
                $quotation['quotation_no'] ?? ''
            ); ?>
            — update quotation details, rate and terms
        </div>
    </div>

    <div class="d-flex gap-1">
        <a
            href="view.php?id=<?= intval($quotation_id); ?>"
            class="btn btn-secondary btn-sm"
        >
            ← View
        </a>

        <a
            href="index.php"
            class="btn btn-light btn-sm"
        >
            Quotation List
        </a>
    </div>

</div>

</div>

</div>


<?php if ($error_message !== '') { ?>

<div class="alert alert-danger alert-dismissible fade show">
    <strong>Error!</strong>
    <?= htmlspecialchars($error_message); ?>

    <button
        type="button"
        class="btn-close"
        data-bs-dismiss="alert"
    ></button>
</div>

<?php } ?>


<form
    action="update.php"
    method="POST"
    id="quotationForm"
>

<input
    type="hidden"
    name="csrf_token"
    value="<?= htmlspecialchars(
        $_SESSION['csrf_token'],
        ENT_QUOTES,
        'UTF-8'
    ); ?>"
>

<input
    type="hidden"
    name="quotation_id"
    value="<?= intval($quotation_id); ?>"
>


<!-- =====================================================
     QUOTATION DETAILS
===================================================== -->

<div class="card quote-card mb-2">

<div class="card-body">

<div class="mb-3">
    <div class="quote-section-title">
        Quotation Details
    </div>
    <div class="quote-section-note">
        Update quotation master information
    </div>
</div>

<div class="row g-2">


<div class="col-xl-3 col-lg-4 col-md-6">

<label class="quote-form-label">
    Quotation No. *
</label>

<input
    type="text"
    name="quotation_no"
    class="form-control"
    value="<?= htmlspecialchars(
        $quotation['quotation_no'] ?? ''
    ); ?>"
    required
>

</div>


<div class="col-12 client-information-panel">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
        <div>
            <label class="quote-form-label mb-0">Customer Information *</label>
            <small class="text-muted d-block">Naye customer ki details yahin type karein. Purana customer lena ho to Master dropdown se select karein.</small>
        </div>
        <input type="hidden" name="client_id" id="client_id" value="<?= intval($quotation['client_id'] ?? 0); ?>">
        <details class="customer-options" <?= !empty($quotation['client_id']) ? 'open' : ''; ?>>
            <summary>Choose existing customer</summary>
            <select id="clientMasterSelect" class="form-select form-select-sm mt-2">
                <option value="">Select from Customer Master</option>
                <?php foreach ($clients as $client) { ?>
                <option value="<?= intval($client['id']); ?>" <?= intval($quotation['client_id'] ?? 0) === intval($client['id']) ? 'selected' : ''; ?>><?= htmlspecialchars($client['company_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></option>
                <?php } ?>
            </select>
        </details>
    </div>
    <div class="row g-2">
        <div class="col-lg-4 col-md-6">
            <label class="quote-form-label">Customer / Company Name *</label>
            <input type="text" name="client_name" id="clientName" class="form-control" maxlength="150" autocomplete="off" value="<?= htmlspecialchars((string)($clientDetailsSnapshot['company_name'] ?? $quotation['client_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" required>
        </div>
        <div class="col-lg-4 col-md-6">
            <label class="quote-form-label">Contact Person</label>
            <input type="text" name="client_contact_person" id="clientContactPerson" class="form-control" maxlength="150" value="<?= htmlspecialchars((string)($clientDetailsSnapshot['contact_person'] ?? $quotation['master_contact_person'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
        </div>
        <div class="col-lg-4 col-md-6">
            <label class="quote-form-label">GSTIN</label>
            <input type="text" name="client_gstin" id="clientGstin" class="form-control" maxlength="30" value="<?= htmlspecialchars((string)($clientDetailsSnapshot['gstin'] ?? $quotation['master_gstin'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
        </div>
        <div class="col-lg-3 col-md-6">
            <label class="quote-form-label">Mobile</label>
            <input type="text" name="client_mobile" id="clientMobile" class="form-control" maxlength="20" value="<?= htmlspecialchars((string)($clientDetailsSnapshot['mobile'] ?? $quotation['master_mobile'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
        </div>
        <div class="col-lg-6 col-md-12">
            <label class="quote-form-label">Email</label>
            <input type="email" name="client_email" id="clientEmail" class="form-control" maxlength="100" value="<?= htmlspecialchars((string)($clientDetailsSnapshot['email'] ?? $quotation['master_email'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
        </div>
        <div class="col-12">
            <label class="quote-form-label">Billing Address</label>
            <textarea name="client_address" id="clientAddress" class="form-control" rows="2"><?= htmlspecialchars((string)($clientDetailsSnapshot['address'] ?? $quotation['master_address'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></textarea>
        </div>
    </div>
</div>

<div class="col-xl-2 col-lg-4 col-md-6">

<label class="quote-form-label">
    RFQ No.
</label>

<input
    type="text"
    name="rfq_no"
    class="form-control"
    value="<?= htmlspecialchars(
        $quotation['rfq_no'] ?? ''
    ); ?>"
>

</div>


<div class="col-xl-2 col-lg-3 col-md-6">

<label class="quote-form-label">
    RFQ Date
</label>

<input
    type="date"
    name="rfq_date"
    class="form-control"
    value="<?= htmlspecialchars(
        $quotation['rfq_date'] ?? ''
    ); ?>"
>

</div>


<div class="col-xl-2 col-lg-3 col-md-6">

<label class="quote-form-label">
    Quote Date *
</label>

<input
    type="date"
    name="quotation_date"
    class="form-control"
    value="<?= htmlspecialchars(
        $quotation['quotation_date'] ?? ''
    ); ?>"
    required
>

</div>


<div class="col-xl-4 col-lg-6 col-md-6">

<label class="quote-form-label">
    Subject
</label>

<input
    type="text"
    name="subject"
    class="form-control"
    value="<?= htmlspecialchars(
        $quotation['subject'] ?? ''
    ); ?>"
>

</div>


<div class="col-xl-2 col-lg-3 col-md-6">

<label class="quote-form-label">
    Currency
</label>

<select
    name="currency"
    id="currency"
    class="form-select"
>

<?php

$currencies = [
    'INR' => 'INR - ₹',
    'USD' => 'USD - $',
    'EUR' => 'EUR - €',
    'GBP' => 'GBP - £'
];

foreach ($currencies as $code => $label) {

?>

<option
    value="<?= htmlspecialchars($code); ?>"
    <?= strtoupper(
        $quotation['currency'] ?? 'INR'
    ) === $code
        ? 'selected'
        : '';
    ?>
>
    <?= htmlspecialchars($label); ?>
</option>

<?php } ?>

</select>

</div>


<div class="col-xl-2 col-lg-3 col-md-6">

<label class="quote-form-label">
    Offer Validity
</label>

<input
    type="text"
    name="validity_text"
    class="form-control"
    value="<?= htmlspecialchars(
        $quotation['validity_text'] ?? ''
    ); ?>"
    placeholder="30 Days"
>

</div>


<div class="col-xl-2 col-lg-3 col-md-6">

<label class="quote-form-label">
    Status
</label>

<select
    name="status"
    class="form-select"
>

<?php

$statuses = [
    'Draft',
    'Sent',
    'Accepted',
    'Rejected',
    'Expired',
    'Revised'
];

$current_status =
    $quotation['status'] ?? 'Draft';

foreach ($statuses as $status) {

?>

<option
    value="<?= htmlspecialchars($status); ?>"
    <?= $current_status === $status
        ? 'selected'
        : '';
    ?>
>
    <?= htmlspecialchars($status); ?>
</option>

<?php } ?>

</select>

</div>


</div>

</div>

</div>


<!-- =====================================================
     DYNAMIC QUOTATION TABLE
===================================================== -->

<div class="card quote-card mb-2">

<div class="card-body">

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
        <div class="quote-section-title">Items</div>
        <div class="quote-section-note">Edit the products and rates in this quotation.</div>
    </div>
    <button type="button" class="btn btn-primary btn-sm" id="addRowBtn">+ Add Item</button>
</div>



<div class="table-responsive quote-table-wrap">

<table class="table table-bordered quote-corporate-table mb-0">

<thead>
<tr id="quotationHead"></tr>
</thead>

<tbody id="quotationBody"></tbody>
<tfoot id="quotationTotals"></tfoot>

</table>

</div>

</div>

</div>


<!-- =====================================================
     COMMERCIAL TERMS
===================================================== -->

<div class="card quote-card mb-2">

<div class="card-body">

<div class="d-flex justify-content-between align-items-center mb-3">

<div>
    <div class="quote-section-title">
        Commercial Terms
    </div>
    <div class="quote-section-note">
        Particulars and terms shown in quotation
    </div>
</div>

<button
    type="button"
    class="btn btn-outline-primary btn-sm"
    id="addCommercialBtn"
>
    + Add Term
</button>

</div>


<div class="table-responsive">

<table class="table table-bordered term-table mb-0">

<thead>
<tr>
    <th style="width:55px;">Sr.</th>
    <th style="width:230px;">Particulars</th>
    <th>Terms</th>
    <th style="width:55px;">Action</th>
</tr>
</thead>

<tbody id="commercialBody">

<?php foreach ($commercial_terms as $index => $term) { ?>

<tr>
    <td class="text-center fw-bold commercial-sr">
        <?= $index + 1; ?>
    </td>

    <td>
        <input
            type="text"
            name="commercial_particular[]"
            class="form-control form-control-sm"
            value="<?= htmlspecialchars(
                $term['particular'] ?? ''
            ); ?>"
        >
    </td>

    <td>
        <textarea
            name="commercial_value[]"
            class="form-control form-control-sm"
            rows="2"
        ><?= htmlspecialchars(
            $term['term_value'] ?? ''
        ); ?></textarea>
    </td>

    <td class="text-center">
        <button
            type="button"
            class="btn btn-outline-danger btn-sm remove-commercial"
        >
            ×
        </button>
    </td>
</tr>

<?php } ?>

</tbody>

</table>

</div>

</div>

</div>


<!-- =====================================================
     GENERAL TERMS
===================================================== -->

<div class="card quote-card mb-2">

<div class="card-body">

<div class="d-flex justify-content-between align-items-center mb-3">

<div>
    <div class="quote-section-title">
        General Terms & Conditions
    </div>
    <div class="quote-section-note">
        Numbered conditions shown in final quotation PDF
    </div>
</div>

<button
    type="button"
    class="btn btn-outline-primary btn-sm"
    id="addGeneralBtn"
>
    + Add Condition
</button>

</div>


<div id="generalTerms">

<?php foreach ($general_terms as $index => $condition) { ?>

<div class="general-condition-row">

    <div class="general-condition-number">
        <?= $index + 1; ?>
    </div>

    <textarea
        name="general_condition[]"
        class="form-control form-control-sm"
        rows="2"
    ><?= htmlspecialchars($condition); ?></textarea>

    <button
        type="button"
        class="btn btn-outline-danger btn-sm remove-general"
    >
        ×
    </button>

</div>

<?php } ?>

</div>

</div>

</div>


<!-- =====================================================
     REMARKS + BUTTONS
===================================================== -->

<div class="card quote-card mb-3">

<div class="card-body">

<label class="quote-form-label">
    Internal / Quotation Remarks
</label>

<textarea
    name="remarks"
    class="form-control"
    rows="3"
><?= htmlspecialchars(
    $quotation['remarks'] ?? ''
); ?></textarea>


<div class="d-flex justify-content-end gap-2 mt-3">

<a
    href="view.php?id=<?= intval($quotation_id); ?>"
    class="btn btn-secondary"
>
    Cancel
</a>

<button
    type="submit"
    class="btn btn-success"
>
    <i class="mdi mdi-content-save-outline me-1"></i>
    Update Quotation
</button>

</div>

</div>

</div>


</form>


</div>

</div>

</div>


<script>

document.addEventListener(
    "DOMContentLoaded",
    function () {

        // =================================================
        // PHP DATA
        // =================================================

        const clientsData =
            <?= json_encode(
                $clients,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            ); ?>;

        let columns =
            <?= json_encode(
                $columns,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            ); ?>;

        let liveRows =
            <?= json_encode(
                $rows,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            ); ?>;

        const calculationConfig = <?= json_encode($calculationConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const roundingConfig = <?= json_encode($roundingConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const taxConfig = <?= json_encode($taxConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const totalsConfig = <?= json_encode($totalsConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;



        // =================================================
        // CURRENCY
        // =================================================

        const currencySelect =
            document.getElementById("currency");

        const currencySymbols = {
            INR: "₹",
            USD: "$",
            EUR: "€",
            GBP: "£"
        };

        function getCurrencySymbol() {
            return currencySymbols[
                currencySelect.value
            ] || "₹";
        }

        function normalizeCurrencyUnit(unit) {

            let value =
                String(unit || "").trim();

            if (value === "") {
                return value;
            }

            if (/^[₹$€£]/u.test(value)) {

                return value.replace(
                    /^[₹$€£]/u,
                    getCurrencySymbol()
                );
            }

            return value;
        }

        function updateCurrencyColumns() {

            columns =
                columns.map(
                    function (column) {

                        if (
                            column.type === "currency"
                        ) {

                            let unit =
                                String(
                                    column.unit || ""
                                ).trim();

                            unit =
                                unit.replace(
                                    /^[₹$€£]/u,
                                    ""
                                );

                            if (unit === "") {
                                unit = "/PCS";
                            }

                            if (
                                !unit.startsWith("/")
                            ) {
                                unit = "/" + unit;
                            }

                            column.unit =
                                getCurrencySymbol() +
                                unit;
                        }

                        return column;
                    }
                );

            renderAll();
        }


        currencySelect.addEventListener(
            "change",
            updateCurrencyColumns
        );


        // =================================================
        // ELEMENTS
        // =================================================

        const quotationHead =
            document.getElementById(
                "quotationHead"
            );

        const quotationBody =
            document.getElementById(
                "quotationBody"
            );
        quotationBody.addEventListener("input", updateLiveAmounts);


        // =================================================
        // PRESERVE CURRENT TABLE VALUES
        // =================================================

        function captureRows() {

            const newRows = [];

            quotationBody
                .querySelectorAll("tr")
                .forEach(
                    function (tr) {

                        const row = [];

                        tr
                            .querySelectorAll(
                                "[data-cell-index]"
                            )
                            .forEach(
                                function (input) {

                                    row[
                                        Number(
                                            input.dataset.cellIndex
                                        )
                                    ] =
                                        input.value;
                                }
                            );

                        newRows.push(row);
                    }
                );

            if (newRows.length > 0) {
                liveRows = newRows;
            }
        }



        const hasAutoAmountColumn = columns.some(function (column) { return amountColumnRole(column) === "auto_amount"; });
        const showLineAmount = (Object.prototype.hasOwnProperty.call(totalsConfig, "show_line_amount")
            ? !!totalsConfig.show_line_amount
            : true) && !hasAutoAmountColumn;
        const showSubtotal = !!totalsConfig.show_subtotal;
        const showTax = !!(totalsConfig.show_tax ?? totalsConfig.show_total) && !!taxConfig.gst_enabled;
        const showGrandTotal = !!totalsConfig.show_grand_total;
        const decimalPlaces = Math.max(0, Math.min(4, Number.parseInt(roundingConfig.decimal_places ?? 2, 10) || 0));
        const totalsFoot = document.getElementById("quotationTotals");

        function amountColumnRole(column) {
            const configuredRole = String(column.field_role || column.role || "").toLowerCase();
            if (configuredRole === "qty" || configuredRole === "quantity") return "qty";
            if (configuredRole === "rate" || configuredRole === "price") return "rate";
            if (configuredRole === "amount") return "amount";
            if (configuredRole === "auto_amount" || column.type === "auto_amount") return "auto_amount";
            const label = String(column.label || column.column_label || "")
                .toLowerCase()
                .replace(/\([^)]*\)/g, " ")
                .replace(/[^\p{L}\p{N}]+/gu, " ")
                .trim();
            if (/^(qty|quantity|ea|eau)(?:\s|$)/.test(label)) return "qty";
            if (/^(unit rate|rate|unit price|price|unit cost)(?:\s|$)/.test(label)) return "rate";
            if (/^(line amount|total amount|line total|amount)(?:\s|$)/.test(label)) return "amount";
            return null;
        }

        function amountNumericValue(raw) {
            const cleaned = String(raw ?? "").trim().replace(/[,₹$€£%\s]/gu, "");
            if (cleaned === "" || !Number.isFinite(Number(cleaned))) return null;
            return Number(cleaned);
        }

        function evaluateAmountFormula(formula, variables) {
            const source = String(formula || "").trim();
            if (!source || source.length > 500) return null;
            const tokens = [];
            const tokenPattern = /\s*(qty|quantity|rate|price|amount|(?:\d+(?:\.\d*)?|\.\d+)|[()+\-*\/])\s*/iy;
            let cursor = 0;
            while (cursor < source.length) {
                tokenPattern.lastIndex = cursor;
                const match = tokenPattern.exec(source);
                if (!match) return null;
                tokens.push(match[1].toLowerCase());
                cursor = tokenPattern.lastIndex;
            }
            let position = 0;
            function factor() {
                if (tokens[position] === "-") {
                    position++;
                    const value = factor();
                    return value === null ? null : -value;
                }
                if (tokens[position] === "(") {
                    position++;
                    const value = expression();
                    if (tokens[position] !== ")") return null;
                    position++;
                    return value;
                }
                const token = tokens[position++];
                if (token === undefined) return null;
                if (/^(?:\d+(?:\.\d*)?|\.\d+)$/.test(token)) return Number(token);
                const key = token === "qty" || token === "quantity" ? "qty"
                    : token === "rate" || token === "price" ? "rate" : token === "amount" ? "amount" : null;
                return key && Number.isFinite(variables[key]) ? variables[key] : null;
            }
            function term() {
                let value = factor();
                while (value !== null && (tokens[position] === "*" || tokens[position] === "/")) {
                    const operator = tokens[position++];
                    const right = factor();
                    if (right === null || (operator === "/" && right === 0)) return null;
                    value = operator === "*" ? value * right : value / right;
                    if (!Number.isFinite(value)) return null;
                }
                return value;
            }
            function expression() {
                let value = term();
                while (value !== null && (tokens[position] === "+" || tokens[position] === "-")) {
                    const operator = tokens[position++];
                    const right = term();
                    if (right === null) return null;
                    value = operator === "+" ? value + right : value - right;
                    if (!Number.isFinite(value)) return null;
                }
                return value;
            }
            const result = expression();
            return position === tokens.length && Number.isFinite(result) ? result : null;
        }

        function calculateLineAmount(values) {
            const variables = {qty:null, rate:null, amount:null};
            columns.forEach(function (column, index) {
                const role = amountColumnRole(column);
                if (role && role !== "auto_amount") variables[role] = amountNumericValue(values[index]);
            });
            const mode = String(calculationConfig.mode || "standard");
            if (mode === "custom") {
                if (variables.amount === null && variables.qty !== null && variables.rate !== null) {
                    variables.amount = variables.qty * variables.rate;
                }
                return evaluateAmountFormula(calculationConfig.formula || "", variables);
            }
            if (variables.qty !== null && variables.rate !== null) return variables.qty * variables.rate;
            return variables.amount;
        }

        function roundLiveAmount(value) {
            const factor = 10 ** decimalPlaces;
            const scaled = value * factor;
            const mode = roundingConfig.mode || "half_up";
            if (mode === "ceil") return Math.ceil(scaled) / factor;
            if (mode === "floor") return Math.floor(scaled) / factor;
            if (mode === "half_even") {
                const lower = Math.floor(scaled);
                const fraction = scaled - lower;
                if (Math.abs(fraction - 0.5) < 1e-9) return (lower % 2 === 0 ? lower : lower + 1) / factor;
            }
            return Math.round(scaled) / factor;
        }

        function formatLiveAmount(value) {
            return getCurrencySymbol() + " " + Number(value).toLocaleString("en-IN", {
                minimumFractionDigits: decimalPlaces,
                maximumFractionDigits: decimalPlaces
            });
        }

        function updateLiveAmounts() {
            const rows = Array.from(quotationBody.querySelectorAll("tr"));
            const active = rows.map(function (row) {
                const values = [];
                row.querySelectorAll("[data-cell-index]").forEach(function (input) {
                    values[Number(input.dataset.cellIndex)] = input.value;
                });
                return {row:row, values:values, hasValues:values.some(function (value) { return String(value || "").trim() !== ""; })};
            }).filter(function (item) { return item.hasValues; });

            let subtotal = 0;
            let calculable = active.length > 0;
            active.forEach(function (item) {
                const amount = calculateLineAmount(item.values);
                item.amount = amount === null ? null : roundLiveAmount(amount);
                if (item.amount === null || !Number.isFinite(item.amount)) calculable = false;
                else subtotal += item.amount;
            });

            rows.forEach(function (row) {
                const item = active.find(function (entry) { return entry.row === row; });
                const amountCell = row.querySelector(".line-amount-value");
                if (amountCell) amountCell.textContent = formatLiveAmount(item && item.amount !== null ? item.amount : 0);
                row.querySelectorAll("[data-auto-amount-index]").forEach(function (input) {
                    const amountText = formatLiveAmount(item && item.amount !== null ? item.amount : 0);
                    input.value = amountText === "" ? "" : String(item.amount);
                    const display = row.querySelector('[data-auto-amount-display="' + input.dataset.autoAmountIndex + '"]');
                    if (display) display.textContent = amountText;
                });
            });

            if (!totalsFoot) return;
            totalsFoot.innerHTML = "";
            if (!calculable) return;
            subtotal = roundLiveAmount(subtotal);
            const taxRate = Math.max(0, Math.min(100, Number(taxConfig.gst_rate || 0)));
            const taxAmount = taxConfig.gst_enabled ? roundLiveAmount(subtotal * taxRate / 100) : 0;
            const grandTotal = roundLiveAmount(subtotal + taxAmount);
            const entries = [];
            if (showSubtotal) entries.push(["Subtotal", subtotal]);
            if (showTax) entries.push(["GST (" + taxRate + "%)", taxAmount]);
            if (showGrandTotal) entries.push(["Grand Total", grandTotal]);
            const totalColumns = columns.length + 2 + (showLineAmount ? 1 : 0);
            entries.forEach(function (entry) {
                const tr = document.createElement("tr");
                tr.className = "quotation-live-total";
                const label = document.createElement("th");
                label.colSpan = Math.max(1, totalColumns - 1);
                label.textContent = entry[0];
                const value = document.createElement("th");
                value.className = "text-end text-nowrap";
                value.textContent = formatLiveAmount(entry[1]);
                tr.appendChild(label);
                tr.appendChild(value);
                totalsFoot.appendChild(tr);
            });
        }


        // =================================================
        // COLUMN EDITOR
        // =================================================

                // =================================================
        // TABLE HEADER
        // =================================================

        function renderHeadOnly() {

            quotationHead.innerHTML = "";

            const sr =
                document.createElement(
                    "th"
                );

            sr.style.width = "50px";
            sr.textContent = "Sr.";

            quotationHead.appendChild(sr);


            columns.forEach(
                function (column) {

                    const th =
                        document.createElement(
                            "th"
                        );

                    th.style.textAlign =
                        column.align || "center";

                    const label =
                        document.createElement(
                            "div"
                        );

                    label.textContent =
                        column.label || "Column";

                    th.appendChild(label);


                    if (
                        String(
                            column.unit || ""
                        ).trim() !== ""
                    ) {

                        const unit =
                            document.createElement(
                                "small"
                            );

                        unit.style.display =
                            "block";

                        unit.textContent =
                            "(" +
                            normalizeCurrencyUnit(
                                column.unit
                            ) +
                            ")";

                        th.appendChild(unit);
                    }

                    quotationHead.appendChild(
                        th
                    );
                }
            );

            if (showLineAmount) {
                const amount = document.createElement("th");
                amount.className = "text-end";
                amount.textContent = "Amount";
                quotationHead.appendChild(amount);
            }

            const action =
                document.createElement(
                    "th"
                );

            action.style.width = "55px";
            action.textContent = "Action";

            quotationHead.appendChild(
                action
            );
        }


        // =================================================
        // TABLE BODY
        // =================================================

        function renderTable() {

            renderHeadOnly();

            quotationBody.innerHTML = "";

            if (liveRows.length === 0) {
                liveRows.push(
                    Array(
                        columns.length
                    ).fill("")
                );
            }


            liveRows.forEach(
                function (row, rowIndex) {

                    const tr =
                        document.createElement(
                            "tr"
                        );

                    const sr =
                        document.createElement(
                            "td"
                        );

                    sr.className =
                        "text-center fw-bold";

                    sr.textContent =
                        rowIndex + 1;

                    tr.appendChild(sr);


                    columns.forEach(
                        function (
                            column,
                            columnIndex
                        ) {

                            const td =
                                document.createElement(
                                    "td"
                                );

                            const input =
                                document.createElement(
                                    column.type ===
                                    "textarea"
                                        ? "textarea"
                                        : "input"
                                );

                            input.name =
                                `row_data[${rowIndex}][${columnIndex}]`;

                            input.dataset.cellIndex =
                                columnIndex;

                            input.className =
                                "form-control form-control-sm";

                            input.style.textAlign =
                                column.align || "left";


                            if (
                                column.type ===
                                    "number" ||
                                column.type ===
                                    "currency"
                            ) {

                                input.type =
                                    "number";

                                input.step =
                                    "0.001";

                            } else if (
                                column.type !==
                                "textarea"
                            ) {

                                input.type =
                                    "text";
                            }


                            if (
                                row &&
                                row[columnIndex] !==
                                    undefined
                            ) {

                                input.value =
                                    row[columnIndex];
                            }


                            if (amountColumnRole(column) === "auto_amount") {
                                input.type = "hidden";
                                input.dataset.autoAmountIndex = String(columnIndex);
                                const display = document.createElement("span");
                                display.className = "text-end d-block fw-semibold";
                                display.dataset.autoAmountDisplay = String(columnIndex);
                                display.textContent = "";
                                td.appendChild(input);
                                td.appendChild(display);
                            } else {
                                td.appendChild(input);
                            }

                            tr.appendChild(td);
                        }
                    );

                    if (showLineAmount) {
                        const amountCell = document.createElement("td");
                        amountCell.className = "text-end text-nowrap line-amount-value";
                        tr.appendChild(amountCell);
                    }

                    const action =
                        document.createElement(
                            "td"
                        );

                    action.className =
                        "text-center";

                    const remove =
                        document.createElement(
                            "button"
                        );

                    remove.type = "button";

                    remove.className =
                        "btn btn-outline-danger btn-sm";

                    remove.textContent = "×";


                    remove.addEventListener(
                        "click",
                        function () {

                            captureRows();

                            if (
                                liveRows.length <= 1
                            ) {

                                liveRows = [
                                    Array(
                                        columns.length
                                    ).fill("")
                                ];

                            } else {

                                liveRows.splice(
                                    rowIndex,
                                    1
                                );
                            }

                            renderTable();
                        }
                    );


                    action.appendChild(
                        remove
                    );

                    tr.appendChild(action);

                    quotationBody.appendChild(
                        tr
                    );
                }
            );
            updateLiveAmounts();
        }


        function renderAll() {
            renderTable();
        }


        // =================================================
        // =================================================
                // ADD ROW
        // =================================================

        document
            .getElementById(
                "addRowBtn"
            )
            .addEventListener(
                "click",
                function () {

                    captureRows();

                    liveRows.push(
                        Array(
                            columns.length
                        ).fill("")
                    );

                    renderTable();
                }
            );


        // =================================================
        // COMMERCIAL TERMS
        // =================================================

        const commercialBody =
            document.getElementById(
                "commercialBody"
            );

        function reindexCommercial() {

            commercialBody
                .querySelectorAll("tr")
                .forEach(
                    function (row, index) {
                        row.querySelector(
                            ".commercial-sr"
                        ).textContent =
                            index + 1;
                    }
                );
        }

        function bindCommercialRemove() {

            commercialBody
                .querySelectorAll(
                    ".remove-commercial"
                )
                .forEach(
                    function (button) {

                        button.onclick =
                            function () {
                                this.closest(
                                    "tr"
                                ).remove();

                                reindexCommercial();
                            };
                    }
                );
        }

        document
            .getElementById(
                "addCommercialBtn"
            )
            .addEventListener(
                "click",
                function () {

                    const tr =
                        document.createElement(
                            "tr"
                        );

                    tr.innerHTML = `
                        <td class="text-center fw-bold commercial-sr"></td>

                        <td>
                            <input
                                type="text"
                                name="commercial_particular[]"
                                class="form-control form-control-sm"
                            >
                        </td>

                        <td>
                            <textarea
                                name="commercial_value[]"
                                class="form-control form-control-sm"
                                rows="2"
                            ></textarea>
                        </td>

                        <td class="text-center">
                            <button
                                type="button"
                                class="btn btn-outline-danger btn-sm remove-commercial"
                            >×</button>
                        </td>
                    `;

                    commercialBody.appendChild(
                        tr
                    );

                    bindCommercialRemove();
                    reindexCommercial();
                }
            );

        bindCommercialRemove();
        reindexCommercial();


        // =================================================
        // GENERAL TERMS
        // =================================================

        const generalTerms =
            document.getElementById(
                "generalTerms"
            );

        function reindexGeneral() {

            generalTerms
                .querySelectorAll(
                    ".general-condition-row"
                )
                .forEach(
                    function (row, index) {

                        row.querySelector(
                            ".general-condition-number"
                        ).textContent =
                            index + 1;
                    }
                );
        }

        function bindGeneralRemove() {

            generalTerms
                .querySelectorAll(
                    ".remove-general"
                )
                .forEach(
                    function (button) {

                        button.onclick =
                            function () {

                                this.closest(
                                    ".general-condition-row"
                                ).remove();

                                reindexGeneral();
                            };
                    }
                );
        }

        document
            .getElementById(
                "addGeneralBtn"
            )
            .addEventListener(
                "click",
                function () {

                    const row =
                        document.createElement(
                            "div"
                        );

                    row.className =
                        "general-condition-row";

                    row.innerHTML = `
                        <div class="general-condition-number"></div>

                        <textarea
                            name="general_condition[]"
                            class="form-control form-control-sm"
                            rows="2"
                        ></textarea>

                        <button
                            type="button"
                            class="btn btn-outline-danger btn-sm remove-general"
                        >×</button>
                    `;

                    generalTerms.appendChild(
                        row
                    );

                    bindGeneralRemove();
                    reindexGeneral();
                }
            );

        bindGeneralRemove();
        reindexGeneral();


        // =================================================
        // BEFORE SUBMIT
        // Capture latest table values before POST
        // =================================================

        document
            .getElementById(
                "quotationForm"
            )
            .addEventListener(
                "submit",
                function () {
                    captureRows();
                }
            );


        // Initial render
        renderAll();

    }
);

</script>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const customers = <?= json_encode($clients, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const select = document.getElementById("clientMasterSelect");
    const clientId = document.getElementById("client_id");
    if (!select || !clientId) return;
    select.value = clientId.value;
    const fieldMap = {
        company_name: "clientName",
        contact_person: "clientContactPerson",
        gstin: "clientGstin",
        mobile: "clientMobile",        email: "clientEmail",
        address: "clientAddress"
    };
    select.addEventListener("change", function () {
        const customer = customers.find(item => String(item.id) === String(this.value));
        if (!customer) {
            clientId.value = "";
            return;
        }
        clientId.value = String(customer.id);
        Object.entries(fieldMap).forEach(([key, id]) => {
            const field = document.getElementById(id);
            if (field) field.value = customer[key] || "";
        });
    });
    document.getElementById("clientName")?.addEventListener("input", function () {
        const selected = customers.find(item => String(item.id) === String(clientId.value));
        if (selected && this.value.trim() !== String(selected.company_name || "").trim()) {
            clientId.value = "";
            select.value = "";
        }
    });
});
</script>

<?php
include("../includes/footer.php");
?>
