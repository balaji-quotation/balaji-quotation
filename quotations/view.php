<?php

session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

include("../includes/db.php");
require_once __DIR__ . '/../includes/permissions.php';
erpRequireCompanyUser($conn);
erpRequire('quotations.view');
$companyId = erpCurrentCompanyId();
include("../includes/header.php");
include("../includes/sidebar.php");


// =====================================================
// QUOTATION ID
// =====================================================

$quotation_id =
    intval(
        $_GET['id'] ?? 0
    );

if ($quotation_id <= 0) {
    die("Invalid quotation ID.");
}


// =====================================================
// MASTER
// =====================================================

$master_sql = "
    SELECT
        q.*,
        c.company_name AS client_name
    FROM quotations q
    INNER JOIN clients c
        ON c.id = q.client_id
    WHERE q.id = ?
      AND q.company_id = ?
    LIMIT 1
";

$master_stmt =
    mysqli_prepare(
        $conn,
        $master_sql
    );

if (!$master_stmt) {
    die(
        "Quotation View Error: " .
        mysqli_error($conn)
    );
}

mysqli_stmt_bind_param(
    $master_stmt,
    "ii",
    $quotation_id,
    $companyId
);

mysqli_stmt_execute(
    $master_stmt
);

$master_result =
    mysqli_stmt_get_result(
        $master_stmt
    );

$quotation =
    mysqli_fetch_assoc(
        $master_result
    );

mysqli_stmt_close(
    $master_stmt
);

if (!$quotation) {
    die("Quotation not found.");
}

$canEditQuotation = (int)($quotation['created_by'] ?? 0) === (int)($_SESSION['user_id'] ?? 0);


// =====================================================
// DYNAMIC COLUMNS
// =====================================================

$columns = [];

$column_sql = "
    SELECT
        column_key,
        column_label,
        unit_text,
        data_type,
        text_align,
        column_width
    FROM quotation_columns
    WHERE
        quotation_id = ?
        AND is_visible = 1
    ORDER BY
        sort_order ASC,
        id ASC
";

$column_stmt =
    mysqli_prepare(
        $conn,
        $column_sql
    );

if (!$column_stmt) {
    die(
        "Quotation Column Error: " .
        mysqli_error($conn)
    );
}

mysqli_stmt_bind_param(
    $column_stmt,
    "i",
    $quotation_id
);

mysqli_stmt_execute(
    $column_stmt
);

$column_result =
    mysqli_stmt_get_result(
        $column_stmt
    );

while (
    $column =
    mysqli_fetch_assoc(
        $column_result
    )
) {
    $columns[] =
        $column;
}

mysqli_stmt_close(
    $column_stmt
);


// =====================================================
// ROWS
// =====================================================

$rows = [];

$row_sql = "
    SELECT
        row_no,
        item_reference,
        row_data,
        row_amount
    FROM quotation_rows
    WHERE quotation_id = ?
    ORDER BY
        sort_order ASC,
        id ASC
";

$row_stmt =
    mysqli_prepare(
        $conn,
        $row_sql
    );

if (!$row_stmt) {
    die(
        "Quotation Row Error: " .
        mysqli_error($conn)
    );
}

mysqli_stmt_bind_param(
    $row_stmt,
    "i",
    $quotation_id
);

mysqli_stmt_execute(
    $row_stmt
);

$row_result =
    mysqli_stmt_get_result(
        $row_stmt
    );

while (
    $row =
    mysqli_fetch_assoc(
        $row_result
    )
) {

    $decoded =
        json_decode(
            $row[
                'row_data'
            ] ?? '{}',
            true
        );

    if (!is_array($decoded)) {
        $decoded = [];
    }

    $row[
        'decoded'
    ] =
        $decoded;

    $rows[] =
        $row;
}

mysqli_stmt_close(
    $row_stmt
);


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

$commercial_stmt =
    mysqli_prepare(
        $conn,
        $commercial_sql
    );

if (!$commercial_stmt) {
    die(
        "Commercial Terms Error: " .
        mysqli_error($conn)
    );
}

mysqli_stmt_bind_param(
    $commercial_stmt,
    "i",
    $quotation_id
);

mysqli_stmt_execute(
    $commercial_stmt
);

$commercial_result =
    mysqli_stmt_get_result(
        $commercial_stmt
    );

while (
    $term =
    mysqli_fetch_assoc(
        $commercial_result
    )
) {
    $commercial_terms[] =
        $term;
}

mysqli_stmt_close(
    $commercial_stmt
);


// =====================================================
// GENERAL TERMS
// =====================================================

$general_terms = [];

$general_sql = "
    SELECT
        condition_text
    FROM quotation_general_terms
    WHERE
        quotation_id = ?
        AND is_visible = 1
    ORDER BY
        sort_order ASC,
        id ASC
