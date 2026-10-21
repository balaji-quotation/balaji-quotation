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

    $_SESSION['csrf_token'] =
        bin2hex(
            random_bytes(32)
        );
}


// =====================================================
// DATABASE / LAYOUT
// =====================================================

include("../includes/db.php");
require_once __DIR__ . '/../includes/permissions.php';
erpRequireCompanyUser($conn);
erpRequire('products.view');
$companyId = erpCurrentCompanyId();
include("../includes/header.php");
include("../includes/sidebar.php");


// =====================================================
// SEARCH & FILTER VALUES
// =====================================================

$search =
    trim($_GET['search'] ?? '');

$status =
    trim($_GET['status'] ?? '');

$pricing_type =
    strtoupper(
        trim(
            $_GET['pricing_type'] ?? ''
        )
    );


// =====================================================
// PRODUCT QUERY
// =====================================================

$sql = "
    SELECT
        p.*
    FROM products p
    WHERE p.company_id = $companyId
";


// =====================================================
// SEARCH
// =====================================================

if ($search !== '') {

    $search_sql =
        mysqli_real_escape_string(
            $conn,
            $search
        );

    $sql .= "
        AND
        (
            p.product_code LIKE '%$search_sql%'
            OR p.product_name LIKE '%$search_sql%'
            OR p.part_number LIKE '%$search_sql%'
            OR p.drawing_number LIKE '%$search_sql%'
        )
    ";
}


// =====================================================
// STATUS FILTER
// =====================================================

if ($status !== '') {

    $status_sql =
        mysqli_real_escape_string(
            $conn,
            $status
        );

    $sql .= "
        AND p.status = '$status_sql'
    ";
}


// =====================================================
// PRICING TYPE FILTER
// =====================================================

if (
    $pricing_type === 'PCS' ||
    $pricing_type === 'KG'
) {

    $pricing_type_sql =
        mysqli_real_escape_string(
            $conn,
            $pricing_type
        );

    $sql .= "
        AND p.pricing_type = '$pricing_type_sql'
    ";
}


// =====================================================
// ORDER
// =====================================================

$sql .= "
    ORDER BY p.id DESC
";


// =====================================================
// EXECUTE
// =====================================================

$result =
    mysqli_query(
        $conn,
        $sql
    );


if (!$result) {

    error_log(
        "Product index query error: " .
        mysqli_error($conn)
    );

    die("Unable to load products.");
}

?>


<style>

.product-table {
    min-width: 980px;
}

.product-table th {
    white-space: nowrap;
}

.product-table td {
    vertical-align: middle;
}

.product-name {
    font-size: 13px;
    font-weight: 700;
    color: #26374a;
}

.product-code {
    font-size: 11px;
    color: #6c757d;
}

.product-drawing {
    display: block;
    font-size: 10px;
    color: #8b96a2;
    margin-top: 3px;
}

</style>


<div class="content-page">

<div class="content">

<div class="container-fluid">


<!-- =====================================================
     PAGE
===================================================== -->

<div class="row mt-3">

<div class="col-12">


<!-- =====================================================
     ALERTS
===================================================== -->


<?php
if (
    isset($_GET['msg']) &&
    $_GET['msg'] === 'linked'
) {
?>

<div
    class="alert alert-warning alert-dismissible fade show"
    role="alert"
>

    <strong>
        Cannot Delete Product!
    </strong>

    This product has

    <strong>
        <?= intval(
            $_GET['count'] ?? 0
        ); ?>
    </strong>

    linked production operation(s).

    Please remove the linked operations first.

    <button
        type="button"
        class="btn-close"
        data-bs-dismiss="alert"
    ></button>

</div>

<?php
}
?>


<?php
if (
    isset($_GET['msg']) &&
    $_GET['msg'] === 'vendor_po_linked'
) {
?>

<div
    class="alert alert-warning alert-dismissible fade show"
    role="alert"
>

    <strong>
        Cannot Delete Product!
    </strong>

    This product is linked with

    <strong>
        <?= intval(
            $_GET['count'] ?? 0
        ); ?>
    </strong>

    vendor PO item(s).

    <button
        type="button"
        class="btn-close"
        data-bs-dismiss="alert"
    ></button>

</div>

<?php
}
?>


