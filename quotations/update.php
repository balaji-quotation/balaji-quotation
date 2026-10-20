<?php

session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

include("../includes/db.php");
require_once __DIR__ . '/../includes/permissions.php';
erpRequireCompanyUser($conn);
erpRequire('quotations.edit');
$companyId = erpCurrentCompanyId();
$userId = (int)$_SESSION['user_id'];


// =====================================================
// ONLY POST REQUEST
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

function postText($key, $default = '')
{
    return trim((string)($_POST[$key] ?? $default));
}

function validDateOrNull($value)
{
    $value = trim((string)$value);

    if ($value === '') {
        return null;
    }

    $date = DateTime::createFromFormat('Y-m-d', $value);

    if (
        $date &&
        $date->format('Y-m-d') === $value
    ) {
        return $value;
    }

    return null;
}

function redirectWithError($quotation_id, $message)
{
    $_SESSION['quotation_error'] = $message;

    header(
        "Location: edit.php?id=" .
        intval($quotation_id)
    );

    exit;
}


// =====================================================
// MASTER POST DATA
// =====================================================

$quotation_id = intval(
    $_POST['quotation_id'] ?? 0
);

if ($quotation_id <= 0) {
    die("Invalid quotation ID.");
}

$quotation_no = postText(
    'quotation_no'
);

$client_id = intval(
    $_POST['client_id'] ?? 0
);

$rfq_no = postText(
    'rfq_no'
);

$rfq_date = validDateOrNull(
    $_POST['rfq_date'] ?? ''
);

$quotation_date = validDateOrNull(
    $_POST['quotation_date'] ?? ''
);

$subject = postText(
    'subject'
);

$currency = strtoupper(
    postText(
        'currency',
        'INR'
    )
);

$validity_text = postText(
    'validity_text'
);

$status = postText(
    'status',
    'Draft'
);

$remarks = postText(
    'remarks'
);


// =====================================================
// VALIDATION
// =====================================================

if ($quotation_no === '') {
    redirectWithError(
        $quotation_id,
        "Quotation number is required."
    );
}

if ($client_id <= 0) {
    redirectWithError(
        $quotation_id,
        "Please select a client."
    );
}

