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
    SELECT q.*
    FROM quotations q
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

mysqli_stmt_close($master_stmt);

if (!$quotation) {
    die("Quotation not found.");
}


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

    $columns[] = [
        'key'   => $column['column_key'],
        'label' => $column['column_label'],
        'unit'  => $column['unit_text'] ?? '',
        'type'  => $column['data_type'] ?? 'text',
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
    font-size: 11px;
    min-width: 900px;
}

.quote-table thead th {
    background: #4a5a6b !important;
    color: #fff !important;
    text-align: center;
    vertical-align: middle;
    font-size: 10px;
    white-space: nowrap;
}

.quote-table td {
    vertical-align: middle;
    padding: 5px;
}

.quote-table .form-control {
    font-size: 11px;
    min-width: 95px;
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


<div class="col-xl-3 col-lg-4 col-md-6">

<label class="quote-form-label">
    Client *
</label>

<select
    name="client_id"
    id="client_id"
    class="form-select"
    required
>

<option value="">
    Select Client
</option>

<?php foreach ($clients as $client) { ?>

<option
    value="<?= intval($client['id']); ?>"
    <?= intval($quotation['client_id'] ?? 0) === intval($client['id'])
        ? 'selected'
        : '';
    ?>
>
    <?= htmlspecialchars(
        $client['company_name']
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


<!-- CLIENT DETAIL PREVIEW -->

<div class="col-12">

<div
    id="clientDetailBox"
    class="client-detail-box mt-1"
>

<div class="row g-2">

    <div class="col-md-3">
        <div class="client-detail-label">Contact Person</div>
        <div class="client-detail-value" id="client_contact">-</div>
    </div>

    <div class="col-md-3">
        <div class="client-detail-label">GSTIN</div>
        <div class="client-detail-value" id="client_gstin">-</div>
    </div>

    <div class="col-md-3">
        <div class="client-detail-label">Mobile</div>
        <div class="client-detail-value" id="client_mobile">-</div>
    </div>

    <div class="col-md-3">
        <div class="client-detail-label">Email</div>
        <div class="client-detail-value" id="client_email">-</div>
    </div>

    <div class="col-12">
        <div class="client-detail-label">Address</div>
        <div class="client-detail-value" id="client_address">-</div>
    </div>

</div>

</div>

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
    <div class="quote-section-title">
        Quotation Table
    </div>
    <div class="quote-section-note">
        Edit row values and terms. Table columns stay with the assigned quotation template.
    </div>
</div>

<div class="d-flex gap-1">
    <button
        type="button"
        class="btn btn-outline-primary btn-sm"
        id="addColumnBtn"
        disabled
    >
        Columns fixed by template
    </button>

    <button
        type="button"
        class="btn btn-primary btn-sm"
        id="addRowBtn"
    >
        + Row
    </button>
</div>

</div>


<div
    class="column-config-wrap mb-3"
    id="columnConfig"
></div>


<div class="table-responsive">

<table class="table table-bordered quote-table mb-0">

<thead>
<tr id="quotationHead"></tr>
</thead>

<tbody id="quotationBody"></tbody>

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
        // CLIENT DETAILS
        // =================================================

        const clientSelect =
            document.getElementById(
                "client_id"
            );

        const clientDetailBox =
            document.getElementById(
                "clientDetailBox"
            );

        function showClientDetails() {

            const clientId =
                Number(
                    clientSelect.value
                );

            const client =
                clientsData.find(
                    item =>
                        Number(item.id) ===
                        clientId
                );

            if (!client) {
                clientDetailBox.style.display =
                    "none";
                return;
            }

            document.getElementById(
                "client_contact"
            ).textContent =
                client.contact_person || "-";

            document.getElementById(
                "client_gstin"
            ).textContent =
                client.gstin || "-";

            document.getElementById(
                "client_mobile"
            ).textContent =
                client.mobile || "-";

            document.getElementById(
                "client_email"
            ).textContent =
                client.email || "-";

            document.getElementById(
                "client_address"
            ).textContent =
                client.address || "-";

            clientDetailBox.style.display =
                "block";
        }

        clientSelect.addEventListener(
            "change",
            showClientDetails
        );

        showClientDetails();


        // =================================================
        // ELEMENTS
        // =================================================

        const columnConfig =
            document.getElementById(
                "columnConfig"
            );

        const quotationHead =
            document.getElementById(
                "quotationHead"
            );

        const quotationBody =
            document.getElementById(
                "quotationBody"
            );


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


        // =================================================
        // COLUMN EDITOR
        // =================================================

        function renderColumnEditors() {

            columnConfig.innerHTML = "";

            columns.forEach(
                function (column, index) {

                    const box =
                        document.createElement(
                            "div"
                        );

                    box.className =
                        "column-editor";

                    box.innerHTML = `
                        <button
                            type="button"
                            class="column-remove"
                            disabled
                            title="Remove Column"
                        >×</button>

                        <div class="column-editor-title">
                            Column ${index + 1}
                        </div>

                        <div class="mb-2">
                            <label class="quote-form-label">
                                Heading
                            </label>
                            <input
                                type="text"
                                name="column_label[]" disabled
                                class="form-control form-control-sm column-label"
                                value=""
                                required
                            >
                        </div>

                        <div class="mb-2">
                            <label class="quote-form-label">
                                Unit
                            </label>
                            <input
                                type="text"
                                name="column_unit[]" disabled
                                class="form-control form-control-sm column-unit"
                                value=""
                                placeholder="KG / ₹/PCS"
                            >
                        </div>

                        <div class="mb-2">
                            <label class="quote-form-label">
                                Type
                            </label>
                            <select
                                name="column_type[]" disabled
                                class="form-select form-select-sm column-type"
                            >
                                <option value="text">Text</option>
                                <option value="number">Number</option>
                                <option value="currency">Currency</option>
                                <option value="textarea">Textarea</option>
                            </select>
                        </div>

                        <div>
                            <label class="quote-form-label">
                                Align
                            </label>
                            <select
                                name="column_align[]" disabled
                                class="form-select form-select-sm column-align"
                            >
                                <option value="left">Left</option>
                                <option value="center">Center</option>
                                <option value="right">Right</option>
                            </select>
                        </div>
                    `;

                    const label =
                        box.querySelector(
                            ".column-label"
                        );

                    const unit =
                        box.querySelector(
                            ".column-unit"
                        );

                    const type =
                        box.querySelector(
                            ".column-type"
                        );

                    const align =
                        box.querySelector(
                            ".column-align"
                        );

                    label.value =
                        column.label || "";

                    unit.value =
                        normalizeCurrencyUnit(
                            column.unit || ""
                        );

                    column.unit = unit.value;

                    type.value =
                        column.type || "text";

                    align.value =
                        column.align || "left";


                    label.addEventListener(
                        "input",
                        function () {
                            column.label =
                                this.value;
                            renderHeadOnly();
                        }
                    );

                    unit.addEventListener(
                        "input",
                        function () {
                            column.unit =
                                this.value;
                            renderHeadOnly();
                        }
                    );

                    type.addEventListener(
                        "change",
                        function () {

                            column.type =
                                this.value;

                            if (
                                column.type ===
                                "currency"
                            ) {

                                let oldUnit =
                                    String(
                                        column.unit || ""
                                    );

                                oldUnit =
                                    oldUnit.replace(
                                        /^[₹$€£]/u,
                                        ""
                                    );

                                if (oldUnit === "") {
                                    oldUnit = "/PCS";
                                }

                                if (
                                    !oldUnit.startsWith(
                                        "/"
                                    )
                                ) {
                                    oldUnit =
                                        "/" + oldUnit;
                                }

                                column.unit =
                                    getCurrencySymbol() +
                                    oldUnit;
                            }

                            captureRows();
                            renderAll();
                        }
                    );

                    align.addEventListener(
                        "change",
                        function () {
                            column.align =
                                this.value;
                            renderTable();
                        }
                    );


                    box
                        .querySelector(
                            ".column-remove"
                        )
                        .addEventListener(
                            "click",
                            function () {

                                if (
                                    columns.length <= 1
                                ) {
                                    alert(
                                        "At least one column is required."
                                    );
                                    return;
                                }

                                captureRows();

                                columns.splice(
                                    index,
                                    1
                                );

                                liveRows =
                                    liveRows.map(
                                        row => {
                                            const copy =
                                                [...row];
                                            copy.splice(
                                                index,
                                                1
                                            );
                                            return copy;
                                        }
                                    );

                                renderAll();
                            }
                        );


                    columnConfig.appendChild(
                        box
                    );
                }
            );
        }


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


                            td.appendChild(input);

                            tr.appendChild(td);
                        }
                    );


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
        }


        function renderAll() {
            renderColumnEditors();
            renderTable();
        }


        // =================================================
        // ADD COLUMN
        // =================================================

        document
            .getElementById(
                "addColumnBtn"
            )
            .addEventListener(
                "click",
                function () {

                    captureRows();

                    columns.push({
                        key:
                            "col_" +
                            (
                                columns.length +
                                1
                            ),
                        label:
                            "New Column",
                        unit: "",
                        type: "text",
                        align: "left"
                    });

                    liveRows =
                        liveRows.map(
                            function (row) {
                                row.push("");
                                return row;
                            }
                        );

                    renderAll();
                }
            );


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


<?php
include("../includes/footer.php");
?>