";

$general_stmt =
    mysqli_prepare(
        $conn,
        $general_sql
    );

if (!$general_stmt) {
    die(
        "General Terms Error: " .
        mysqli_error($conn)
    );
}

mysqli_stmt_bind_param(
    $general_stmt,
    "i",
    $quotation_id
);

mysqli_stmt_execute(
    $general_stmt
);

$general_result =
    mysqli_stmt_get_result(
        $general_stmt
    );

while (
    $term =
    mysqli_fetch_assoc(
        $general_result
    )
) {
    $general_terms[] =
        $term[
            'condition_text'
        ];
}

mysqli_stmt_close(
    $general_stmt
);


// =====================================================
// CURRENCY
// =====================================================

$currency_code = strtoupper(
    trim(
        $quotation['currency'] ?? 'INR'
    )
);

$currency_symbols = [
    'INR' => '₹',
    'USD' => '$',
    'EUR' => '€',
    'GBP' => '£'
];

$currency_symbol =
    $currency_symbols[$currency_code]
    ?? $currency_code;

function quoteCurrencyUnit($unit, $currency_symbol)
{
    $unit = trim((string)$unit);

    if ($unit === '') {
        return '';
    }

    // Change only an existing currency symbol.
    // Plain units such as KG, PCS, MM and NOS remain unchanged.
    if (preg_match('/^[₹$€£]/u', $unit)) {
        return preg_replace(
            '/^[₹$€£]/u',
            $currency_symbol,
            $unit
        );
    }

    return $unit;
}


// =====================================================
// HELPERS
// =====================================================

function quoteViewDate($date)
{
    if (
        empty($date)
        ||
        $date === '0000-00-00'
    ) {
        return '-';
    }

    return date(
        'd-m-Y',
        strtotime($date)
    );
}


function quoteViewStatusBadge($status)
{
    $status =
        trim(
            $status ?? ''
        );

    $map = [
        'Draft' =>
            'bg-secondary',

        'Sent' =>
            'bg-primary',

        'Revised' =>
            'bg-info text-dark',

        'Accepted' =>
            'bg-success',

        'Rejected' =>
            'bg-danger',

        'Expired' =>
            'bg-warning text-dark'
    ];

    $class =
        $map[
            $status
        ] ?? 'bg-light text-dark';

    return
        '<span class="badge ' .
        $class .
        '">' .
        htmlspecialchars(
            $status !== ''
                ? $status
                : '-'
        ) .
        '</span>';
}

?>

<style>

.quote-view-card {
    border: 0;
    border-radius: 12px;
    box-shadow: 0 5px 20px rgba(31,45,61,.06);
}

.quote-view-title {
    font-weight: 700;
    color: #26374a;
}

.quote-view-subtitle {
    font-size: 11px;
    color: #7b8794;
}

.quote-info-box {
    background: #f8fafc;
    border: 1px solid #e7ebf0;
    border-radius: 10px;
    padding: 10px 12px;
    height: 100%;
}

.quote-info-label {
    font-size: 10px;
    color: #7b8794;
    text-transform: uppercase;
    letter-spacing: .3px;
}

.quote-info-value {
    margin-top: 3px;
    font-size: 12px;
    font-weight: 700;
    color: #26374a;
}

.corporate-table {
    min-width: 900px;
    font-size: 11px;
}

.corporate-table thead th {
    background: #233f6b !important;
    color: #ffffff !important;
    border-color: #405985 !important;
    text-align: center;
    vertical-align: middle;
    font-weight: 700;
}

.corporate-table td {
    vertical-align: middle;
    border-color: #e5e9ef;
}

.section-heading {
    font-size: 15px;
    font-weight: 700;
    color: #233f6b;
}

.commercial-table {
    font-size: 11px;
}

.commercial-table thead th {
    background: #233f6b !important;
    color: #fff !important;
    text-align: center;
}

.general-list {
    margin: 0;
    padding-left: 22px;
}

.general-list li {
    margin-bottom: 7px;
    color: #2f3f51;
    line-height: 1.45;
    font-size: 11px;
}

.revision-note {
    background: #eef5ff;
    border: 1px solid #d7e7ff;
    color: #35567e;
    border-radius: 8px;
    padding: 8px 10px;
    font-size: 11px;
}

</style>


<div class="content-page">

<div class="content">

<div class="container-fluid">


<!-- =====================================================
     HEADER
===================================================== -->

<div class="card quote-view-card mt-2 mb-2">

