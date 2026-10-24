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
// SOURCE QUOTATION
// =====================================================

$source_id =
    intval(
        $_GET['id'] ?? 0
    );

if ($source_id <= 0) {
    die("Invalid quotation ID.");
}


// =====================================================
// LOAD SOURCE MASTER
// =====================================================

$source_sql = "
    SELECT
        q.*,
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

$source_stmt =
    mysqli_prepare(
        $conn,
        $source_sql
    );

if (!$source_stmt) {
    die(
        "Quotation Load Error: " .
        mysqli_error($conn)
    );
}

mysqli_stmt_bind_param(
    $source_stmt,
    "iii",
    $source_id,
    $companyId,
    $userId
);

mysqli_stmt_execute(
    $source_stmt
);

$source_result =
    mysqli_stmt_get_result(
        $source_stmt
    );

$source =
    mysqli_fetch_assoc(
        $source_result
    );
$clientDetailsSnapshot = json_decode((string)($source['client_details_snapshot'] ?? ''), true);
if (!is_array($clientDetailsSnapshot)) {
    $clientDetailsSnapshot = [];
}

mysqli_stmt_close(
    $source_stmt
);

if (!$source) {
    die("Quotation not found.");
}


// =====================================================
// REVISION ROOT
//
// If user revises R1/R2, always keep the original root.
// =====================================================

$revision_root_id =
    !empty(
        $source[
            'revision_of_quotation_id'
        ]
    )
        ? intval(
            $source[
                'revision_of_quotation_id'
            ]
        )
        : intval(
            $source[
                'id'
            ]
        );


// =====================================================
// ROOT QUOTATION NUMBER
// =====================================================

$root_sql = "
    SELECT quotation_no
    FROM quotations
    WHERE id = ?
      AND company_id = ?
    LIMIT 1
";

$root_stmt =
    mysqli_prepare(
        $conn,
        $root_sql
    );

if (!$root_stmt) {
    die(
        "Revision Root Error: " .
        mysqli_error($conn)
    );
}

mysqli_stmt_bind_param(
    $root_stmt,
    "ii",
    $revision_root_id,
    $companyId
);

mysqli_stmt_execute(
    $root_stmt
);

$root_result =
    mysqli_stmt_get_result(
        $root_stmt
    );

$root_row =
    mysqli_fetch_assoc(
        $root_result
    );

mysqli_stmt_close(
    $root_stmt
);

if (!$root_row) {
    die("Original quotation not found.");
}

$root_quotation_no =
    trim(
        $root_row[
            'quotation_no'
        ] ?? ''
    );


// Remove an accidental -R<number> suffix from root display,
// though original root normally has no revision suffix.

$root_quotation_no =
    preg_replace(
        '/-R\d+$/i',
        '',
        $root_quotation_no
    );


// =====================================================
// NEXT REVISION NUMBER
// =====================================================

$revision_sql = "
    SELECT
        COALESCE(
            MAX(revision_no),
            0
        ) AS max_revision
    FROM quotations
    WHERE company_id = ?
      AND (id = ? OR revision_of_quotation_id = ?)
";

$revision_stmt =
    mysqli_prepare(
        $conn,
        $revision_sql
    );

if (!$revision_stmt) {
    die(
        "Revision Number Error: " .
        mysqli_error($conn)
    );
}

mysqli_stmt_bind_param(
    $revision_stmt,
    "iii",
    $companyId,
    $revision_root_id,
    $revision_root_id
);

mysqli_stmt_execute(
    $revision_stmt
);

$revision_result =
    mysqli_stmt_get_result(
        $revision_stmt
    );

$revision_row =
    mysqli_fetch_assoc(
        $revision_result
    );

mysqli_stmt_close(
    $revision_stmt
);

$next_revision_no =
    intval(
        $revision_row[
            'max_revision'
        ] ?? 0
    ) + 1;

$new_quotation_no =
    $root_quotation_no .
    '-R' .
    $next_revision_no;


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
// SOURCE DYNAMIC COLUMNS
// =====================================================

$columns = [];

$column_sql = "
    SELECT
        id,
        column_key,
        column_label,
        unit_text,
        data_type,
        text_align,
        column_width,
        sort_order
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
        "Quotation Column Load Error: " .
        mysqli_error($conn)
    );
}

mysqli_stmt_bind_param(
    $column_stmt,
    "i",
    $source_id
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

    $columns[] = [
        'key' =>
            $column[
                'column_key'
            ],

        'label' =>
            $column[
                'column_label'
            ],

        'unit' =>
            $column[
                'unit_text'
            ] ?? '',

        'type' =>
            $column[
                'data_type'
            ] ?? 'text',

        'align' =>
            $column[
                'text_align'
            ] ?? 'left'
    ];
}