<?php
if (
    isset($_GET['msg']) &&
    $_GET['msg'] === 'client_po_linked'
) {
?>

<div
    class="alert alert-warning alert-dismissible fade show"
    role="alert"
>

    <strong>
        Cannot Delete Product!
    </strong>

    This product is linked with

    <strong>
        <?= intval(
            $_GET['count'] ?? 0
        ); ?>
    </strong>

    client PO item(s).

    <button
        type="button"
        class="btn-close"
        data-bs-dismiss="alert"
    ></button>

</div>

<?php
}
?>


<?php
if (
    isset($_GET['msg']) &&
    $_GET['msg'] === 'deleted'
) {
?>

<div
    class="alert alert-success alert-dismissible fade show"
    role="alert"
>

    <strong>
        Success!
    </strong>

    Product deleted successfully.

    <button
        type="button"
        class="btn-close"
        data-bs-dismiss="alert"
    ></button>

</div>

<?php
}
?>


<?php
if (
    isset($_GET['msg']) &&
    $_GET['msg'] === 'notfound'
) {
?>

<div
    class="alert alert-danger alert-dismissible fade show"
    role="alert"
>

    <strong>
        Error!
    </strong>

    Product not found.

    <button
        type="button"
        class="btn-close"
        data-bs-dismiss="alert"
    ></button>

</div>

<?php
}
?>


<?php
if (
    isset($_GET['msg']) &&
    (
        $_GET['msg'] === 'invalid' ||
        $_GET['msg'] === 'invalid_request'
    )
) {
?>

<div
    class="alert alert-danger alert-dismissible fade show"
    role="alert"
>

    <strong>
        Invalid Request!
    </strong>

    The product request was not valid.

    <button
        type="button"
        class="btn-close"
        data-bs-dismiss="alert"
    ></button>

</div>

<?php
}
?>


<?php
if (
    isset($_GET['msg']) &&
    $_GET['msg'] === 'delete_failed'
) {
?>

<div
    class="alert alert-danger alert-dismissible fade show"
    role="alert"
>

    <strong>
        Delete Failed!
    </strong>

    Product could not be deleted.

    <button
        type="button"
        class="btn-close"
        data-bs-dismiss="alert"
    ></button>

</div>

<?php
}
?>


<?php
if (
    isset($_GET['msg']) &&
    $_GET['msg'] === 'db_error'
) {
?>

<div
    class="alert alert-danger alert-dismissible fade show"
    role="alert"
>

    <strong>
        Database Error!
    </strong>

    Please try again.

    <button
        type="button"
        class="btn-close"
        data-bs-dismiss="alert"
    ></button>

</div>

<?php
}
?>


<?php
if (
    isset($_GET['msg']) &&
    $_GET['msg'] === 'updated'
) {
?>

<div
    class="alert alert-success alert-dismissible fade show"
    role="alert"
>

    <strong>
        Success!
    </strong>

    Product updated successfully.

    <button
        type="button"
        class="btn-close"
        data-bs-dismiss="alert"
    ></button>

</div>

<?php
}
?>


<?php
if (
    isset($_GET['msg']) &&
    $_GET['msg'] === 'added'
) {
?>

<div
    class="alert alert-success alert-dismissible fade show"
    role="alert"
>

    <strong>
        Success!
    </strong>

    Product added successfully.

    <button
        type="button"
        class="btn-close"
        data-bs-dismiss="alert"
    ></button>

</div>

<?php
}
?>


<!-- =====================================================
     CARD
===================================================== -->

<div class="card">

<div class="card-body">


<!-- =====================================================
     HEADER
===================================================== -->

<div
    class="
        d-flex
        justify-content-between
        align-items-center
        mb-3
    "
>

    <div>

        <h4 class="card-title mb-0">
            Product Master
        </h4>

        <small class="text-muted">
            Manage product master information
        </small>

    </div>


    <a
        href="add.php"
        class="btn btn-primary"
    >
        + Add Product
    </a>

</div>


<!-- =====================================================
     SEARCH / FILTER
===================================================== -->

<form
    method="GET"
    class="row g-2 mb-4"
>


<div class="col-lg-5 col-md-6">

    <label class="form-label">
        Search Product
    </label>

    <input
        type="text"
        name="search"
        class="form-control"
        placeholder="Code / Name / Part / Drawing"
        value="<?= htmlspecialchars(
            $search,
            ENT_QUOTES,
            'UTF-8'
        ); ?>"
    >

</div>