<div class="card-body py-2 px-3">

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2">

    <div>

        <h4 class="quote-view-title mb-1">
            Quotation View
        </h4>

        <div class="quote-view-subtitle">
            <?= htmlspecialchars(
                $quotation[
                    'quotation_no'
                ]
            ); ?>
        </div>

    </div>


    <div class="d-flex gap-1 flex-wrap">

        <a
            href="index.php"
            class="btn btn-secondary btn-sm"
        >
            ← List
        </a>

        <?php if ($canEditQuotation && empty($_SESSION['subscription_read_only'])) { ?>
        <a
            href="edit.php?id=<?= intval(
                $quotation_id
            ); ?>"
            class="btn btn-warning btn-sm"
        >
            <i class="mdi mdi-pencil-outline me-1"></i>
            Edit
        </a>

        <a
            href="revise.php?id=<?= intval(
                $quotation_id
            ); ?>"
            class="btn btn-primary btn-sm"
        >
            <i class="mdi mdi-file-document-edit-outline me-1"></i>
            Revise
        </a>
        <?php } ?>

        <a
            href="print.php?id=<?= intval(
                $quotation_id
            ); ?>"
            target="_blank"
            class="btn btn-success btn-sm"
        >
            <i class="mdi mdi-printer-outline me-1"></i>
            Print / PDF
        </a>

    </div>

</div>

</div>

</div>


<?php if (
    intval(
        $quotation[
            'revision_no'
        ] ?? 0
    ) > 0
) { ?>

<div class="revision-note mb-2">

    Revision:
    <strong>
        R<?= intval(
            $quotation[
                'revision_no'
            ]
        ); ?>
    </strong>

    <?php if (
        !empty(
            $quotation[
                'revision_of_quotation_id'
            ]
        )
    ) { ?>

        &nbsp; | &nbsp;
        Original Quotation ID:
        <strong>
            <?= intval(
                $quotation[
                    'revision_of_quotation_id'
                ]
            ); ?>
        </strong>

    <?php } ?>

</div>

<?php } ?>


<!-- =====================================================
     MASTER DETAILS
===================================================== -->

<div class="card quote-view-card mb-2">

<div class="card-body">

<div class="row g-2">


<div class="col-xl-3 col-lg-4 col-md-6">

<div class="quote-info-box">

    <div class="quote-info-label">
        Quotation No.
    </div>

    <div class="quote-info-value">
        <?= htmlspecialchars(
            $quotation[
                'quotation_no'
            ]
        ); ?>
    </div>

</div>

</div>


<div class="col-xl-3 col-lg-4 col-md-6">

<div class="quote-info-box">

    <div class="quote-info-label">
        Client
    </div>

    <div class="quote-info-value">
        <?= htmlspecialchars(
            $quotation[
                'client_name'
            ]
        ); ?>
    </div>

</div>

</div>


<div class="col-xl-2 col-lg-4 col-md-6">

<div class="quote-info-box">

    <div class="quote-info-label">
        RFQ No.
    </div>

    <div class="quote-info-value">
        <?= !empty(
            $quotation[
                'rfq_no'
            ]
        )
            ? htmlspecialchars(
                $quotation[
                    'rfq_no'
                ]
            )
            : '-';
        ?>
    </div>

</div>

</div>


<div class="col-xl-2 col-lg-4 col-md-6">

<div class="quote-info-box">

    <div class="quote-info-label">
        RFQ Date
    </div>

    <div class="quote-info-value">
        <?= quoteViewDate(
            $quotation[
                'rfq_date'
            ] ?? ''
        ); ?>
    </div>

</div>

</div>


<div class="col-xl-2 col-lg-4 col-md-6">

<div class="quote-info-box">

    <div class="quote-info-label">
        Quote Date
    </div>

    <div class="quote-info-value">
        <?= quoteViewDate(
            $quotation[
                'quotation_date'
            ]
        ); ?>
    </div>

</div>

</div>


<div class="col-xl-5 col-lg-6 col-md-12">

<div class="quote-info-box">

    <div class="quote-info-label">
        Subject
    </div>

    <div class="quote-info-value">
        <?= !empty(
            $quotation[
                'subject'
            ]
        )
            ? htmlspecialchars(
                $quotation[
                    'subject'
                ]
            )
            : '-';
        ?>
    </div>

</div>

</div>


<div class="col-xl-2 col-lg-3 col-md-6">

<div class="quote-info-box">

    <div class="quote-info-label">
        Currency
    </div>

    <div class="quote-info-value">
        <?= htmlspecialchars(
            $currency_code
        ); ?>

        <span class="text-muted">
            (<?= htmlspecialchars(
                $currency_symbol
            ); ?>)
        </span>
    </div>

</div>

</div>


<div class="col-xl-3 col-lg-3 col-md-6">

