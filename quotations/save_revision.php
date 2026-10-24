<?php

session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

include("../includes/db.php");
require_once __DIR__ . "/../includes/permissions.php";
erpRequireCompanyUser($conn);
erpRequire("quotations.edit");
$companyId = erpCurrentCompanyId();
$userId = (int)$_SESSION["user_id"];


// =====================================================
// REQUEST METHOD
// =====================================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit;
}


// =====================================================
// CSRF CHECK
// =====================================================

$csrf_token = $_POST['csrf_token'] ?? '';

if (
    empty($_SESSION['csrf_token']) ||
    empty($csrf_token) ||
    !hash_equals(
        $_SESSION['csrf_token'],
        $csrf_token
    )
) {

    $_SESSION['quotation_error'] =
        "Invalid or expired request. Please try again.";

    header("Location: index.php");
    exit;
}


// =====================================================
// HELPERS
// =====================================================

function revisionClean($value)
{
    return trim((string)($value ?? ''));
}


function revisionNullableDate($value)
{
    $value =
        revisionClean(
            $value
        );

    if ($value === '') {
        return null;
    }

    $date =
        DateTime::createFromFormat(
            'Y-m-d',
            $value
        );

    if (
        !$date ||
        $date->format('Y-m-d') !== $value
    ) {
        return null;
    }

    return $value;
}


function revisionAllowedStatus($value)
{
    $allowed = [
        'Draft',
        'Sent'
    ];

    return in_array(
        $value,
        $allowed,
        true
    )
        ? $value
        : 'Draft';
}


function revisionAllowedColumnType($value)
{
    $allowed = [
        'text',
        'number',
        'currency',
        'textarea'
    ];

    return in_array(
        $value,
        $allowed,
        true
    )
        ? $value
        : 'text';
}


function revisionAllowedAlign($value)
{
    $allowed = [
        'left',
        'center',
        'right'
    ];

    return in_array(
        $value,
        $allowed,
        true
    )
        ? $value
        : 'left';
}


function revisionFail($message, $source_id = 0)
{
    $_SESSION['quotation_error'] =
        $message;

    if ($source_id > 0) {
        header(
            "Location: revise.php?id=" .
            intval(
                $source_id
            )
        );
    } else {
        header("Location: index.php");
    }

    exit;
}


// =====================================================
// REVISION IDENTIFIERS
// =====================================================

$source_quotation_id =
    intval(
        $_POST[
            'source_quotation_id'
        ] ?? 0
    );

$revision_root_id =
    intval(
        $_POST[
            'revision_root_id'
        ] ?? 0
    );

$revision_no =
    intval(
        $_POST[
            'revision_no'
        ] ?? 0
    );


if (
    $source_quotation_id <= 0 ||
    $revision_root_id <= 0 ||
    $revision_no <= 0
) {

    revisionFail(
        "Invalid quotation revision request.",
        $source_quotation_id
    );
}


// =====================================================
// MASTER INPUTS
// =====================================================

$quotation_no =
    revisionClean(
        $_POST[
            'quotation_no'
        ] ?? ''
    );

$client_id =
    max(0, intval($_POST['client_id'] ?? 0));

$client_name =
    revisionClean($_POST['client_name'] ?? '');

$rfq_no =
    revisionClean(
        $_POST[
            'rfq_no'
        ] ?? ''
    );

$rfq_date =
    revisionNullableDate(
        $_POST[
            'rfq_date'
        ] ?? ''
    );

$quotation_date =
    revisionNullableDate(
        $_POST[
            'quotation_date'
        ] ?? ''
    );

$subject =
    revisionClean(
        $_POST[
            'subject'
        ] ?? ''
    );

$currency =
    strtoupper(
        revisionClean(
            $_POST[
                'currency'
            ] ?? 'INR'
        )
    );

$validity_text =
    revisionClean(
        $_POST[
            'validity_text'
        ] ?? ''
    );