<div class="col-lg-2 col-md-6">

    <label class="form-label">
        Pricing Type
    </label>

    <select
        name="pricing_type"
        class="form-select"
    >

        <option value="">
            All
        </option>

        <option
            value="PCS"
            <?= (
                $pricing_type === 'PCS'
            )
                ? 'selected'
                : '';
            ?>
        >
            PCS
        </option>

        <option
            value="KG"
            <?= (
                $pricing_type === 'KG'
            )
                ? 'selected'
                : '';
            ?>
        >
            KG
        </option>

    </select>

</div>


<div class="col-lg-3 col-md-6">

    <label class="form-label">
        Status
    </label>

    <select
        name="status"
        class="form-select"
    >

        <option value="">
            All Status
        </option>

        <option
            value="Received"
            <?= (
                $status === 'Received'
            )
                ? 'selected'
                : '';
            ?>
        >
            Received
        </option>

        <option
            value="In Production"
            <?= (
                $status === 'In Production'
            )
                ? 'selected'
                : '';
            ?>
        >
            In Production
        </option>

        <option
            value="Completed"
            <?= (
                $status === 'Completed'
            )
                ? 'selected'
                : '';
            ?>
        >
            Completed
        </option>

        <option
            value="Dispatched"
            <?= (
                $status === 'Dispatched'
            )
                ? 'selected'
                : '';
            ?>
        >
            Dispatched
        </option>

        <option
            value="Hold"
            <?= (
                $status === 'Hold'
            )
                ? 'selected'
                : '';
            ?>
        >
            Hold
        </option>

    </select>

</div>


<div
    class="
        col-lg-2
        col-md-6
        d-flex
        align-items-end
    "
>

    <div class="d-flex gap-1">

        <button
            type="submit"
            class="btn btn-primary"
        >
            Search
        </button>

        <a
            href="index.php"
            class="btn btn-secondary"
        >
            Reset
        </a>

    </div>

</div>


</form>


<!-- =====================================================
     TABLE
===================================================== -->

<div class="table-responsive">


<table
    class="
        table
        table-bordered
        table-hover
        align-middle
        product-table
    "
>


<thead class="table-dark">

<tr>

    <th style="width:60px;">
        ID
    </th>

    <th>
        Product
    </th>

    <th>
        Part No.
    </th>

    <th>
        Material
    </th>

    <th class="text-center">
        Pricing
    </th>

    <th class="text-end">
        Weight / Piece
    </th>

    <th class="text-end">
        Quantity
    </th>

    <th class="text-center">
        Priority
    </th>

    <th class="text-center">
        Status
    </th>

    <th
        class="text-center"
        style="min-width:190px;"
    >
        Action
    </th>

</tr>

</thead>


<tbody>


