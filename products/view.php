<?php

session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

include("../includes/db.php");
require_once __DIR__ . '/../includes/permissions.php';
erpRequireCompanyUser($conn);
erpRequire('products.view');
$companyId = erpCurrentCompanyId();
include("../includes/header.php");
include("../includes/sidebar.php");


// =====================================================
// GET PRODUCT ID
// =====================================================

if (
    !isset($_GET['id']) ||
    !is_numeric($_GET['id'])
) {

    header("Location: index.php?msg=notfound");
    exit;
}


$id = intval($_GET['id']);


if ($id <= 0) {

    header("Location: index.php?msg=notfound");
    exit;
}


// =====================================================
// GET PRODUCT DETAILS
// =====================================================

$sql = "
    SELECT *
    FROM products
    WHERE id = $id
      AND company_id = $companyId
    LIMIT 1
";


$result =
    mysqli_query(
        $conn,
        $sql
    );


if (!$result) {

    die(
        "Database Error: " .
        mysqli_error($conn)
    );
}


if (
    mysqli_num_rows(
        $result
    ) == 0
) {

    header("Location: index.php?msg=notfound");
    exit;
}


$row =
    mysqli_fetch_assoc(
        $result
    );


// =====================================================
// PRICING INFORMATION
// =====================================================

$pricing_type =
    strtoupper(
        trim(
            $row['pricing_type'] ?? 'PCS'
        )
    );


if (
    $pricing_type !== 'KG'
) {

    $pricing_type =
        'PCS';
}


$unit =
    strtoupper(
        trim(
            $row['unit'] ??
            $pricing_type
        )
    );


if (
    $pricing_type === 'KG'
) {

    $unit =
        'KG';

} else {

    $unit =
        'PCS';
}


// =====================================================
// WEIGHT PER PIECE
// =====================================================

$weight_per_piece =
    floatval(
        $row['weight_per_piece'] ?? 0
    );


// Old Product Compatibility

if (
    $pricing_type === 'KG' &&
    $weight_per_piece <= 0 &&
    isset($row['weight']) &&
    floatval(
        $row['weight']
    ) > 0
) {

    $weight_per_piece =
        floatval(
            $row['weight']
        );
}


// =====================================================
// STATUS
// =====================================================

$status =
    trim(
        $row['status'] ??
        'Received'
    );


// =====================================================
// PRIORITY
// =====================================================

$priority =
    trim(
        $row['priority'] ??
        'Low'
    );

?>


<div class="content-page">

<div class="content">

<div class="container-fluid">


<!-- =====================================================
     PAGE HEADER
===================================================== -->

<div
    class="
        py-3
        d-flex
        justify-content-between
        align-items-center
    "
>

    <div>

        <h4 class="mb-0">
            Product Details
        </h4>

        <small class="text-muted">
            Product Master Information
        </small>

    </div>


    <div class="d-flex gap-2">

        <a
            href="index.php"
            class="btn btn-secondary"
        >
            ← Back
        </a>


        <a
            href="edit.php?id=<?= intval(
                $row['id']
            ); ?>"
            class="btn btn-warning"
        >
            Edit Product
        </a>

    </div>

</div>


<!-- =====================================================
     PRODUCT INFORMATION
===================================================== -->

<div class="card mb-3">

<div class="card-header">

    <h5 class="mb-0">
        Product Information
    </h5>

</div>


<div class="card-body">

<div class="row">


<!-- PRODUCT CODE -->

<div class="col-lg-3 col-md-4 mb-3">

    <label class="text-muted">
        Product Code
    </label>

    <div class="fw-bold">

        <?= htmlspecialchars(
            $row['product_code'] ?? ''
        ); ?>

    </div>

</div>


<!-- PRODUCT NAME -->

<div class="col-lg-3 col-md-4 mb-3">

    <label class="text-muted">
        Product Name
    </label>

    <div class="fw-bold">

        <?= htmlspecialchars(
            $row['product_name'] ?? ''
        ); ?>

    </div>

