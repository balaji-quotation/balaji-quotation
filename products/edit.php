<?php

session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

include("../includes/db.php");
require_once __DIR__ . '/../includes/permissions.php';
erpRequireCompanyUser($conn);
erpRequire('products.edit');
$companyId = erpCurrentCompanyId();
include("../includes/header.php");
include("../includes/sidebar.php");


// =====================================================
// PRODUCT ID
// =====================================================

$id = intval($_GET['id'] ?? 0);

if ($id <= 0) {
    die("Invalid Product ID");
}


// =====================================================
// PRODUCT DETAILS
// =====================================================

$product = mysqli_query(
    $conn,
    "SELECT * FROM products WHERE id = $id AND company_id = $companyId LIMIT 1"
);

if (!$product) {
    die(
        "Database Error: " .
        mysqli_error($conn)
    );
}

if (
    mysqli_num_rows($product) == 0
) {
    die("Product Not Found");
}

$row =
    mysqli_fetch_assoc($product);


// =====================================================
// OLD DATA COMPATIBILITY
// =====================================================

$pricing_type =
    strtoupper(
        trim(
            $row['pricing_type'] ?? 'PCS'
        )
    );

if (
    !in_array(
        $pricing_type,
        ['PCS', 'KG'],
        true
    )
) {
    $pricing_type = 'PCS';
}


$unit =
    strtoupper(
        trim(
            $row['unit'] ??
            $pricing_type
        )
    );

if (
    !in_array(
        $unit,
        ['PCS', 'KG'],
        true
    )
) {
    $unit = $pricing_type;
}


// =====================================================
// WEIGHT PER PIECE
// =====================================================

$weight_per_piece =
    floatval(
        $row['weight_per_piece'] ?? 0
    );


// Old products may only have weight

if (
    $weight_per_piece <= 0 &&
    isset($row['weight']) &&
    floatval($row['weight']) > 0
) {

    $weight_per_piece =
        floatval(
            $row['weight']
        );
}


// =====================================================
// REVISION DEFAULT
// =====================================================

$revision_no =
    trim(
        $row['revision_no'] ?? ''
    );

if ($revision_no === '') {
    $revision_no = '00';
}

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
            Edit Product
        </h4>

        <small class="text-muted">

            <?= htmlspecialchars(
                $row['product_code'] ?? ''
            ); ?>

            -

            <?= htmlspecialchars(
                $row['product_name'] ?? ''
            ); ?>

        </small>

    </div>


    <a
        href="index.php"
        class="btn btn-secondary"
    >
        Back
    </a>

</div>


<!-- =====================================================
     CARD
===================================================== -->

<div class="card">

<div class="card-body">


<!-- =====================================================
     DUPLICATE MESSAGE
===================================================== -->

<?php
if (
    isset($_GET['msg']) &&
    $_GET['msg'] === 'duplicate_code'
) {
?>

<div
    class="
        alert
        alert-warning
        alert-dismissible
        fade
        show
    "
>

    <strong>
        Product Code Already Exists!
    </strong>

    Product Code

    <strong>
        <?= htmlspecialchars(
            $_GET['code'] ?? ''
        ); ?>
    </strong>

    is already used by another product.

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
     FORM
===================================================== -->

<form
    action="update.php"
    method="POST"
    enctype="multipart/form-data"
    id="productForm"
>
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">


<input
    type="hidden"
    name="id"
    value="<?= intval(
        $row['id']
    ); ?>"
>


<div class="row">


<!-- =====================================================
     PRODUCT CODE
===================================================== -->

<div class="col-md-4 mb-3">

    <label class="form-label">

        Product Code

        <span class="text-danger">
            *
        </span>

    </label>

    <input
        type="text"
        name="product_code"
        class="form-control"
        value="<?= htmlspecialchars(
            $row['product_code'] ?? ''
        ); ?>"
        required
    >

</div>


<!-- =====================================================
     PRODUCT NAME
===================================================== -->

<div class="col-md-8 mb-3">

    <label class="form-label">

        Product Name

        <span class="text-danger">
            *
        </span>

    </label>

    <input
        type="text"
        name="product_name"
        class="form-control"
        value="<?= htmlspecialchars(
            $row['product_name'] ?? ''
        ); ?>"
        required
    >

</div>


<!-- =====================================================
     PART NUMBER
===================================================== -->

<div class="col-md-6 mb-3">

    <label class="form-label">
        Part Number
    </label>

    <input
        type="text"
        name="part_number"
        class="form-control"
        value="<?= htmlspecialchars(
            $row['part_number'] ?? ''
        ); ?>"
    >

</div>


<!-- =====================================================
     DRAWING NUMBER
===================================================== -->

