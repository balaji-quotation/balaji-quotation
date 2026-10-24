<?php

session_start();


// =====================================================
// CSRF TOKEN
// =====================================================

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}


if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

include("../includes/db.php");
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/quotation_numbering.php';
require_once __DIR__ . '/../includes/product_master.php';
erpRequireCompanyUser($conn);
erpRequire('quotations.add');
$companyId = erpCurrentCompanyId();
$userId = (int)$_SESSION['user_id'];
$templateStmt = $conn->prepare(
    "SELECT v.id, v.config_json
     FROM users u
     JOIN quotation_template_versions v ON v.id = u.assigned_template_version_id
     JOIN quotation_templates t ON t.id = v.template_id
     WHERE u.id = ? AND u.company_id = ? AND t.company_id = ? AND u.status = 'Active' AND t.is_active = 1
     LIMIT 1"
);
$templateStmt->bind_param('iii', $userId, $companyId, $companyId);
$templateStmt->execute();
$templateRow = $templateStmt->get_result()->fetch_assoc();
$templateStmt->close();
if (!$templateRow) {
    http_response_code(409);
    exit('No active quotation template is assigned to this user. Contact your administrator.');
}
$quotationTemplateVersionId = (int)$templateRow['id'];
$templateConfig = json_decode($templateRow['config_json'], true);
if (!is_array($templateConfig)) {
    http_response_code(500);
    exit('Assigned quotation template is invalid.');
}
include("../includes/header.php");
include("../includes/sidebar.php");


// =====================================================
// CLIENTS
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

$client_result =
    mysqli_query(
        $conn,
        $client_sql
    );

if (!$client_result) {
    die(
        "Client Load Error: " .
        mysqli_error($conn)
    );
}

while (
    $client =
    mysqli_fetch_assoc(
        $client_result
    )
) {
    $clients[] = $client;
}


// =====================================================
// PRODUCTS
// Company-scoped catalog for optional quotation row selection.
// =====================================================

$products = [];

$catalogColumns = [];
foreach (['description', 'part_number', 'drawing_number', 'material', 'unit', 'custom_fields_json'] as $catalogColumn) {
    if (productMasterColumnExists($conn, $catalogColumn)) {
        $catalogColumns[] = $catalogColumn;
    }
}
$productSelectColumns = $catalogColumns
    ? ', ' . implode(', ', array_map(static fn($column) => '`' . $column . '`', $catalogColumns))
    : '';
$productMasterFieldMap = array_map(
    static fn($field) => [
        'key' => (string)$field['field_key'],
        'label' => (string)$field['label'],
    ],
    productMasterFieldDefinitions($conn)
);
$product_sql = "
    SELECT
        id,
        product_code,
        product_name,
        default_rate" . $productSelectColumns . "
    FROM products
    WHERE company_id = $companyId
    ORDER BY product_name ASC
";

$product_result =
    mysqli_query(
        $conn,
        $product_sql
    );

if (!$product_result) {
    die(
        "Product Load Error: " .
        mysqli_error($conn)
    );
}

while (
    $product =
    mysqli_fetch_assoc(
        $product_result
    )
) {
    $product['custom_fields'] = productMasterDecodeValues($product['custom_fields_json'] ?? null);
    unset($product['custom_fields_json']);
    $products[] = $product;
}


// =====================================================
// COMPANY SETTINGS / QUOTATION PREFIX
// =====================================================

$quotation_prefix =
    'UT/QTN/';
$quotation_start_number = 1;

$setting_sql = "
    SELECT quotation_prefix, quotation_start_number
    FROM quotation_company_settings
    WHERE company_id = $companyId
    ORDER BY id ASC
    LIMIT 1
";

$setting_result =
    mysqli_query(
        $conn,
        $setting_sql
    );

if (
    $setting_result &&
    mysqli_num_rows(
        $setting_result
    ) > 0
) {

    $setting =
        mysqli_fetch_assoc(
            $setting_result
        );

    if (
        !empty(
            $setting[
                'quotation_prefix'
            ]
        )
    ) {

        $quotation_prefix =
            trim(
                $setting[
                    'quotation_prefix'
                ]
            );
    }
    $quotation_start_number = max(1, (int)($setting['quotation_start_number'] ?? 1));
}


// =====================================================
// SUGGESTED QUOTATION NUMBER
// FY format: 2026-27
// User can edit it before saving.
// =====================================================

$quotationNow = new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'));
$year = (int)$quotationNow->format('Y');
$month = (int)$quotationNow->format('n');

if ($month >= 4) {
    $fy_start = $year;
    $fy_end = $year + 1;
} else {
    $fy_start = $year - 1;
    $fy_end = $year;
}

$fy_text =
    $fy_start .
    '-' .
    substr(
        strval($fy_end),
        -2
    );

$next_serial = quotationNextSequence(
    $conn,
    $companyId,
    $quotation_prefix,
    $fy_text,
    $year,
    $quotation_start_number
);

$suggested_quotation_no = quotationFormatNumber(
    $quotation_prefix,
    $fy_text,
    $year,
    $next_serial
);


// =====================================================
// SAVED TERMS SETS
// Load everything once so the page remains fast.
// =====================================================

$term_sets = [];

$term_set_sql = "
    SELECT
        id,
        set_name,
        description
    FROM quotation_term_sets
    WHERE company_id = $companyId
      AND is_active = 1
    ORDER BY set_name ASC
