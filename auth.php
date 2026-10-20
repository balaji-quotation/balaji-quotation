<?php

// =====================================================
// SECURE SESSION SETTINGS
// =====================================================

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax'
]);

session_start();


// =====================================================
// DATABASE
// =====================================================

require_once __DIR__ . "/includes/db.php";


// =====================================================
// ONLY POST REQUEST
// =====================================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: login.php");
    exit;
}


// =====================================================
// FORM DATA
// =====================================================

$email = strtolower(trim($_POST['email'] ?? ''));
$password = (string)($_POST['password'] ?? '');


// =====================================================
// BASIC VALIDATION
// =====================================================

if (
    $email === '' ||
    $password === '' ||
    !filter_var($email, FILTER_VALIDATE_EMAIL)
) {
    header("Location: login.php?error=1");
    exit;
}


// =====================================================
// CLIENT IP
// =====================================================

$ip_address = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';


// =====================================================
// BRUTE FORCE SETTINGS
// =====================================================

$max_attempts = 5;
$lock_minutes = 15;


// =====================================================
// REMOVE OLD LOGIN ATTEMPTS
// =====================================================

$cleanup_stmt = mysqli_prepare(
    $conn,
    "
    DELETE FROM login_attempts
    WHERE attempt_time < DATE_SUB(NOW(), INTERVAL 15 MINUTE)
    "
);

if ($cleanup_stmt) {
    mysqli_stmt_execute($cleanup_stmt);
    mysqli_stmt_close($cleanup_stmt);
}


// =====================================================
// COUNT FAILED ATTEMPTS
// Email + IP combination
// =====================================================

$attempt_stmt = mysqli_prepare(
    $conn,
    "
    SELECT COUNT(*) AS total_attempts
    FROM login_attempts
    WHERE email = ?
      AND ip_address = ?
      AND attempt_time >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
    "
);

if (!$attempt_stmt) {

    error_log(
        "ERP Login Attempt Check Error: " .
        mysqli_error($conn)
    );

    header("Location: login.php?error=1");
    exit;
}

mysqli_stmt_bind_param(
    $attempt_stmt,
    "ss",
    $email,
    $ip_address
);

mysqli_stmt_execute($attempt_stmt);

$attempt_result =
    mysqli_stmt_get_result($attempt_stmt);

$attempt_data =
    mysqli_fetch_assoc($attempt_result);

mysqli_stmt_close($attempt_stmt);

$current_attempts =
    intval($attempt_data['total_attempts'] ?? 0);


// =====================================================
// ACCOUNT TEMPORARILY LOCKED
// =====================================================

if ($current_attempts >= $max_attempts) {

    header(
        "Location: login.php?error=locked"
    );

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
        password,
        role,
        status
    FROM users
    WHERE LOWER(email) = ?
    LIMIT 1
    "
);

if (!$stmt) {

    error_log(
        "ERP Login Prepare Error: " .
        mysqli_error($conn)
    );

    header("Location: login.php?error=1");
    exit;
}

mysqli_stmt_bind_param(
    $stmt,
    "s",
    $email
);

mysqli_stmt_execute($stmt);

$result =
    mysqli_stmt_get_result($stmt);

$user =
    mysqli_fetch_assoc($result);

mysqli_stmt_close($stmt);


// =====================================================
// FUNCTION: RECORD FAILED ATTEMPT
// =====================================================

function recordFailedLogin(
    $conn,
    $email,
    $ip_address
) {

    $fail_stmt = mysqli_prepare(
        $conn,
        "
        INSERT INTO login_attempts
            (email, ip_address, attempt_time)
        VALUES
            (?, ?, NOW())
        "
    );

    if ($fail_stmt) {

        mysqli_stmt_bind_param(
            $fail_stmt,
            "ss",
            $email,
            $ip_address
        );

        mysqli_stmt_execute($fail_stmt);
        mysqli_stmt_close($fail_stmt);
    }
}


// =====================================================
// USER NOT FOUND
// =====================================================

if (!$user) {

    recordFailedLogin(
        $conn,
        $email,
        $ip_address
    );

    $current_attempts++;

    if ($current_attempts >= $max_attempts) {
        header("Location: login.php?error=locked");
    } else {
        header("Location: login.php?error=1");
    }

    exit;
}


