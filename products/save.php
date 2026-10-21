<?php

session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . '/../includes/permissions.php';
erpRequireCompanyUser($conn);
erpRequire('products.add');
$companyId = erpCurrentCompanyId();


// =====================================================
// CHECK REQUEST
// =====================================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: add.php");
    exit;
}
$csrfToken = (string)($_POST['csrf_token'] ?? '');
if (empty($_SESSION['csrf_token']) || $csrfToken === '' || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    http_response_code(400);
    exit('Invalid request token.');
}


// =====================================================
// FORM DATA
// =====================================================

$product_code =
    trim($_POST['product_code'] ?? '');

$product_name =
    trim($_POST['product_name'] ?? '');

$default_rate =
    trim((string)($_POST['default_rate'] ?? ''));

$part_number =
    trim($_POST['part_number'] ?? '');

$drawing_number =
    trim($_POST['drawing_number'] ?? '');

$revision_no =
    trim($_POST['revision_no'] ?? '00');

$material =
    trim($_POST['material'] ?? '');

$pricing_type =
    strtoupper(
        trim(
            $_POST['pricing_type'] ?? 'PCS'
        )
    );

$unit =
    strtoupper(
        trim(
            $_POST['unit'] ?? 'PCS'
        )
    );

$weight_per_piece =
    floatval(
        $_POST['weight_per_piece'] ?? 0
    );

$quantity =
    floatval(
        $_POST['quantity'] ?? 0
    );

$priority =
    trim(
        $_POST['priority'] ?? 'Low'
    );

$status =
    trim(
        $_POST['status'] ?? 'Received'
    );

$remarks =
    trim(
        $_POST['remarks'] ?? ''
    );


// =====================================================
// BASIC VALIDATION
// =====================================================

if ($product_code === '') {
    header("Location: add.php?msg=required");
    exit;
}

if ($product_name === '') {
    header("Location: add.php?msg=name_required");
    exit;
}
if ($default_rate !== '' && (!is_numeric($default_rate) || !is_finite((float)$default_rate) || (float)$default_rate < 0)) {
    header("Location: add.php?msg=invalid_rate");
    exit;
}


// =====================================================
// REVISION DEFAULT
// =====================================================

if ($revision_no === '') {
    $revision_no = '00';
}


// =====================================================
// PRICING TYPE VALIDATION
// =====================================================

if (
    !in_array(
        $pricing_type,
        ['PCS', 'KG'],
        true
    )
) {
    $pricing_type = 'PCS';
}


// =====================================================
// UNIT / WEIGHT LOGIC
// =====================================================

if ($pricing_type === 'KG') {

    $unit = 'KG';

    if ($weight_per_piece <= 0) {
        header("Location: add.php?msg=weight_required");
        exit;
    }

} else {

    $pricing_type = 'PCS';
    $unit = 'PCS';
    $weight_per_piece = 0;
}


// =====================================================
// STATUS VALIDATION
// =====================================================

$allowed_status = [
    'Received',
    'In Production',
    'Completed',
    'Dispatched',
    'Hold'
];

if (
    !in_array(
        $status,
        $allowed_status,
        true
    )
) {
    $status = 'Received';
}


// =====================================================
// PRIORITY VALIDATION
// =====================================================

$allowed_priority = [
    'Low',
    'Medium',
    'High',
    'Urgent'
];

if (
    !in_array(
        $priority,
        $allowed_priority,
        true
    )
) {
    $priority = 'Low';
}


// =====================================================
// DUPLICATE PRODUCT CODE CHECK
// =====================================================

$check_stmt = mysqli_prepare(
    $conn,
    "
    SELECT id
    FROM products
    WHERE product_code = ?
      AND company_id = ?
    LIMIT 1
    "
);

if (!$check_stmt) {

    error_log(
        "Product duplicate check prepare failed: " .
        mysqli_error($conn)
    );

    header("Location: add.php?msg=db_error");
    exit;
}

mysqli_stmt_bind_param(
    $check_stmt,
    "si",
    $product_code,
    $companyId
);

mysqli_stmt_execute(
    $check_stmt
);

$check_result =
    mysqli_stmt_get_result(
        $check_stmt
    );

if (
    $check_result &&
    mysqli_num_rows($check_result) > 0
) {

    mysqli_stmt_close($check_stmt);

    header(
        "Location: add.php?msg=duplicate_code&code=" .
        urlencode($product_code)
    );

    exit;
}

