<?php

session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

include("../includes/db.php");
require_once __DIR__ . "/../includes/permissions.php";
erpRequireCompanyUser($conn);
erpRequire('clients.add');
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
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
            Add New Client
        </h4>

        <div class="client-page-subtitle">
            Create client master for quotation, order and dispatch workflow
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
     FORM
===================================================== -->

<div class="card client-form-card">

<div class="card-body">

<form
    action="save.php"
    method="POST"
    id="client-form"
    autocomplete="off"
>
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']); ?>">


<div class="client-section-title mb-3">
    Client Details
</div>


<div class="row g-3">


<!-- COMPANY NAME -->
<div class="col-xl-6 col-lg-6 col-md-12">

<label class="client-form-label">
    Company Name
    <span class="required-mark">*</span>
</label>

<input
    type="text"
    name="company_name"
    class="form-control"
    maxlength="150"
    placeholder="Enter company name"
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
    placeholder="Contact person name"
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
    placeholder="15-character GSTIN"
>

<div class="client-help">
    15-character GSTIN, if applicable
</div>

</div>


<!-- EMAIL -->
<div class="col-xl-4 col-lg-4 col-md-6">

<label class="client-form-label">
    Email
</label>

<input
    type="email"
    name="email"
    class="form-control"
    maxlength="100"
    placeholder="client@company.com"
>

</div>


<!-- MOBILE -->
<div class="col-xl-4 col-lg-4 col-md-6">

<label class="client-form-label">
    Mobile / Phone
</label>

<input
    type="text"
    name="mobile"
    class="form-control"
    maxlength="20"
    placeholder="+91 9876543210"
>

<div class="client-help">Enter a mobile number here or provide an alternate mobile number.</div>

</div>


<!-- ALTERNATE MOBILE -->
<div class="col-xl-4 col-lg-4 col-md-6">

<label class="client-form-label">
    Alternate Mobile
</label>

<input
    type="text"
    name="alternate_mobile"
    id="alternate_mobile"
    class="form-control"
    maxlength="30"
    placeholder="Alternate phone number"
>

</div>


<!-- STATUS -->
<div class="col-xl-4 col-lg-4 col-md-6">

<label class="client-form-label">
    Status
</label>

<select
    name="status"
    class="form-select"
>

<option value="Active" selected>
    Active
</option>

<option value="Inactive">
    Inactive
</option>

</select>

</div>


<!-- ADDRESS -->
<div class="col-12">

<label class="client-form-label">
    Complete Address
    <span class="required-mark">*</span>
</label>

<textarea
    name="address"
    class="form-control"
    rows="4"
    required
    placeholder="Plot / Building, Street, Area, City, State, PIN Code, Country"
></textarea>

</div>


</div>


<hr class="my-4">


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
    Save Client
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
        const alternateMobile = document.getElementById("alternate_mobile");

        if (form) {
            form.addEventListener("submit", function (event) {
                const hasPhone = mobile.value.trim() !== "" || alternateMobile.value.trim() !== "";
                if (!hasPhone) {
                    event.preventDefault();
                    mobile.setCustomValidity("Enter a mobile number or an alternate mobile number.");
                    mobile.reportValidity();
                } else {
                    mobile.setCustomValidity("");
                }
            });
            [mobile, alternateMobile].forEach(function (field) {
                field.addEventListener("input", function () {
                    mobile.setCustomValidity("");
                });
            });
        }

        const gstin =
            document.getElementById(
                "gstin"
            );

        if (gstin) {

            gstin.addEventListener(
                "input",
                function () {

                    this.value =
                        this.value
                            .toUpperCase()
                            .replace(
                                /[^A-Z0-9]/g,
                                ""
                            )
                            .slice(
                                0,
                                15
                            );
                }
            );
        }

    }
);

</script>


<?php
include("../includes/footer.php");
?>
