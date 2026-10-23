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
        company_name
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

$setting_sql = "
    SELECT quotation_prefix
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
    $year
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
    min-width: 950px;
    font-size: 11px;
    margin-bottom: 0;
}

.quote-corporate-table thead th {
    background: #233f6b !important;
    color: #ffffff !important;
    border-color: #405985 !important;
    text-align: center;
    vertical-align: middle;
    font-weight: 700;
}

.quote-corporate-table td {
    vertical-align: middle;
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


<div class="col-xl-3 col-lg-4 col-md-6">

<label class="quote-form-label">
    Client *
</label>

<select
    name="client_id"
    class="form-select"
    required
>

<option value="">
    Select Client
</option>

<?php foreach ($clients as $client) { ?>

<option
    value="<?= intval(
        $client[
            'id'
        ]
    ); ?>"
>
    <?= htmlspecialchars(
        $client[
            'company_name'
        ]
    ); ?>
</option>

<?php } ?>

</select>
<button type="button" class="btn btn-sm btn-outline-primary mt-2" id="toggleQuickClient">+ Quick add customer</button>
<div class="border rounded p-2 mt-2" id="quickClientPanel" style="display:none;">
    <div class="small fw-bold mb-2">Quick add customer</div>
    <input type="text" id="quickClientName" class="form-control form-control-sm mb-2" placeholder="Customer / company name *" maxlength="150">
    <textarea id="quickClientAddress" class="form-control form-control-sm mb-2" placeholder="Billing address *" rows="2"></textarea>
    <div class="row g-2">
        <div class="col-6"><input type="text" id="quickClientMobile" class="form-control form-control-sm" placeholder="Mobile" maxlength="20"></div>
        <div class="col-6"><input type="text" id="quickClientAltMobile" class="form-control form-control-sm" placeholder="Alternate mobile" maxlength="30"></div>
    </div>
    <input type="email" id="quickClientEmail" class="form-control form-control-sm mt-2" placeholder="Email (optional)" maxlength="100">
    <div id="quickClientMessage" class="small mt-2" role="status"></div>
    <button type="button" class="btn btn-sm btn-primary mt-2" id="saveQuickClient">Save and select</button>
    <button type="button" class="btn btn-sm btn-light mt-2" id="cancelQuickClient">Cancel</button>
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
    <?php if (erpCan('products.view')): ?><a href="../products/index.php" class="btn btn-outline-primary btn-sm">Manage Product Master</a><?php endif; ?>
    <button type="button" class="btn btn-primary btn-sm" id="addRow">+ Add Item</button>
</div>



<div class="table-responsive">

<table class="table table-bordered quote-corporate-table">

<thead>

<tr id="quotationColumns">

    <th style="width:55px;">
        Sr.
    </th>

</tr>

</thead>

<tbody id="quotationBody"></tbody>

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

            const productTh = document.createElement("th");
            productTh.style.minWidth = "190px";
            productTh.textContent = "Product Master (optional)";
            quotationColumns.appendChild(productTh);

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


                        td.appendChild(
                            input
                        );

                        tr.appendChild(
                            td
                        );
                    }
                );


                const productTd = document.createElement("td");
                const productPicker = document.createElement("select");
                productPicker.name = `product_id[${rowIndex}]`;
                productPicker.className = "form-select form-select-sm product-master-picker";

                const emptyProductOption = document.createElement("option");
                emptyProductOption.value = "";
                emptyProductOption.textContent = "Manual entry";
                productPicker.appendChild(emptyProductOption);

                productCatalog.forEach(function (product) {
                    const option = document.createElement("option");
                    option.value = String(product.id);
                    const code = String(product.product_code || "").trim();
                    const name = String(product.product_name || "").trim();
                    const part = String(product.part_number || "").trim();
                    option.textContent = [code, name, part].filter(Boolean).join(" · ");
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
                });
                productTd.appendChild(productPicker);

                if (canSaveProducts) {
                    const saveProductLabel = document.createElement("label");
                    saveProductLabel.className = "small text-muted d-flex align-items-center gap-1 mt-1";
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
                    productTd.appendChild(saveProductLabel);
                }
                tr.appendChild(productTd);

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
(function () {
    var panel = document.getElementById('quickClientPanel');
    var toggle = document.getElementById('toggleQuickClient');
    var message = document.getElementById('quickClientMessage');
    var selector = document.querySelector('select[name="client_id"]');
    if (!panel || !toggle || !selector) return;
    toggle.addEventListener('click', function () { panel.style.display = panel.style.display === 'none' ? 'block' : 'none'; });
    document.getElementById('cancelQuickClient').addEventListener('click', function () { panel.style.display = 'none'; });
    document.getElementById('saveQuickClient').addEventListener('click', async function () {
        var name = document.getElementById('quickClientName').value.trim();
        var address = document.getElementById('quickClientAddress').value.trim();
        var mobile = document.getElementById('quickClientMobile').value.trim();
        var alternateMobile = document.getElementById('quickClientAltMobile').value.trim();
        var email = document.getElementById('quickClientEmail').value.trim();
        message.className = 'small mt-2 text-danger';
        if (!name || !address || (!mobile && !alternateMobile)) {
            message.textContent = 'Name, billing address, and mobile or alternate mobile are required.';
            return;
        }
        var body = new URLSearchParams({
            csrf_token: <?= json_encode($_SESSION['csrf_token']); ?>,
            company_name: name, address: address, mobile: mobile,
            alternate_mobile: alternateMobile, email: email
        });
        this.disabled = true;
        try {
            var response = await fetch('../clients/quick_save.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', 'Accept': 'application/json'},
                body: body.toString(), credentials: 'same-origin'
            });
            var result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.message || 'Customer could not be saved.');
            selector.add(new Option(result.company_name, result.id, true, true));
            selector.value = String(result.id);
            panel.style.display = 'none';
            ['quickClientName','quickClientAddress','quickClientMobile','quickClientAltMobile','quickClientEmail'].forEach(function (id) { document.getElementById(id).value = ''; });
            message.textContent = '';
        } catch (error) {
            message.textContent = error.message || 'Customer could not be saved.';
        } finally {
            this.disabled = false;
        }
    });
})();
</script>

<?php
include("../includes/footer.php");
?>