</div>


<!-- PART NUMBER -->

<div class="col-lg-3 col-md-4 mb-3">

    <label class="text-muted">
        Part Number
    </label>

    <div>

        <?= !empty(
            $row['part_number']
        )
            ? htmlspecialchars(
                $row['part_number']
            )
            : '-';
        ?>

    </div>

</div>


<!-- DRAWING NUMBER -->

<div class="col-lg-3 col-md-4 mb-3">

    <label class="text-muted">
        Drawing Number
    </label>

    <div>

        <?= !empty(
            $row['drawing_number']
        )
            ? htmlspecialchars(
                $row['drawing_number']
            )
            : '-';
        ?>

    </div>

</div>


<!-- REVISION -->

<div class="col-lg-3 col-md-4 mb-3">

    <label class="text-muted">
        Revision No.
    </label>

    <div>

        <?= !empty(
            $row['revision_no']
        )
            ? htmlspecialchars(
                $row['revision_no']
            )
            : '00';
        ?>

    </div>

</div>


<!-- STATUS -->

<div class="col-lg-3 col-md-4 mb-3">

    <label class="text-muted d-block">
        Status
    </label>


    <?php
    if (
        $status ===
        'Received'
    ) {
    ?>

        <span class="badge bg-primary">
            Received
        </span>

    <?php
    }

    elseif (
        $status ===
        'In Production'
    ) {
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

    elseif (
        $status ===
        'Completed'
    ) {
    ?>

        <span class="badge bg-success">
            Completed
        </span>

    <?php
    }

    elseif (
        $status ===
        'Dispatched'
    ) {
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

    elseif (
        $status ===
        'Hold'
    ) {
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
                $status
            ); ?>

        </span>

    <?php
    }
    ?>

</div>


</div>

</div>

</div>


<!-- =====================================================
     PRICING & UNIT INFORMATION
===================================================== -->

<div class="card mb-3">

<div class="card-header">

    <h5 class="mb-0">
        Pricing & Unit Information
    </h5>

</div>


<div class="card-body">

<div class="row">


<!-- PRICING TYPE -->

<div class="col-md-4 mb-3">

    <label class="text-muted d-block">
        Pricing Type
    </label>


    <?php
    if (
        $pricing_type ===
        'KG'
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

</div>


<!-- UNIT -->

<div class="col-md-4 mb-3">

    <label class="text-muted">
        Unit
    </label>

    <div class="fw-bold">

        <?= htmlspecialchars(
            $unit
        ); ?>

    </div>

</div>


<!-- WEIGHT PER PIECE -->

<div class="col-md-4 mb-3">

    <label class="text-muted">
        Weight Per Piece
    </label>

    <div class="fw-bold">

        <?php

        if (
            $pricing_type ===
            'KG'
        ) {

            echo number_format(
                $weight_per_piece,
                3
            );

            echo ' KG';

        } else {

            echo '-';
        }

        ?>

    </div>

</div>


</div>

</div>

</div>


<!-- =====================================================
     PRODUCTION INFORMATION
===================================================== -->

<div class="card mb-3">

<div class="card-header">

    <h5 class="mb-0">
        Production Information
    </h5>

</div>


<div class="card-body">

<div class="row">


<!-- MATERIAL -->

<div class="col-md-4 mb-3">

    <label class="text-muted">
        Material
    </label>

    <div>

        <?= !empty(
            $row['material']
        )
            ? htmlspecialchars(
                $row['material']
            )
            : '-';
        ?>

    </div>

</div>


<!-- QUANTITY -->

<div class="col-md-4 mb-3">

    <label class="text-muted">
        Quantity
    </label>


    <div class="fw-bold">

        <?php

        $quantity =
            floatval(
                $row['quantity'] ?? 0
            );


        if (
            floor($quantity) ==
            $quantity
        ) {

            echo number_format(
                $quantity,
                0
            );

        } else {

            echo number_format(
                $quantity,
                2
            );
        }

        ?>

        PCS

    </div>

</div>


<!-- PRIORITY -->

<div class="col-md-4 mb-3">

    <label class="text-muted d-block">
        Priority
    </label>


    <?php

    $priority_class =
        'bg-secondary';


    if (
        $priority ===
        'Low'
    ) {

        $priority_class =
            'bg-success';

    }

    elseif (
        $priority ===
        'Medium'
    ) {

        $priority_class =
            'bg-warning text-dark';

    }

    elseif (
        $priority ===
        'High'
    ) {

        $priority_class =
            'bg-danger';

    }

    elseif (
        $priority ===
        'Urgent'
    ) {

        $priority_class =
            'bg-dark';
    }

    ?>


    <span
        class="
            badge
            <?= $priority_class; ?>
        "
    >

        <?= htmlspecialchars(
            $priority
        ); ?>

    </span>

</div>


</div>

</div>

</div>


<!-- =====================================================
     DOCUMENTS
===================================================== -->

<div class="card mb-3">

<div class="card-header">

    <h5 class="mb-0">
        Documents & Product Image
    </h5>

</div>


<div class="card-body">

<div class="row">


<!-- DRAWING -->

<div class="col-md-6 mb-3">

    <label class="text-muted d-block mb-2">
        Drawing
    </label>


    <?php
    if (
        !empty(
            $row['drawing_file']
        )
    ) {
    ?>

        <a
            href="../uploads/drawings/<?= rawurlencode(
                basename(
                    $row['drawing_file']
                )
            ); ?>"
            target="_blank"
            class="
                btn
                btn-sm
                btn-info
                text-white
            "
        >
            View Drawing
        </a>

    <?php
    } else {
    ?>

        <span class="text-muted">
            No Drawing File
        </span>

    <?php
    }
    ?>

</div>


<!-- PRODUCT IMAGE -->

<div class="col-md-6 mb-3">

    <label class="text-muted d-block mb-2">
        Product Image
    </label>


    <?php
    if (
        !empty(
            $row['product_image']
        )
    ) {
    ?>

        <a
            href="../uploads/products/<?= rawurlencode(
                basename(
                    $row['product_image']
                )
            ); ?>"
            target="_blank"
        >

            <img
                src="../uploads/products/<?= rawurlencode(
                    basename(
                        $row['product_image']
                    )
                ); ?>"
                alt="Product Image"
                class="img-thumbnail"
                style="
                    max-width:180px;
                    max-height:180px;
                    object-fit:contain;
                "
            >

        </a>

    <?php
    } else {
    ?>

        <span class="text-muted">
            No Product Image
        </span>

    <?php
    }
    ?>

</div>


</div>

</div>

</div>


<!-- =====================================================
     REMARKS
===================================================== -->

<div class="card mb-4">

<div class="card-header">

    <h5 class="mb-0">
        Remarks
    </h5>

</div>


<div class="card-body">


<?php
if (
    !empty(
        $row['remarks']
    )
) {
?>

    <div class="border rounded p-3">

        <?= nl2br(
            htmlspecialchars(
                $row['remarks']
            )
        ); ?>

    </div>

<?php
} else {
?>

    <span class="text-muted">
        No remarks available.
    </span>

<?php
}
?>


</div>

</div>


<!-- =====================================================
     BOTTOM BUTTONS
===================================================== -->

<div class="mb-4">

    <a
        href="index.php"
        class="btn btn-secondary"
    >
        ← Back to Product Master
    </a>


    <a
        href="edit.php?id=<?= intval(
            $row['id']
        ); ?>"
        class="btn btn-warning"
    >
        Edit Product
    </a>

</div>


</div>

</div>

</div>


<?php

include("../includes/footer.php");

?>