<div class="col-md-6 mb-3">

    <label class="form-label">
        Drawing Number
    </label>

    <input
        type="text"
        name="drawing_number"
        class="form-control"
        value="<?= htmlspecialchars(
            $row['drawing_number'] ?? ''
        ); ?>"
    >

</div>


<!-- =====================================================
     REVISION NUMBER
===================================================== -->

<div class="col-md-4 mb-3">

    <label class="form-label">
        Revision No.
    </label>

    <input
        type="text"
        name="revision_no"
        class="form-control"
        value="<?= htmlspecialchars(
            $revision_no
        ); ?>"
    >

</div>


<!-- =====================================================
     MATERIAL
===================================================== -->

<div class="col-md-8 mb-3">

    <label class="form-label">
        Material
    </label>

    <input
        type="text"
        name="material"
        class="form-control"
        value="<?= htmlspecialchars(
            $row['material'] ?? ''
        ); ?>"
    >

</div>


<!-- =====================================================
     PRICING TYPE
===================================================== -->

<div class="col-md-4 mb-3">

    <label class="form-label">

        Pricing Type

        <span class="text-danger">
            *
        </span>

    </label>


    <select
        name="pricing_type"
        id="pricing_type"
        class="form-select"
        required
    >

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


<!-- =====================================================
     UNIT
===================================================== -->

<div class="col-md-4 mb-3">

    <label class="form-label">

        Unit

        <span class="text-danger">
            *
        </span>

    </label>


    <select
        name="unit"
        id="unit"
        class="form-select"
        required
    >

        <option
            value="PCS"
            <?= (
                $unit === 'PCS'
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
                $unit === 'KG'
            )
                ? 'selected'
                : '';
            ?>
        >
            KG
        </option>

    </select>

</div>


<!-- =====================================================
     WEIGHT PER PIECE
===================================================== -->

<div
    class="col-md-4 mb-3"
    id="weightPerPieceBox"
>

    <label class="form-label">

        Weight Per Piece (KG)

        <span
            class="text-danger"
            id="weightRequiredStar"
        >
            *
        </span>

    </label>


    <input
        type="number"
        name="weight_per_piece"
        id="weight_per_piece"
        class="form-control"
        min="0"
        step="0.001"
        value="<?= htmlspecialchars(
            number_format(
                $weight_per_piece,
                3,
                '.',
                ''
            )
        ); ?>"
    >


    <small class="text-muted">
        Required only for KG pricing.
    </small>

</div>


<!-- =====================================================
     QUANTITY
===================================================== -->

<div class="col-md-4 mb-3">

    <label class="form-label">
        Quantity
    </label>

    <input
        type="number"
        name="quantity"
        class="form-control"
        min="0"
        step="0.01"
        value="<?= htmlspecialchars(
            $row['quantity'] ?? 0
        ); ?>"
    >

</div>


<!-- =====================================================
     PRIORITY
===================================================== -->

<div class="col-md-4 mb-3">

    <label class="form-label">
        Priority
    </label>


    <select
        name="priority"
        class="form-select"
    >

        <option
            value="Low"
            <?= (
                ($row['priority'] ?? '') ===
                'Low'
            )
                ? 'selected'
                : '';
            ?>
        >
            Low
        </option>


        <option
            value="Medium"
            <?= (
                ($row['priority'] ?? '') ===
                'Medium'
            )
                ? 'selected'
                : '';
            ?>
        >
            Medium
        </option>


        <option
            value="High"
            <?= (
                ($row['priority'] ?? '') ===
                'High'
            )
                ? 'selected'
                : '';
            ?>
        >
            High
        </option>


        <option
            value="Urgent"
            <?= (
                ($row['priority'] ?? '') ===
                'Urgent'
            )
                ? 'selected'
                : '';
            ?>
        >
            Urgent
        </option>

    </select>

</div>


<!-- =====================================================
     STATUS
===================================================== -->

<div class="col-md-4 mb-3">

    <label class="form-label">
        Status
    </label>


    <select
        name="status"
        class="form-select"
    >

        <option
            value="Received"
            <?= (
                ($row['status'] ?? '') ===
                'Received'
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
                ($row['status'] ?? '') ===
                'In Production'
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
                ($row['status'] ?? '') ===
                'Completed'
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
                ($row['status'] ?? '') ===
                'Dispatched'
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
                ($row['status'] ?? '') ===
                'Hold'
            )
                ? 'selected'
                : '';
            ?>
        >
            Hold
        </option>

    </select>

</div>


<!-- =====================================================
     DRAWING FILE
===================================================== -->

