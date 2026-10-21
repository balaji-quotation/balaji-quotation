<?php

session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

include("../includes/db.php");
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/quotation_template_runtime.php';
erpRequireCompanyUser($conn);
erpRequire('quotations.view');
$companyId = erpCurrentCompanyId();


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
// HELPERS
// =====================================================

function printDate($date)
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


function printImageUrl($value)
{
    $value =
        trim(
            (string)$value
        );

    if ($value === '') {
        return '';
    }

    if (
        preg_match(
            '#^https?://#i',
            $value
        )
    ) {
        return $value;
    }

    if (
        str_starts_with(
            $value,
            '/'
        )
    ) {
        return $value;
    }

    return
        '/quotation/uploads/quotation/' .
        rawurlencode(
            basename(
                $value
            )
        );
}


// =====================================================
// MASTER
// =====================================================

$master_sql = "
    SELECT
        q.*,
        c.company_name AS client_name,
        c.contact_person AS client_contact_person,
        c.gstin AS client_gstin,
        c.mobile AS client_mobile,
        c.email AS client_email,
        c.address AS client_address
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
        "Quotation Print Error: " .
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

$templateConfig = json_decode((string)($quotation['template_config_snapshot'] ?? ''), true);
if (!is_array($templateConfig)) $templateConfig = [];
$roundingConfig = is_array($templateConfig['rounding'] ?? null) ? $templateConfig['rounding'] : [];
$decimalPlaces = filter_var($roundingConfig['decimal_places'] ?? 2, FILTER_VALIDATE_INT);
$decimalPlaces = $decimalPlaces === false ? 2 : max(0, min(4, (int)$decimalPlaces));
$roundingMode = (string)($roundingConfig['mode'] ?? 'half_up');
if (!in_array($roundingMode, ['half_up','half_even','ceil','floor'], true)) $roundingMode = 'half_up';
$calculationConfig = is_array($templateConfig['calculation'] ?? null) ? $templateConfig['calculation'] : ['mode'=>'standard','formula'=>''];
$taxConfig = is_array($templateConfig['tax'] ?? null) ? $templateConfig['tax'] : ['gst_enabled'=>false,'gst_rate'=>0];
$totalsConfig = is_array($templateConfig['totals'] ?? null) ? $templateConfig['totals'] : [];
$printLayout = (string)($templateConfig['print_layout'] ?? 'Classic');
if (!in_array($printLayout, ['Classic','Compact','Minimal','Modern'], true)) $printLayout = 'Classic';



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


function printCurrencyUnit(
    $unit,
    $currency_symbol
) {
    $unit = trim((string)$unit);

    if ($unit === '') {
        return '';
    }

    // Existing currency symbol ko selected quotation currency se replace kare.
    if (preg_match('/^[₹$€£]/u', $unit)) {
        return preg_replace(
            '/^[₹$€£]/u',
            $currency_symbol,
            $unit
        );
    }

    // Plain units (KG, PCS, NOS, MM...) unchanged rahenge.
    return $unit;
}

