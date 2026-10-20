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
erpRequireCompanyUser($conn);
erpRequire('quotations.add');
$companyId = erpCurrentCompanyId();
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
// Optional reference for faster manual RFQ entry later.
// =====================================================

$products = [];

$product_sql = "
    SELECT
        id,
        product_code,
        product_name,
        part_number
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

$year =
    intval(
        date('Y')
    );

$month =
    intval(
        date('n')
    );

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

$next_serial = 1;

$next_sql = "
    SELECT
        COALESCE(
            MAX(id),
            0
        ) + 1 AS next_id
    FROM quotations
    WHERE company_id = $companyId
";

$next_result =
    mysqli_query(
        $conn,
        $next_sql
    );

if ($next_result) {

    $next_row =
        mysqli_fetch_assoc(
            $next_result
        );

    $next_serial =
        intval(
            $next_row[
                'next_id'
            ] ?? 1
        );
}

$suggested_quotation_no =
    $quotation_prefix .
    $fy_text .
    '/' .
    str_pad(
        $next_serial,
        4,
        '0',
        STR_PAD_LEFT
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

</div>


<div class="col-xl-2 col-lg-4 col-md-6">

<label class="quote-form-label">
    RFQ No.
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

<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">

    <div>

        <div class="quote-section-title">
            Quotation Table
        </div>

        <div class="quote-section-note">
            Rename columns as per RFQ. Add only the columns and rows you need.
        </div>

    </div>

    <div class="d-flex gap-1 flex-wrap">

        <button
            type="button"
            class="btn btn-outline-primary btn-sm"
            id="addColumn"
        >
            + Column
        </button>

        <button
            type="button"
            class="btn btn-primary btn-sm"
            id="addRow"
        >
            + Row
        </button>

    </div>

</div>


<div
    id="columnConfigArea"
    class="mb-2"
></div>


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

            renderColumnConfig();
        }


        // =================================================
        // DEFAULT COLUMN DEFINITIONS
        // =================================================

        let columns = [
            {
                label: "Part No. / Drawing No.",
                unit: "",
                type: "text",
                align: "left"
            },
            {
                label: "Description",
                unit: "",
                type: "text",
                align: "left"
            },
            {
                label: "Material / Grade",
                unit: "",
                type: "text",
                align: "left"
            },
            {
                label: "Qty / EAU",
                unit: "",
                type: "number",
                align: "right"
            },
            {
                label: "Rate",
                unit: getCurrencySymbol() + "/PCS",
                type: "currency",
                align: "right"
            }
        ];


        let rowCount = 1;


        // =================================================
        // DEFAULT COMMERCIAL TERMS
        // =================================================

        const defaultCommercial = [
            {
                particular: "INCO Terms",
                value: "Ex-Works, Unicorn Technoflex – Bhojpara, Gondal, Rajkot."
            },
            {
                particular: "Taxes",
                value: "GST as applicable."
            },
            {
                particular: "Payment Terms",
                value: "As mutually agreed / as per Purchase Order."
            },
            {
                particular: "Delivery / Lead Time",
                value: "As mutually agreed / as per Purchase Order."
            },
            {
                particular: "Freight",
                value: "To be confirmed at the time of order."
            },
            {
                particular: "Offer Validity",
                value: "30 days from the date of this quotation."
            }
        ];


        // =================================================
        // DEFAULT GENERAL TERMS
        // =================================================

        const defaultGeneral = [
            "This quotation is based on the details received from the Buyer at the time of enquiry. Any change in that information will affect the pricing.",
            "All applicable taxes at the time of delivery will be charged as per Government of India rules prevailing on that date.",
            "The Seller does not accept any product liability except free replacement or refund against defective supplies.",
            "All commercial disputes shall be subject to Rajkot Jurisdiction only.",
            "The Purchase Order must be issued in favour of \"Unicorn Technoflex\", quoting this Quotation Number as reference."
        ];


        // =================================================
        // DOM
        // =================================================

        const columnConfigArea =
            document.getElementById(
                "columnConfigArea"
            );

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
        // RENDER COLUMN CONFIG
        // =================================================

        function renderColumnConfig() {

            columnConfigArea.innerHTML = "";

            columns.forEach(
                function (column, index) {

                    const box =
                        document.createElement(
                            "div"
                        );

                    box.className =
                        "column-config";

                    box.innerHTML = `
                        <div class="column-config-grid">

                            <div>
                                <label class="quote-form-label">
                                    Column ${index + 1}
                                </label>

                                <input
                                    type="text"
                                    name="column_label[]"
                                    class="form-control form-control-sm column-label-input"
                                    data-index="${index}"
                                    value="${escapeHtml(column.label)}"
                                    required
                                >
                            </div>

                            <div>
                                <label class="quote-form-label">
                                    Unit
                                </label>

                                <input
                                    type="text"
                                    name="column_unit[]"
                                    class="form-control form-control-sm column-unit-input"
                                    data-index="${index}"
                                    value="${escapeHtml(column.unit)}"
                                    placeholder="kg / currency unit"
                                >
                            </div>

                            <div>
                                <label class="quote-form-label">
                                    Type
                                </label>

                                <select
                                    name="column_type[]"
                                    class="form-select form-select-sm column-type-input"
                                    data-index="${index}"
                                >
                                    <option value="text" ${column.type === "text" ? "selected" : ""}>
                                        Text
                                    </option>

                                    <option value="number" ${column.type === "number" ? "selected" : ""}>
                                        Number
                                    </option>

                                    <option value="currency" ${column.type === "currency" ? "selected" : ""}>
                                        Currency
                                    </option>

                                    <option value="textarea" ${column.type === "textarea" ? "selected" : ""}>
                                        Long Text
                                    </option>
                                </select>
                            </div>

                            <div>
                                <label class="quote-form-label">
                                    Align
                                </label>

                                <select
                                    name="column_align[]"
                                    class="form-select form-select-sm column-align-input"
                                    data-index="${index}"
                                >
                                    <option value="left" ${column.align === "left" ? "selected" : ""}>
                                        Left
                                    </option>

                                    <option value="center" ${column.align === "center" ? "selected" : ""}>
                                        Center
                                    </option>

                                    <option value="right" ${column.align === "right" ? "selected" : ""}>
                                        Right
                                    </option>
                                </select>
                            </div>

                            <div>
                                <button
                                    type="button"
                                    class="btn btn-outline-danger btn-sm remove-column"
                                    data-index="${index}"
                                    title="Remove Column"
                                >
                                    ×
                                </button>
                            </div>

                        </div>
                    `;

                    columnConfigArea.appendChild(
                        box
                    );
                }
            );

            bindColumnConfigEvents();

            renderQuotationTable();
        }


        // =================================================
        // COLUMN CONFIG EVENTS
        // =================================================

        function bindColumnConfigEvents() {

            document
                .querySelectorAll(
                    ".column-label-input"
                )
                .forEach(
                    function (input) {

                        input.addEventListener(
                            "input",
                            function () {

                                const index =
                                    Number(
                                        this.dataset.index
                                    );

                                columns[index].label =
                                    this.value;

                                renderQuotationHeader();
                            }
                        );
                    }
                );


            document
                .querySelectorAll(
                    ".column-unit-input"
                )
                .forEach(
                    function (input) {

                        input.addEventListener(
                            "input",
                            function () {

                                const index =
                                    Number(
                                        this.dataset.index
                                    );

                                columns[index].unit =
                                    this.value;

                                renderQuotationHeader();
                            }
                        );
                    }
                );


            document
                .querySelectorAll(
                    ".column-type-input"
                )
                .forEach(
                    function (input) {

                        input.addEventListener(
                            "change",
                            function () {

                                const index =
                                    Number(
                                        this.dataset.index
                                    );

                                columns[index].type =
                                    this.value;
                            }
                        );
                    }
                );


            document
                .querySelectorAll(
                    ".column-align-input"
                )
                .forEach(
                    function (input) {

                        input.addEventListener(
                            "change",
                            function () {

                                const index =
                                    Number(
                                        this.dataset.index
                                    );

                                columns[index].align =
                                    this.value;

                                renderQuotationHeader();
                            }
                        );
                    }
                );


            document
                .querySelectorAll(
                    ".remove-column"
                )
                .forEach(
                    function (button) {

                        button.addEventListener(
                            "click",
                            function () {

                                if (
                                    columns.length <= 1
                                ) {
                                    alert(
                                        "At least one quotation column is required."
                                    );
                                    return;
                                }

                                const index =
                                    Number(
                                        this.dataset.index
                                    );

                                columns.splice(
                                    index,
                                    1
                                );

                                renderColumnConfig();
                            }
                        );
                    }
                );
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

        function renderQuotationTable() {

            renderQuotationHeader();

            const existingValues = [];

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
                }
            );
        }


        // =================================================
        // ADD COLUMN
        // =================================================

        document
            .getElementById(
                "addColumn"
            )
            .addEventListener(
                "click",
                function () {

                    columns.push(
                        {
                            label: "New Column",
                            unit: "",
                            type: "text",
                            align: "left"
                        }
                    );

                    renderColumnConfig();
                }
            );


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

        renderColumnConfig();

        renderCommercialTerms(
            defaultCommercial
        );

        renderGeneralTerms(
            defaultGeneral
        );

    }
);

</script>


<?php
include("../includes/footer.php");
?>