mysqli_stmt_close(
    $column_stmt
);

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
            'label' => 'Rate',
            'unit' => '₹/PCS',
            'type' => 'currency',
            'align' => 'right'
        ]
    ];
}


// =====================================================
// SOURCE DYNAMIC ROWS
// =====================================================

$rows = [];

$row_sql = "
    SELECT
        row_data,
        sort_order
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
        "Quotation Row Load Error: " .
        mysqli_error($conn)
    );
}

mysqli_stmt_bind_param(
    $row_stmt,
    "i",
    $source_id
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

    $row_values = [];

    foreach (
        $columns
        as $column
    ) {

        $row_values[] =
            trim(
                (string)(
                    $decoded[
                        $column[
                            'key'
                        ]
                    ] ?? ''
                )
            );
    }

    $rows[] =
        $row_values;
}

mysqli_stmt_close(
    $row_stmt
);

if (count($rows) === 0) {
    $rows[] =
        array_fill(
            0,
            count($columns),
            ''
        );
}


// =====================================================
// SOURCE COMMERCIAL TERMS
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
        "Commercial Terms Load Error: " .
        mysqli_error($conn)
    );
}

mysqli_stmt_bind_param(
    $commercial_stmt,
    "i",
    $source_id
);

mysqli_stmt_execute(
    $commercial_stmt
);

$commercial_result =
    mysqli_stmt_get_result(
        $commercial_stmt
    );

while (
    $commercial =
    mysqli_fetch_assoc(
        $commercial_result
    )
) {

    $commercial_terms[] = [
        'particular' =>
            $commercial[
                'particular'
            ],

        'value' =>
            $commercial[
                'term_value'
            ] ?? ''
    ];
}

mysqli_stmt_close(
    $commercial_stmt
);


// =====================================================
// SOURCE GENERAL TERMS
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
        "General Terms Load Error: " .
        mysqli_error($conn)
    );
}

mysqli_stmt_bind_param(
    $general_stmt,
    "i",
    $source_id
);

mysqli_stmt_execute(
    $general_stmt
);

$general_result =
    mysqli_stmt_get_result(
        $general_stmt
    );

while (
    $general =
    mysqli_fetch_assoc(
        $general_result
    )
) {

    $general_terms[] =
        $general[
            'condition_text'
        ];
}

mysqli_stmt_close(
    $general_stmt
);


