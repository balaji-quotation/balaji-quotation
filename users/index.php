<?php
session_start();


if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}


require_once("../includes/permissions.php");

// Only Administrator can open Users Master directly.
erpRequire('users.view');

include("../includes/db.php");
include("../includes/header.php");
include("../includes/sidebar.php");


// =====================================================
// USERS TABLE COLUMNS
// This keeps the page compatible with the current DB
// even if optional fields are not available yet.
// =====================================================

$user_columns = [];

$columns_result = mysqli_query(
    $conn,
    "SHOW COLUMNS FROM users"
);

if (!$columns_result) {
    die(
        "Users Table Error: " .
        mysqli_error($conn)
    );
}

while (
    $column = mysqli_fetch_assoc(
        $columns_result
    )
) {
    $user_columns[] = $column['Field'];
}


function userHasColumn($columns, $column)
{
    return in_array(
        $column,
        $columns,
        true
    );
}


function userFirstColumn($columns, $choices)
{
    foreach ($choices as $choice) {
        if (
            in_array(
                $choice,
                $columns,
                true
            )
        ) {
            return $choice;
        }
    }

    return null;
}


// Flexible mapping for current / future users table.
$name_column = userFirstColumn(
    $user_columns,
    [
        'name',
        'full_name',
        'user_name',
        'display_name'
    ]
);

$email_column = userFirstColumn(
    $user_columns,
    [
        'email',
        'email_id'
    ]
);

$role_column = userFirstColumn(
    $user_columns,
    [
        'role',
        'user_role',
        'type'
    ]
);

$status_column = userFirstColumn(
    $user_columns,
    [
        'status',
        'is_active'
    ]
);

$last_login_column = userFirstColumn(
    $user_columns,
    [
        'last_login',
        'last_login_at'
    ]
);

$created_column = userFirstColumn(
    $user_columns,
    [
        'created_at',
        'created_date'
    ]
);


// =====================================================
// GET USERS
// =====================================================

$order_column =
    userHasColumn(
        $user_columns,
        'id'
    )
        ? 'id'
        : $user_columns[0];

$sql = "
    SELECT *
    FROM users
    ORDER BY `$order_column` DESC
";

$result = mysqli_query(
    $conn,
    $sql
);

if (!$result) {
    die(
        "Users Database Error: " .
        mysqli_error($conn)
    );
}


// =====================================================
// HELPERS
// =====================================================

function usersDate($date)
{
    if (
        empty($date) ||
        $date === '0000-00-00' ||
        $date === '0000-00-00 00:00:00'
    ) {
        return '-';
    }

    $time = strtotime($date);

    if (!$time) {
        return '-';
    }

    return date(
        'd-m-Y h:i A',
        $time
    );
}


function usersStatusBadge($value)
{
    $value = trim(
        (string)$value
    );

    if (
        $value === '1' ||
        strcasecmp(
            $value,
            'Active'
        ) === 0
    ) {
        return '<span class="badge bg-success">Active</span>';
    }

    if (
        $value === '0' ||
        strcasecmp(
            $value,
            'Inactive'
        ) === 0
    ) {
        return '<span class="badge bg-danger">Inactive</span>';
    }

    if ($value === '') {
        return '<span class="badge bg-secondary">-</span>';
    }

    return '<span class="badge bg-secondary">' .
        htmlspecialchars($value) .
        '</span>';
}

?>

<style>

.users-card {
    border: 0;
    border-radius: 14px;
    box-shadow: 0 6px 24px rgba(31,45,61,.06);
}

.users-page-title {
    font-weight: 700;
    color: #26374a;
    margin-bottom: 2px;
}

.users-page-subtitle {
    font-size: 11px;
    color: #7b8794;
}

.users-table {
    font-size: 11px;
    min-width: 1050px;
    margin-bottom: 0;
}

.users-table thead th {
    background: #4a5a6b !important;
    color: #fff !important;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .25px;
    vertical-align: middle;
    white-space: nowrap;
    padding: 12px 10px;
    border-color: #657687 !important;
}

.users-table tbody td {
    vertical-align: middle;
    padding: 10px;
    border-color: #edf0f4;
    color: #2d3b4d;
}

.users-table tbody tr:hover td {
    background: #f8fafc;
}

.user-name {
    font-weight: 700;
    color: #26374a;
}

.user-sub {
    margin-top: 2px;
    font-size: 10px;
    color: #7b8794;
}

.user-role {
    font-weight: 600;
    color: #34465a;
}

.user-actions {
    display: flex;
    gap: 5px;
    flex-wrap: wrap;
}

