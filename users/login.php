<?php
session_start();

if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}

$error = '';

if (isset($_GET['error'])) {
    $code = intval($_GET['error']);

    if ($code === 1) {
        $error = 'Invalid Email or Password.';
    } elseif ($code === 2) {
        $error = 'Your account is inactive. Please contact Administrator.';
    } elseif ($code === 3) {
        $error = 'Please enter Email and Password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Login - Product Tracking ERP</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="/erp/dist/assets/css/app.min.css" rel="stylesheet" type="text/css">
    <link href="/erp/dist/assets/css/icons.min.css" rel="stylesheet" type="text/css">
    <style>
        body{min-height:100vh;margin:0;background:#f4f7fb;display:flex;align-items:center;justify-content:center;font-family:Arial,sans-serif}
        .login-card{width:100%;max-width:445px;background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 8px 30px rgba(31,45,61,.10);border:1px solid #e5e9ef}
        .login-head{background:#1f73f1;color:#fff;text-align:center;font-size:28px;font-weight:700;padding:18px 20px}
        .login-body{padding:22px 18px 18px}
        .form-label{font-size:15px;font-weight:500;margin-bottom:6px}
        .form-control{height:42px}
        .login-error{background:#fde8e8;border:1px solid #f5bcbc;color:#842029;border-radius:7px;padding:10px 12px;margin-bottom:15px;font-size:13px}
        .btn-login{height:42px;width:100%;background:#1f73f1;border:0;color:#fff;border-radius:6px;font-size:16px}
    </style>
</head>
<body>
<div class="login-card">
    <div class="login-head">Product Tracking ERP</div>
    <div class="login-body">
        <?php if ($error !== '') { ?>
            <div class="login-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php } ?>

        <form action="auth.php" method="POST" autocomplete="off">
            <div class="mb-3">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" required autofocus autocomplete="username">
            </div>

            <div class="mb-3">
                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-control" required autocomplete="current-password">
            </div>

            <button type="submit" class="btn-login">Login</button>
        </form>
    </div>
</div>
</body>
</html>