// =====================================================
// SAVED TERMS SETS
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

    $set_id =
        intval(
            $term_set[
                'id'
            ]
        );

    $term_sets[
        $set_id
    ] = [
        'id' =>
            $set_id,

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
            term_value
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
            condition_text
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


// =====================================================
// JSON FOR JS
// =====================================================

$columns_json =
    json_encode(
        $columns,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

$rows_json =
    json_encode(
        $rows,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

$commercial_json =
    json_encode(
        $commercial_terms,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

$general_json =
    json_encode(
        $general_terms,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

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

.revision-info {
    background: #eef5ff;
    border: 1px solid #d7e7ff;
    border-radius: 9px;
    padding: 10px 12px;
    font-size: 11px;
    color: #35567e;
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


<form
    action="save_revision.php"
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
    name="source_quotation_id"
    value="<?= intval(
        $source_id
    ); ?>"
>

<input
    type="hidden"
    name="revision_root_id"
    value="<?= intval(
        $revision_root_id
    ); ?>"
>

<input
    type="hidden"
    name="revision_no"
    value="<?= intval(
        $next_revision_no
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
            Revise Quotation
        </h4>

        <div class="quote-builder-subtitle">
            Create a new revision without overwriting the previous quotation
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


<div class="revision-info mb-2">

    <strong>Original / Source:</strong>
    <?= htmlspecialchars(
        $source[
            'quotation_no'
        ]
    ); ?>

    &nbsp; → &nbsp;

    <strong>New Revision:</strong>
    <?= htmlspecialchars(
        $new_quotation_no
    ); ?>

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
    Review and change only the values required for this revision.
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
        $new_quotation_no
    ); ?>"
    readonly
    required
>

</div>


<div class="col-12 client-information-panel">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
        <div>
            <label class="quote-form-label mb-0">Customer Information *</label>
            <small class="text-muted d-block">Naye customer ki details yahin type karein. Purana customer lena ho to Master dropdown se select karein.</small>
        </div>
        <input type="hidden" name="client_id" id="client_id" value="<?= intval($source['client_id'] ?? 0); ?>">
        <details class="customer-options" <?= !empty($source['client_id']) ? 'open' : ''; ?>>
            <summary>Choose existing customer</summary>
            <select id="clientMasterSelect" class="form-select form-select-sm mt-2">
                <option value="">Select from Customer Master</option>
                <?php foreach ($clients as $client) { ?>
                <option value="<?= intval($client['id']); ?>" <?= intval($source['client_id'] ?? 0) === intval($client['id']) ? 'selected' : ''; ?>><?= htmlspecialchars($client['company_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></option>
                <?php } ?>
            </select>
        </details>
    </div>
    <div class="row g-2">
        <div class="col-lg-4 col-md-6">
            <label class="quote-form-label">Customer / Company Name *</label>
            <input type="text" name="client_name" id="clientName" class="form-control" maxlength="150" autocomplete="off" value="<?= htmlspecialchars((string)($clientDetailsSnapshot['company_name'] ?? $source['client_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" required>
        </div>
        <div class="col-lg-4 col-md-6">
            <label class="quote-form-label">Contact Person</label>
            <input type="text" name="client_contact_person" id="clientContactPerson" class="form-control" maxlength="150" value="<?= htmlspecialchars((string)($clientDetailsSnapshot['contact_person'] ?? $source['master_contact_person'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
        </div>
        <div class="col-lg-4 col-md-6">
            <label class="quote-form-label">GSTIN</label>
            <input type="text" name="client_gstin" id="clientGstin" class="form-control" maxlength="30" value="<?= htmlspecialchars((string)($clientDetailsSnapshot['gstin'] ?? $source['master_gstin'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
        </div>
        <div class="col-lg-3 col-md-6">
            <label class="quote-form-label">Mobile</label>
            <input type="text" name="client_mobile" id="clientMobile" class="form-control" maxlength="20" value="<?= htmlspecialchars((string)($clientDetailsSnapshot['mobile'] ?? $source['master_mobile'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
        </div>
        <div class="col-lg-6 col-md-12">
            <label class="quote-form-label">Email</label>
            <input type="email" name="client_email" id="clientEmail" class="form-control" maxlength="100" value="<?= htmlspecialchars((string)($clientDetailsSnapshot['email'] ?? $source['master_email'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
        </div>
        <div class="col-12">
            <label class="quote-form-label">Billing Address</label>
            <textarea name="client_address" id="clientAddress" class="form-control" rows="2"><?= htmlspecialchars((string)($clientDetailsSnapshot['address'] ?? $source['master_address'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></textarea>
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
        $source[
            'rfq_no'
        ] ?? ''
    ); ?>"
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
    value="<?= htmlspecialchars(
        $source[
            'rfq_date'
        ] ?? ''
    ); ?>"
>

</div>


<div class="col-xl-2 col-lg-4 col-md-6">

<label class="quote-form-label">
    Revision Date *
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
    value="<?= htmlspecialchars(
        $source[
            'subject'
        ] ?? ''
    ); ?>"
>

</div>


<div class="col-xl-2 col-lg-3 col-md-6">

<label class="quote-form-label">
    Currency
</label>

<select
    name="currency"
    class="form-select"
>

<?php
$currencies = [
    'INR' => 'INR ₹',
    'USD' => 'USD $',
    'EUR' => 'EUR €',
    'GBP' => 'GBP £'
];

foreach (
    $currencies
    as $currency_code =>
       $currency_label
) {
?>

<option
    value="<?= htmlspecialchars(
        $currency_code
    ); ?>"
    <?= (
        strtoupper(
            $source[
                'currency'
            ] ?? 'INR'
        ) ===
        $currency_code
    )
        ? 'selected'
        : '';
    ?>
>
    <?= htmlspecialchars(
        $currency_label
    ); ?>
</option>

<?php } ?>

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
    value="<?= htmlspecialchars(
        $source[
            'validity_text'
        ] ?? ''
    ); ?>"
>

</div>


<div class="col-xl-2 col-lg-3 col-md-6">

<label class="quote-form-label">
    New Revision Status
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
            The source quotation's assigned template is retained. Add rows or edit item values as needed.
        </div>

    </div>

    <div class="d-flex gap-1 flex-wrap">

        <button
            type="button"
            class="btn btn-outline-primary btn-sm"
            id="addColumn"
            disabled
        >
            Columns fixed by source template
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
    Keep Current Revision Terms
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
            Existing commercial terms copied from source quotation
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
            Existing general conditions copied from source quotation
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
     REVISION REMARKS
===================================================== -->

<div class="card quote-builder-card mb-2">

<div class="card-body">

<div class="quote-section-title mb-2">
    Revision Remarks
</div>

<textarea
    name="remarks"
    class="form-control"
    rows="3"
    placeholder="Example: Rate revised as per client discussion..."
><?= htmlspecialchars(
    $source[
        'remarks'
    ] ?? ''
); ?></textarea>

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
    class="btn btn-primary"
>
    <i class="mdi mdi-file-document-edit-outline me-1"></i>
    Save Revision R<?= intval(
        $next_revision_no
    ); ?>
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
        // PRELOADED SOURCE DATA
        // =================================================

        const termSets =
            <?= $term_sets_json ?: '{}'; ?>;

        let columns =
            <?= $columns_json ?: '[]'; ?>;

        let initialRows =
            <?= $rows_json ?: '[]'; ?>;

        let currentCommercial =
            <?= $commercial_json ?: '[]'; ?>;

        let currentGeneral =
            <?= $general_json ?: '[]'; ?>;


        let rowCount =
            initialRows.length > 0
                ? initialRows.length
                : 1;


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


        // =================================================
        // ESCAPE
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
        // COLUMN CONFIG
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
                                    name="column_label[]" disabled
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
                                    name="column_unit[]" disabled
                                    class="form-control form-control-sm column-unit-input"
                                    data-index="${index}"
                                    value="${escapeHtml(column.unit || "")}"
                                    placeholder="kg / ₹/kg"
                                >
                            </div>

                            <div>
                                <label class="quote-form-label">
                                    Type
                                </label>

                                <select
                                    name="column_type[]" disabled
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
                                    name="column_align[]" disabled
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
                                    disabled
                                    data-index="${index}"
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

                                columns[
                                    Number(
                                        this.dataset.index
                                    )
                                ].label =
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

                                columns[
                                    Number(
                                        this.dataset.index
                                    )
                                ].unit =
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

                                columns[
                                    Number(
                                        this.dataset.index
                                    )
                                ].type =
                                    this.value;

                                renderQuotationTable();
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

                                columns[
                                    Number(
                                        this.dataset.index
                                    )
                                ].align =
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

                                initialRows =
                                    initialRows.map(
                                        function (row) {

                                            row.splice(
                                                index,
                                                1
                                            );

                                            return row;
                                        }
                                    );

                                renderColumnConfig();
                            }
                        );
                    }
                );
        }


        // =================================================
        // HEADER
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
                        column.align || "left";

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
        // TABLE
        // =================================================

        function collectCurrentRows() {

            const values = [];

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
                                    ] =
                                        input.value;
                                }
                            );

                        values.push(
                            rowValues
                        );
                    }
                );

            return values;
        }


        function renderQuotationTable() {

            const liveRows =
                quotationBody.children.length > 0
                    ? collectCurrentRows()
                    : initialRows;

            renderQuotationHeader();

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
                                column.type === "textarea"
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
                            column.align || "left";


                        if (
                            column.type === "number" ||
                            column.type === "currency"
                        ) {

                            input.type =
                                "number";

                            input.step =
                                "0.001";

                        } else if (
                            column.type !== "textarea"
                        ) {

                            input.type =
                                "text";
                        }


                        if (
                            liveRows[rowIndex] &&
                            liveRows[rowIndex][columnIndex] !== undefined
                        ) {

                            input.value =
                                liveRows[rowIndex][columnIndex];
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


            initialRows =
                collectCurrentRows();
        }


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

            initialRows =
                collectCurrentRows();
        }


        // =================================================
        // ADD COLUMN / ROW
        // =================================================

        document
            .getElementById(
                "addColumn"
            )
            .addEventListener(
                "click",
                function () {

                    initialRows =
                        collectCurrentRows();

                    columns.push(
                        {
                            label: "New Column",
                            unit: "",
                            type: "text",
                            align: "left"
                        }
                    );

                    initialRows =
                        initialRows.map(
                            function (row) {

                                row.push("");

                                return row;
                            }
                        );

                    renderColumnConfig();
                }
            );


        document
            .getElementById(
                "addRow"
            )
            .addEventListener(
                "click",
                function () {

                    initialRows =
                        collectCurrentRows();

                    rowCount++;

                    initialRows.push(
                        Array(
                            columns.length
                        ).fill("")
                    );

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
                                value="${escapeHtml(row.particular || "")}"
                                required
                            >
                        </td>

                        <td>
                            <textarea
                                name="commercial_value[]"
                                class="form-control form-control-sm"
                                rows="2"
                            >${escapeHtml(row.value || row.term_value || "")}</textarea>
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

            bindCommercialDelete();
        }


        function bindCommercialDelete() {

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

                    bindCommercialDelete();
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
        // LOAD SAVED TERMS
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
                            currentCommercial
                        );

                        renderGeneralTerms(
                            currentGeneral
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
                                        row.particular || "",

                                    value:
                                        row.term_value || ""
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
                                    row.condition_text || ""
                                );
                            }
                        );


                    renderCommercialTerms(
                        commercialRows
                    );

                    renderGeneralTerms(
                        generalRows
                    );
                }
            );


        // =================================================
        // INITIAL RENDER
        // =================================================

        renderColumnConfig();

        renderCommercialTerms(
            currentCommercial
        );

        renderGeneralTerms(
            currentGeneral
        );

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