.user-actions .btn {
    padding: 4px 8px;
    font-size: 10px;
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

<div class="card users-card">
<div class="card-body py-2 px-3">

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2">

    <div>

        <h4 class="users-page-title">
            Users Master
        </h4>

        <div class="users-page-subtitle">
            ERP login users, roles and account status
        </div>

    </div>

    <a
        href="add.php"
        class="btn btn-primary btn-sm"
    >
        <i class="mdi mdi-plus me-1"></i>
        Add User
    </a>

</div>

</div>
</div>

</div>
</div>


<!-- =====================================================
     ALERT MESSAGES
===================================================== -->

<?php if (
    isset($_GET['msg']) &&
    $_GET['msg'] === 'added'
) { ?>

<div class="alert alert-success alert-dismissible fade show" role="alert">
    <strong>Success!</strong>
    User added successfully.

    <button
        type="button"
        class="btn-close"
        data-bs-dismiss="alert"
    ></button>
</div>

<?php } ?>


<?php if (
    isset($_GET['msg']) &&
    $_GET['msg'] === 'updated'
) { ?>

<div class="alert alert-success alert-dismissible fade show" role="alert">
    <strong>Success!</strong>
    User updated successfully.

    <button
        type="button"
        class="btn-close"
        data-bs-dismiss="alert"
    ></button>
</div>

<?php } ?>


<?php if (
    isset($_GET['msg']) &&
    $_GET['msg'] === 'status'
) { ?>

<div class="alert alert-success alert-dismissible fade show" role="alert">
    <strong>Success!</strong>
    User status updated successfully.

    <button
        type="button"
        class="btn-close"
        data-bs-dismiss="alert"
    ></button>
</div>

<?php } ?>


<!-- =====================================================
     USERS TABLE
===================================================== -->

<div class="card users-card">
<div class="card-body p-0">

<div class="table-responsive">

<table class="table table-bordered table-hover align-middle users-table">

<thead>
<tr>
    <th style="width:65px;">ID</th>
    <th style="min-width:170px;">Name</th>
    <th style="min-width:220px;">Email / Login ID</th>
    <th style="min-width:120px;">Role</th>
    <th style="width:100px;">Status</th>
    <th style="min-width:145px;">Last Login</th>
    <th style="min-width:145px;">Created</th>
    <th style="width:170px;">Action</th>
</tr>
</thead>

<tbody>

<?php

$users_found = false;

while (
    $user = mysqli_fetch_assoc(
        $result
    )
) {

    $users_found = true;

    $user_id =
        isset($user['id'])
            ? intval($user['id'])
            : 0;

    $name_value =
        $name_column !== null
            ? trim(
                (string)(
                    $user[$name_column] ?? ''
                )
            )
            : '';

    $email_value =
        $email_column !== null
            ? trim(
                (string)(
                    $user[$email_column] ?? ''
                )
            )
            : '';

    $role_value =
        $role_column !== null
            ? trim(
                (string)(
                    $user[$role_column] ?? ''
                )
            )
            : '';

    $status_value =
        $status_column !== null
            ? $user[$status_column]
            : 'Active';

    $last_login_value =
        $last_login_column !== null
            ? $user[$last_login_column]
            : null;

    $created_value =
        $created_column !== null
            ? $user[$created_column]
            : null;

?>

<tr>

<td>
    <?= $user_id > 0
        ? $user_id
        : '-';
    ?>
</td>


<td>

    <div class="user-name">
        <?= $name_value !== ''
            ? htmlspecialchars(
                $name_value
            )
            : '-';
        ?>
    </div>

</td>


<td>
    <?= $email_value !== ''
        ? htmlspecialchars(
            $email_value
        )
        : '<span class="text-muted">-</span>';
    ?>
</td>


<td>
    <span class="user-role">
        <?= $role_value !== ''
            ? htmlspecialchars(
                $role_value
            )
            : '-';
        ?>
    </span>
</td>


<td>
    <?= usersStatusBadge(
        $status_value
    ); ?>
</td>


<td>
    <?= usersDate(
        $last_login_value
    ); ?>
</td>


<td>
    <?= usersDate(
        $created_value
    ); ?>
</td>


<td>

<div class="user-actions">

    <?php if ($user_id > 0) { ?>

        <a
            href="edit.php?id=<?= $user_id; ?>"
            class="btn btn-warning btn-sm"
        >
            <i class="mdi mdi-pencil-outline me-1"></i>
            Edit
        </a>

        <?php if ($status_column !== null) { ?>

            <a
                href="status.php?id=<?= $user_id; ?>"
                class="btn btn-outline-secondary btn-sm"
            >
                <i class="mdi mdi-account-switch-outline me-1"></i>
                Status
            </a>

        <?php } ?>

    <?php } else { ?>

        <span class="text-muted">
            -
        </span>

    <?php } ?>

</div>

</td>

</tr>

<?php } ?>


<?php if (!$users_found) { ?>

<tr>
<td
    colspan="8"
    class="text-center text-muted py-5"
>
    <i class="mdi mdi-account-group-outline fs-2 d-block mb-2"></i>
    No users found.
</td>
</tr>

<?php } ?>

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