function formatQuotationValue(
    $value,
    $column,
    $currency_symbol,
    $decimal_places = 2,
    $rounding_mode = 'half_up'
) {
    $value = trim((string)$value);

    if ($value === '') {
        return '-';
    }

    $clean_numeric = str_replace(',', '', $value);

    $key = strtolower(
        trim((string)($column['column_key'] ?? ''))
    );

    $label = strtolower(
        trim((string)($column['column_label'] ?? ''))
    );

    $type = strtolower(
        trim((string)($column['data_type'] ?? ''))
    );

    $unit = strtolower(
        trim((string)($column['unit_text'] ?? ''))
    );

    $is_currency =
        $type === 'currency' ||
        str_contains($key, 'rate') ||
        str_contains($key, 'price') ||
        str_contains($key, 'amount') ||
        str_contains($key, 'cost') ||
        str_contains($label, 'rate') ||
        str_contains($label, 'price') ||
        str_contains($label, 'amount') ||
        str_contains($label, 'cost') ||
        preg_match('/[₹$€£]/u', $unit);

    if (
        $is_currency &&
        is_numeric($clean_numeric)
    ) {
        return
            $currency_symbol .
            ' ' .
            number_format(
                quotationTemplateRound((float)$clean_numeric, (int)$decimal_places, (string)$rounding_mode),
                (int)$decimal_places,
                '.',
                ','
            );
    }

    $is_number =
        $type === 'number' ||
        str_contains($key, 'qty') ||
        str_contains($key, 'quantity') ||
        str_contains($key, 'eau') ||
        str_contains($key, 'weight') ||
        str_contains($label, 'qty') ||
        str_contains($label, 'quantity') ||
        str_contains($label, 'eau') ||
        str_contains($label, 'weight');

    if (
        $is_number &&
        is_numeric($clean_numeric)
    ) {
        return number_format(
            quotationTemplateRound((float)$clean_numeric, (int)$decimal_places, (string)$rounding_mode),
            (int)$decimal_places,
            '.',
            ','
        );
    }

    return $value;
}


// =====================================================
// COMPANY SETTINGS
// =====================================================

$company = [
    'company_name' =>
        'Unicorn Technoflex',

    'company_address' =>
        'Plot 74 & 75, Survey No. 3, Rolex Industrial Area, Bhojpara, Gondal, Rajkot, Gujarat, INDIA',

    'gst_no' =>
        '24AAIFU3176H1Z1',

    'phone' =>
        '+91 9727201111',

    'email' =>
        'kishan@unicorntechnoflex.com',

    'website' =>
        '',

    'seal_image' =>
        '',

    'signature_image' =>
        '',

    'signatory_name' =>
        '',

    'signatory_designation' =>
        'Authorized Signatory',

    'closing_text' =>
        "We hope the above costing and terms enable you to reach a favourable conclusion. Kindly review our rates and revert with your comments before the offer validity expires.\n\nWe look forward to receiving your Purchase Order in line with this quotation and assure you of our dedication towards your complete satisfaction.",

    'show_seal' =>
        1,

    'show_signature' =>
        1
];

$company_sql = "
    SELECT *
    FROM quotation_company_settings
    WHERE company_id = $companyId
    ORDER BY id ASC
    LIMIT 1
";

$company_result =
    mysqli_query(
        $conn,
        $company_sql
    );

if (
    $company_result &&
    mysqli_num_rows(
        $company_result
    ) > 0
) {

    $db_company =
        mysqli_fetch_assoc(
            $company_result
        );

    foreach (
        $company
        as $key =>
           $default_value
    ) {

        if (
            array_key_exists(
                $key,
                $db_company
            )
            &&
            $db_company[
                $key
            ] !== null
            &&
            $db_company[
                $key
            ] !== ''
        ) {

            $company[
                $key
            ] =
                $db_company[
                    $key
                ];
        }
    }
}


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
        row_data
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

    $rows[] =
        $decoded;
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
// PRINT MODE
$subtotal = 0.0;
$totalsCalculable = count($rows) > 0;
foreach ($rows as $row) {
    $amount = quotationTemplateRowAmount($row, $columns, $calculationConfig);
    if ($amount === null) {
        $totalsCalculable = false;
        break;
    }
    $subtotal += $amount;
}
if (!is_finite($subtotal)) $totalsCalculable = false;
$subtotal = $totalsCalculable ? quotationTemplateRound($subtotal, $decimalPlaces, $roundingMode) : 0.0;
$gstEnabled = !empty($taxConfig['gst_enabled']);
$gstRate = is_numeric($taxConfig['gst_rate'] ?? null) ? max(0.0, min(100.0, (float)$taxConfig['gst_rate'])) : 0.0;
$taxAmount = $gstEnabled && $totalsCalculable
    ? quotationTemplateRound($subtotal * $gstRate / 100, $decimalPlaces, $roundingMode)
    : 0.0;
