<?php

session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

include("../includes/db.php");
require_once __DIR__ . '/../includes/permissions.php';
erpRequireCompanyUser($conn);
erpRequire('quotations.add');
$companyId = erpCurrentCompanyId();


// =====================================================
// REQUEST METHOD
// =====================================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: add.php");
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

    header("Location: add.php");
    exit;
}


// =====================================================
// HELPERS
// =====================================================

function quoteClean($value)
{
    return trim((string)($value ?? ''));
}


function quoteNullableDate($value)
{
    $value = quoteClean($value);

    if ($value === '') {
        return null;
    }

    $date = DateTime::createFromFormat(
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


function quoteAllowedStatus($value)
{
    $allowed = [
        'Draft',
        'Sent',
        'Accepted',
        'Rejected',
        'Expired'
    ];

    return in_array(
        $value,
        $allowed,
        true
    )
        ? $value
        : 'Draft';
}


function quoteAllowedColumnType($value)
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


function quoteAllowedAlign($value)
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


function quoteFail($message)
{
    $_SESSION['quotation_error'] =
        $message;

    header("Location: add.php");
    exit;
}


// =====================================================
// MASTER INPUTS
// =====================================================

$quotation_no =
    quoteClean(
        $_POST['quotation_no'] ?? ''
    );

$client_id =
    intval(
        $_POST['client_id'] ?? 0
    );

$rfq_no =
    quoteClean(
        $_POST['rfq_no'] ?? ''
    );

$rfq_date =
    quoteNullableDate(
        $_POST['rfq_date'] ?? ''
    );

$quotation_date =
    quoteNullableDate(
        $_POST['quotation_date'] ?? ''
    );

$subject =
    quoteClean(
        $_POST['subject'] ?? ''
    );

$currency =
    strtoupper(
        quoteClean(
            $_POST['currency'] ?? 'INR'
        )
    );

$validity_text =
    quoteClean(
        $_POST['validity_text'] ?? ''
    );

$status =
    quoteAllowedStatus(
        quoteClean(
            $_POST['status'] ?? 'Draft'
        )
    );

$remarks =
    quoteClean(
        $_POST['remarks'] ?? ''
    );

$created_by =
    intval(
        $_SESSION['user_id'] ?? 0
    );


// =====================================================
// BASIC VALIDATION
// =====================================================

if ($quotation_no === '') {
    quoteFail(
        "Quotation No. is required."
    );
}

if ($client_id <= 0) {
    quoteFail(
        "Please select Client."
    );
}

if ($quotation_date === null) {
    quoteFail(
        "Valid Quotation Date is required."
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
// CHECK CLIENT EXISTS
// =====================================================

$client_stmt = mysqli_prepare(
    $conn,
    "
    SELECT id
    FROM clients
    WHERE id = ?
      AND company_id = ?
    LIMIT 1
    "
);

if (!$client_stmt) {
    error_log(
        "Quotation client check prepare failed: " .
        mysqli_error($conn)
    );

    quoteFail(
        "Unable to validate selected client."
    );
}

mysqli_stmt_bind_param(
    $client_stmt,
    "ii",
    $client_id,
    $companyId
);

mysqli_stmt_execute(
    $client_stmt
);

$client_result =
    mysqli_stmt_get_result(
        $client_stmt
    );

$client_exists =
    mysqli_fetch_assoc(
        $client_result
    );

mysqli_stmt_close(
    $client_stmt
);

if (!$client_exists) {
    quoteFail(
        "Selected client was not found."
    );
}


// =====================================================
// DYNAMIC COLUMN INPUTS
// =====================================================

$column_labels =
    $_POST['column_label'] ?? [];

$column_units =
    $_POST['column_unit'] ?? [];

$column_types =
    $_POST['column_type'] ?? [];

$column_aligns =
    $_POST['column_align'] ?? [];

if (
    !is_array(
        $column_labels
    )
) {
    $column_labels = [];
}

if (
    count(
        $column_labels
    ) === 0
) {
    quoteFail(
        "At least one quotation column is required."
    );
}


// Build clean column definitions.

$columns = [];

foreach (
    $column_labels
    as $index =>
       $label
) {

    $label =
        quoteClean(
            $label
        );

    if ($label === '') {
        continue;
    }

    $columns[] = [
        'source_index' =>
            intval(
                $index
            ),

        'column_key' =>
            'col_' .
            (
                count(
                    $columns
                ) + 1
            ),

        'column_label' =>
            $label,

        'unit_text' =>
            quoteClean(
                $column_units[
                    $index
                ] ?? ''
            ),

        'data_type' =>
            quoteAllowedColumnType(
                quoteClean(
                    $column_types[
                        $index
                    ] ?? 'text'
                )
            ),

        'text_align' =>
            quoteAllowedAlign(
                quoteClean(
                    $column_aligns[
                        $index
                    ] ?? 'left'
                )
            )
    ];
}


if (
    count(
        $columns
    ) === 0
) {
    quoteFail(
        "At least one valid quotation column is required."
    );
}


// =====================================================
// DYNAMIC ROW INPUTS
// =====================================================

$posted_rows =
    $_POST['row_data'] ?? [];

if (
    !is_array(
        $posted_rows
    )
) {
    $posted_rows = [];
}


$rows = [];


foreach (
    $posted_rows
    as $posted_row
) {

    if (
        !is_array(
            $posted_row
        )
    ) {
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
            quoteClean(
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


    // Ignore completely blank rows.

    if (!$has_value) {
        continue;
    }


    $item_reference = '';

    if (
        count(
            $columns
        ) > 0
    ) {

        $first_key =
            $columns[0][
                'column_key'
            ];

        $item_reference =
            quoteClean(
                $row_json_data[
                    $first_key
                ] ?? ''
            );
    }


    $rows[] = [
        'item_reference' =>
            $item_reference,

        'row_data' =>
            $row_json_data
    ];
}


// A quotation can be saved with no item row only if user
// intentionally keeps it as draft? For this first version,
// require at least one non-empty row to avoid empty quotations.

if (
    count(
        $rows
    ) === 0
) {
    quoteFail(
        "Please enter at least one quotation row."
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

if (
    !is_array(
        $commercial_particulars
    )
) {
    $commercial_particulars = [];
}

if (
    !is_array(
        $commercial_values
    )
) {
    $commercial_values = [];
}


$commercial_terms = [];


foreach (
    $commercial_particulars
    as $index =>
       $particular
) {

    $particular =
        quoteClean(
            $particular
        );

    $term_value =
        quoteClean(
            $commercial_values[
                $index
            ] ?? ''
        );


    if (
        $particular === ''
        &&
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

if (
    !is_array(
        $general_conditions
    )
) {
    $general_conditions = [];
}


$general_terms = [];


foreach (
    $general_conditions
    as $condition
) {

    $condition =
        quoteClean(
            $condition
        );

    if ($condition === '') {
        continue;
    }

    $general_terms[] =
        $condition;
}


// =====================================================
// LOAD THE ASSIGNED TEMPLATE VERSION
// =====================================================

$templateStmt = mysqli_prepare(
    $conn,
    "SELECT v.id, v.config_json
     FROM users u
     JOIN quotation_template_versions v ON v.id = u.assigned_template_version_id
     JOIN quotation_templates t ON t.id = v.template_id
     WHERE u.id = ? AND u.company_id = ? AND u.status = 'Active' AND t.is_active = 1
     LIMIT 1"
);
mysqli_stmt_bind_param($templateStmt, "ii", $userId, $companyId);
mysqli_stmt_execute($templateStmt);
$templateRow = mysqli_stmt_get_result($templateStmt);
$templateRow = $templateRow ? mysqli_fetch_assoc($templateRow) : null;
mysqli_stmt_close($templateStmt);
if (!$templateRow) {
    throw new RuntimeException("No active quotation template is assigned to this user.");
}
$templateVersionId = (int)$templateRow['id'];
$templateSnapshot = (string)$templateRow['config_json'];
if (!is_array(json_decode($templateSnapshot, true))) {
    throw new RuntimeException("Assigned quotation template is invalid.");
}


// =====================================================
// DATABASE TRANSACTION
// =====================================================

mysqli_begin_transaction(
    $conn
);


try {

    // =================================================
    // DUPLICATE QUOTATION NO. CHECK
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
            "Quotation No. already exists."
        );
    }

    mysqli_stmt_close(
        $duplicate_stmt
    );


    // =================================================
    // INSERT QUOTATION MASTER
    // =================================================

    $master_sql = "
        INSERT INTO quotations
        (
            quotation_no,
            client_id,
            rfq_no,
            rfq_date,
            quotation_date,
            subject,
            currency,
            validity_text,
            status,
            remarks,
            copied_from_quotation_id,
            company_id,
            template_version_id,
            template_config_snapshot,
            created_by
        )
        VALUES
        (
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
            NULL,
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
            "Quotation master prepare failed: " .
            mysqli_error($conn)
        );
    }


    mysqli_stmt_bind_param(
        $master_stmt,
        "sissssssssiisi",
        $quotation_no,
        $client_id,
        $rfq_no,
        $rfq_date,
        $quotation_date,
        $subject,
        $currency,
        $validity_text,
        $status,
        $remarks,
        $companyId,
        $templateVersionId,
        $templateSnapshot,
        $created_by
    );


    if (
        !mysqli_stmt_execute(
            $master_stmt
        )
    ) {

        throw new Exception(
            "Quotation master save failed: " .
            mysqli_stmt_error(
                $master_stmt
            )
        );
    }


    $quotation_id =
        mysqli_insert_id(
            $conn
        );


    mysqli_stmt_close(
        $master_stmt
    );


    // =================================================
    // INSERT DYNAMIC COLUMNS
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
            "Quotation column prepare failed: " .
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
            $quotation_id,
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
                "Quotation column save failed: " .
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
    // INSERT DYNAMIC ROWS
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
            "Quotation row prepare failed: " .
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
                "Unable to encode quotation row data."
            );
        }


        mysqli_stmt_bind_param(
            $row_stmt,
            "iissi",
            $quotation_id,
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
                "Quotation row save failed: " .
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
                "Commercial terms prepare failed: " .
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
                $quotation_id,
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
                    "Commercial term save failed: " .
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
                "General terms prepare failed: " .
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
                $quotation_id,
                $condition,
                $sort_order
            );


            if (
                !mysqli_stmt_execute(
                    $general_stmt
                )
            ) {

                throw new Exception(
                    "General term save failed: " .
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
    // COMMIT
    // =================================================

    mysqli_commit(
        $conn
    );


    $_SESSION['quotation_success'] =
        "Quotation saved successfully.";


    header(
        "Location: index.php?created=1&id=" .
        intval(
            $quotation_id
        )
    );

    exit;


} catch (Throwable $e) {

    mysqli_rollback(
        $conn
    );


    error_log(
        "Quotation save failed: " .
        $e->getMessage()
    );


    $_SESSION['quotation_error'] =
        "Quotation could not be saved. Please try again.";


    header(
        "Location: add.php"
    );

    exit;
}
?>
