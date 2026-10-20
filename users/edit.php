<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

require_once("../includes/permissions.php");
erpRequire('users.edit');

include("../includes/db.php");
// =====================================================
// USER ID
// =====================================================

$id = intval($_GET['id'] ?? 0);

if ($id <= 0) {
    header("Location: index.php?msg=invalid");
    exit;
}

// =====================================================
// GET USER
// =====================================================

$stmt = mysqli_prepare(
    $conn,
    "
    SELECT
        id,
        name,
        email,
        role,
        status,
        created_at,
        updated_at
    FROM users
    WHERE id = ?
    LIMIT 1
    "
);

if (!$stmt) {
    die("Database Error: " . mysqli_error($conn));
}

mysqli_stmt_bind_param(
    $stmt,
    "i",
    $id
);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

$user = mysqli_fetch_assoc($result);

mysqli_stmt_close($stmt);

if (!$user) {
    header("Location: index.php?msg=notfound");
    exit;
}

include("../includes/header.php");
include("../includes/sidebar.php");

$error =
    $_SESSION['user_error']
    ?? '';

unset($_SESSION['user_error']);
?>

<style>

.user-form-card {
    border: 0;
    border-radius: 14px;
    box-shadow: 0 6px 24px rgba(31,45,61,.06);
}

.user-page-title {
    font-weight: 700;
    color: #26374a;
    margin-bottom: 2px;
}

.user-page-subtitle {
    font-size: 11px;
    color: #7b8794;
}

.user-form-label {
    font-size: 11px;
    font-weight: 700;
    color: #405066;
    margin-bottom: 5px;
}

.user-form-help {
    font-size: 10px;
    color: #7b8794;
    margin-top: 4px;
}

.user-required {
    color: #dc3545;
}

</style>

<div class="content-page">

<div class="content">

<div class="container-fluid">


<!-- =====================================================
     PAGE HEADER
===================================================== -->

<div class="row mt-2 mb-3">

<div class="col-12">

<div class="card user-form-card">

<div class="card-body py-2 px-3">

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2">

    <div>

        <h4 class="user-page-title">
            Edit User
        </h4>

        <div class="user-page-subtitle">
            Update ERP login user details
        </div>

    </div>

    <a
        href="index.php"
        class="btn btn-secondary btn-sm"
    >
        <i class="mdi mdi-arrow-left me-1"></i>
        Back to Users
    </a>

</div>

</div>

</div>

</div>

</div>


<!-- =====================================================
     ERROR MESSAGE
===================================================== -->

<?php if ($error !== '') { ?>

<div
    class="alert alert-danger alert-dismissible fade show"
    role="alert"
>

    <strong>
        Cannot Update User!
    </strong>

    <?= htmlspecialchars($error); ?>

    <button
        type="button"
        class="btn-close"
        data-bs-dismiss="alert"
    ></button>

</div>

<?php } ?>


<!-- =====================================================
     EDIT FORM
===================================================== -->

<div class="row">

<div class="col-xl-8 col-lg-10 col-12">

<div class="card user-form-card">

<div class="card-body p-4">

<form
    action="update.php"
    method="POST"
    autocomplete="off"
>

<input
    type="hidden"
    name="id"
    value="<?= intval($user['id']); ?>"
>


<div class="row">


<!-- FULL NAME -->

<div class="col-md-6 mb-3">

<label class="user-form-label">

    Full Name
    <span class="user-required">*</span>

</label>

<input
    type="text"
    name="name"
    class="form-control"
    value="<?= htmlspecialchars($user['name'] ?? ''); ?>"
    required
>

</div>


<!-- EMAIL / LOGIN ID -->

<div class="col-md-6 mb-3">

<label class="user-form-label">

    Email / Login ID
    <span class="user-required">*</span>

</label>

<input
    type="email"
    name="email"
    class="form-control"
    value="<?= htmlspecialchars($user['email'] ?? ''); ?>"
    required
>

<div class="user-form-help">
    This email is used as the ERP Login ID and must be unique.
</div>

</div>


<!-- ROLE -->

<div class="col-md-6 mb-3">

<label class="user-form-label">
    Role
</label>

<select
    name="role"
    class="form-select"
>

<?php

$roles = [
    'Administrator',
    'Manager',
    'Production',
    'Quality',
    'Dispatch',
    'Accounts',
    'User'
];

$current_role =
    trim(
        $user['role']
        ?? ''
    );

foreach ($roles as $role) {

?>

<option
    value="<?= htmlspecialchars($role); ?>"
    <?= (
        strcasecmp(
            $current_role,
            $role
        ) === 0
    )
        ? 'selected'
        : '';
    ?>
>
    <?= htmlspecialchars($role); ?>
</option>

<?php } ?>

</select>

</div>


<!-- STATUS -->

<div class="col-md-6 mb-3">

<label class="user-form-label">
    Status
</label>

<select
    name="status"
    class="form-select"
>

<option
    value="Active"
    <?= (
        strcasecmp(
            $user['status'] ?? '',
            'Active'
        ) === 0
    )
        ? 'selected'
        : '';
    ?>
>
    Active
</option>

<option
    value="Inactive"
    <?= (
        strcasecmp(
            $user['status'] ?? '',
            'Inactive'
        ) === 0
    )
        ? 'selected'
        : '';
    ?>
>
    Inactive
</option>

</select>

</div>


<!-- NEW PASSWORD -->

<div class="col-md-6 mb-3">

<label class="user-form-label">
    New Password
</label>

<input
    type="password"
    name="password"
    class="form-control"
    minlength="6"
    placeholder="Leave blank to keep current password"
>

<div class="user-form-help">
    Enter a new password only if you want to change it.
</div>

</div>


<!-- CONFIRM PASSWORD -->

<div class="col-md-6 mb-3">

<label class="user-form-label">
    Confirm New Password
</label>

<input
    type="password"
    name="confirm_password"
    class="form-control"
    minlength="6"
    placeholder="Enter new password again"
>

</div>


<!-- INFO -->

<div class="col-12 mb-3">

<div class="alert alert-light border mb-0">

    <div class="small">

        <strong>User ID:</strong>
        <?= intval($user['id']); ?>

        &nbsp; | &nbsp;

        <strong>Created:</strong>
        <?= !empty($user['created_at'])
            ? date(
                'd-m-Y h:i A',
                strtotime($user['created_at'])
            )
            : '-';
        ?>

    </div>

</div>

</div>


<!-- BUTTONS -->

<div class="col-12 mt-2">

<button
    type="submit"
    class="btn btn-success"
>
    <i class="mdi mdi-content-save-outline me-1"></i>
    Update User
</button>

<a
    href="index.php"
    class="btn btn-secondary ms-1"
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

</div>

</div>


<?php
include("../includes/footer.php");
?>