// =====================================================
// USER STATUS
// =====================================================

$status =
    trim($user['status'] ?? '');

if (
    strcasecmp(
        $status,
        'Active'
    ) !== 0
) {

    recordFailedLogin(
        $conn,
        $email,
        $ip_address
    );

    $current_attempts++;

    if ($current_attempts >= $max_attempts) {
        header("Location: login.php?error=locked");
    } else {
        header("Location: login.php?error=1");
    }

    exit;
}


// =====================================================
// PASSWORD CHECK
// =====================================================

$stored_password =
    (string)($user['password'] ?? '');

$password_ok = false;

$info =
    password_get_info($stored_password);


// =====================================================
// HASHED PASSWORD
// =====================================================

if (!empty($info['algo'])) {

    $password_ok =
        password_verify(
            $password,
            $stored_password
        );


// =====================================================
// LEGACY PLAIN PASSWORD
// Convert automatically to secure hash
// =====================================================

} else {

    $password_ok =
        hash_equals(
            $stored_password,
            $password
        );

    if ($password_ok) {

        $new_hash =
            password_hash(
                $password,
                PASSWORD_DEFAULT
            );

        if ($new_hash !== false) {

            $update_stmt =
                mysqli_prepare(
                    $conn,
                    "
                    UPDATE users
                    SET password = ?
                    WHERE id = ?
                    LIMIT 1
                    "
                );

            if ($update_stmt) {

                $uid =
                    intval($user['id']);

                mysqli_stmt_bind_param(
                    $update_stmt,
                    "si",
                    $new_hash,
                    $uid
                );

                mysqli_stmt_execute(
                    $update_stmt
                );

                mysqli_stmt_close(
                    $update_stmt
                );
            }
        }
    }
}


// =====================================================
// WRONG PASSWORD
// =====================================================

if (!$password_ok) {

    recordFailedLogin(
        $conn,
        $email,
        $ip_address
    );

    $current_attempts++;

    if ($current_attempts >= $max_attempts) {

        header(
            "Location: login.php?error=locked"
        );

    } else {

        header(
            "Location: login.php?error=1"
        );
    }

    exit;
}


// =====================================================
// PASSWORD REHASH
// =====================================================

if (
    !empty($info['algo']) &&
    password_needs_rehash(
        $stored_password,
        PASSWORD_DEFAULT
    )
) {

    $new_hash =
        password_hash(
            $password,
            PASSWORD_DEFAULT
        );

    if ($new_hash !== false) {

        $rehash_stmt =
            mysqli_prepare(
                $conn,
                "
                UPDATE users
                SET password = ?
                WHERE id = ?
                LIMIT 1
                "
            );

        if ($rehash_stmt) {

            $uid =
                intval($user['id']);

            mysqli_stmt_bind_param(
                $rehash_stmt,
                "si",
                $new_hash,
                $uid
            );

            mysqli_stmt_execute(
                $rehash_stmt
            );

            mysqli_stmt_close(
                $rehash_stmt
            );
        }
    }
}


// =====================================================
// LOGIN SUCCESS
// CLEAR FAILED ATTEMPTS
// =====================================================

$clear_stmt =
    mysqli_prepare(
        $conn,
        "
        DELETE FROM login_attempts
        WHERE email = ?
          AND ip_address = ?
        "
    );

if ($clear_stmt) {

    mysqli_stmt_bind_param(
        $clear_stmt,
        "ss",
        $email,
        $ip_address
    );

    mysqli_stmt_execute(
        $clear_stmt
    );

    mysqli_stmt_close(
        $clear_stmt
    );
}


// =====================================================
// NEW SESSION ID
// =====================================================

session_regenerate_id(true);


// =====================================================
// SESSION DATA
// =====================================================

$_SESSION['user_id'] =
    intval($user['id']);

$_SESSION['user_name'] =
    $user['name'] ?? 'User';

$_SESSION['user_email'] =
    $user['email'] ?? '';

$_SESSION['user_role'] =
    $user['role'] ?? 'User';

$_SESSION['login_time'] =
    time();


// =====================================================
// REDIRECT
// =====================================================

header("Location: quotations/index.php");
exit;
?>