$grandTotal = $totalsCalculable
    ? quotationTemplateRound($subtotal + $taxAmount, $decimalPlaces, $roundingMode)
    : 0.0;
$showSubtotal = !empty($totalsConfig['show_subtotal']);
$showTax = !empty($totalsConfig['show_tax'] ?? $totalsConfig['show_total'] ?? false) && $gstEnabled;
$showGrandTotal = !empty($totalsConfig['show_grand_total']);
$showTotals = $totalsCalculable && ($showSubtotal || $showTax || $showGrandTotal);

// Wide table => landscape
// =====================================================

$landscape =
    count(
        $columns
    ) >= 7;

$page_size =
    $landscape
        ? 'A4 landscape'
        : 'A4 portrait';

$logo_url =
    '/quotation/quotations/assets/unicorn-logo.png';

$seal_signature_url =
    '/quotation/quotations/assets/unicorn-stamp-signature.png';

?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="utf-8">

<title>
    <?= htmlspecialchars(
        $quotation[
            'quotation_no'
        ]
    ); ?>
    | Quotation
</title>

<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>

<style>

@page {
    size: <?= $page_size; ?>;
    margin: 0;
}

* {
    box-sizing: border-box;
}

html,
body {
    margin: 0;
    padding: 0;
    background: #eef1f5;
    color: #182536;
    font-family: Arial, Helvetica, sans-serif;
    font-size: 10px;
}

.print-toolbar {
    max-width: <?= $landscape ? '277mm' : '190mm'; ?>;
    margin: 10px auto;
    display: flex;
    justify-content: flex-end;
    gap: 8px;
}

.print-toolbar a,
.print-toolbar button {
    border: 0;
    border-radius: 6px;
    padding: 8px 13px;
    font-size: 10px;
    cursor: pointer;
    text-decoration: none;
}

.btn-back {
    background: #6c757d;
    color: #fff;
}

.btn-print {
    background: #233f6b;
    color: #fff;
}

.quotation-sheet {
    width: 100%;
    max-width: <?= $landscape ? '297mm' : '210mm'; ?>;
    min-height: <?= $landscape ? '210mm' : '297mm'; ?>;
    margin: 0 auto;
    background: #fff;
    padding: 7mm 8mm 9mm 8mm;
    box-shadow: 0 2px 15px rgba(0,0,0,.08);
    position: relative;
}

.company-header {
    border-bottom: 2px solid #233f6b;
    padding: 2.5mm 4mm 2.5mm;
    display: grid;
    grid-template-columns: 42mm 1fr auto;
    gap: 4mm;
    align-items: center;
}

/* Ready-made template print presets */
.layout-compact .main-quote-table th,
.layout-compact .main-quote-table td,
.layout-compact .commercial-table th,
.layout-compact .commercial-table td {
    padding: 3px 4px;
    font-size: 9px;
}
.layout-minimal .main-quote-table th,
.layout-minimal .commercial-table th {
    background: #fff !important;
    color: #182536 !important;
    border-bottom: 2px solid #233f6b;
}
.layout-minimal .section-title {
    border-bottom-width: 1px;
}
.layout-modern .main-quote-table th,
.layout-modern .commercial-table th {
    background: #0f766e !important;
    border-color: #0f766e;
}
.layout-modern .section-title {
    color: #0f766e;
    border-color: #0f766e;
}
.total-summary {
    width: 100%;
    max-width: 90mm;
    margin: 8px 0 0 auto;
    border-collapse: collapse;
    break-inside: avoid;
}
.total-summary td {
    padding: 5px 7px;
    border: 1px solid #c7ced7;
}
.total-summary td:last-child {
    text-align: right;
    white-space: nowrap;
}
.total-summary .grand-total td {
    font-weight: 800;
    border-top: 2px solid #233f6b;
}

/* Ready-made template print presets */

