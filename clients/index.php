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

require_once __DIR__ . "/../includes/db.php";

include("../includes/header.php");
include("../includes/sidebar.php");


// =====================================================
// GET CLIENTS
// =====================================================

$sql = "
    SELECT
        id,
        company_name,
        contact_person,
        gstin,
        mobile,
        email,
        address,
        status
    FROM clients
    ORDER BY id DESC
";

$result =
    mysqli_query(
        $conn,
        $sql
    );


if (!$result) {

    error_log(
        "Clients index query error: " .
        mysqli_error($conn)
    );

    die(
        "Unable to load clients."
    );
}

?>


<style>

.client-card {
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

.client-table {
    font-size: 11px;
    min-width: 1200px;
}

.client-table th {
    white-space: nowrap;
    background: #4a5a6b;
    color: #fff;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: .25px;
    vertical-align: middle;
}

.client-table td {
    vertical-align: middle;
    border-color: #edf0f4;
}

.client-name {
    font-weight: 700;
    color: #26374a;
}

.gstin-text {
    font-weight: 600;
    color: #34465a;
    white-space: nowrap;
}

.client-actions {
    display: flex;
    gap: 5px;
    flex-wrap: wrap;
    align-items: center;
}

.client-actions .btn {
    padding: 4px 8px;
    font-size: 10px;
}

.client-actions form {
    margin: 0;
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

<div class="card client-card">

<div class="card-body py-2 px-3">

<div
    class="
        d-flex
        justify-content-between
        align-items-center
        flex-wrap
        gap-2
    "
>

    <div>

        <h4 class="client-page-title mb-1">
            Clients Master
        </h4>

        <div class="client-page-subtitle">
            Client contact, GSTIN and address master
        </div>

    </div>


    <a
        href="add.php"
        class="btn btn-primary btn-sm"
    >
        <i class="mdi mdi-plus me-1"></i>
        Add Client
    </a>

</div>

</div>

</div>

</div>

</div>


<!-- =====================================================
     ALERT MESSAGES
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
        Cannot Delete Client!
    </strong>

    This client is linked with

    <strong>
        <?= intval(
            $_GET['count'] ?? 0
        ); ?>
    </strong>

    Client PO(s).

    Please remove or close the linked records first.

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

    Client deleted successfully.

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

    Client added successfully.

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

    Client updated successfully.

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

    Client not found.

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

    The client request was not valid.

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

    Client could not be deleted.

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


<!-- =====================================================
     CLIENT TABLE
===================================================== -->

<div class="card client-card">

<div class="card-body">


<div class="table-responsive">

<table
    class="
        table
        table-bordered
        table-hover
        align-middle
        mb-0
        client-table
    "
>


<thead>

<tr>

    <th style="width:60px;">
        ID
    </th>

    <th>
        Company Name
    </th>

    <th>
        Contact Person
    </th>

    <th>
        GSTIN
    </th>

    <th>
        Mobile
    </th>

    <th>
        Email
    </th>

    <th>
        Address
    </th>

    <th style="width:90px;">
        Status
    </th>

    <th style="width:170px;">
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

    while (
        $row =
        mysqli_fetch_assoc(
            $result
        )
    ) {

?>


<tr>


<!-- ID -->

<td>

    <?= intval(
        $row['id']
    ); ?>

</td>


<!-- COMPANY NAME -->

<td>

    <div class="client-name">

        <?= htmlspecialchars(
            $row['company_name'] ?? '',
            ENT_QUOTES,
            'UTF-8'
        ); ?>

    </div>

</td>


<!-- CONTACT PERSON -->

<td>

<?php

if (
    !empty(
        $row['contact_person']
    )
) {

    echo htmlspecialchars(
        $row['contact_person'],
        ENT_QUOTES,
        'UTF-8'
    );

} else {

    echo
        '<span class="text-muted">-</span>';
}

?>

</td>


<!-- GSTIN -->

<td>

<?php

if (
    !empty(
        $row['gstin']
    )
) {

?>

    <span class="gstin-text">

        <?= htmlspecialchars(
            $row['gstin'],
            ENT_QUOTES,
            'UTF-8'
        ); ?>

    </span>

<?php

} else {

    echo
        '<span class="text-muted">-</span>';
}

?>

</td>


<!-- MOBILE -->

<td>

<?php

if (
    !empty(
        $row['mobile']
    )
) {

    echo htmlspecialchars(
        $row['mobile'],
        ENT_QUOTES,
        'UTF-8'
    );

} else {

    echo
        '<span class="text-muted">-</span>';
}

?>

</td>


<!-- EMAIL -->

<td>

<?php

if (
    !empty(
        $row['email']
    )
) {

    echo htmlspecialchars(
        $row['email'],
        ENT_QUOTES,
        'UTF-8'
    );

} else {

    echo
        '<span class="text-muted">-</span>';
}

?>

</td>


<!-- ADDRESS -->

<td>

<?php

if (
    !empty(
        $row['address']
    )
) {

    echo nl2br(
        htmlspecialchars(
            $row['address'],
            ENT_QUOTES,
            'UTF-8'
        )
    );

} else {

    echo
        '<span class="text-muted">-</span>';
}

?>

</td>


<!-- STATUS -->

<td>

<?php

$row_status =
    trim(
        $row['status'] ?? ''
    );


if (
    $row_status ===
    'Active'
) {

?>

    <span class="badge bg-success">
        Active
    </span>

<?php

} elseif (
    $row_status ===
    'Inactive'
) {

?>

    <span class="badge bg-danger">
        Inactive
    </span>

<?php

} else {

?>

    <span class="badge bg-secondary">

        <?= htmlspecialchars(
            $row_status !== ''
                ? $row_status
                : '-',
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

<td>

<div class="client-actions">


    <!-- EDIT -->

    <a
        href="edit.php?id=<?= intval(
            $row['id']
        ); ?>"
        class="btn btn-warning btn-sm"
    >

        <i class="mdi mdi-pencil-outline me-1"></i>

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
                'Are you sure you want to delete this client?'
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
            class="btn btn-danger btn-sm"
        >

            <i class="mdi mdi-delete-outline me-1"></i>

            Delete

        </button>

    </form>


</div>

</td>


</tr>


<?php

    }

} else {

?>


<tr>

<td
    colspan="9"
    class="text-center text-muted py-5"
>

    <i
        class="
            mdi
            mdi-account-group-outline
            fs-2
            d-block
            mb-2
        "
    ></i>

    No clients found.

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


<?php

include("../includes/footer.php");

?>