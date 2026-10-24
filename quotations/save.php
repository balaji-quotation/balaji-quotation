<?php

session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

include("../includes/db.php");
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/product_master.php';
require_once __DIR__ . '/../includes/quotation_template_runtime.php';
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
        'textarea',
        'auto_amount'
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
    max(0, intval($_POST['client_id'] ?? 0));

$client_name = quoteClean($_POST['client_name'] ?? '');
$client_details_snapshot = json_encode([
    'company_name' => $client_name,
    'contact_person' => quoteClean($_POST['client_contact_person'] ?? ''),
    'gstin' => quoteClean($_POST['client_gstin'] ?? ''),
    'mobile' => quoteClean($_POST['client_mobile'] ?? ''),    'email' => quoteClean($_POST['client_email'] ?? ''),
    'address' => quoteClean($_POST['client_address'] ?? '')
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($client_details_snapshot)) {
    throw new RuntimeException('Customer information could not be encoded.');
}



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

if ($client_name === '' || mb_strlen($client_name, 'UTF-8') > 150) {
    quoteFail(
        "Type the customer or company name (maximum 150 characters)."
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
// VALIDATE OPTIONAL CUSTOMER MASTER LINK
// =====================================================

if ($client_id > 0) {
    $client_stmt = mysqli_prepare(
        $conn,
        "SELECT company_name FROM clients WHERE id = ? AND company_id = ? LIMIT 1"
    );
    if (!$client_stmt) {
        error_log("Quotation client check prepare failed: " . mysqli_error($conn));
        quoteFail("Unable to validate selected customer.");
    }
    mysqli_stmt_bind_param($client_stmt, "ii", $client_id, $companyId);
    mysqli_stmt_execute($client_stmt);
    $client_result = mysqli_stmt_get_result($client_stmt);
    $client_exists = mysqli_fetch_assoc($client_result);
    mysqli_stmt_close($client_stmt);
    if (!$client_exists) {
        quoteFail("Selected Customer Master record was not found.");
    }
    $client_name = quoteClean($client_exists['company_name']);
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
     WHERE u.id = ? AND u.company_id = ? AND t.company_id = ? AND u.status = 'Active' AND t.is_active = 1
     LIMIT 1"
);
mysqli_stmt_bind_param($templateStmt, "iii", $userId, $companyId, $companyId);
mysqli_stmt_execute($templateStmt);
$templateRow = mysqli_stmt_get_result($templateStmt);
$templateRow = $templateRow ? mysqli_fetch_assoc($templateRow) : null;
mysqli_stmt_close($templateStmt);
if (!$templateRow) {
    $_SESSION['quotation_error'] = "No active quotation template is assigned to this user. Contact your administrator.";
    header("Location: add.php");
    exit;
}
$templateVersionId = (int)$templateRow['id'];
$templateSnapshot = (string)$templateRow['config_json'];
if (!is_array(json_decode($templateSnapshot, true))) {
    $_SESSION['quotation_error'] = "Assigned quotation template is invalid. Contact your administrator.";
    header("Location: add.php");
    exit;
}


// =====================================================

// Columns always come from the assigned version on the server.
// Posted column metadata is ignored so client input cannot alter an approved template.
$templateConfig = json_decode($templateSnapshot, true);
if (!is_array($templateConfig) || empty($templateConfig['columns']) || !is_array($templateConfig['columns'])) {
    $_SESSION['quotation_error'] = "Assigned quotation template has no valid columns.";
    header("Location: add.php");
    exit;
}

$columns = [];
foreach ($templateConfig['columns'] as $index => $templateColumn) {
    if (!is_array($templateColumn)) continue;
    $label = quoteClean($templateColumn['label'] ?? '');
    if ($label === '') continue;
    $type = quoteAllowedColumnType($templateColumn['type'] ?? 'text');
    $align = quoteAllowedAlign($templateColumn['align'] ?? 'left');
    $columns[] = [
        'source_index' => (int)$index,
        'column_key' => 'col_' . (count($columns) + 1),
        'column_label' => $label,
        'unit_text' => quoteClean($templateColumn['unit'] ?? ''),
        'data_type' => $type,
        'field_role' => (string)($templateColumn['field_role'] ?? 'none'),
        'text_align' => $align
    ];
}
if (!$columns) {
    $_SESSION['quotation_error'] = "Assigned quotation template has no usable columns.";
    header("Location: add.php");
    exit;
}

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


$posted_product_ids = $_POST['product_id'] ?? [];
if (!is_array($posted_product_ids)) {
    $posted_product_ids = [];
}

$posted_save_to_product = $_POST['save_to_product'] ?? [];
if (!is_array($posted_save_to_product)) {
    $posted_save_to_product = [];
}

$productCheckStmt = $conn->prepare(
    'SELECT id FROM products WHERE id = ? AND company_id = ? LIMIT 1'
);
if (!$productCheckStmt) {
    quoteFail('Product selection could not be verified.');
}

$rows = [];

foreach (
    $posted_rows
    as $postedIndex => $posted_row
) {

    if (
        !is_array(
            $posted_row
        )
    ) {
        continue;
    }


    $saveToProduct = (string)($posted_save_to_product[$postedIndex] ?? '') === '1';
    if ($saveToProduct && !erpCan('quotations.save_products_to_master')) {
        $productCheckStmt->close();
        quoteFail('Your account cannot save quotation items to Product Master.');
    }

    $rawProductId = trim((string)($posted_product_ids[$postedIndex] ?? ''));
    $productId = 0;
    if ($rawProductId !== '') {
        $validatedProductId = filter_var($rawProductId, FILTER_VALIDATE_INT);
        if ($validatedProductId === false || $validatedProductId < 1) {
            $productCheckStmt->close();
            quoteFail('Invalid product selection.');
        }
        $productId = (int)$validatedProductId;
        if ($saveToProduct) {
            $productCheckStmt->close();
            quoteFail('Choose Manual entry before saving a quotation item to Product Master.');
        }
        $productCheckStmt->bind_param('ii', $productId, $companyId);
        $productCheckStmt->execute();
        if ($productCheckStmt->get_result()->num_rows !== 1) {
            $productCheckStmt->close();
            quoteFail('Selected product does not belong to your company.');
        }
    }

    $row_json_data = [];

    $has_value = $productId > 0;


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


    // Recompute server-side automatic line amount; do not trust posted calculated cells.
    foreach ($columns as $column) {
        if (($column['field_role'] ?? '') === 'auto_amount' || ($column['data_type'] ?? '') === 'auto_amount') {
            $row_json_data[$column['column_key']] = '';
        }
    }
    $calculation = is_array($templateConfig['calculation'] ?? null) ? $templateConfig['calculation'] : ['mode'=>'standard','formula'=>''];
    $computedAmount = quotationTemplateRowAmount($row_json_data, $columns, $calculation);
    if ($computedAmount !== null && is_finite($computedAmount)) {
        $rounding = is_array($templateConfig['rounding'] ?? null) ? $templateConfig['rounding'] : [];
        $places = max(0, min(4, (int)($rounding['decimal_places'] ?? 2)));
        $roundMode = (string)($rounding['mode'] ?? 'half_up');
        $formattedAmount = number_format(quotationTemplateRound($computedAmount, $places, $roundMode), $places, '.', '');
        foreach ($columns as $column) {
            if (($column['field_role'] ?? '') === 'auto_amount' || ($column['data_type'] ?? '') === 'auto_amount') {
                $row_json_data[$column['column_key']] = $formattedAmount;
            }
        }
    }

    // Ignore completely blank rows.

    if (!$has_value) {
        continue;
    }


    $catalogName = '';
    $catalogDescription = '';
    $catalogRate = '';
    $catalogPartNumber = '';
    if ($saveToProduct) {
        $nameCandidates = [
            'productname' => 1,
            'itemname' => 2,
            'productdescription' => 3,
            'itemdescription' => 4,
            'description' => 5,
            'item' => 6,
            'product' => 7,
            'material' => 8,
            'particular' => 9,
            'particulars' => 10,
            'details' => 11
        ];
        $bestNamePriority = PHP_INT_MAX;

        foreach ($columns as $column) {
            $labelKey = strtolower((string)preg_replace('/[^a-z0-9]+/i', '', $column['column_label']));
            $value = quoteClean($row_json_data[$column['column_key']] ?? '');
            if ($value === '') {
                continue;
            }

            if (isset($nameCandidates[$labelKey]) && $nameCandidates[$labelKey] < $bestNamePriority) {
                $catalogName = $value;
                $bestNamePriority = $nameCandidates[$labelKey];
            }
            if ($catalogDescription === '' && in_array($labelKey, ['description', 'productdescription', 'itemdescription', 'details'], true)) {
                $catalogDescription = $value;
            }
            if ($catalogRate === '' && in_array($labelKey, ['rate', 'price', 'unitrate', 'unitprice', 'rateperunit', 'priceperunit'], true)) {
                $catalogRate = $value;
            }
            if ($catalogPartNumber === '' && in_array($labelKey, ['partnumber', 'partno', 'part'], true)) {
                $catalogPartNumber = $value;
            }
        }

        if ($catalogName === '') {
            $productCheckStmt->close();
            quoteFail('Add a value in the Product Name or Description column before saving this item to Product Master.');
        }
        if ($catalogDescription === '') {
            $catalogDescription = $catalogName;
        }
        if ($catalogRate !== '' && (!is_numeric($catalogRate) || !is_finite((float)$catalogRate) || (float)$catalogRate < 0)) {
            $productCheckStmt->close();
            quoteFail('Product Master rate must be a valid non-negative number.');
        }
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
        'product_id' =>
            $productId > 0 ? $productId : null,
        'item_reference' =>
            $item_reference,
        'save_to_product' =>
            $saveToProduct,
        'catalog_name' =>
            $catalogName,
        'catalog_description' =>
            $catalogDescription,
        'catalog_rate' =>
            $catalogRate,
        'catalog_part_number' =>
            $catalogPartNumber,
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
// DATABASE TRANSACTION
// =====================================================

mysqli_begin_transaction(
    $conn
);


try {

    // Serialize number allocation within this company. The database unique
    // index remains the final guard against duplicate numbers.
    $company_lock_stmt = mysqli_prepare(
        $conn,
        "SELECT id FROM companies WHERE id = ? FOR UPDATE"
    );
    if (!$company_lock_stmt) {
        throw new Exception("Could not lock the company quotation sequence.");
    }
    mysqli_stmt_bind_param($company_lock_stmt, "i", $companyId);
    if (!mysqli_stmt_execute($company_lock_stmt)) {
        mysqli_stmt_close($company_lock_stmt);
        throw new Exception("Could not lock the company quotation sequence.");
    }
    mysqli_stmt_store_result($company_lock_stmt);
    $company_exists = mysqli_stmt_num_rows($company_lock_stmt) === 1;
    mysqli_stmt_close($company_lock_stmt);
    if (!$company_exists) {
        throw new Exception("The company account is no longer available.");
    }

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
            client_name_override,
            client_details_snapshot,
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
            NULL,
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
            "Quotation master prepare failed: " .
            mysqli_error($conn)
        );
    }


    mysqli_stmt_bind_param(
        $master_stmt,
        "sissssssssssiisi",
        $quotation_no,
        $client_id,
        $client_name,
        $client_details_snapshot,
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
            ($column['data_type'] ?? '') === 'auto_amount'
                ? 'number'
                : $column['data_type'];

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
            ?,
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


    $productInsertStmt = null;
    foreach ($rows as $row) {
        if (!empty($row['save_to_product'])) {
            $hasProductDescription = productMasterColumnExists($conn, 'description');
            $productInsertSql = $hasProductDescription
                ? "INSERT INTO products
                   (company_id, product_code, product_name, default_rate, part_number, drawing_number, revision_no, pricing_type, unit, weight_per_piece, material, quantity, priority, status, drawing_file, product_image, remarks, description)
                   VALUES (?, ?, ?, ?, ?, '', '00', 'PCS', 'PCS', 0, '', 0, 'Low', 'Received', '', '', ?, ?)"
                : "INSERT INTO products
                   (company_id, product_code, product_name, default_rate, part_number, drawing_number, revision_no, pricing_type, unit, weight_per_piece, material, quantity, priority, status, drawing_file, product_image, remarks)
                   VALUES (?, ?, ?, ?, ?, '', '00', 'PCS', 'PCS', 0, '', 0, 'Low', 'Received', '', '', ?)";
            $productInsertStmt = mysqli_prepare($conn, $productInsertSql);
            if (!$productInsertStmt) {
                throw new Exception("Product Master insert prepare failed: " . mysqli_error($conn));
            }
            break;
        }
    }

    $savedProductCount = 0;
    foreach (
        $rows
        as $index =>
           $row
    ) {

        $row_no =
            $index + 1;

        $sort_order =
            $row_no;

        $product_id =
            $row['product_id'];

        if (!empty($row['save_to_product'])) {
            $product_code = 'QTN-' . $companyId . '-' . bin2hex(random_bytes(8));
            $product_name = $row['catalog_name'];
            $default_rate = $row['catalog_rate'];
            $part_number = $row['catalog_part_number'];
            $product_remarks = 'Saved from quotation ' . $quotation_no;

            $default_rate = $default_rate === '' ? 0.0 : (float)$default_rate;
            if ($hasProductDescription) {
                $product_description = $row['catalog_description'];
                mysqli_stmt_bind_param(
                    $productInsertStmt,
                    "issdsss",
                    $companyId,
                    $product_code,
                    $product_name,
                    $default_rate,
                    $part_number,
                    $product_remarks,
                    $product_description
                );
            } else {
                mysqli_stmt_bind_param(
                    $productInsertStmt,
                    "issdss",
                    $companyId,
                    $product_code,
                    $product_name,
                    $default_rate,
                    $part_number,
                    $product_remarks
                );
            }

            if (!mysqli_stmt_execute($productInsertStmt)) {
                throw new Exception("Product Master save failed: " . mysqli_stmt_error($productInsertStmt));
            }
            $product_id = (int)mysqli_insert_id($conn);
            $savedProductCount++;
        }

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
            "iiissi",
            $quotation_id,
            $row_no,
            $product_id,
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
    if ($productInsertStmt instanceof mysqli_stmt) {
        mysqli_stmt_close($productInsertStmt);
    }
    $productCheckStmt->close();


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


    $_SESSION['quotation_success'] = $savedProductCount > 0
        ? "Quotation saved successfully. {$savedProductCount} item(s) added to Product Master."
        : "Quotation saved successfully.";


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
        (str_contains(strtolower($e->getMessage()), 'duplicate')
            || str_contains(strtolower($e->getMessage()), 'already exists'))
            ? "This quotation number is already in use. Reload the form to get the next number, then try again."
            : "Quotation could not be saved. Please try again.";


    header(
        "Location: add.php"
    );

    exit;
}
?>