if ($quotation_date === null) {
    redirectWithError(
        $quotation_id,
        "Valid quotation date is required."
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

$allowed_statuses = [
    'Draft',
    'Sent',
    'Accepted',
    'Rejected',
    'Expired',
    'Revised'
];

if (
    !in_array(
        $status,
        $allowed_statuses,
        true
    )
) {
    $status = 'Draft';
}


// =====================================================
// CHECK QUOTATION EXISTS
// =====================================================

$stmt = mysqli_prepare(
    $conn,
    "
        SELECT id
        FROM quotations
        WHERE id = ?
          AND company_id = ?
          AND created_by = ?
        LIMIT 1
    "
);

if (!$stmt) {
    die(
        "Database Error: " .
        mysqli_error($conn)
    );
}

mysqli_stmt_bind_param(
    $stmt,
    "iii",
    $quotation_id,
    $companyId,
    $userId
);

mysqli_stmt_execute($stmt);

$result =
    mysqli_stmt_get_result($stmt);

if (
    !$result ||
    mysqli_num_rows($result) === 0
) {
    mysqli_stmt_close($stmt);
    die("Quotation not found.");
}

mysqli_stmt_close($stmt);


// =====================================================
// CHECK CLIENT EXISTS
// =====================================================

$stmt = mysqli_prepare(
    $conn,
    "
        SELECT id
        FROM clients
        WHERE id = ?
          AND company_id = ?
        LIMIT 1
    "
);

if (!$stmt) {
    die(
        "Database Error: " .
        mysqli_error($conn)
    );
}

mysqli_stmt_bind_param(
    $stmt,
    "ii",
    $client_id,
    $companyId
);

mysqli_stmt_execute($stmt);

$result =
    mysqli_stmt_get_result($stmt);

if (
    !$result ||
    mysqli_num_rows($result) === 0
) {
    mysqli_stmt_close($stmt);

    redirectWithError(
        $quotation_id,
        "Selected client was not found."
    );
}

mysqli_stmt_close($stmt);


// =====================================================
// CHECK DUPLICATE QUOTATION NUMBER
// =====================================================

$stmt = mysqli_prepare(
    $conn,
    "
        SELECT id
        FROM quotations
        WHERE
            quotation_no = ?
            AND id <> ?
            AND company_id = ?
        LIMIT 1
    "
);

if (!$stmt) {
    die(
        "Database Error: " .
        mysqli_error($conn)
    );
}

mysqli_stmt_bind_param(
    $stmt,
    "sii",
    $quotation_no,
    $quotation_id,
    $companyId
);

mysqli_stmt_execute($stmt);

$result =
    mysqli_stmt_get_result($stmt);

if (
    $result &&
    mysqli_num_rows($result) > 0
) {
    mysqli_stmt_close($stmt);

    redirectWithError(
        $quotation_id,
        "Quotation number already exists."
    );
}

mysqli_stmt_close($stmt);


// =====================================================
// DYNAMIC COLUMN DATA
// =====================================================

$column_labels =
    $_POST['column_label'] ?? [];

$column_units =
    $_POST['column_unit'] ?? [];

$column_types =
    $_POST['column_type'] ?? [];

$column_aligns =
    $_POST['column_align'] ?? [];

$row_data =
    $_POST['row_data'] ?? [];


if (!is_array($column_labels)) {
    $column_labels = [];
}

if (!is_array($column_units)) {
    $column_units = [];
}

if (!is_array($column_types)) {
    $column_types = [];
}

if (!is_array($column_aligns)) {
    $column_aligns = [];
}

if (!is_array($row_data)) {
    $row_data = [];
}


// =====================================================
// CLEAN COLUMNS
// =====================================================

$columns = [];

$allowed_types = [
    'text',
    'number',
    'currency',
    'textarea'
];

$allowed_aligns = [
    'left',
    'center',
    'right'
];

$currency_symbols = [
    'INR' => '₹',
    'USD' => '$',
    'EUR' => '€',
    'GBP' => '£'
];

$currency_symbol =
    $currency_symbols[$currency]
    ?? '₹';


foreach (
    $column_labels
    as $index => $label
) {

    $label = trim(
        (string)$label
    );

    if ($label === '') {
        continue;
    }

    $unit = trim(
        (string)(
            $column_units[$index]
            ?? ''
        )
    );

    $type = trim(
        (string)(
            $column_types[$index]
            ?? 'text'
        )
    );

    $align = trim(
        (string)(
            $column_aligns[$index]
            ?? 'left'
        )
    );


    if (
        !in_array(
            $type,
            $allowed_types,
            true
        )
    ) {
        $type = 'text';
    }

    if (
        !in_array(
            $align,
            $allowed_aligns,
            true
        )
    ) {
        $align = 'left';
    }


    // ---------------------------------------------
    // Currency column:
    // ₹/KG -> $/KG etc. according to quote currency
    // ---------------------------------------------

    if ($type === 'currency') {

        $unit = preg_replace(
            '/^[₹$€£]/u',
            '',
            $unit
        );

        $unit = trim(
            (string)$unit
        );

        if ($unit === '') {
            $unit = '/PCS';
        }

        if (
            substr(
                $unit,
                0,
                1
            ) !== '/'
        ) {
            $unit =
                '/' .
                $unit;
        }

        $unit =
            $currency_symbol .
            $unit;
    }


    $columns[] = [
        'original_index' => intval($index),
        'label'          => $label,
        'unit'           => $unit,
        'type'           => $type,
        'align'          => $align
    ];
}


if (count($columns) === 0) {

    redirectWithError(
        $quotation_id,
        "At least one quotation table column is required."
    );
}


// =====================================================
// CLEAN ROWS
// =====================================================

$clean_rows = [];

foreach ($row_data as $row) {

    if (!is_array($row)) {
        continue;
    }

    $clean_row = [];

    $has_value = false;


    foreach (
        $columns
        as $new_index => $column
    ) {

        $original_index =
            $column['original_index'];

        $value = trim(
            (string)(
                $row[$original_index]
                ?? ''
            )
        );

        if ($value !== '') {
            $has_value = true;
        }

        $clean_row[
            $new_index
        ] = $value;
    }


    if ($has_value) {
        $clean_rows[] =
            $clean_row;
    }
}


// Keep one blank row if all rows are empty.
if (count($clean_rows) === 0) {

    $blank_row = [];

    foreach (
        $columns
        as $index => $column
    ) {
        $blank_row[$index] = '';
    }

    $clean_rows[] =
        $blank_row;
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

$commercial_count =
    max(
        count(
            $commercial_particulars
        ),
        count(
            $commercial_values
        )
    );


for (
    $i = 0;
    $i < $commercial_count;
    $i++
) {

    $particular = trim(
        (string)(
            $commercial_particulars[$i]
            ?? ''
        )
    );

    $value = trim(
        (string)(
            $commercial_values[$i]
            ?? ''
        )
    );

    if (
        $particular === '' &&
        $value === ''
    ) {
        continue;
    }

    $commercial_terms[] = [
        'particular' => $particular,
        'value'      => $value
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

    $condition = trim(
        (string)$condition
    );

    if ($condition === '') {
        continue;
    }

    $general_terms[] =
        $condition;
}


// =====================================================
// DATABASE TRANSACTION
// =====================================================

mysqli_begin_transaction($conn);

try {


    // =================================================
    // UPDATE QUOTATION MASTER
    // =================================================

    $master_sql = "
        UPDATE quotations
        SET
            quotation_no = ?,
            client_id = ?,
            rfq_no = ?,
            rfq_date = ?,
            quotation_date = ?,
            subject = ?,
            currency = ?,
            validity_text = ?,
            status = ?,
            remarks = ?
        WHERE id = ?
          AND company_id = ?
          AND created_by = ?
    ";

    $stmt =
        mysqli_prepare(
            $conn,
            $master_sql
        );

    if (!$stmt) {
        throw new Exception(
            "Quotation Update Prepare Error: " .
            mysqli_error($conn)
        );
    }

    mysqli_stmt_bind_param(
        $stmt,
        "sissssssssiii",
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
        $quotation_id,
        $companyId,
        $userId
    );

    if (
        !mysqli_stmt_execute(
            $stmt
        )
    ) {
        throw new Exception(
            mysqli_stmt_error($stmt)
        );
    }

    mysqli_stmt_close($stmt);


    // =================================================
    // DELETE OLD CHILD DATA
    // =================================================

    $delete_tables = [
        'quotation_rows',
        'quotation_columns',
        'quotation_commercial_terms',
        'quotation_general_terms'
    ];


    foreach (
        $delete_tables
        as $table
    ) {

        $sql =
            "DELETE FROM " .
            $table .
            " WHERE quotation_id = ?";

        $stmt =
            mysqli_prepare(
                $conn,
                $sql
            );

        if (!$stmt) {
            throw new Exception(
                "Delete Prepare Error (" .
                $table .
                "): " .
                mysqli_error($conn)
            );
        }

        mysqli_stmt_bind_param(
            $stmt,
            "i",
            $quotation_id
        );

        if (
            !mysqli_stmt_execute(
                $stmt
            )
        ) {
            throw new Exception(
                mysqli_stmt_error($stmt)
            );
        }

        mysqli_stmt_close($stmt);
    }


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
            sort_order,
            is_visible
        )
        VALUES
        (
            ?, ?, ?, ?, ?, ?, ?, 1
        )
    ";

    $column_stmt =
        mysqli_prepare(
            $conn,
            $column_sql
        );

    if (!$column_stmt) {
        throw new Exception(
            "Column Prepare Error: " .
            mysqli_error($conn)
        );
    }


    $saved_column_keys = [];


    foreach (
        $columns
        as $index => $column
    ) {

        $sort_order =
            $index + 1;

        $column_key =
            'col_' .
            $sort_order;

        $saved_column_keys[] =
            $column_key;

        $label =
            $column['label'];

        $unit =
            $column['unit'];

        $type =
            $column['type'];

        $align =
            $column['align'];


        mysqli_stmt_bind_param(
            $column_stmt,
            "isssssi",
            $quotation_id,
            $column_key,
            $label,
            $unit,
            $type,
            $align,
            $sort_order
        );


        if (
            !mysqli_stmt_execute(
                $column_stmt
            )
        ) {
            throw new Exception(
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
    // Store JSON using the new saved column keys
    // =================================================

    $row_sql = "
        INSERT INTO quotation_rows
        (
            quotation_id,
            row_data,
            sort_order
        )
        VALUES
        (
            ?, ?, ?
        )
    ";

    $row_stmt =
        mysqli_prepare(
            $conn,
            $row_sql
        );

    if (!$row_stmt) {
        throw new Exception(
            "Row Prepare Error: " .
            mysqli_error($conn)
        );
    }


    foreach (
        $clean_rows
        as $row_index => $row
    ) {

        $json_row = [];

        foreach (
            $saved_column_keys
            as $column_index =>
                $column_key
        ) {

            $json_row[
                $column_key
            ] =
                $row[
                    $column_index
                ] ?? '';
        }


        $row_json =
            json_encode(
                $json_row,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            );

        if ($row_json === false) {
            throw new Exception(
                "Unable to encode quotation row."
            );
        }


        $sort_order =
            $row_index + 1;


        mysqli_stmt_bind_param(
            $row_stmt,
            "isi",
            $quotation_id,
            $row_json,
            $sort_order
        );


        if (
            !mysqli_stmt_execute(
                $row_stmt
            )
        ) {
            throw new Exception(
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
            INSERT INTO
                quotation_commercial_terms
            (
                quotation_id,
                particular,
                term_value,
                sort_order,
                is_visible
            )
            VALUES
            (
                ?, ?, ?, ?, 1
            )
        ";

        $commercial_stmt =
            mysqli_prepare(
                $conn,
                $commercial_sql
            );

        if (!$commercial_stmt) {
            throw new Exception(
                "Commercial Terms Prepare Error: " .
                mysqli_error($conn)
            );
        }


        foreach (
            $commercial_terms
            as $index => $term
        ) {

            $particular =
                $term['particular'];

            $value =
                $term['value'];

            $sort_order =
                $index + 1;


            mysqli_stmt_bind_param(
                $commercial_stmt,
                "issi",
                $quotation_id,
                $particular,
                $value,
                $sort_order
            );


            if (
                !mysqli_stmt_execute(
                    $commercial_stmt
                )
            ) {
                throw new Exception(
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
            INSERT INTO
                quotation_general_terms
            (
                quotation_id,
                condition_text,
                sort_order,
                is_visible
            )
            VALUES
            (
                ?, ?, ?, 1
            )
        ";

        $general_stmt =
            mysqli_prepare(
                $conn,
                $general_sql
            );

        if (!$general_stmt) {
            throw new Exception(
                "General Terms Prepare Error: " .
                mysqli_error($conn)
            );
        }


        foreach (
            $general_terms
            as $index => $condition
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

    mysqli_commit($conn);


    header(
        "Location: view.php?id=" .
        $quotation_id .
        "&msg=updated"
    );

    exit;


} catch (Throwable $e) {


    // =================================================
    // ROLLBACK
    // =================================================

    mysqli_rollback($conn);


    // Log technical details privately
    error_log(
        "Quotation update failed for ID " .
        intval($quotation_id) .
        ": " .
        $e->getMessage()
    );


    // Show only a safe message to the user
    $_SESSION[
        'quotation_error'
    ] =
        "Quotation update failed. Please try again.";


    header(
        "Location: edit.php?id=" .
        intval($quotation_id)
    );

    exit;
}
?>