mysqli_stmt_close($check_stmt);


// =====================================================
// UPLOAD DIRECTORIES
// =====================================================

$drawing_dir =
    __DIR__ . "/../uploads/drawings/";

$product_dir =
    __DIR__ . "/../uploads/products/";


// =====================================================
// CREATE DIRECTORIES
// =====================================================

if (!is_dir($drawing_dir)) {

    if (
        !mkdir(
            $drawing_dir,
            0755,
            true
        ) &&
        !is_dir($drawing_dir)
    ) {

        error_log(
            "Unable to create drawing upload directory."
        );

        header(
            "Location: add.php?msg=upload_error"
        );

        exit;
    }
}


if (!is_dir($product_dir)) {

    if (
        !mkdir(
            $product_dir,
            0755,
            true
        ) &&
        !is_dir($product_dir)
    ) {

        error_log(
            "Unable to create product upload directory."
        );

        header(
            "Location: add.php?msg=upload_error"
        );

        exit;
    }
}


// =====================================================
// SECURE FILE UPLOAD FUNCTION
// =====================================================

function uploadProductFile(
    $file,
    $directory,
    $allowed_extensions,
    $allowed_mime_types,
    $max_size
) {

    // No file selected
    if (
        !isset($file) ||
        !is_array($file) ||
        empty($file['name'])
    ) {
        return '';
    }


    // Upload error
    if (
        !isset($file['error']) ||
        $file['error'] !== UPLOAD_ERR_OK
    ) {
        throw new RuntimeException(
            "File upload error."
        );
    }


    // Real HTTP upload check
    if (
        empty($file['tmp_name']) ||
        !is_uploaded_file(
            $file['tmp_name']
        )
    ) {
        throw new RuntimeException(
            "Invalid upload source."
        );
    }


    // Size validation
    $file_size =
        intval(
            $file['size'] ?? 0
        );

    if (
        $file_size <= 0 ||
        $file_size > $max_size
    ) {
        throw new RuntimeException(
            "Uploaded file size is invalid."
        );
    }


    // Extension validation
    $original_name =
        basename(
            $file['name']
        );

    $extension =
        strtolower(
            pathinfo(
                $original_name,
                PATHINFO_EXTENSION
            )
        );

    if (
        !in_array(
            $extension,
            $allowed_extensions,
            true
        )
    ) {
        throw new RuntimeException(
            "Invalid file extension."
        );
    }


    // MIME validation
    if (!function_exists('finfo_open')) {
        throw new RuntimeException(
            "Server MIME validation is unavailable."
        );
    }

    $finfo =
        finfo_open(
            FILEINFO_MIME_TYPE
        );

    if (!$finfo) {
        throw new RuntimeException(
            "Unable to validate file type."
        );
    }

    $mime_type =
        finfo_file(
            $finfo,
            $file['tmp_name']
        );

    finfo_close($finfo);


    if (
        !in_array(
            $mime_type,
            $allowed_mime_types,
            true
        )
    ) {
        throw new RuntimeException(
            "Invalid file content/type."
        );
    }


    // Image validation
    if (
        in_array(
            $extension,
            ['jpg', 'jpeg', 'png', 'webp'],
            true
        )
    ) {

        if (
            @getimagesize(
                $file['tmp_name']
            ) === false
        ) {
            throw new RuntimeException(
                "Invalid image file."
            );
        }
    }


    // Generate secure random filename
    try {

        $random_name =
            bin2hex(
                random_bytes(16)
            );

    } catch (Throwable $e) {

        $random_name =
            str_replace(
                '.',
                '',
                uniqid('', true)
            );
    }


    $new_name =
        $random_name .
        '.' .
        $extension;


    $destination =
        rtrim(
            $directory,
            '/\\'
        ) .
        DIRECTORY_SEPARATOR .
        $new_name;


    // Move uploaded file
    if (
        !move_uploaded_file(
            $file['tmp_name'],
            $destination
        )
    ) {
        throw new RuntimeException(
            "File upload failed."
        );
    }


    // Secure file permission
    @chmod(
        $destination,
        0644
    );


    return $new_name;
}


// =====================================================
// FILE VARIABLES
// =====================================================

$drawing_file = "";
$product_image = "";


// =====================================================
// DRAWING FILE
// =====================================================