<div class="col-md-6 mb-3">

    <label class="form-label">
        Drawing File
    </label>


    <?php
    if (
        !empty(
            $row['drawing_file']
        )
    ) {
    ?>

        <div class="mb-2">

            <a
                href="../uploads/drawings/<?= rawurlencode(
                    basename(
                        $row['drawing_file']
                    )
                ); ?>"
                target="_blank"
                class="btn btn-sm btn-info"
            >
                View Current Drawing
            </a>

        </div>

    <?php
    } else {
    ?>

        <div class="text-muted mb-2">
            No Drawing File
        </div>

    <?php
    }
    ?>


    <input
        type="file"
        name="drawing_file"
        class="form-control"
        accept=".pdf,.jpg,.jpeg,.png,.dwg,.dxf"
    >


    <small class="text-muted">
        Leave blank to keep existing drawing.
    </small>

</div>


<!-- =====================================================
     PRODUCT IMAGE
===================================================== -->

<div class="col-md-6 mb-3">

    <label class="form-label">
        Product Image
    </label>


    <?php
    if (
        !empty(
            $row['product_image']
        )
    ) {
    ?>

        <div class="mb-2">

            <img
                src="../uploads/products/<?= rawurlencode(
                    basename(
                        $row['product_image']
                    )
                ); ?>"
                width="120"
                class="img-thumbnail"
                alt="Product Image"
            >

        </div>

    <?php
    } else {
    ?>

        <div class="text-muted mb-2">
            No Product Image
        </div>

    <?php
    }
    ?>


    <input
        type="file"
        name="product_image"
        class="form-control"
        accept=".jpg,.jpeg,.png,.webp"
    >


    <small class="text-muted">
        Leave blank to keep existing image.
    </small>

</div>


<!-- =====================================================
     REMARKS
===================================================== -->

<div class="col-md-12 mb-3">

    <label class="form-label">
        Remarks
    </label>

    <textarea
        name="remarks"
        rows="4"
        class="form-control"
    ><?= htmlspecialchars(
        $row['remarks'] ?? ''
    ); ?></textarea>

</div>


<!-- =====================================================
     BUTTONS
===================================================== -->

<div class="col-md-12">

    <button
        type="submit"
        class="btn btn-success"
    >
        Update Product
    </button>


    <a
        href="index.php"
        class="btn btn-secondary"
    >
        Cancel
    </a>

</div>


</div>

</form>

</div>

</div>

</div>

</div>

</div>


<!-- =====================================================
     PRICING TYPE LOGIC
===================================================== -->

<script>

document.addEventListener(
    "DOMContentLoaded",
    function () {


        const pricingType =
            document.getElementById(
                "pricing_type"
            );


        const unit =
            document.getElementById(
                "unit"
            );


        const weightBox =
            document.getElementById(
                "weightPerPieceBox"
            );


        const weightInput =
            document.getElementById(
                "weight_per_piece"
            );


        const weightRequiredStar =
            document.getElementById(
                "weightRequiredStar"
            );


        // =================================================
        // KEEP CURRENT KG WEIGHT
        // =================================================

        let lastKgWeight =
            parseFloat(
                weightInput.value
            ) || 0;


        // =================================================
        // UPDATE FIELD VISIBILITY
        // =================================================

        function updatePricingFields() {


            if (
                pricingType.value ===
                "KG"
            ) {

                unit.value =
                    "KG";

                weightBox.style.display =
                    "";

                weightInput.required =
                    true;

                weightRequiredStar.style.display =
                    "inline";


                if (
                    parseFloat(
                        weightInput.value
                    ) <= 0 &&
                    lastKgWeight > 0
                ) {

                    weightInput.value =
                        lastKgWeight.toFixed(3);
                }

            } else {

                const currentWeight =
                    parseFloat(
                        weightInput.value
                    ) || 0;


                if (
                    currentWeight > 0
                ) {

                    lastKgWeight =
                        currentWeight;
                }


                unit.value =
                    "PCS";

                weightBox.style.display =
                    "none";

                weightInput.required =
                    false;

                weightRequiredStar.style.display =
                    "none";
            }

        }


        // =================================================
        // PRICING CHANGE
        // =================================================

        pricingType.addEventListener(
            "change",
            updatePricingFields
        );


        // =================================================
        // UNIT CHANGE
        // =================================================

        unit.addEventListener(
            "change",
            function () {

                if (
                    unit.value ===
                    "KG"
                ) {

                    pricingType.value =
                        "KG";

                } else {

                    pricingType.value =
                        "PCS";
                }


                updatePricingFields();
            }
        );


        // =================================================
        // WEIGHT CHANGE
        // =================================================

        weightInput.addEventListener(
            "input",
            function () {

                const value =
                    parseFloat(
                        weightInput.value
                    ) || 0;


                if (
                    value > 0
                ) {

                    lastKgWeight =
                        value;
                }
            }
        );


        // =================================================
        // INITIAL PAGE LOAD
        // =================================================

        updatePricingFields();

    }
);

</script>


<?php

include("../includes/footer.php");

?>