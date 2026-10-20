<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

include("../includes/header.php");
include("../includes/sidebar.php");

$error = $_SESSION['users_error'] ?? '';
$old   = $_SESSION['users_old'] ?? [];

unset($_SESSION['users_error'], $_SESSION['users_old']);

function oldUserValue($old, $key, $default = '')
{
    return htmlspecialchars(
        (string)($old[$key] ?? $default),
        ENT_QUOTES,
        'UTF-8'
    );
}
?>

<style>
.user-form-card {
    border: 0;
    border-radius: 14px;
    box-shadow: 0 6px 24px rgba(31,45,61,.06);
}

.user-form-title {
    font-weight: 700;
    color: #26374a;
    margin-bottom: 2px;
}

.user-form-subtitle {
    font-size: 11px;
    color: #7b8794;
}

.user-form-card .form-label {
    font-size: 11px;
    font-weight: 600;
    color: #405166;
    margin-bottom: 5px;
}

.user-form-card .form-control,
.user-form-card .form-select {
    font-size: 12px;
    min-height: 38px;
}

.user-form-note {
    font-size: 10px;
    color: #8793a1;
    margin-top: 4px;
}

.password-wrap {
    position: relative;
}

.password-toggle {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    border: 0;
    background: transparent;
    color: #7b8794;
    padding: 0;
    cursor: pointer;
}
</style>

<div class="content-page">
<div class="content">
<div class="container-fluid">

<div class="row mt-2 mb-2">
<div class="col-12">
<div class="card user-form-card">
<div class="card-body py-2 px-3">

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h4 class="user-form-title">Add User</h4>
        <div class="user-form-subtitle">
            Create a new ERP login user
        </div>
    </div>

    <a href="index.php" class="btn btn-secondary btn-sm">
        <i class="mdi mdi-arrow-left me-1"></i>
        Back to Users
    </a>
</div>

</div>
</div>
</div>
</div>

<?php if ($error !== '') { ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <strong>Cannot Save User!</strong>
    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php } ?>

<div class="row">
<div class="col-xl-9 col-lg-10 col-12">

<div class="card user-form-card">
<div class="card-body p-3 p-md-4">

<form action="save.php" method="POST" autocomplete="off">

<div class="row">

<div class="col-md-6 mb-3">
    <label class="form-label">
        Full Name <span class="text-danger">*</span>
    </label>
    <input
        type="text"
        name="name"
        class="form-control"
        value="<?= oldUserValue($old, 'name'); ?>"
        placeholder="Enter full name"
        maxlength="100"
        required
    >
</div>

<div class="col-md-6 mb-3">
    <label class="form-label">
        Email / Login ID <span class="text-danger">*</span>
    </label>
    <input
        type="email"
        name="email"
        class="form-control"
        value="<?= oldUserValue($old, 'email'); ?>"
        placeholder="name@example.com"
        maxlength="100"
        autocomplete="off"
        required
    >
    <div class="user-form-note">
        This email will be used as the ERP Login ID and must be unique.
    </div>
</div>

<div class="col-md-6 mb-3">
    <label class="form-label">Role</label>
    <select name="role" class="form-select">
        <?php
        $selected_role = $old['role'] ?? 'User';
        foreach (['Administrator', 'Manager', 'User'] as $role) {
        ?>
            <option
                value="<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?>"
                <?= $selected_role === $role ? 'selected' : ''; ?>
            >
                <?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?>
            </option>
        <?php } ?>
    </select>
</div>

<div class="col-md-6 mb-3">
    <label class="form-label">Status</label>
    <select name="status" class="form-select">
        <option
            value="Active"
            <?= (($old['status'] ?? 'Active') === 'Active') ? 'selected' : ''; ?>
        >
            Active
        </option>
        <option
            value="Inactive"
            <?= (($old['status'] ?? '') === 'Inactive') ? 'selected' : ''; ?>
        >
            Inactive
        </option>
    </select>
</div>

<div class="col-md-6 mb-3">
    <label class="form-label">
        Password <span class="text-danger">*</span>
    </label>
    <div class="password-wrap">
        <input
            type="password"
            name="password"
            id="password"
            class="form-control pe-5"
            minlength="6"
            placeholder="Minimum 6 characters"
            required
            autocomplete="new-password"
        >
        <button
            type="button"
            class="password-toggle"
            data-target="password"
            title="Show / Hide Password"
        >
            <i class="ri-eye-line"></i>
        </button>
    </div>
</div>

<div class="col-md-6 mb-3">
    <label class="form-label">
        Confirm Password <span class="text-danger">*</span>
    </label>
    <div class="password-wrap">
        <input
            type="password"
            name="confirm_password"
            id="confirm_password"
            class="form-control pe-5"
            minlength="6"
            placeholder="Enter password again"
            required
            autocomplete="new-password"
        >
        <button
            type="button"
            class="password-toggle"
            data-target="confirm_password"
            title="Show / Hide Password"
        >
            <i class="ri-eye-line"></i>
        </button>
    </div>
</div>

<div class="col-12 mt-2">
    <button type="submit" class="btn btn-success">
        <i class="mdi mdi-content-save-outline me-1"></i>
        Save User
    </button>

    <a href="index.php" class="btn btn-secondary ms-1">
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

<script>
document.querySelectorAll('.password-toggle').forEach(function (button) {
    button.addEventListener('click', function () {
        var targetId = this.getAttribute('data-target');
        var input = document.getElementById(targetId);
        var icon = this.querySelector('i');

        if (!input) {
            return;
        }

        if (input.type === 'password') {
            input.type = 'text';
            if (icon) {
                icon.className = 'ri-eye-off-line';
            }
        } else {
            input.type = 'password';
            if (icon) {
                icon.className = 'ri-eye-line';
            }
        }
    });
});
</script>

<?php include("../includes/footer.php"); ?>