";

$term_set_result =
    mysqli_query(
        $conn,
        $term_set_sql
    );

if (!$term_set_result) {
    die(
        "Terms Set Load Error: " .
        mysqli_error($conn)
    );
}

while (
    $term_set =
    mysqli_fetch_assoc(
        $term_set_result
    )
) {

    $term_set_id =
        intval(
            $term_set[
                'id'
            ]
        );

    $term_sets[
        $term_set_id
    ] = [
        'id' =>
            $term_set_id,

        'set_name' =>
            $term_set[
                'set_name'
            ],

        'description' =>
            $term_set[
                'description'
            ],

        'commercial' => [],
        'general' => []
    ];
}


if (count($term_sets) > 0) {

    $commercial_set_sql = "
        SELECT
            term_set_id,
            particular,
            term_value,
            sort_order
        FROM quotation_term_set_commercial
        WHERE is_visible = 1
        ORDER BY
            term_set_id ASC,
            sort_order ASC,
            id ASC
    ";

    $commercial_set_result =
        mysqli_query(
            $conn,
            $commercial_set_sql
        );

    if (!$commercial_set_result) {
        die(
            "Commercial Terms Set Error: " .
            mysqli_error($conn)
        );
    }

    while (
        $commercial =
        mysqli_fetch_assoc(
            $commercial_set_result
        )
    ) {

        $set_id =
            intval(
                $commercial[
                    'term_set_id'
                ]
            );

        if (
            isset(
                $term_sets[
                    $set_id
                ]
            )
        ) {

            $term_sets[
                $set_id
            ][
                'commercial'
            ][] = [
                'particular' =>
                    $commercial[
                        'particular'
                    ],

                'term_value' =>
                    $commercial[
                        'term_value'
                    ]
            ];
        }
    }


    $general_set_sql = "
        SELECT
            term_set_id,
            condition_text,
            sort_order
        FROM quotation_term_set_general
        WHERE is_visible = 1
        ORDER BY
            term_set_id ASC,
            sort_order ASC,
            id ASC
    ";

    $general_set_result =
        mysqli_query(
            $conn,
            $general_set_sql
        );

    if (!$general_set_result) {
        die(
            "General Terms Set Error: " .
            mysqli_error($conn)
        );
    }

    while (
        $general =
        mysqli_fetch_assoc(
            $general_set_result
        )
    ) {

        $set_id =
            intval(
                $general[
                    'term_set_id'
                ]
            );

        if (
            isset(
                $term_sets[
                    $set_id
                ]
            )
        ) {

            $term_sets[
                $set_id
            ][
                'general'
            ][] = [
                'condition_text' =>
                    $general[
                        'condition_text'
                    ]
            ];
        }
    }
}