$status =
    revisionAllowedStatus(
        revisionClean(
            $_POST[
                'status'
            ] ?? 'Draft'
        )
    );

$remarks =
    revisionClean(
        $_POST[
            'remarks'
        ] ?? ''
    );

$created_by =
    intval(
        $_SESSION[
            'user_id'
        ] ?? 0
    );


// =====================================================
// VALIDATION
// =====================================================

if ($quotation_no === '') {

    revisionFail(
        "Quotation No. is required.",
        $source_quotation_id
    );
}


if ($client_name === '' || mb_strlen($client_name, 'UTF-8') > 150) {
    revisionFail(
        "Type the customer or company name (maximum 150 characters).",
        $source_quotation_id
    );
}


if ($quotation_date === null) {

    revisionFail(
        "Valid Revision Date is required.",
        $source_quotation_id
    );
}


$allowed_currencies = [
    'INR',
    'USD',
    'EUR',
    'GBP'
];

if (
    !in_array(
        $currency,
        $allowed_currencies,
        true
    )
) {
    $currency = 'INR';
}


// =====================================================
// Preserve the source quotation's historical template columns. Posted column edits are ignored.
$sourceColumnsStmt = mysqli_prepare(
    $conn,
    "SELECT qc.column_key, qc.column_label, qc.unit_text, qc.data_type, qc.text_align
     FROM quotation_columns qc
     INNER JOIN quotations q ON q.id = qc.quotation_id
     WHERE q.id = ? AND q.company_id = ? AND q.created_by = ? AND qc.is_visible = 1
     ORDER BY qc.sort_order ASC, qc.id ASC"
);
if (!$sourceColumnsStmt) {
    revisionFail("Unable to load the source quotation template.", $source_quotation_id);
}
mysqli_stmt_bind_param($sourceColumnsStmt, "iii", $source_quotation_id, $companyId, $userId);
mysqli_stmt_execute($sourceColumnsStmt);
$sourceColumnsResult = mysqli_stmt_get_result($sourceColumnsStmt);
$columns = [];
while ($sourceColumn = mysqli_fetch_assoc($sourceColumnsResult)) {
    $columns[] = [
        'source_index' => count($columns),
        'column_key' => (string)$sourceColumn['column_key'],
        'column_label' => (string)$sourceColumn['column_label'],
        'unit_text' => (string)($sourceColumn['unit_text'] ?? ''),
        'data_type' => revisionAllowedColumnType($sourceColumn['data_type'] ?? 'text'),
        'text_align' => revisionAllowedAlign($sourceColumn['text_align'] ?? 'left')
    ];
}
mysqli_stmt_close($sourceColumnsStmt);
if (!$columns) {
    revisionFail("The source quotation has no active template columns.", $source_quotation_id);
}


// =====================================================


// DYNAMIC ROWS
// =====================================================

$posted_rows =
    $_POST[
        'row_data'
    ] ?? [];

if (!is_array($posted_rows)) {
    $posted_rows = [];
}


$rows = [];


foreach (
    $posted_rows
    as $posted_row
) {

    if (!is_array($posted_row)) {
        continue;
    }

    $row_json_data = [];
    $has_value = false;


    foreach (
        $columns
        as $column
    ) {

        $source_index =
            $column[
                'source_index'
            ];

        $column_key =
            $column[
                'column_key'
            ];

        $cell_value =
            revisionClean(
                $posted_row[
                    $source_index
                ] ?? ''
            );


        if ($cell_value !== '') {
            $has_value = true;
        }


        $row_json_data[
            $column_key
        ] =
            $cell_value;
    }


    if (!$has_value) {
        continue;
    }


    $first_key =
        $columns[0][
            'column_key'
        ];

    $item_reference =
        revisionClean(
            $row_json_data[
                $first_key
            ] ?? ''
        );


    $rows[] = [
        'item_reference' =>
            $item_reference,

        'row_data' =>
            $row_json_data
    ];
}