<?php
if (
    mysqli_num_rows(
        $result
    ) > 0
) {
?>


<?php
while (
    $row =
    mysqli_fetch_assoc(
        $result
    )
) {


    // =================================================
    // PRICING TYPE
    // =================================================

    $row_pricing_type =
        strtoupper(
            trim(
                $row['pricing_type'] ??
                'PCS'
            )
        );


    if ($row_pricing_type !== 'KG') {

        $row_pricing_type =
            'PCS';
    }


    // =================================================
    // WEIGHT
    // =================================================

    $weight_per_piece =
        floatval(
            $row['weight_per_piece'] ?? 0
        );


    if (
        $weight_per_piece <= 0 &&
        isset($row['weight']) &&
        floatval(
            $row['weight']
        ) > 0 &&
        $row_pricing_type === 'KG'
    ) {

        $weight_per_piece =
            floatval(
                $row['weight']
            );
    }

?>


<tr>


<td>

    <?= intval(
        $row['id']
    ); ?>

</td>


<td>

    <div class="product-name">

        <?= htmlspecialchars(
            $row['product_name'] ?? '',
            ENT_QUOTES,
            'UTF-8'
        ); ?>

    </div>


    <div class="product-code">

        Code:

        <?= htmlspecialchars(
            $row['product_code'] ?? '-',
            ENT_QUOTES,
            'UTF-8'
        ); ?>

    </div>


    <?php
    if (
        !empty(
            $row['drawing_number']
        )
    ) {
    ?>

        <span class="product-drawing">

            Drawing:

            <?= htmlspecialchars(
                $row['drawing_number'],
                ENT_QUOTES,
                'UTF-8'
            ); ?>


            <?php
            if (
                !empty(
                    $row['revision_no']
                )
            ) {
            ?>

                |

                Rev:

                <?= htmlspecialchars(
                    $row['revision_no'],
                    ENT_QUOTES,
                    'UTF-8'
                ); ?>

            <?php
            }
            ?>

        </span>

    <?php
    }
    ?>

</td>


<td>

    <?= !empty(
        $row['part_number']
    )
        ? htmlspecialchars(
            $row['part_number'],
            ENT_QUOTES,
            'UTF-8'
        )
        : '-';
    ?>

</td>


<td>

    <?= !empty(
        $row['material']
    )
        ? htmlspecialchars(
            $row['material'],
            ENT_QUOTES,
            'UTF-8'
        )
        : '-';
    ?>

</td>


<td class="text-center">


<?php
if (
    $row_pricing_type === 'KG'
) {
?>

    <span
        class="
            badge
            bg-info
            text-dark
        "
    >
        KG
    </span>

<?php
} else {
?>

    <span class="badge bg-primary">
        PCS
    </span>

<?php
}
?>


</td>


<td class="text-end">


<?php
if (
    $row_pricing_type === 'KG'
) {

    echo number_format(
        $weight_per_piece,
        3
    );

    echo " KG";

} else {

    echo "-";
}
?>


</td>


<td class="text-end">

<?php

$qty =
    floatval(
        $row['quantity'] ?? 0
    );


if (
    floor($qty) == $qty
) {

    echo number_format(
        $qty,
        0
    );

} else {

    echo number_format(
        $qty,
        2
    );
}

?>

    <small class="text-muted">
        PCS
    </small>

</td>


<td class="text-center">

<?php

$priority =
    $row['priority'] ?? 'Low';


if ($priority === 'Urgent') {
?>

    <span class="badge bg-danger">
        Urgent
    </span>

<?php
}

elseif ($priority === 'High') {
?>

    <span class="badge bg-warning text-dark">
        High
    </span>

<?php
}

elseif ($priority === 'Medium') {
?>

    <span class="badge bg-info text-dark">
        Medium
    </span>

<?php
}

else {
?>

    <span class="badge bg-secondary">
        Low
    </span>

<?php
}
?>

</td>


<td class="text-center">


<?php

$row_status =
    $row['status'] ?? 'Received';


if ($row_status === 'Received') {
?>

    <span class="badge bg-primary">
        Received
    </span>

<?php
}

elseif ($row_status === 'In Production') {
?>

    <span
        class="
            badge
            bg-warning
            text-dark
        "
    >
        In Production
    </span>

<?php
}

elseif ($row_status === 'Completed') {
?>

    <span class="badge bg-success">
        Completed
    </span>

<?php
}

elseif ($row_status === 'Dispatched') {
?>

    <span
        class="
            badge
            bg-info
            text-dark
        "
    >
        Dispatched
    </span>

<?php
}

elseif ($row_status === 'Hold') {
?>

    <span class="badge bg-danger">
        Hold
    </span>

<?php
}

else {
?>

    <span class="badge bg-secondary">

        <?= htmlspecialchars(
            $row_status,
            ENT_QUOTES,
            'UTF-8'
        ); ?>

    </span>

<?php
}
?>


</td>


<!-- =====================================================
     ACTION
===================================================== -->

<td class="text-center">

    <div
        class="
            d-flex
            gap-1
            justify-content-center
            flex-wrap
        "
    >


        <a
            href="view.php?id=<?= intval(
                $row['id']
            ); ?>"
            class="
                btn
                btn-sm
                btn-info
                text-white
            "
        >
            View
        </a>


        <a
            href="edit.php?id=<?= intval(
                $row['id']
            ); ?>"
            class="
                btn
                btn-sm
                btn-warning
            "
        >
            Edit
        </a>


        <!-- =================================================
             SECURE DELETE
             POST + CSRF
        ================================================== -->

        <form
            action="delete.php"
            method="POST"
            class="d-inline"
            onsubmit="
                return confirm(
                    'Are you sure you want to delete this product?'
                );
            "
        >

            <input
                type="hidden"
                name="id"
                value="<?= intval(
                    $row['id']
                ); ?>"
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


            <button
                type="submit"
                class="
                    btn
                    btn-sm
                    btn-danger
                "
            >
                Delete
            </button>

        </form>


    </div>

</td>


</tr>


<?php
}
?>


<?php
} else {
?>


<tr>

    <td
        colspan="10"
        class="
            text-center
            text-muted
            py-5
        "
    >

        No products found.

    </td>

</tr>


<?php
}
?>


</tbody>

</table>

</div>


</div>

</div>


</div>

</div>


</div>

</div>

</div>


<?php

include("../includes/footer.php");

?>