try {

    $drawing_file =
        uploadProductFile(
            $_FILES['drawing_file'] ?? null,
            $drawing_dir,
            [
                'pdf',
                'jpg',
                'jpeg',
                'png',
                'dwg',
                'dxf'
            ],
            [
                'application/pdf',
                'image/jpeg',
                'image/png',

                // Common DWG MIME values
                'application/acad',
                'application/x-acad',
                'application/autocad_dwg',
                'application/dwg',
                'image/vnd.dwg',
                'application/octet-stream',

                // Common DXF MIME values
                'application/dxf',
                'image/vnd.dxf',
                'text/plain'
            ],
            10 * 1024 * 1024
        );

} catch (RuntimeException $e) {

    error_log(
        "Drawing upload error: " .
        $e->getMessage()
    );

    header(
        "Location: add.php?msg=invalid_drawing"
    );

    exit;
}


// =====================================================
// PRODUCT IMAGE
// =====================================================

try {

    $product_image =
        uploadProductFile(
            $_FILES['product_image'] ?? null,
            $product_dir,
            [
                'jpg',
                'jpeg',
                'png',
                'webp'
            ],
            [
                'image/jpeg',
                'image/png',
                'image/webp'
            ],
            5 * 1024 * 1024
        );

} catch (RuntimeException $e) {

    // If drawing was uploaded but image fails,
    // remove drawing to avoid orphan file.

    if ($drawing_file !== '') {

        $drawing_path =
            $drawing_dir .
            basename(
                $drawing_file
            );

        if (
            is_file(
                $drawing_path
            )
        ) {
            @unlink(
                $drawing_path
            );
        }
    }


    error_log(
        "Product image upload error: " .
        $e->getMessage()
    );

    header(
        "Location: add.php?msg=invalid_image"
    );

    exit;
}


// =====================================================
// INSERT PRODUCT
// =====================================================

$insert_stmt =
    mysqli_prepare(
        $conn,
        "
        INSERT INTO products
        (
            company_id,
            product_code,
            product_name,
            default_rate,
            part_number,
            drawing_number,
            revision_no,
            pricing_type,
            unit,
            weight_per_piece,
            material,
            quantity,
            priority,
            status,
            drawing_file,
            product_image,
            remarks
        )
        VALUES
        (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
        )
        "
    );


if (!$insert_stmt) {

    error_log(
        "Product insert prepare failed: " .
        mysqli_error($conn)
    );


    // Delete newly uploaded files
    if ($drawing_file !== '') {

        $drawing_path =
            $drawing_dir .
            basename(
                $drawing_file
            );

        if (is_file($drawing_path)) {
            @unlink($drawing_path);
        }
    }


    if ($product_image !== '') {

        $image_path =
            $product_dir .
            basename(
                $product_image
            );

        if (is_file($image_path)) {
            @unlink($image_path);
        }
    }


    header(
        "Location: add.php?msg=db_error"
    );

    exit;
}


// =====================================================
// BIND PARAMETERS
// =====================================================

mysqli_stmt_bind_param(
    $insert_stmt,
    "issssssssdsdsssss",
    $companyId,
    $product_code,
    $product_name,
    $default_rate,
    $part_number,
    $drawing_number,
    $revision_no,
    $pricing_type,
    $unit,
    $weight_per_piece,
    $material,
    $quantity,
    $priority,
    $status,
    $drawing_file,
    $product_image,
    $remarks
);


// =====================================================
// EXECUTE
// =====================================================

if (
    !mysqli_stmt_execute(
        $insert_stmt
    )
) {

    error_log(
        "Product insert failed: " .
        mysqli_stmt_error(
            $insert_stmt
        )
    );


    mysqli_stmt_close(
        $insert_stmt
    );


    // Remove uploaded files because DB save failed
    if ($drawing_file !== '') {

        $drawing_path =
            $drawing_dir .
            basename(
                $drawing_file
            );

        if (is_file($drawing_path)) {
            @unlink($drawing_path);
        }
    }


    if ($product_image !== '') {

        $image_path =
            $product_dir .
            basename(
                $product_image
            );

        if (is_file($image_path)) {
            @unlink($image_path);
        }
    }


    header(
        "Location: add.php?msg=db_error"
    );

    exit;
}


mysqli_stmt_close(
    $insert_stmt
);


// =====================================================
// SUCCESS
// =====================================================

header(
    "Location: index.php?msg=added"
);

exit;
?>