$term_sets_json =
    json_encode(
        $term_sets,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

?>

<style>

.quote-builder-card {
    border: 0;
    border-radius: 12px;
    box-shadow: 0 5px 20px rgba(31,45,61,.06);
}

.quote-builder-title {
    font-weight: 700;
    color: #26374a;
}

.quote-builder-subtitle {
    font-size: 11px;
    color: #7b8794;
}

.quote-section-title {
    font-size: 14px;
    font-weight: 700;
    color: #233f6b;
}

.quote-section-note {
    font-size: 10px;
    color: #7b8794;
}

.quote-form-label {
    font-size: 11px;
    font-weight: 600;
    color: #526173;
    margin-bottom: 5px;
}

.quote-corporate-table {
    min-width: 980px;
    font-size: 12px;
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

.quote-corporate-table thead th {
    position: sticky;
    top: 0;
    z-index: 2;
    background: #203d68 !important;
    color: #ffffff !important;
    border-color: #405985 !important;
    text-align: center;
    vertical-align: middle;
    font-size: 12px;
    line-height: 1.35;
    padding: 13px 11px;
    font-weight: 700;
}

.quote-corporate-table tbody td {
    vertical-align: middle;
    padding: 12px 10px;
    background: #fff;
}

.quote-corporate-table tbody tr:hover td {
    background: #f8fbff;
}

.quote-corporate-table .dynamic-cell-input {
    min-height: 39px;
    min-width: 105px;
    font-size: 13px;
    border-color: #d8e0ea;
    border-radius: 7px;
}

.quote-corporate-table textarea.dynamic-cell-input {
    min-height: 58px;
}

.quote-corporate-table .form-control:focus,
.quote-corporate-table .form-select:focus {
    border-color: #7ba8e8;
    box-shadow: 0 0 0 .18rem rgba(61, 130, 220, .13);
}

.quote-corporate-table .product-master-inline {
    margin: 0 0 7px;
    padding: 0 0 7px;
    border-bottom: 1px solid #e7edf5;
}

.quote-corporate-table .product-master-inline-label {
    margin-bottom: 4px;
    color: #526173;
    font-size: 10px;
    font-weight: 600;
}

.quote-corporate-table .product-master-picker {
    width: 100%;
    min-width: 150px;
    min-height: 36px;
    border-color: #d8e0ea;
    border-radius: 7px;
    font-size: 12px;
}

.quote-corporate-table .save-to-product-checkbox {
    flex: 0 0 auto;
}

.quote-corporate-table .line-amount-value,
.quote-corporate-table [data-auto-amount-display] {
    color: #173b6d;
    font-size: 13px;
    font-weight: 700;
    white-space: nowrap;
}

.quote-corporate-table tfoot th {
    background: #f3f7fd;
    color: #233f6b;
    padding: 11px;
    border-color: #e1e7ef;
}

.quote-items-actions {
    display: flex;
    align-items: center;
    gap: 8px;
}

.quote-items-actions .btn {
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

.quote-corporate-table tbody td:first-child {
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
    .quote-items-actions {
        width: 100%;
    }

    .quote-items-actions .btn {
        flex: 1 1 auto;
    }

    .quote-builder-card .card-body {
        padding: 14px;
    }

    .quote-corporate-table {
        min-width: 850px;
    }
}

.column-config {
    background: #f7f9fb;
    border: 1px solid #e7ebf0;
    border-radius: 10px;
    padding: 10px;
    margin-bottom: 10px;
}

.column-config-grid {
    display: grid;
    grid-template-columns:
        minmax(160px, 1.8fr)
        minmax(85px, .8fr)
        minmax(100px, 1fr)
        minmax(90px, .8fr)
        42px;
    gap: 7px;
    align-items: end;
}

.dynamic-cell-input {
    min-width: 90px;
    font-size: 11px;
}

.terms-table {
    font-size: 11px;
}

.terms-table thead th {
    background: #233f6b !important;
    color: #ffffff !important;
    text-align: center;
    vertical-align: middle;
}

.general-term-row {
    display: grid;
    grid-template-columns: 42px 1fr 42px;
    gap: 8px;
    align-items: start;
    margin-bottom: 8px;
}

.general-term-no {
    width: 42px;
    height: 38px;
    border-radius: 7px;
    background: #f1f4f8;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    color: #233f6b;
}

.builder-actions {
    position: sticky;
    bottom: 0;
    z-index: 20;
    background: rgba(255,255,255,.96);
    border-top: 1px solid #e7ebf0;
    padding: 10px 0;
    margin-top: 12px;
}

@media (max-width: 767.98px) {
    .column-config-grid {
        grid-template-columns: 1fr;
    }

    .general-term-row {
        grid-template-columns: 34px 1fr 38px;
    }
}

.customer-options summary { cursor:pointer; color:#315b91; font-size:12px; font-weight:600; }
.client-information-panel { background:#f8fbff; border:1px solid #e2eaf4; border-radius:10px; padding:14px; }
.customer-options select { max-width:100%; }
#toggleQuickClient { text-decoration:none; }
</style>


<div class="content-page">

<div class="content">

<div class="container-fluid">


<form
    action="save.php"
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


<!-- =====================================================
     HEADER
===================================================== -->

<div class="card quote-builder-card mt-2 mb-2">

<div class="card-body py-2 px-3">

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2">

    <div>

        <h4 class="quote-builder-title mb-1">
            One-Page Quotation Builder
        </h4>

        <div class="quote-builder-subtitle">
            RFQ to corporate quotation — dynamic table, terms and conditions
        </div>

    </div>

    <a
        href="index.php"
        class="btn btn-secondary btn-sm"
    >
        ← Quotation List
    </a>

</div>

</div>

</div>


<!-- =====================================================
     BASIC DETAILS
===================================================== -->

<div class="card quote-builder-card mb-2">

<div class="card-body">

<div class="quote-section-title mb-1">
    Quotation Details
</div>

<div class="quote-section-note mb-3">
    Common quotation information
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
        $suggested_quotation_no
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
        <input type="hidden" name="client_id" id="clientId" value="">
        <details class="customer-options">
            <summary>Choose existing customer</summary>
            <select id="clientMasterSelect" class="form-select form-select-sm mt-2">
                <option value="">Select from Customer Master</option>
                <?php foreach ($clients as $client) { ?>
                <option value="<?= intval($client['id']); ?>"><?= htmlspecialchars($client['company_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></option>
                <?php } ?>
            </select>
        </details>
    </div>
    <div class="row g-2">
        <div class="col-lg-4 col-md-6">
            <label class="quote-form-label">Customer / Company Name *</label>
            <input type="text" name="client_name" id="clientName" class="form-control" maxlength="150" autocomplete="off" required>
        </div>
        <div class="col-lg-4 col-md-6">
            <label class="quote-form-label">Contact Person</label>
            <input type="text" name="client_contact_person" id="clientContactPerson" class="form-control" maxlength="150">
        </div>
        <div class="col-lg-4 col-md-6">
            <label class="quote-form-label">GSTIN</label>
            <input type="text" name="client_gstin" id="clientGstin" class="form-control" maxlength="30">
        </div>
        <div class="col-lg-3 col-md-6">
            <label class="quote-form-label">Mobile</label>
            <input type="text" name="client_mobile" id="clientMobile" class="form-control" maxlength="20">
        </div>
        <div class="col-lg-3 col-md-6">
            <label class="quote-form-label">Alternate Mobile</label>
            <input type="text" name="client_alternate_mobile" id="clientAlternateMobile" class="form-control" maxlength="30">
        </div>
        <div class="col-lg-6 col-md-12">
            <label class="quote-form-label">Email</label>
            <input type="email" name="client_email" id="clientEmail" class="form-control" maxlength="100">
        </div>
        <div class="col-12">
            <label class="quote-form-label">Billing Address</label>
            <textarea name="client_address" id="clientAddress" class="form-control" rows="2"></textarea>
        </div>
    </div>
</div>

<div class="col-xl-2 col-lg-4 col-md-6">

<label class="quote-form-label">
</label>

<input
    type="text"
    name="rfq_no"
    class="form-control"
    placeholder="Client RFQ No."
>

</div>


<div class="col-xl-2 col-lg-4 col-md-6">

<label class="quote-form-label">
    RFQ Date
</label>

<input
    type="date"
    name="rfq_date"
    class="form-control"
>

</div>


<div class="col-xl-2 col-lg-4 col-md-6">

<label class="quote-form-label">
    Quote Date *
</label>

<input
    type="date"
    name="quotation_date"
    class="form-control"
    value="<?= date('Y-m-d'); ?>"
    required
>

</div>


<div class="col-xl-5 col-lg-6 col-md-12">

<label class="quote-form-label">
    Subject
</label>

<input
    type="text"
    name="subject"
    class="form-control"
    placeholder="Quotation for..."
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

<option value="INR" selected>
    INR ₹
</option>

<option value="USD">
    USD $
</option>

<option value="EUR">
    EUR €
</option>

<option value="GBP">
    GBP £
</option>

</select>

</div>


<div class="col-xl-3 col-lg-3 col-md-6">

<label class="quote-form-label">
    Offer Validity
</label>

<input
    type="text"
    name="validity_text"
    class="form-control"
    value="30 Days"
    placeholder="e.g. 30 Days"
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

<option value="Draft" selected>
    Draft
</option>

<option value="Sent">
    Sent
</option>

<option value="Accepted">
    Accepted
</option>

<option value="Rejected">
    Rejected
</option>

<option value="Expired">
    Expired
</option>

</select>

</div>


</div>

</div>

</div>


<!-- =====================================================
     DYNAMIC QUOTATION TABLE
===================================================== -->

<div class="card quote-builder-card mb-2">

<div class="card-body">

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
        <div class="quote-section-title">Items</div>
        <div class="quote-section-note">Enter the products and rates for this quotation.</div>
    </div>
    <div class="quote-items-actions">
        <?php if (erpCan('products.view')): ?><a href="../products/index.php" class="btn btn-outline-primary btn-sm">Product Master</a><?php endif; ?>
        <button type="button" class="btn btn-primary btn-sm" id="addRow">+ Add Item</button>
    </div>
</div>



<div class="table-responsive quote-table-wrap">

<table class="table table-bordered quote-corporate-table">

<thead>

<tr id="quotationColumns">

    <th style="width:55px;">
        Sr.
    </th>

</tr>

</thead>

<tbody id="quotationBody"></tbody>
<tfoot id="quotationTotals"></tfoot>

</table>

</div>

</div>

</div>


<!-- =====================================================
     SAVED TERMS SET
===================================================== -->

<div class="card quote-builder-card mb-2">

<div class="card-body">

<div class="row g-2 align-items-end">

<div class="col-xl-5 col-lg-6">

<label class="quote-form-label">
    Saved Terms Set
</label>

<select
    id="savedTermsSet"
    class="form-select"
>

<option value="">
    Use Default Terms
</option>

<?php foreach ($term_sets as $term_set) { ?>

<option
    value="<?= intval(
        $term_set[
            'id'
        ]
    ); ?>"
>
    <?= htmlspecialchars(
        $term_set[
            'set_name'
        ]
    ); ?>
</option>

<?php } ?>

</select>

</div>


<div class="col-xl-3 col-lg-4">

<button
    type="button"
    class="btn btn-outline-primary"
    id="loadTermsSet"
>
    Load Terms
</button>

</div>

</div>

</div>

</div>


<!-- =====================================================
     COMMERCIAL TERMS
===================================================== -->

<div class="card quote-builder-card mb-2">

<div class="card-body">

<div class="d-flex justify-content-between align-items-center mb-3">

    <div>

        <div class="quote-section-title">
            Commercial Terms
        </div>

        <div class="quote-section-note">
            Sr. | Particulars | Terms
        </div>

    </div>

    <button
        type="button"
        class="btn btn-outline-primary btn-sm"
        id="addCommercialTerm"
    >
        + Add Term
    </button>

</div>


<div class="table-responsive">

<table class="table table-bordered terms-table mb-0">

<thead>

<tr>

    <th style="width:60px;">
        Sr.
    </th>

    <th style="width:28%;">
        Particulars
    </th>

    <th>
        Terms
    </th>

    <th style="width:55px;">
        Action
    </th>

</tr>

</thead>

<tbody id="commercialTermsBody"></tbody>

</table>

</div>

</div>

</div>


<!-- =====================================================
     GENERAL TERMS
===================================================== -->

<div class="card quote-builder-card mb-2">

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
        id="addGeneralTerm"
    >
        + Add Condition
    </button>

</div>


<div id="generalTermsBody"></div>

</div>

</div>


<!-- =====================================================
     REMARKS
===================================================== -->

<div class="card quote-builder-card mb-2">

<div class="card-body">

<div class="quote-section-title mb-2">
    Internal / Quotation Remarks
</div>

<textarea
    name="remarks"
    class="form-control"
    rows="3"
    placeholder="Optional remarks"
></textarea>

</div>

</div>


<!-- =====================================================
     ACTIONS
===================================================== -->

<div class="builder-actions">

<div class="d-flex justify-content-end gap-2">

<a
    href="index.php"
    class="btn btn-secondary"
>
    Cancel
</a>

<button
    type="submit"
    class="btn btn-success"
>
    <i class="mdi mdi-content-save-outline me-1"></i>
    Save Quotation
</button>

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
        // SAVED DATA
        // =================================================

        const termSets =
            <?= $term_sets_json ?: '{}'; ?>;

        const currencySelect =
            document.getElementById("currency");

        const currencySymbols = {
            INR: "₹",
            USD: "$",
            EUR: "€",
            GBP: "£"
        };

        function getCurrencySymbol() {
            return currencySymbols[currencySelect?.value] || "₹";
        }

        function updateCurrencyUnits() {
            const symbol = getCurrencySymbol();

            columns.forEach(function (column) {
                if (column.type !== "currency") {
                    return;
                }

                let unit = String(column.unit || "").trim();
                unit = unit.replace(/^[₹$€£]/, "");

                if (unit === "") {
                    unit = "/PCS";
                } else if (!unit.startsWith("/")) {
                    unit = "/" + unit;
                }

                column.unit = symbol + unit;
            });

            refreshQuotationTable();
        }


        // =================================================
        // DEFAULT COLUMN DEFINITIONS
        // =================================================

        const defaultColumns = <?= json_encode($templateConfig['columns'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const productCatalog = <?= json_encode($products, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const productMasterFieldMap = <?= json_encode($productMasterFieldMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        
        const calculationConfig = <?= json_encode($templateConfig['calculation'] ?? ['mode'=>'standard','formula'=>''], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const roundingConfig = <?= json_encode($templateConfig['rounding'] ?? ['decimal_places'=>2,'mode'=>'half_up'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const taxConfig = <?= json_encode($templateConfig['tax'] ?? ['gst_enabled'=>false,'gst_rate'=>0], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const totalsConfig = <?= json_encode($templateConfig['totals'] ?? ['show_line_amount'=>true,'show_subtotal'=>true,'show_tax'=>false,'show_grand_total'=>true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        const canSaveProducts = <?= erpCan('quotations.save_products_to_master') ? 'true' : 'false'; ?>;
        let columns = Array.isArray(defaultColumns) && defaultColumns.length
            ? defaultColumns
            : [{label:"Description",unit:"",type:"text",align:"left"}];


        let rowCount = 1;


        // =================================================
        // DEFAULT COMMERCIAL TERMS
        // =================================================

        const defaultCommercial = <?= json_encode($templateConfig['commercial_terms'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const defaultGeneral = <?= json_encode($templateConfig['general_terms'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;


        // =================================================
        // DOM
        // =================================================

        const quotationColumns =
            document.getElementById(
                "quotationColumns"
            );

        const quotationBody =
            document.getElementById(
                "quotationBody"
            );
        quotationBody.addEventListener("input", updateLiveAmounts);

        const commercialTermsBody =
            document.getElementById(
                "commercialTermsBody"
            );

        const generalTermsBody =
            document.getElementById(
                "generalTermsBody"
            );

        if (currencySelect) {
            currencySelect.addEventListener(
                "change",
                updateCurrencyUnits
            );
        }


        // =================================================
        // ESCAPE HTML
        // =================================================

        function escapeHtml(value) {

            return String(value ?? "")
                .replaceAll("&", "&amp;")
                .replaceAll("<", "&lt;")
                .replaceAll(">", "&gt;")
                .replaceAll('"', "&quot;")
                .replaceAll("'", "&#039;");
        }


        // =================================================
        // Refresh the item table after currency changes.
        function refreshQuotationTable() {
            renderQuotationTable();
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
        // RENDER QUOTATION HEADER
        // =================================================

        function renderQuotationHeader() {

            quotationColumns.innerHTML =
                '<th style="width:55px;">Sr.</th>';

            columns.forEach(
                function (column) {

                    const th =
                        document.createElement(
                            "th"
                        );

                    th.style.textAlign =
                        column.align;

                    th.innerHTML =
                        escapeHtml(
                            column.label
                        ) +
                        (
                            column.unit
                                ? '<div style="font-size:9px;font-weight:400;margin-top:3px;">(' +
                                  escapeHtml(
                                      column.unit
                                  ) +
                                  ')</div>'
                                : ''
                        );

                    quotationColumns.appendChild(
                        th
                    );
                }
            );

            if (showLineAmount) {
                const amountTh = document.createElement("th");
                amountTh.className = "text-end";
                amountTh.textContent = "Amount";
                quotationColumns.appendChild(amountTh);
            }

            const actionTh =
                document.createElement(
                    "th"
                );

            actionTh.style.width =
                "55px";

            actionTh.innerText =
                "Action";

            quotationColumns.appendChild(
                actionTh
            );
        }


        // =================================================
        // RENDER QUOTATION TABLE
        // =================================================

        function catalogCellValues(productId) {
            const product = productCatalog.find(function (item) {
                return String(item.id) === String(productId);
            });
            if (!product) return {};

            const normalize = function (value) {
                return String(value || "").toLowerCase().replace(/[^a-z0-9]+/g, "");
            };
            const customValues = product.custom_fields && typeof product.custom_fields === "object"
                ? product.custom_fields
                : {};
            const values = {};

            columns.forEach(function (column, index) {
                const label = normalize(column.label);
                let value = null;

                const customField = productMasterFieldMap.find(function (field) {
                    return normalize(field.label) === label || normalize(field.key) === label;
                });
                if (customField && Object.prototype.hasOwnProperty.call(customValues, customField.key)) {
                    value = customValues[customField.key];
                } else if (/^(rate|price|unitrate|unitprice|rateperunit|priceperunit)$/.test(label)) {
                    value = product.default_rate ?? "";
                } else if (label.includes("part") && label.includes("drawing")) {
                    value = [product.part_number, product.drawing_number].filter(Boolean).join(" / ");
                } else if (label.includes("drawing") && /(no|number|reference|code)/.test(label)) {
                    value = product.drawing_number || "";
                } else if (label.includes("part") && /(no|number|reference|code)/.test(label)) {
                    value = product.part_number || "";
                } else if (/^(material|materialgrade|grade|materialgrademre)$/.test(label) || label.includes("material") || label.includes("grade")) {
                    value = product.material || "";
                } else if (/^(description|productdescription|itemdescription|details)$/.test(label)) {
                    value = product.description || product.product_name || "";
                } else if (/^(productcode|itemcode|code|sku)$/.test(label)) {
                    value = product.product_code || "";
                } else if (/^(unit|uom|unitofmeasure)$/.test(label)) {
                    value = product.unit || "";
                } else if (/product|item|particular/.test(label)) {
                    value = product.product_name || "";
                }

                if (value !== null && value !== "") values[String(index)] = String(value);
            });
            return values;
        }

        function clearUnchangedCatalogValues(row, appliedValues) {
            row.querySelectorAll("[data-cell-index]").forEach(function (input) {
                const index = String(Number(input.dataset.cellIndex));
                if (
                    Object.prototype.hasOwnProperty.call(appliedValues, index) &&
                    String(input.value) === appliedValues[index]
                ) {
                    input.value = "";
                }
            });
        }

        function applyCatalogProduct(row, productId) {
            const values = catalogCellValues(productId);
            row.querySelectorAll("[data-cell-index]").forEach(function (input) {
                const index = String(Number(input.dataset.cellIndex));
                if (Object.prototype.hasOwnProperty.call(values, index)) {
                    input.value = values[index];
                }
            });
            return values;
        }

        function renderQuotationTable() {

            renderQuotationHeader();

            const existingValues = [];
            const existingProductIds = [];
            const existingSaveToProduct = [];

            quotationBody
                .querySelectorAll(
                    "tr"
                )
                .forEach(
                    function (row) {

                        const rowValues = [];

                        row
                            .querySelectorAll(
                                "[data-cell-index]"
                            )
                            .forEach(
                                function (input) {

                                    rowValues[
                                        Number(
                                            input.dataset.cellIndex
                                        )
                                    ] = input.value;
                                }
                            );

                        existingValues.push(
                            rowValues
                        );
                        const productPicker = row.querySelector(".product-master-picker");
                        existingProductIds.push(productPicker ? productPicker.value : "");
                        const saveProductCheckbox = row.querySelector(".save-to-product-checkbox");
                        existingSaveToProduct.push(saveProductCheckbox && saveProductCheckbox.checked ? "1" : "");
                    }
                );


            quotationBody.innerHTML = "";


            for (
                let rowIndex = 0;
                rowIndex < rowCount;
                rowIndex++
            ) {

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

                tr.appendChild(
                    sr
                );


                columns.forEach(
                    function (column, columnIndex) {

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
                            "form-control form-control-sm dynamic-cell-input";

                        input.style.textAlign =
                            column.align;

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
                            existingValues[rowIndex] &&
                            existingValues[rowIndex][columnIndex] !== undefined
                        ) {

                            input.value =
                                existingValues[rowIndex][columnIndex];
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

                        tr.appendChild(
                            td
                        );
                    }
                );

                if (showLineAmount) {
                    const amountTd = document.createElement("td");
                    amountTd.className = "text-end text-nowrap line-amount-value";
                    tr.appendChild(amountTd);
                }

                const productPicker = document.createElement("select");
                productPicker.name = `product_id[${rowIndex}]`;
                productPicker.className = "form-select form-select-sm product-master-picker";

                const emptyProductOption = document.createElement("option");
                emptyProductOption.value = "";
                emptyProductOption.textContent = "Choose from Product Master (optional)";
                productPicker.appendChild(emptyProductOption);

                productCatalog.forEach(function (product) {
                    const option = document.createElement("option");
                    option.value = String(product.id);
                    option.textContent = String(product.product_name || "").trim();
                    productPicker.appendChild(option);
                });

                productPicker.value = existingProductIds[rowIndex] || "";
                productPicker.dataset.appliedCatalogValues = productPicker.value
                    ? JSON.stringify(catalogCellValues(productPicker.value))
                    : "{}";

                productPicker.addEventListener("change", function () {
                    let previouslyApplied = {};
                    try {
                        previouslyApplied = JSON.parse(this.dataset.appliedCatalogValues || "{}");
                    } catch (error) {
                        previouslyApplied = {};
                    }
                    clearUnchangedCatalogValues(tr, previouslyApplied);

                    if (this.value !== "") {
                        this.dataset.appliedCatalogValues = JSON.stringify(applyCatalogProduct(tr, this.value));
                    } else {
                        this.dataset.appliedCatalogValues = "{}";
                    }

                    const saveProductCheckbox = tr.querySelector(".save-to-product-checkbox");
                    if (saveProductCheckbox) {
                        saveProductCheckbox.disabled = this.value !== "";
                        if (saveProductCheckbox.disabled) saveProductCheckbox.checked = false;
                    }
                    updateLiveAmounts();
                });

                const productWrap = document.createElement("div");
                productWrap.className = "product-master-inline";
                const productLabel = document.createElement("div");
                productLabel.className = "product-master-inline-label";
                productLabel.textContent = "Product Master";
                productWrap.appendChild(productLabel);
                productWrap.appendChild(productPicker);

                if (canSaveProducts) {
                    const saveProductLabel = document.createElement("label");
                    saveProductLabel.className = "small text-muted d-flex align-items-center gap-1 mt-2";
                    const saveProductCheckbox = document.createElement("input");
                    saveProductCheckbox.type = "checkbox";
                    saveProductCheckbox.name = `save_to_product[${rowIndex}]`;
                    saveProductCheckbox.value = "1";
                    saveProductCheckbox.className = "form-check-input mt-0 save-to-product-checkbox";
                    saveProductCheckbox.checked = existingSaveToProduct[rowIndex] === "1";
                    saveProductCheckbox.disabled = productPicker.value !== "";
                    if (saveProductCheckbox.disabled) saveProductCheckbox.checked = false;
                    saveProductLabel.appendChild(saveProductCheckbox);
                    const saveProductText = document.createElement("span");
                    saveProductText.textContent = "Save manual item to Product Master";
                    saveProductLabel.appendChild(saveProductText);
                    productWrap.appendChild(saveProductLabel);
                }

                const descriptionIndex = columns.findIndex(function (column) {
                    const label = String(column.label || "").toLowerCase().replace(/[^a-z0-9]+/g, " ").trim();
                    return amountColumnRole(column) !== "auto_amount"
                        && /^(description|item description|product name|item name|particular|particulars)$/.test(label);
                });
                const fallbackProductIndex = columns.findIndex(function (column) {
                    const label = String(column.label || "").toLowerCase();
                    return amountColumnRole(column) !== "auto_amount"
                        && /description|product|item|particular|name/.test(label);
                });
                const firstVisibleIndex = columns.findIndex(function (column) {
                    return amountColumnRole(column) !== "auto_amount";
                });
                const targetColumnIndex = descriptionIndex >= 0
                    ? descriptionIndex
                    : (fallbackProductIndex >= 0 ? fallbackProductIndex : Math.max(0, firstVisibleIndex));
                const productCell = tr.children[targetColumnIndex + 1];
                if (productCell) productCell.prepend(productWrap);

                const actionTd =
                    document.createElement(
                        "td"
                    );

                actionTd.className =
                    "text-center";

                const removeButton =
                    document.createElement(
                        "button"
                    );

                removeButton.type =
                    "button";

                removeButton.className =
                    "btn btn-outline-danger btn-sm";

                removeButton.innerText =
                    "×";

                removeButton.addEventListener(
                    "click",
                    function () {

                        if (
                            rowCount <= 1
                        ) {
                            alert(
                                "At least one quotation row is required."
                            );
                            return;
                        }

                        rowCount--;

                        tr.remove();

                        reindexQuotationRows();
                    }
                );

                actionTd.appendChild(
                    removeButton
                );

                tr.appendChild(
                    actionTd
                );

                quotationBody.appendChild(
                    tr
                );
            }
            updateLiveAmounts();
        }


        // =================================================
        // REINDEX QUOTATION ROWS
        // =================================================

        function reindexQuotationRows() {

            const rows =
                quotationBody.querySelectorAll(
                    "tr"
                );

            rowCount =
                rows.length;

            rows.forEach(
                function (row, rowIndex) {

                    row.children[0].textContent =
                        rowIndex + 1;

                    row
                        .querySelectorAll(
                            "[data-cell-index]"
                        )
                        .forEach(
                            function (input) {

                                const columnIndex =
                                    Number(
                                        input.dataset.cellIndex
                                    );

                                input.name =
                                    `row_data[${rowIndex}][${columnIndex}]`;
                            }
                        );

                    const productPicker = row.querySelector(".product-master-picker");
                    if (productPicker) productPicker.name = `product_id[${rowIndex}]`;
                    const saveProductCheckbox = row.querySelector(".save-to-product-checkbox");
                    if (saveProductCheckbox) saveProductCheckbox.name = `save_to_product[${rowIndex}]`;
                }
            );
        }


        // =================================================
        // =================================================
                // ADD ROW
        // =================================================

        document
            .getElementById(
                "addRow"
            )
            .addEventListener(
                "click",
                function () {

                    rowCount++;

                    renderQuotationTable();
                }
            );


        // =================================================
        // COMMERCIAL TERMS
        // =================================================

        function renderCommercialTerms(
            rows
        ) {

            commercialTermsBody.innerHTML =
                "";

            rows.forEach(
                function (row, index) {

                    const tr =
                        document.createElement(
                            "tr"
                        );

                    tr.innerHTML = `
                        <td class="text-center fw-bold">
                            ${index + 1}
                        </td>

                        <td>
                            <input
                                type="text"
                                name="commercial_particular[]"
                                class="form-control form-control-sm"
                                value="${escapeHtml(row.particular)}"
                                required
                            >
                        </td>

                        <td>
                            <textarea
                                name="commercial_value[]"
                                class="form-control form-control-sm"
                                rows="2"
                            >${escapeHtml(row.value)}</textarea>
                        </td>

                        <td class="text-center">
                            <button
                                type="button"
                                class="btn btn-outline-danger btn-sm remove-commercial"
                            >
                                ×
                            </button>
                        </td>
                    `;

                    commercialTermsBody.appendChild(
                        tr
                    );
                }
            );


            commercialTermsBody
                .querySelectorAll(
                    ".remove-commercial"
                )
                .forEach(
                    function (button) {

                        button.addEventListener(
                            "click",
                            function () {

                                this
                                    .closest(
                                        "tr"
                                    )
                                    .remove();

                                reindexCommercialTerms();
                            }
                        );
                    }
                );
        }


        function reindexCommercialTerms() {

            commercialTermsBody
                .querySelectorAll(
                    "tr"
                )
                .forEach(
                    function (row, index) {

                        row.children[0].textContent =
                            index + 1;
                    }
                );
        }


        document
            .getElementById(
                "addCommercialTerm"
            )
            .addEventListener(
                "click",
                function () {

                    const tr =
                        document.createElement(
                            "tr"
                        );

                    const index =
                        commercialTermsBody
                            .querySelectorAll(
                                "tr"
                            )
                            .length;

                    tr.innerHTML = `
                        <td class="text-center fw-bold">
                            ${index + 1}
                        </td>

                        <td>
                            <input
                                type="text"
                                name="commercial_particular[]"
                                class="form-control form-control-sm"
                                required
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
                            >
                                ×
                            </button>
                        </td>
                    `;

                    commercialTermsBody.appendChild(
                        tr
                    );

                    tr
                        .querySelector(
                            ".remove-commercial"
                        )
                        .addEventListener(
                            "click",
                            function () {

                                tr.remove();

                                reindexCommercialTerms();
                            }
                        );
                }
            );


        // =================================================
        // GENERAL TERMS
        // =================================================

        function renderGeneralTerms(
            rows
        ) {

            generalTermsBody.innerHTML =
                "";

            rows.forEach(
                function (text, index) {

                    appendGeneralTerm(
                        text,
                        index
                    );
                }
            );
        }


        function appendGeneralTerm(
            text = "",
            forcedIndex = null
        ) {

            const index =
                forcedIndex !== null
                    ? forcedIndex
                    : generalTermsBody
                        .querySelectorAll(
                            ".general-term-row"
                        )
                        .length;

            const row =
                document.createElement(
                    "div"
                );

            row.className =
                "general-term-row";

            row.innerHTML = `
                <div class="general-term-no">
                    ${index + 1}
                </div>

                <textarea
                    name="general_condition[]"
                    class="form-control"
                    rows="2"
                    required
                >${escapeHtml(text)}</textarea>

                <button
                    type="button"
                    class="btn btn-outline-danger btn-sm remove-general"
                >
                    ×
                </button>
            `;

            generalTermsBody.appendChild(
                row
            );

            row
                .querySelector(
                    ".remove-general"
                )
                .addEventListener(
                    "click",
                    function () {

                        row.remove();

                        reindexGeneralTerms();
                    }
                );
        }


        function reindexGeneralTerms() {

            generalTermsBody
                .querySelectorAll(
                    ".general-term-row"
                )
                .forEach(
                    function (row, index) {

                        row
                            .querySelector(
                                ".general-term-no"
                            )
                            .textContent =
                            index + 1;
                    }
                );
        }


        document
            .getElementById(
                "addGeneralTerm"
            )
            .addEventListener(
                "click",
                function () {

                    appendGeneralTerm();
                }
            );


        // =================================================
        // LOAD SAVED TERMS SET
        // =================================================

        document
            .getElementById(
                "loadTermsSet"
            )
            .addEventListener(
                "click",
                function () {

                    const setId =
                        document
                            .getElementById(
                                "savedTermsSet"
                            )
                            .value;

                    if (!setId) {

                        renderCommercialTerms(
                            defaultCommercial
                        );

                        renderGeneralTerms(
                            defaultGeneral
                        );

                        return;
                    }


                    const selected =
                        termSets[setId];

                    if (!selected) {
                        return;
                    }


                    const commercialRows =
                        (
                            selected.commercial ||
                            []
                        )
                        .map(
                            function (row) {

                                return {
                                    particular:
                                        row.particular ||
                                        "",

                                    value:
                                        row.term_value ||
                                        ""
                                };
                            }
                        );


                    const generalRows =
                        (
                            selected.general ||
                            []
                        )
                        .map(
                            function (row) {

                                return (
                                    row.condition_text ||
                                    ""
                                );
                            }
                        );


                    renderCommercialTerms(
                        commercialRows.length
                            ? commercialRows
                            : defaultCommercial
                    );

                    renderGeneralTerms(
                        generalRows.length
                            ? generalRows
                            : defaultGeneral
                    );
                }
            );


        // =================================================
        // INITIAL RENDER
        // =================================================

        refreshQuotationTable();

        renderCommercialTerms(
            defaultCommercial
        );

        renderGeneralTerms(
            defaultGeneral
        );

    }
);

</script>


<script>
document.addEventListener("DOMContentLoaded", function () {
    const customers = <?= json_encode($clients, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const select = document.getElementById("clientMasterSelect");
    const clientId = document.getElementById("clientId");
    const fieldMap = {
        company_name: "clientName",
        contact_person: "clientContactPerson",
        gstin: "clientGstin",
        mobile: "clientMobile",
        alternate_mobile: "clientAlternateMobile",
        email: "clientEmail",
        address: "clientAddress"
    };
    if (!select || !clientId) return;
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