<div class="quote-info-box">

    <div class="quote-info-label">
        Offer Validity
    </div>

    <div class="quote-info-value">
        <?= !empty(
            $quotation[
                'validity_text'
            ]
        )
            ? htmlspecialchars(
                $quotation[
                    'validity_text'
                ]
            )
            : '-';
        ?>
    </div>

</div>

</div>


<div class="col-xl-2 col-lg-3 col-md-6">

<div class="quote-info-box">

    <div class="quote-info-label">
        Status
    </div>

    <div class="quote-info-value">
        <?= quoteViewStatusBadge(
            $quotation[
                'status'
            ]
        ); ?>
    </div>

</div>

</div>


</div>

</div>

</div>


<!-- =====================================================
     QUOTATION TABLE
===================================================== -->

<div class="card quote-view-card mb-2">

<div class="card-body">

<div class="section-heading mb-2">
    Quotation
</div>

<div class="table-responsive">

<table class="table table-bordered corporate-table mb-0">

<thead>

<tr>

    <th style="width:55px;">
        Sr.
    </th>

    <?php foreach ($columns as $column) { ?>

    <th
        style="text-align:<?= htmlspecialchars(
            $column[
                'text_align'
            ] ?? 'left'
        ); ?>;"
    >
        <?= htmlspecialchars(
            $column[
                'column_label'
            ]
        ); ?>

        <?php if (
            !empty(
                $column[
                    'unit_text'
                ]
            )
        ) { ?>

            <div style="font-size:9px;font-weight:400;margin-top:3px;">
                (
                <?= htmlspecialchars(
                    quoteCurrencyUnit(
                        $column['unit_text'] ?? '',
                        $currency_symbol
                    )
                ); ?>
                )
            </div>

        <?php } ?>

    </th>

    <?php } ?>

</tr>

</thead>

<tbody>

<?php if (count($rows) > 0) { ?>

<?php foreach ($rows as $index => $row) { ?>

<tr>

<td class="text-center fw-bold">
    <?= $index + 1; ?>
</td>

<?php foreach ($columns as $column) {

    $column_key =
        $column[
            'column_key'
        ];

    $cell =
        $row[
            'decoded'
        ][
            $column_key
        ] ?? '';

?>

<td
    style="text-align:<?= htmlspecialchars(
        $column[
            'text_align'
        ] ?? 'left'
    ); ?>;"
>
    <?= nl2br(
        htmlspecialchars(
            (string)$cell
        )
    ); ?>
</td>

<?php } ?>

</tr>

<?php } ?>

<?php } else { ?>

<tr>

<td
    colspan="<?= count($columns) + 1; ?>"
    class="text-center text-muted py-4"
>
    No quotation items found.
</td>

</tr>

<?php } ?>

</tbody>

</table>

</div>

</div>

</div>


<!-- =====================================================
     COMMERCIAL TERMS
===================================================== -->

<div class="card quote-view-card mb-2">

<div class="card-body">

<div class="section-heading mb-2">
    Commercial Terms
</div>

<div class="table-responsive">

<table class="table table-bordered commercial-table mb-0">

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

</tr>

</thead>

<tbody>

<?php if (count($commercial_terms) > 0) { ?>

<?php foreach ($commercial_terms as $index => $term) { ?>

<tr>

<td class="text-center fw-bold">
    <?= $index + 1; ?>
</td>

<td class="fw-bold">
    <?= htmlspecialchars(
        $term[
            'particular'
        ]
    ); ?>
</td>

<td>
    <?= nl2br(
        htmlspecialchars(
            $term[
                'term_value'
            ] ?? ''
        )
    ); ?>
</td>

</tr>

<?php } ?>

<?php } else { ?>

<tr>

<td
    colspan="3"
    class="text-center text-muted py-3"
>
    No commercial terms.
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

<div class="card quote-view-card mb-2">

<div class="card-body">

<div class="section-heading mb-2">
    General Terms & Conditions
</div>

<?php if (count($general_terms) > 0) { ?>

<ol class="general-list">

<?php foreach ($general_terms as $condition) { ?>

<li>
    <?= nl2br(
        htmlspecialchars(
            $condition
        )
    ); ?>
</li>

<?php } ?>

</ol>

<?php } else { ?>

<div class="text-muted">
    No general terms.
</div>

<?php } ?>

</div>

</div>


<?php if (
    !empty(
        $quotation[
            'remarks'
        ]
    )
) { ?>

<div class="card quote-view-card mb-3">

<div class="card-body">

<div class="section-heading mb-2">
    Remarks
</div>

<div style="font-size:11px;line-height:1.5;">
    <?= nl2br(
        htmlspecialchars(
            $quotation[
                'remarks'
            ]
        )
    ); ?>
</div>

</div>

</div>

<?php } ?>


</div>

</div>

</div>


<?php
include("../includes/footer.php");
?>
