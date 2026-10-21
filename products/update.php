<?php

// =====================================================
// SESSION
// =====================================================

session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}


// =====================================================
// DATABASE
// =====================================================

require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . '/../includes/permissions.php';
erpRequireCompanyUser($conn);
erpRequire('products.edit');
$companyId = erpCurrentCompanyId();


// =====================================================
// CHECK REQUEST
// =====================================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit;
}
$csrfToken = (string)($_POST['csrf_token'] ?? '');
if (empty($_SESSION['csrf_token']) || $csrfToken === '' || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    http_response_code(400);
    exit('Invalid request token.');
}


// =====================================================
// PRODUCT ID
// =====================================================

$id = intval($_POST['id'] ?? 0);

if ($id <= 0) {
    header("Location: index.php?msg=invalid_id");
    exit;
}


// =====================================================
// GET EXISTING PRODUCT
// =====================================================

$get_stmt = mysqli_prepare(
    $conn,
    "
    SELECT
        id,
        drawing_file,
        product_image
    FROM products
    WHERE id = ?
      AND company_id = ?
    LIMIT 1
    "
);

if (!$get_stmt) {

    error_log(
        "Product get prepare error: " .
        mysqli_error($conn)
    );

    header("Location: index.php?msg=db_error");
    exit;
}


mysqli_stmt_bind_param(
    $get_stmt,
    "ii",
    $id,
    $companyId
);

mysqli_stmt_execute($get_stmt);

$get_result =
    mysqli_stmt_get_result(
        $get_stmt
    );

$old =
    mysqli_fetch_assoc(
        $get_result
    );

mysqli_stmt_close(
    $get_stmt
);


if (!$old) {
    header("Location: index.php?msg=not_found");
    exit;
}


// =====================================================
// FORM DATA
// =====================================================

$product_code =
    trim($_POST['product_code'] ?? '');

$product_name =
    trim($_POST['product_name'] ?? '');

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

    header(
        "Location: edit.php?id=" .
        $id .
        "&msg=required"
    );

    exit;
}


if ($product_name === '') {

    header(
        "Location: edit.php?id=" .
        $id .
        "&msg=name_required"
    );

    exit;
}


// =====================================================
// REVISION DEFAULT
// =====================================================

if ($revision_no === '') {
    $revision_no = '00';
}


// =====================================================
// PRICING TYPE
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
// UNIT / WEIGHT
// =====================================================

if ($pricing_type === 'KG') {

    $unit = 'KG';

    if ($weight_per_piece <= 0) {

        header(
            "Location: edit.php?id=" .
            $id .
            "&msg=weight_required"
        );

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

$duplicate_stmt =
    mysqli_prepare(
        $conn,
        "
        SELECT id
        FROM products
        WHERE product_code = ?
        AND id != ?
        LIMIT 1
        "
    );


if (!$duplicate_stmt) {

    error_log(
        "Duplicate product check error: " .
        mysqli_error($conn)
    );

    header(
        "Location: edit.php?id=" .
        $id .
        "&msg=db_error"
    );

    exit;
}


mysqli_stmt_bind_param(
    $duplicate_stmt,
    "si",
    $product_code,
    $id
);


mysqli_stmt_execute(
    $duplicate_stmt
);


$duplicate_result =
    mysqli_stmt_get_result(
        $duplicate_stmt
    );


if (
    $duplicate_result &&
    mysqli_num_rows(
        $duplicate_result
    ) > 0
) {

    mysqli_stmt_close(
        $duplicate_stmt
    );

    header(
        "Location: edit.php?id=" .
        $id .
        "&msg=duplicate_code&code=" .
        urlencode($product_code)
    );

    exit;
}


mysqli_stmt_close(
    $duplicate_stmt
);


// =====================================================
// EXISTING FILES
// =====================================================

$old_drawing_file =
    $old['drawing_file'] ?? '';

$old_product_image =
    $old['product_image'] ?? '';

$drawing_file =
    $old_drawing_file;

$product_image =
    $old_product_image;


// =====================================================
// DIRECTORIES
// =====================================================

$drawing_dir =
    __DIR__ .
    "/../uploads/drawings/";

$product_dir =
    __DIR__ .
    "/../uploads/products/";


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
            "Unable to create drawings directory."
        );

        header(
            "Location: edit.php?id=" .
            $id .
            "&msg=upload_error"
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
            "Unable to create products directory."
        );

        header(
            "Location: edit.php?id=" .
            $id .
            "&msg=upload_error"
        );

        exit;
    }
}


// =====================================================
// SECURE FILE UPLOAD FUNCTION
// =====================================================