if (count($rows) === 0) {

    revisionFail(
        "Please enter at least one quotation row.",
        $source_quotation_id
    );
}


// =====================================================
// COMMERCIAL TERMS
// =====================================================

$commercial_particulars =
    $_POST[
        'commercial_particular'
    ] ?? [];

$commercial_values =
    $_POST[
        'commercial_value'
    ] ?? [];

if (!is_array($commercial_particulars)) {
    $commercial_particulars = [];
}

if (!is_array($commercial_values)) {
    $commercial_values = [];
}


$commercial_terms = [];


foreach (
    $commercial_particulars
    as $index =>
       $particular
) {

    $particular =
        revisionClean(
            $particular
        );

    $term_value =
        revisionClean(
            $commercial_values[
                $index
            ] ?? ''
        );


    if (
        $particular === '' &&
        $term_value === ''
    ) {
        continue;
    }


    $commercial_terms[] = [
        'particular' =>
            $particular,

        'term_value' =>
            $term_value
    ];
}


// =====================================================
// GENERAL TERMS
// =====================================================

$general_conditions =
    $_POST[
        'general_condition'
    ] ?? [];

if (!is_array($general_conditions)) {
    $general_conditions = [];
}


$general_terms = [];


foreach (
    $general_conditions
    as $condition
) {

    $condition =
        revisionClean(
            $condition
        );

    if ($condition === '') {
        continue;
    }

    $general_terms[] =
        $condition;
}


// =====================================================
// TENANT-OWNED CLIENT CHECK
// =====================================================

if ($client_id > 0) {
    $clientCheck = mysqli_prepare(
        $conn,
        "SELECT company_name FROM clients WHERE id = ? AND company_id = ? LIMIT 1"
    );
    if (!$clientCheck) {
        revisionFail("Unable to validate selected customer.", $source_quotation_id);
    }
    mysqli_stmt_bind_param($clientCheck, "ii", $client_id, $companyId);
    mysqli_stmt_execute($clientCheck);
    $clientResult = mysqli_stmt_get_result($clientCheck);
    $selectedClient = $clientResult ? mysqli_fetch_assoc($clientResult) : null;
    mysqli_stmt_close($clientCheck);
    if (!$selectedClient) {
        revisionFail("Selected Customer Master record was not found.", $source_quotation_id);
    }
    $client_name = revisionClean($selectedClient['company_name']);
}


// TRANSACTION
// =====================================================

mysqli_begin_transaction(
    $conn
);