.company-left {
    display: block;
}

.company-logo {
    width: 39mm;
    max-height: 16mm;
    object-fit: contain;
    object-position: left center;
    display: block;
}

.company-contact {
    line-height: 1.5;
    font-size: 8px;
    color: #34465a;
}

.company-contact strong {
    color: #233f6b;
}

.document-title {
    text-align: right;
    min-width: 38mm;
}

.document-title h1 {
    margin: 0;
    color: #233f6b;
    font-size: 18px;
    letter-spacing: .7px;
}

.document-title .revision {
    margin-top: 4px;
    font-weight: 700;
    color: #566476;
}

.sheet-body {
    padding: 4mm 4mm 3mm;
}

.quote-meta-table,
.main-quote-table,
.commercial-table {
    width: 100%;
    border-collapse: collapse;
}

.quote-meta-table td {
    border: 1px solid #c7ced7;
    padding: 6px 6px;
    vertical-align: top;
}

.meta-label {
    display: block;
    color: #6c7785;
    font-size: 8.5px;
    text-transform: uppercase;
    font-weight: 700;
    margin-bottom: 4px;
}

.meta-value {
    color: #182536;
    font-size: 7.5px;
    font-weight: 700;
}


.client-company {
    color: #182536;
    font-size: 8px;
    font-weight: 700;
    line-height: 1.25;
    margin-bottom: 2px;
}

.client-line {
    color: #34465a;
    font-size: 7px;
    line-height: 1.28;
    margin-top: 1px;
    word-break: break-word;
}

.client-inline {
    color: #34465a;
    font-size: 7px;
    line-height: 1.28;
    margin-top: 1px;
}

.client-inline span {
    display: inline-block;
    margin-right: 10px;
}

.subject-line {
    margin-top: 7px;
    padding: 6px 7px;
    border: 1px solid #c7ced7;
    background: #f8fafc;
}

.section-title {
    color: #233f6b;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    margin: 10px 0 6px;
    border-bottom: 1.5px solid #233f6b;
    padding-bottom: 3px;
}

.main-quote-table {
    table-layout: fixed;
}

.main-quote-table th,
.main-quote-table td {
    border: 1px solid #8d99a6;
    padding: 6px 5px;
    vertical-align: middle;
    word-wrap: break-word;
    overflow-wrap: anywhere;
}

.main-quote-table thead {
    display: table-header-group;
}

.main-quote-table th {
    background: #16345f !important;
    color: #ffffff !important;
    font-weight: 800;
    text-align: center;
    line-height: 1.2;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
    color-adjust: exact !important;
}

.main-quote-table .unit {
    margin-top: 2px;
    font-size: 8px;
    font-weight: 600;
    opacity: 1;
    color: #ffffff !important;
}

.main-quote-table td {
    min-height: 30px;
}

.commercial-table thead {
    display: table-header-group;
}

.commercial-table th,
.commercial-table td {
    border: 1px solid #9ca6b1;
    padding: 6px 6px;
    vertical-align: top;
}

.commercial-table th {
    background: #16345f !important;
    color: #ffffff !important;
    text-align: center;
    font-weight: 800;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
    color-adjust: exact !important;
}

.commercial-table td:nth-child(1) {
    width: 7%;
    text-align: center;
    font-weight: 700;
}

.commercial-table td:nth-child(2) {
    width: 29%;
    font-weight: 700;
}

.general-terms {
    margin: 0;
    padding-left: 22px;
}

.general-terms li {
    margin-bottom: 5px;
    padding-left: 4px;
    line-height: 1.38;
    break-inside: avoid;
}

.closing-text {
    margin-top: 11px;
    line-height: 1.42;
    white-space: pre-line;
}

.signature-area {
    margin-top: 10px;
    display: flex;
    justify-content: flex-end;
    page-break-inside: avoid;
}

.signature-box {
    width: 62mm;
    text-align: left;
}