function secureProductUpload(
    $file,
    $directory,
    $allowed_extensions,
    $allowed_mime_types,
    $max_size
) {

    // No new file
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


    // Real HTTP upload
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


    // File size
    $file_size =
        intval(
            $file['size'] ?? 0
        );

    if (
        $file_size <= 0 ||
        $file_size > $max_size
    ) {
        throw new RuntimeException(
            "Invalid file size."
        );
    }


    // Extension
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
            "Server MIME validation unavailable."
        );
    }


    $finfo =
        finfo_open(
            FILEINFO_MIME_TYPE
        );


    if (!$finfo) {

        throw new RuntimeException(
            "Unable to validate MIME type."
        );
    }


    $mime_type =
        finfo_file(
            $finfo,
            $file['tmp_name']
        );


    finfo_close(
        $finfo
    );


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


    // Extra image validation
    if (
        in_array(
            $extension,
            [
                'jpg',
                'jpeg',
                'png',
                'webp'
            ],
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


    // Secure random filename
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


    // Move file
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


    // Secure permission
    @chmod(
        $destination,
        0644
    );


    return $new_name;
}


// =====================================================
// TRACK NEW FILES
// =====================================================

$new_drawing_file = '';
$new_product_image = '';


// =====================================================
// DRAWING UPLOAD
// =====================================================

try {

    $new_drawing_file =
        secureProductUpload(
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

                // DWG
                'application/acad',
                'application/x-acad',
                'application/autocad_dwg',
                'application/dwg',
                'image/vnd.dwg',
                'application/octet-stream',

                // DXF
                'application/dxf',
                'image/vnd.dxf',
                'text/plain'
            ],
            10 * 1024 * 1024
        );


    if ($new_drawing_file !== '') {

        $drawing_file =
            $new_drawing_file;
    }

} catch (RuntimeException $e) {

    error_log(
        "Drawing update upload error: " .
        $e->getMessage()
    );

    header(
        "Location: edit.php?id=" .
        $id .
        "&msg=invalid_drawing"
    );

    exit;
}


// =====================================================
// PRODUCT IMAGE UPLOAD
// =====================================================

try {

    $new_product_image =
        secureProductUpload(
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


    if ($new_product_image !== '') {

        $product_image =
            $new_product_image;
    }

} catch (RuntimeException $e) {


    // Remove new drawing if image upload failed
    if ($new_drawing_file !== '') {

        $new_drawing_path =
            $drawing_dir .
            basename(
                $new_drawing_file
            );


        if (
            is_file(
                $new_drawing_path
            )
        ) {
            @unlink(
                $new_drawing_path
            );
        }
    }


    error_log(
        "Product image update error: " .
        $e->getMessage()
    );


    header(
        "Location: edit.php?id=" .
        $id .
        "&msg=invalid_image"
    );

    exit;
}


// =====================================================
// UPDATE PRODUCT
// =====================================================

$update_stmt =
    mysqli_prepare(
        $conn,
        "
        UPDATE products

        SET
            product_code = ?,
            product_name = ?,
            part_number = ?,
            drawing_number = ?,
            revision_no = ?,
            material = ?,
            pricing_type = ?,
            unit = ?,
            weight_per_piece = ?,
            quantity = ?,
            priority = ?,
            status = ?,
            drawing_file = ?,
            product_image = ?,
            remarks = ?,
            updated_at = NOW()

        WHERE id = ?
          AND company_id = ?

        LIMIT 1
        "
    );


if (!$update_stmt) {

    error_log(
        "Product update prepare error: " .
        mysqli_error($conn)
    );


    // Delete newly uploaded files
    if ($new_drawing_file !== '') {

        $path =
            $drawing_dir .
            basename(
                $new_drawing_file
            );

        if (is_file($path)) {
            @unlink($path);
        }
    }


    if ($new_product_image !== '') {

        $path =
            $product_dir .
            basename(
                $new_product_image
            );

        if (is_file($path)) {
            @unlink($path);
        }
    }


    header(
        "Location: edit.php?id=" .
        $id .
        "&msg=db_error"
    );

    exit;
}


// =====================================================
// BIND PARAMETERS
// =====================================================

mysqli_stmt_bind_param(
    $update_stmt,
    "ssssssssddsssssii",

    $product_code,
    $product_name,
    $part_number,
    $drawing_number,
    $revision_no,
    $material,
    $pricing_type,
    $unit,
    $weight_per_piece,
    $quantity,
    $priority,
    $status,
    $drawing_file,
    $product_image,
    $remarks,
    $id,
    $companyId
);


// =====================================================
// EXECUTE UPDATE
// =====================================================

if (
    !mysqli_stmt_execute(
        $update_stmt
    )
) {

    error_log(
        "Product update failed: " .
        mysqli_stmt_error(
            $update_stmt
        )
    );


    mysqli_stmt_close(
        $update_stmt
    );


    // Database update failed:
    // remove only NEW uploaded files.

    if ($new_drawing_file !== '') {

        $path =
            $drawing_dir .
            basename(
                $new_drawing_file
            );

        if (is_file($path)) {
            @unlink($path);
        }
    }


    if ($new_product_image !== '') {

        $path =
            $product_dir .
            basename(
                $new_product_image
            );

        if (is_file($path)) {
            @unlink($path);
        }
    }


    header(
        "Location: edit.php?id=" .
        $id .
        "&msg=db_error"
    );

    exit;
}


mysqli_stmt_close(
    $update_stmt
);


// =====================================================
// DATABASE UPDATED SUCCESSFULLY
// NOW DELETE OLD FILES
// =====================================================

// Delete old drawing only after successful DB update

if (
    $new_drawing_file !== '' &&
    $old_drawing_file !== '' &&
    $old_drawing_file !== $drawing_file
) {

    $old_path =
        $drawing_dir .
        basename(
            $old_drawing_file
        );


    if (
        is_file(
            $old_path
        )
    ) {

        @unlink(
            $old_path
        );
    }
}


// Delete old product image

if (
    $new_product_image !== '' &&
    $old_product_image !== '' &&
    $old_product_image !== $product_image
) {

    $old_path =
        $product_dir .
        basename(
            $old_product_image
        );


    if (
        is_file(
            $old_path
        )
    ) {

        @unlink(
            $old_path
        );
    }
}


// =====================================================
// SUCCESS
// =====================================================

header(
    "Location: index.php?msg=updated"
);

exit;

?>