try {

    // =================================================
    // VERIFY SOURCE + ROOT
    // =================================================

    $verify_sql = "
        SELECT
            id,
            revision_of_quotation_id,
            template_version_id,
            template_config_snapshot
        FROM quotations
        WHERE id = ?
          AND company_id = ?
          AND created_by = ?
        LIMIT 1
    ";

    $verify_stmt =
        mysqli_prepare(
            $conn,
            $verify_sql
        );

    if (!$verify_stmt) {
        throw new Exception(
            mysqli_error($conn)
        );
    }

    mysqli_stmt_bind_param(
        $verify_stmt,
        "iii",
        $source_quotation_id,
        $companyId,
        $userId
    );

    mysqli_stmt_execute(
        $verify_stmt
    );

    $verify_result =
        mysqli_stmt_get_result(
            $verify_stmt
        );

    $verify_row =
        mysqli_fetch_assoc(
            $verify_result
        );

    mysqli_stmt_close(
        $verify_stmt
    );


    if (!$verify_row) {
        throw new Exception(
            "Source quotation not found."
        );
    }


    $actual_root_id =
        !empty(
            $verify_row[
                'revision_of_quotation_id'
            ]
        )
            ? intval(
                $verify_row[
                    'revision_of_quotation_id'
                ]
            )
            : intval(
                $verify_row[
                    'id'
                ]
            );


    if (
        $actual_root_id !==
        $revision_root_id
    ) {

        throw new Exception(
            "Revision root mismatch."
        );
    }


    // =================================================
    // CALCULATE NEXT REVISION AGAIN
    //
    // Do not trust only hidden form value.
    // =================================================

    $next_revision_sql = "
        SELECT
            COALESCE(
                MAX(revision_no),
                0
            ) + 1 AS next_revision
        FROM quotations
        WHERE company_id = ?
          AND (id = ? OR revision_of_quotation_id = ?)
    ";

    $next_revision_stmt =
        mysqli_prepare(
            $conn,
            $next_revision_sql
        );

    if (!$next_revision_stmt) {
        throw new Exception(
            mysqli_error($conn)
        );
    }

    mysqli_stmt_bind_param(
        $next_revision_stmt,
        "iii",
        $companyId,
        $revision_root_id,
        $revision_root_id
    );

    mysqli_stmt_execute(
        $next_revision_stmt
    );

    $next_revision_result =
        mysqli_stmt_get_result(
            $next_revision_stmt
        );

    $next_revision_row =
        mysqli_fetch_assoc(
            $next_revision_result
        );

    mysqli_stmt_close(
        $next_revision_stmt
    );


    $actual_revision_no =
        intval(
            $next_revision_row[
                'next_revision'
            ] ?? 1
        );


    if (
        $actual_revision_no !==
        $revision_no
    ) {

        throw new Exception(
            "Revision number changed. Please reopen the Revise page and try again."
        );
    }


    // =================================================
    // DUPLICATE QUOTATION NUMBER
    // =================================================

    $duplicate_sql = "
        SELECT id
        FROM quotations
        WHERE quotation_no = ?
          AND company_id = ?
        LIMIT 1
    ";

    $duplicate_stmt =
        mysqli_prepare(
            $conn,
            $duplicate_sql
        );

    if (!$duplicate_stmt) {
        throw new Exception(
            mysqli_error($conn)
        );
    }

    mysqli_stmt_bind_param(
        $duplicate_stmt,
        "si",
        $quotation_no,
        $companyId
    );

    mysqli_stmt_execute(
        $duplicate_stmt
    );

    mysqli_stmt_store_result(
        $duplicate_stmt
    );


    if (
        mysqli_stmt_num_rows(
            $duplicate_stmt
        ) > 0
    ) {

        mysqli_stmt_close(
            $duplicate_stmt
        );

        throw new Exception(
            "Revision quotation number already exists."
        );
    }


    mysqli_stmt_close(
        $duplicate_stmt
    );


    // =================================================
    // INSERT NEW REVISION MASTER
    // =================================================

    $master_sql = "
        INSERT INTO quotations
        (
            quotation_no,
            client_id,
            client_name_override,
            rfq_no,
            rfq_date,
            quotation_date,
            subject,
            currency,
            validity_text,
            status,
            remarks,
            company_id,
            template_version_id,
            template_config_snapshot,
            copied_from_quotation_id,
            revision_of_quotation_id,
            revision_no,
            created_by
        )
        VALUES
        (
            ?,
            NULLIF(?, 0),
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?
        )
    ";

    $master_stmt =
        mysqli_prepare(
            $conn,
            $master_sql
        );

    if (!$master_stmt) {
        throw new Exception(
            "Revision master prepare failed: " .
            mysqli_error($conn)
        );
    }


    $sourceTemplateVersionId = (int)($verify_row['template_version_id'] ?? 0);
    $sourceTemplateSnapshot = (string)($verify_row['template_config_snapshot'] ?? '');

    mysqli_stmt_bind_param(
        $master_stmt,
        "sisssssssssiiisiii",
        $quotation_no,
        $client_id,
        $client_name,
        $rfq_no,
        $rfq_date,
        $quotation_date,
        $subject,
        $currency,
        $validity_text,
        $status,
        $remarks,
        $companyId,
        $sourceTemplateVersionId,
        $sourceTemplateSnapshot,
        $source_quotation_id,
        $revision_root_id,
        $revision_no,
        $created_by
    );


    if (
        !mysqli_stmt_execute(
            $master_stmt
        )
    ) {

        throw new Exception(
            "Revision master save failed: " .
            mysqli_stmt_error(
                $master_stmt
            )
        );
    }


    $new_quotation_id =
        mysqli_insert_id(
            $conn
        );


    mysqli_stmt_close(
        $master_stmt
    );


    // =================================================
    // INSERT COLUMNS
    // =================================================

    $column_sql = "
        INSERT INTO quotation_columns
        (
            quotation_id,
            column_key,
            column_label,
            unit_text,
            data_type,
            text_align,
            column_width,
            sort_order,
            is_visible
        )
        VALUES
        (
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            NULL,
            ?,
            1
        )
    ";

    $column_stmt =
        mysqli_prepare(
            $conn,
            $column_sql
        );

    if (!$column_stmt) {
        throw new Exception(
            "Revision column prepare failed: " .
            mysqli_error($conn)
        );
    }


    foreach (
        $columns
        as $index =>
           $column
    ) {

        $sort_order =
            $index + 1;

        $column_key =
            $column[
                'column_key'
            ];

        $column_label =
            $column[
                'column_label'
            ];

        $unit_text =
            $column[
                'unit_text'
            ];

        $data_type =
            $column[
                'data_type'
            ];

        $text_align =
            $column[
                'text_align'
            ];


        mysqli_stmt_bind_param(
            $column_stmt,
            "isssssi",
            $new_quotation_id,
            $column_key,
            $column_label,
            $unit_text,
            $data_type,
            $text_align,
            $sort_order
        );


        if (
            !mysqli_stmt_execute(
                $column_stmt
            )
        ) {

            throw new Exception(
                "Revision column save failed: " .
                mysqli_stmt_error(
                    $column_stmt
                )
            );
        }
    }


    mysqli_stmt_close(
        $column_stmt
    );


    // =================================================
    // INSERT ROWS
    // =================================================

    $row_sql = "
        INSERT INTO quotation_rows
        (
            quotation_id,
            row_no,
            product_id,
            item_reference,
            row_data,
            row_amount,
            sort_order
        )
        VALUES
        (
            ?,
            ?,
            NULL,
            ?,
            ?,
            NULL,
            ?
        )
    ";

    $row_stmt =
        mysqli_prepare(
            $conn,
            $row_sql
        );

    if (!$row_stmt) {
        throw new Exception(
            "Revision row prepare failed: " .
            mysqli_error($conn)
        );
    }


    foreach (
        $rows
        as $index =>
           $row
    ) {

        $row_no =
            $index + 1;

        $sort_order =
            $row_no;

        $item_reference =
            $row[
                'item_reference'
            ];

        $row_json =
            json_encode(
                $row[
                    'row_data'
                ],
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            );


        if ($row_json === false) {
            throw new Exception(
                "Unable to encode revision row data."
            );
        }


        mysqli_stmt_bind_param(
            $row_stmt,
            "iissi",
            $new_quotation_id,
            $row_no,
            $item_reference,
            $row_json,
            $sort_order
        );


        if (
            !mysqli_stmt_execute(
                $row_stmt
            )
        ) {

            throw new Exception(
                "Revision row save failed: " .
                mysqli_stmt_error(
                    $row_stmt
                )
            );
        }
    }


    mysqli_stmt_close(
        $row_stmt
    );


    // =================================================
    // INSERT COMMERCIAL TERMS
    // =================================================

    if (
        count(
            $commercial_terms
        ) > 0
    ) {

        $commercial_sql = "
            INSERT INTO quotation_commercial_terms
            (
                quotation_id,
                particular,
                term_value,
                sort_order,
                is_visible
            )
            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                1
            )
        ";

        $commercial_stmt =
            mysqli_prepare(
                $conn,
                $commercial_sql
            );

        if (!$commercial_stmt) {
            throw new Exception(
                "Revision commercial terms prepare failed: " .
                mysqli_error($conn)
            );
        }


        foreach (
            $commercial_terms
            as $index =>
               $term
        ) {

            $sort_order =
                $index + 1;

            $particular =
                $term[
                    'particular'
                ];

            $term_value =
                $term[
                    'term_value'
                ];


            mysqli_stmt_bind_param(
                $commercial_stmt,
                "issi",
                $new_quotation_id,
                $particular,
                $term_value,
                $sort_order
            );


            if (
                !mysqli_stmt_execute(
                    $commercial_stmt
                )
            ) {

                throw new Exception(
                    "Revision commercial term save failed: " .
                    mysqli_stmt_error(
                        $commercial_stmt
                    )
                );
            }
        }


        mysqli_stmt_close(
            $commercial_stmt
        );
    }


    // =================================================
    // INSERT GENERAL TERMS
    // =================================================

    if (
        count(
            $general_terms
        ) > 0
    ) {

        $general_sql = "
            INSERT INTO quotation_general_terms
            (
                quotation_id,
                condition_text,
                sort_order,
                is_visible
            )
            VALUES
            (
                ?,
                ?,
                ?,
                1
            )
        ";

        $general_stmt =
            mysqli_prepare(
                $conn,
                $general_sql
            );

        if (!$general_stmt) {
            throw new Exception(
                "Revision general terms prepare failed: " .
                mysqli_error($conn)
            );
        }


        foreach (
            $general_terms
            as $index =>
               $condition
        ) {

            $sort_order =
                $index + 1;


            mysqli_stmt_bind_param(
                $general_stmt,
                "isi",
                $new_quotation_id,
                $condition,
                $sort_order
            );


            if (
                !mysqli_stmt_execute(
                    $general_stmt
                )
            ) {

                throw new Exception(
                    "Revision general term save failed: " .
                    mysqli_stmt_error(
                        $general_stmt
                    )
                );
            }
        }


        mysqli_stmt_close(
            $general_stmt
        );
    }


    // =================================================
    // MARK SOURCE QUOTATION AS REVISED
    //
    // Previous quotation remains in history.
    // =================================================

    $source_update_sql = "
        UPDATE quotations
        SET status = 'Revised'
        WHERE id = ?
          AND company_id = ?
          AND created_by = ?
    ";

    $source_update_stmt =
        mysqli_prepare(
            $conn,
            $source_update_sql
        );

    if (!$source_update_stmt) {
        throw new Exception(
            "Source status update prepare failed: " .
            mysqli_error($conn)
        );
    }


    mysqli_stmt_bind_param(
        $source_update_stmt,
        "iii",
        $source_quotation_id,
        $companyId,
        $userId
    );


    if (
        !mysqli_stmt_execute(
            $source_update_stmt
        )
    ) {

        throw new Exception(
            "Source status update failed: " .
            mysqli_stmt_error(
                $source_update_stmt
            )
        );
    }


    mysqli_stmt_close(
        $source_update_stmt
    );


    // =================================================
    // COMMIT
    // =================================================

    mysqli_commit(
        $conn
    );


    $_SESSION['quotation_success'] =
        "Quotation Revision R" .
        $revision_no .
        " saved successfully.";


    header(
        "Location: index.php?revised=1&id=" .
        intval(
            $new_quotation_id
        )
    );

    exit;


} catch (Throwable $e) {

    mysqli_rollback(
        $conn
    );


    $_SESSION['quotation_error'] =
        $e->getMessage();


    header(
        "Location: revise.php?id=" .
        intval(
            $source_quotation_id
        )
    );

    exit;
}
?>
