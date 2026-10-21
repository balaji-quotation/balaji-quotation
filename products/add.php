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
erpRequire('products.add');
include("../includes/header.php");
include("../includes/sidebar.php");

?>

<div class="content-page">

    <div class="content">

        <div class="container-fluid">

            <!-- =====================================================
                 PAGE HEADER
            ====================================================== -->

            <div class="py-3 d-flex justify-content-between align-items-center">

                <div>
                    <h4 class="mb-0">
                        Add New Product
                    </h4>

                    <small class="text-muted">
                        Create a new product in Product Master
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
            ====================================================== -->

            <div class="card">

                <div class="card-body">

                    <!-- =============================================
                         MESSAGES
                    ============================================== -->

                    <?php
                    if (
                        isset($_GET['msg']) &&
                        $_GET['msg'] === 'duplicate_code'
                    ) {
                    ?>

                        <div class="alert alert-warning alert-dismissible fade show">

                            <strong>
                                Product Code Already Exists!
                            </strong>

                            Product Code

                            <strong>
                                <?= htmlspecialchars(
                                    $_GET['code'] ?? ''
                                ); ?>
                            </strong>

                            is already used.

                            Please enter a different Product Code.

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
                        $_GET['msg'] === 'required'
                    ) {
                    ?>

                        <div class="alert alert-danger alert-dismissible fade show">

                            Product Code is required.

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
                        $_GET['msg'] === 'name_required'
                    ) {
                    ?>

                        <div class="alert alert-danger alert-dismissible fade show">

                            Product Name is required.

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
                         PRODUCT FORM
                    ====================================================== -->

                    <form
                        action="save.php"
                        method="POST"
                        enctype="multipart/form-data"
                        id="productForm"
                    >
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">

                        <div class="row">


                            <!-- =================================================
                                 PRODUCT CODE
                            ================================================== -->

                            <div class="col-md-4 mb-3">

                                <label class="form-label">
                                    Product Code
                                </label>

                                <input
                                    type="text"
                                    name="product_code"
                                    class="form-control"
                                    maxlength="100"
                                    placeholder="Enter Product Code"
                                >

                            </div>


                            <!-- =================================================
                                 PRODUCT NAME
                            ================================================== -->

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
                                    maxlength="255"
                                    placeholder="Enter Product Name"
                                    required
                                >

                            </div>


                            <!-- =================================================
                                 PART NUMBER
                            ================================================== -->

                            <div class="col-md-6 mb-3">

                                <label class="form-label">
                                    Part Number
                                </label>

                                <input
                                    type="text"
                                    name="part_number"
                                    class="form-control"
                                    maxlength="100"
                                    placeholder="Enter Part Number"
                                >

                            </div>


                            <!-- =================================================
                                 DRAWING NUMBER
                            ================================================== -->

                            <div class="col-md-6 mb-3">

                                <label class="form-label">
                                    Drawing Number
                                </label>

                                <input
                                    type="text"
                                    name="drawing_number"
                                    class="form-control"
                                    maxlength="100"
                                    placeholder="Enter Drawing Number"
                                >

                            </div>


                            <!-- =================================================
                                 REVISION NUMBER
                            ================================================== -->

                            <div class="col-md-4 mb-3">

                                <label class="form-label">
                                    Revision No.
                                </label>

                                <input
                                    type="text"
                                    name="revision_no"
                                    class="form-control"
                                    maxlength="50"
                                    value="00"
                                    placeholder="00"
                                >

                            </div>


                            <!-- =================================================
                                 MATERIAL
                            ================================================== -->

                            <div class="col-md-8 mb-3">

                                <label class="form-label">
                                    Material
                                </label>

                                <input
                                    type="text"
                                    name="material"
                                    class="form-control"
                                    maxlength="255"
                                    placeholder="Enter Material"
                                >

                            </div>


                            <!-- =================================================
                                 PRICING TYPE
                            ================================================== -->

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
                                        selected
                                    >
                                        PCS
                                    </option>

                                    <option value="KG">
                                        KG
                                    </option>

                                </select>

                            </div>


                            <!-- =================================================
                                 UNIT
                            ================================================== -->

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
                                        selected
                                    >
                                        PCS
                                    </option>

                                    <option value="KG">
                                        KG
                                    </option>

                                </select>

                            </div>


                            <!-- =================================================
                                 WEIGHT PER PIECE
                            ================================================== -->

                            <div
                                class="col-md-4 mb-3"
                                id="weightPerPieceBox"
                                style="display:none;"
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
                                    value="0"
                                    placeholder="Enter Weight Per Piece"
                                >

                                <small class="text-muted">
                                    Required for KG pricing
                                </small>

                            </div>


                            <!-- =================================================
                                 QUANTITY
                            ================================================== -->

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
                                    value="0"
                                    placeholder="Enter Quantity"
                                >

                            </div>


                            <!-- =================================================
                                 PRIORITY
                            ================================================== -->

                            <div class="col-md-4 mb-3">

                                <label class="form-label">
                                    Priority
                                </label>

                                <select
                                    name="priority"
                                    class="form-select"
                                >

                                    <option value="Low">
                                        Low
                                    </option>

                                    <option value="Medium">
                                        Medium
                                    </option>

                                    <option value="High">
                                        High
                                    </option>

                                    <option value="Urgent">
                                        Urgent
                                    </option>

                                </select>

                            </div>


                            <!-- =================================================
                                 STATUS
                            ================================================== -->

                            <div class="col-md-4 mb-3">

                                <label class="form-label">
                                    Status
                                </label>

                                <select
                                    name="status"
                                    class="form-select"
                                >

                                    <option value="Received">
                                        Received
                                    </option>

                                    <option value="In Production">
                                        In Production
                                    </option>

                                    <option value="Completed">
                                        Completed
                                    </option>

                                    <option value="Dispatched">
                                        Dispatched
                                    </option>

                                    <option value="Hold">
                                        Hold
                                    </option>

                                </select>

                            </div>


                            <!-- =================================================
                                 DRAWING FILE
                            ================================================== -->

                            <div class="col-md-6 mb-3">

                                <label class="form-label">
                                    Drawing File
                                </label>

                                <input
                                    type="file"
                                    name="drawing_file"
                                    class="form-control"
                                    accept=".pdf,.jpg,.jpeg,.png,.dwg,.dxf"
                                >

                                <small class="text-muted">
                                    PDF, JPG, JPEG, PNG, DWG, DXF
                                </small>

                            </div>


                            <!-- =================================================
                                 PRODUCT IMAGE
                            ================================================== -->

                            <div class="col-md-6 mb-3">

                                <label class="form-label">
                                    Product Image
                                </label>

                                <input
                                    type="file"
                                    name="product_image"
                                    class="form-control"
                                    accept=".jpg,.jpeg,.png,.webp"
                                >

                                <small class="text-muted">
                                    JPG, JPEG, PNG, WEBP
                                </small>

                            </div>


                            <!-- =================================================
                                 REMARKS
                            ================================================== -->

                            <div class="col-12 mb-3">

                                <label class="form-label">
                                    Remarks
                                </label>

                                <textarea
                                    name="remarks"
                                    class="form-control"
                                    rows="4"
                                    placeholder="Enter Remarks"
                                ></textarea>

                            </div>

                        </div>


                        <!-- =====================================================
                             BUTTONS
                        ====================================================== -->

                        <div class="mt-3">

                            <button
                                type="submit"
                                class="btn btn-success"
                            >
                                Save Product
                            </button>

                            <a
                                href="index.php"
                                class="btn btn-secondary"
                            >
                                Cancel
                            </a>

                        </div>

                    </form>

                </div>

            </div>

        </div>

    </div>

</div>


<!-- =========================================================
     PRICING TYPE JAVASCRIPT
========================================================= -->

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


        // =====================================================
        // UPDATE PRICING FIELDS
        // =====================================================

        function updatePricingFields() {

            if (!pricingType) {
                return;
            }


            const type =
                pricingType.value;


            // =================================================
            // KG PRICING
            // =================================================

            if (type === "KG") {

                weightBox.style.display =
                    "";

                weightInput.required =
                    true;

                weightRequiredStar.style.display =
                    "inline";

                unit.value =
                    "KG";

            }


            // =================================================
            // PCS PRICING
            // =================================================

            else {

                weightBox.style.display =
                    "none";

                weightInput.required =
                    false;

                weightInput.removeAttribute(
                    "required"
                );

                weightInput.value =
                    "0";

                unit.value =
                    "PCS";

                weightRequiredStar.style.display =
                    "none";

            }

        }


        // =====================================================
        // PRICING TYPE CHANGE
        // =====================================================

        pricingType.addEventListener(
            "change",
            updatePricingFields
        );


        // =====================================================
        // UNIT CHANGE
        // =====================================================

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


        // =====================================================
        // INITIAL LOAD
        // =====================================================

        updatePricingFields();

    }
);

</script>


<?php

include("../includes/footer.php");

?>