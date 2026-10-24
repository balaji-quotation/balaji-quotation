<?php

session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

include("../includes/db.php");
require_once __DIR__ . "/../includes/permissions.php";
erpRequireCompanyUser($conn);
erpRequire('clients.edit');
$companyId = erpCurrentCompanyId();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}


// =====================================================
// GET CLIENT ID
// =====================================================

$id = intval($_GET['id'] ?? 0);

if ($id <= 0) {
    header("Location: index.php");
    exit;
}


// =====================================================
// GET CLIENT DATA
// =====================================================

$stmt = mysqli_prepare(
    $conn,
    "SELECT * FROM clients WHERE id = ? AND company_id = ? LIMIT 1"
);

mysqli_stmt_bind_param(
    $stmt,
    "ii",
    $id,
    $companyId
);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

$row = mysqli_fetch_assoc($result);

mysqli_stmt_close($stmt);


if (!$row) {
    header("Location: index.php");
    exit;
}


// =====================================================
// ERP LAYOUT
// =====================================================

include("../includes/header.php");
include("../includes/sidebar.php");

?>

<style>

.client-form-card {
    border: 0;
    border-radius: 14px;
    box-shadow: 0 6px 24px rgba(31,45,61,.06);
}

.client-page-title {
    font-weight: 700;
    color: #26374a;
}

.client-page-subtitle {
    font-size: 11px;
    color: #7b8794;
}

.client-section-title {
    font-size: 14px;
    font-weight: 700;
    color: #26374a;
}

.client-form-label {
    font-size: 11px;
    font-weight: 600;
    color: #526173;
    margin-bottom: 5px;
}

.client-help {
    font-size: 10px;
    color: #8a96a3;
    margin-top: 4px;
}

.client-form-card .form-control,
.client-form-card .form-select {
    min-height: 40px;
    border-radius: 8px;
    border-color: #dfe5eb;
    font-size: 12px;
}

.client-form-card textarea.form-control {
    min-height: 95px;
    resize: vertical;
}

.client-form-card .form-control:focus,
.client-form-card .form-select:focus {
    border-color: #86b7fe;
    box-shadow: 0 0 0 .15rem rgba(13,110,253,.10);
}

.client-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    flex-wrap: wrap;
}

.required-mark {
    color: #dc3545;
}

</style>


<div class="content-page">

<div class="content">

<div class="container-fluid">


<!-- =====================================================
     PAGE HEADER
===================================================== -->

<div class="row mt-2 mb-2">

<div class="col-12">

<div class="card client-form-card">

<div class="card-body py-2 px-3">

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2">

<div>

    <h4 class="client-page-title mb-1">
        Edit Client
    </h4>

    <div class="client-page-subtitle">
        Update client contact, GSTIN and address details
    </div>

</div>


<a
    href="index.php"
    class="btn btn-secondary btn-sm"
>
    ← Client List
</a>

</div>

</div>

</div>

</div>

</div>


<!-- =====================================================
     EDIT FORM
===================================================== -->

<div class="card client-form-card">

<div class="card-body">

<form
    action="update.php"
    method="POST"
    id="client-form"
    autocomplete="off"
>
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">

<input
    type="hidden"
    name="id"
    value="<?= intval($row['id']); ?>"
>


<div class="client-section-title mb-3">
    Client Details
</div>


<div class="row g-3">


<!-- COMPANY NAME -->

<div class="col-xl-6 col-lg-6 col-md-12">

<label class="client-form-label">
    Customer / Company Name
    <span class="required-mark">*</span>
</label>

<input
    type="text"
    name="company_name"
    class="form-control"
    maxlength="150"
    value="<?= htmlspecialchars($row['company_name'] ?? ''); ?>"
    required
>

</div>


<!-- CONTACT PERSON -->

<div class="col-xl-3 col-lg-3 col-md-6">

<label class="client-form-label">
    Contact Person
</label>

<input
    type="text"
    name="contact_person"
    class="form-control"
    value="<?= htmlspecialchars($row['contact_person'] ?? ''); ?>"
>

</div>


<!-- GSTIN -->

<div class="col-xl-3 col-lg-3 col-md-6">

<label class="client-form-label">
    GSTIN
</label>

<input
    type="text"
    name="gstin"
    id="gstin"
    class="form-control text-uppercase"
    maxlength="15"
    value="<?= htmlspecialchars($row['gstin'] ?? ''); ?>"
    placeholder="15-character GSTIN"
>

<div class="client-help">
    15-character GSTIN, if applicable
</div>

</div>


<!-- MOBILE -->
<div class="col-xl-4 col-lg-4 col-md-6">
<label class="client-form-label">Mobile</label>
<input
    type="text"
    name="mobile"
    class="form-control"
    maxlength="20"
    value="<?= htmlspecialchars($row['mobile'] ?? ''); ?>"
    placeholder="+91 9876543210"
    <?= trim((string)($row['alternate_mobile'] ?? '')) === '' ? 'required' : ''; ?>
>
<div class="client-help">Enter the customer's mobile number.</div>
</div>

<!-- EMAIL -->
<div class="col-xl-4 col-lg-4 col-md-6">
<label class="client-form-label">Email</label>
<input
    type="email"
    name="email"
    class="form-control"
    maxlength="100"
    value="<?= htmlspecialchars($row['email'] ?? ''); ?>"
    placeholder="client@company.com"
>
</div>

<!-- ADDRESS -->

<div class="col-12">

<label class="client-form-label">
    Billing Address
    <span class="required-mark">*</span>
</label>

<textarea
    name="address"
    class="form-control"
    rows="4"
    required
    placeholder="Plot / Building, Street, Area, City, State, PIN Code, Country"
><?= htmlspecialchars($row['address'] ?? ''); ?></textarea>

</div>


</div>


<hr class="my-4">


<!-- BUTTONS -->

<div class="client-actions">

<a
    href="index.php"
    class="btn btn-secondary"
>
    Cancel
</a>

<button
    type="submit"
    class="btn btn-success px-4"
>
    <i class="mdi mdi-content-save-outline me-1"></i>
    Update Client
</button>

</div>


</form>

</div>

</div>


</div>

</div>

</div>


<script>

document.addEventListener(
    "DOMContentLoaded",
    function () {

        const form = document.getElementById("client-form");
        const mobile = document.querySelector('[name="mobile"]');
        const hasLegacyAlternate = <?= !empty($row['alternate_mobile']) ? 'true' : 'false'; ?>;

        if (form) {
            form.addEventListener("submit", function (event) {
                if (mobile.value.trim() === "" && !hasLegacyAlternate) {
                    event.preventDefault();
                    mobile.setCustomValidity("Enter a mobile number.");
                    mobile.reportValidity();
                } else {
                    mobile.setCustomValidity("");
                }
            });
            mobile.addEventListener("input", function () {
                mobile.setCustomValidity("");
            });
        }

        const gstin =
            document.getElementById("gstin");

        if (gstin) {

            gstin.addEventListener(
                "input",
                function () {

                    this.value =
                        this.value
                            .toUpperCase()
                            .replace(/[^A-Z0-9]/g, "")
                            .slice(0, 15);

                }
            );

        }

    }
);

</script>


<?php
include("../includes/footer.php");
?>