.signature-heading {
    font-weight: 700;
    font-size: 12px;
    margin-bottom: 3px;
}

.signature-images {
    min-height: 27mm;
    display: flex;
    align-items: flex-end;
}

.seal-signature-image {
    object-fit: contain;
    object-position: left bottom;
    max-height: 27mm;
    max-width: 60mm;
}

.signatory {
    border-top: 1px solid #87919c;
    margin-top: 2px;
    padding-top: 3px;
    width: 58mm;
}

.signatory-name {
    font-weight: 700;
}

.signatory-designation {
    color: #5f6d7c;
    margin-top: 2px;
}

.footer-note {
    position: absolute;
    left: 8mm;
    right: 8mm;
    bottom: 7mm;
    border-top: 1px solid #233f6b;
    padding-top: 4px;
    color: #233f6b;
    text-align: center;
    font-size: 8px;
    font-weight: 700;
    background: #fff;
}

tr,
td,
th {
    break-inside: avoid;
}

@media print {

    html,
    body {
        background: #fff !important;
        margin: 0 !important;
        padding: 0 !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

    .print-toolbar {
        display: none !important;
    }

    .quotation-sheet {
        width: <?= $landscape ? '297mm' : '210mm'; ?>;
        min-height: <?= $landscape ? '210mm' : '297mm'; ?>;
        max-width: none;
        margin: 0;
        box-shadow: none;
        padding: 7mm 8mm 9mm 8mm;
        overflow: hidden;
    }

    .company-header {
        padding-top: 0;
    }

    .main-quote-table th,
    .commercial-table th {
        background: #16345f !important;
        color: #ffffff !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

}

</style>

</head>

<body>


<div class="print-toolbar">

<a
    href="view.php?id=<?= intval(
        $quotation_id
    ); ?>"
    class="btn-back"
>
    ← Back
</a>

<button
    type="button"
    class="btn-print"
    onclick="window.print()"
>
    Print / Save PDF
</button>



</div>


<div class="quotation-sheet layout-<?= strtolower($printLayout); ?>">


<!-- =====================================================
     COMPANY HEADER
===================================================== -->

<div class="company-header">

<div class="company-left">

    <img
        src="<?= htmlspecialchars($logo_url); ?>"
        alt="Unicorn Technoflex"
        class="company-logo"
    >

</div>

<div class="company-contact">

    <div>
        <strong>Address:</strong>
        <?= htmlspecialchars($company['company_address']); ?>
    </div>

    <div>
        <strong>GSTIN:</strong>
        <?= htmlspecialchars($company['gst_no']); ?>
    </div>

    <div>
        <strong>Email:</strong>
        <?= htmlspecialchars($company['email']); ?>
    </div>

    <div>
        <strong>Phone:</strong>
        <?= htmlspecialchars($company['phone']); ?>
    </div>

</div>


<div class="document-title">

    <h1>
        QUOTATION
    </h1>

    <?php if (
        intval(
            $quotation[
                'revision_no'
            ] ?? 0
        ) > 0
    ) { ?>

        <div class="revision">
            Revision R<?= intval(
                $quotation[
                    'revision_no'
                ]
            ); ?>
        </div>

    <?php } ?>

</div>

</div>


<div class="sheet-body">


<!-- =====================================================
     QUOTATION META
===================================================== -->

<table class="quote-meta-table">

<tr>

<td style="width:25%;">

    <span class="meta-label">
        Quotation No.
    </span>

    <span class="meta-value">
        <?= htmlspecialchars(
            $quotation[
                'quotation_no'
            ]
        ); ?>
    </span>

</td>


<td style="width:25%;">

    <span class="meta-label">
        Quotation Date
    </span>

    <span class="meta-value">
        <?= printDate(
            $quotation[
                'quotation_date'
            ]
        ); ?>
    </span>

</td>


<td style="width:25%;">

    <span class="meta-label">
        RFQ No.
    </span>

    <span class="meta-value">
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
    </span>

</td>


<td style="width:25%;">

    <span class="meta-label">
        RFQ Date
    </span>

    <span class="meta-value">
        <?= printDate(
            $quotation[
                'rfq_date'
            ] ?? ''
        ); ?>
    </span>

</td>

</tr>


<tr>

<td colspan="2">

    <span class="meta-label">
        Client
    </span>

    <div class="client-company">
        <?= htmlspecialchars(
            $quotation['client_name'] ?? '-'
        ); ?>
    </div>

    <?php if (!empty($quotation['client_contact_person'])) { ?>
        <div class="client-line">
            <strong>Contact:</strong>
            <?= htmlspecialchars($quotation['client_contact_person']); ?>
        </div>
    <?php } ?>

    <?php if (!empty($quotation['client_address'])) { ?>
        <div class="client-line">
            <strong>Address:</strong>
            <?= nl2br(htmlspecialchars($quotation['client_address'])); ?>
        </div>
    <?php } ?>

    <?php if (!empty($quotation['client_gstin']) || !empty($quotation['client_mobile'])) { ?>
        <div class="client-inline">
            <?php if (!empty($quotation['client_gstin'])) { ?>
                <span>
                    <strong>GSTIN:</strong>
                    <?= htmlspecialchars($quotation['client_gstin']); ?>
                </span>
            <?php } ?>

            <?php if (!empty($quotation['client_mobile'])) { ?>
                <span>
                    <strong>Mobile:</strong>
                    <?= htmlspecialchars($quotation['client_mobile']); ?>
                </span>
            <?php } ?>
        </div>
    <?php } ?>

    <?php if (!empty($quotation['client_email'])) { ?>
        <div class="client-line">
            <strong>Email:</strong>
            <?= htmlspecialchars($quotation['client_email']); ?>
        </div>
    <?php } ?>

</td>


<td>

    <span class="meta-label">
        Currency
    </span>

    <span class="meta-value">
        <?= htmlspecialchars(
            $currency_code
        ); ?>
        (<?= htmlspecialchars(
            $currency_symbol
        ); ?>)
    </span>

</td>


<td>

    <span class="meta-label">
        Offer Validity
    </span>

    <span class="meta-value">
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
    </span>

</td>

</tr>

</table>


<?php if (
    !empty(
        $quotation[
            'subject'
        ]
    )
) { ?>

<div class="subject-line">

    <strong>Subject:</strong>
    <?= htmlspecialchars(
        $quotation[
            'subject'
        ]
    ); ?>

</div>

<?php } ?>


<!-- =====================================================
     MAIN QUOTATION TABLE
===================================================== -->

<div class="section-title">
    Quotation Details
</div>

<table class="main-quote-table">

<thead>

<tr>

<th style="width:6%;">
    Sr.
</th>

<?php

$usable_width =
    94;

$column_width =
    count(
        $columns
    ) > 0
        ? floor(
            $usable_width /
            count(
                $columns
            )
        )
        : $usable_width;

foreach (
    $columns
    as $column
) {

?>

<th
    style="
        width:<?= $column_width; ?>%;
        text-align:center;
    "
>

    <?php
        $display_label = $column['column_label'] ?? '';

        $display_unit = printCurrencyUnit(
            $column['unit_text'] ?? '',
            $currency_symbol
        );
    ?>

    <?= htmlspecialchars($display_label); ?>

    <?php if ($display_unit !== '') { ?>
        <div class="unit">
            ( <?= htmlspecialchars($display_unit); ?> )
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

<td style="text-align:center;font-weight:700;">
    <?= $index + 1; ?>
</td>

<?php foreach ($columns as $column) {

    $key =
        $column[
            'column_key'
        ];

    $value =
        $row[
            $key
        ] ?? '';

?>

<td
    style="text-align:center;"
>
    <?= nl2br(
        htmlspecialchars(
            formatQuotationValue(
                $value,
                $column,
                $currency_symbol,
                $decimalPlaces,
                $roundingMode
            )
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
    style="text-align:center;padding:12px;"
>
    No quotation items.
</td>

</tr>

<?php } ?>

</tbody>

</table>

<?php if ($showTotals) { ?>
<table class="total-summary" aria-label="Quotation totals">
    <tbody>
        <?php if ($showSubtotal) { ?><tr><td>Subtotal</td><td><?= htmlspecialchars($currency_symbol) ?> <?= number_format($subtotal, $decimalPlaces, '.', ',') ?></td></tr><?php } ?>
        <?php if ($showTax) { ?><tr><td>GST (<?= number_format($gstRate, 2) ?>%)</td><td><?= htmlspecialchars($currency_symbol) ?> <?= number_format($taxAmount, $decimalPlaces, '.', ',') ?></td></tr><?php } ?>
        <?php if ($showGrandTotal) { ?><tr class="grand-total"><td>Grand Total</td><td><?= htmlspecialchars($currency_symbol) ?> <?= number_format($grandTotal, $decimalPlaces, '.', ',') ?></td></tr><?php } ?>
    </tbody>
</table>
<?php } ?>


<!-- =====================================================
     COMMERCIAL TERMS
===================================================== -->

<?php if (count($commercial_terms) > 0) { ?>

<div class="section-title">
    Commercial Terms
</div>

<table class="commercial-table">

<thead>

<tr>

<th style="width:7%;">
    Sr.
</th>

<th style="width:29%;">
    Particulars
</th>

<th>
    Terms
</th>

</tr>

</thead>

<tbody>

<?php foreach ($commercial_terms as $index => $term) { ?>

<tr>

<td>
    <?= str_pad(
        (string)(
            $index + 1
        ),
        2,
        '0',
        STR_PAD_LEFT
    ); ?>
</td>

<td>
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

</tbody>

</table>

<?php } ?>


<!-- =====================================================
     GENERAL TERMS
===================================================== -->

<?php if (count($general_terms) > 0) { ?>

<div class="section-title">
    General Terms & Conditions
</div>

<ol class="general-terms">

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

<?php } ?>


<!-- =====================================================
     CLOSING TEXT
===================================================== -->

<?php if (
    !empty(
        $company[
            'closing_text'
        ]
    )
) { ?>

<div class="closing-text">
    <?= htmlspecialchars(
        $company[
            'closing_text'
        ]
    ); ?>
</div>

<?php } ?>


<!-- =====================================================
     SIGNATURE / SEAL
===================================================== -->

<div class="signature-area">

<div class="signature-box">

    <div class="signature-heading">
        For Unicorn Technoflex
    </div>

    <div class="signature-images">

        <img
            src="<?= htmlspecialchars($seal_signature_url); ?>"
            alt="Company Seal and Authorized Signature"
            class="seal-signature-image"
        >

    </div>


    <div class="signatory">

        <?php if (
            !empty(
                $company[
                    'signatory_name'
                ]
            )
        ) { ?>

            <div class="signatory-name">
                <?= htmlspecialchars(
                    $company[
                        'signatory_name'
                    ]
                ); ?>
            </div>

        <?php } ?>


        <div class="signatory-designation">
            <?= htmlspecialchars(
                $company[
                    'signatory_designation'
                ]
            ); ?>
        </div>

    </div>

</div>

</div>


<div class="footer-note">
    Unicorn Technoflex
    &nbsp; | &nbsp;
    Quotation No. <?= htmlspecialchars($quotation['quotation_no']); ?>
    &nbsp; | &nbsp;
    Page 1 of 1
</div>


</div>

</div>


<script>

document.addEventListener(
    "keydown",
    function (event) {

        if (
            (
                event.ctrlKey ||
                event.metaKey
            )
            &&
            event.key.toLowerCase() === "p"
        ) {

            event.preventDefault();

            window.print();
        }
    }
);

</script>

</body>

</html>
