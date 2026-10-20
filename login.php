<?php

session_start();

if (isset($_SESSION['user_id'])) {
    $destination = strcasecmp((string)($_SESSION['user_role'] ?? ''), 'Super Admin') === 0
        ? 'superadmin/dashboard.php'
        : 'quotations/index.php';
    header("Location: " . $destination);
    exit;
}

$error = $_GET['error'] ?? '';

?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport"
      content="width=device-width, initial-scale=1.0">

<title>Login - Product Tracking ERP</title>

<link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
    rel="stylesheet"
>

<style>

*{
    box-sizing:border-box;
}

body{
    margin:0;
    min-height:100vh;

    display:flex;
    align-items:center;
    justify-content:center;

    background:
        linear-gradient(
            135deg,
            #eef4f8 0%,
            #f8fbfd 55%,
            #edf2f7 100%
        );

    font-family:
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        Roboto,
        Arial,
        sans-serif;
}


/* =====================================================
   WRAPPER
===================================================== */

.login-wrapper{
    width:100%;
    max-width:430px;
    padding:20px;
}


/* =====================================================
   CARD
===================================================== */

.login-card{

    background:#ffffff;

    border-radius:20px;

    box-shadow:
        0 18px 50px rgba(26,45,65,.12);

    overflow:hidden;

    border:1px solid rgba(0,0,0,.04);
}


/* =====================================================
   HEADER
===================================================== */

.login-header{

    background:#00263d;

    padding:28px 30px 24px;

    text-align:center;
}


.login-logo{

    max-width:180px;

    width:100%;

    height:auto;

    margin-bottom:18px;
}


.login-title{

    color:#ffffff;

    font-size:22px;

    font-weight:700;

    margin:0;
}


.login-subtitle{

    color:rgba(255,255,255,.70);

    font-size:12px;

    margin-top:5px;
}


/* =====================================================
   BODY
===================================================== */

.login-body{

    padding:30px;
}


/* =====================================================
   ALERT
===================================================== */

.login-alert{

    border-radius:10px;

    font-size:13px;

    padding:12px 14px;

    margin-bottom:20px;

    line-height:1.45;
}


/* =====================================================
   FORM
===================================================== */

.form-label{

    font-size:13px;

    font-weight:600;

    color:#34495e;

    margin-bottom:7px;
}


.form-control{

    height:48px;

    border-radius:10px;

    border:1px solid #dfe6ec;

    font-size:14px;

    padding:10px 13px;
}


.form-control:focus{

    border-color:#388ef7;

    box-shadow:
        0 0 0 3px rgba(56,142,247,.10);
}


/* =====================================================
   PASSWORD
===================================================== */

.password-wrap{

    position:relative;
}


.password-wrap input{

    padding-right:55px;
}


.password-toggle{

    position:absolute;

    right:13px;

    top:50%;

    transform:translateY(-50%);

    border:0;

    background:transparent;

    color:#758494;

    font-size:13px;

    cursor:pointer;

    padding:4px;
}


/* =====================================================
   LOGIN BUTTON
===================================================== */

.login-btn{

    height:48px;

    border-radius:10px;

    font-weight:600;

    font-size:15px;

    background:#1f7aec;

    border-color:#1f7aec;
}


.login-btn:hover{

    background:#176bd3;

    border-color:#176bd3;
}


/* =====================================================
   FOOTER
===================================================== */

.login-footer{

    text-align:center;

    color:#8996a3;

    font-size:11px;

    margin-top:22px;
}


/* =====================================================
   RESPONSIVE
===================================================== */

@media(max-width:576px){

    .login-wrapper{

        max-width:100%;

        padding:15px;
    }


    .login-body{

        padding:24px 20px;
    }


    .login-header{

        padding:25px 20px;
    }


    .login-logo{

        max-width:155px;
    }

}

</style>

</head>


<body>


<div class="login-wrapper">


<div class="login-card">


    <!-- =====================================================
         HEADER
    ====================================================== -->

    <div class="login-header">


        <img
            src="/quotation/dist/assets/images/unicornlogodark.png"
            class="login-logo"
            alt="Unicorn Technoflex"
            onerror="
                this.onerror=null;
                this.src='/quotation/dist/assets/images/logo.png';
            "
        >


        <h1 class="login-title">

            Product Tracking ERP

        </h1>


        <div class="login-subtitle">

            Secure Production & Order Management

        </div>


    </div>



    <!-- =====================================================
         BODY
    ====================================================== -->

    <div class="login-body">


        <!-- =================================================
             SESSION TIMEOUT MESSAGE
        ================================================== -->

        <?php if ($error === 'timeout'): ?>


            <div
                class="alert alert-warning login-alert"
                role="alert"
            >

                <strong>Session expired.</strong>

                <br>

                Your session was inactive for 30 minutes.

                Please login again.

            </div>


        <!-- =================================================
             LOGIN LOCK MESSAGE
        ================================================== -->

        <?php elseif ($error === 'locked'): ?>


            <div
                class="alert alert-danger login-alert"
                role="alert"
            >

                <strong>
                    Login temporarily locked.
                </strong>

                <br>

                Too many failed login attempts.

                Please try again after 15 minutes.

            </div>


        <!-- =================================================
             INVALID LOGIN MESSAGE
        ================================================== -->

        <?php elseif ($error !== ''): ?>


            <div
                class="alert alert-danger login-alert"
                role="alert"
            >

                Invalid email or password.

            </div>


        <?php endif; ?>



        <!-- =================================================
             LOGIN FORM
        ================================================== -->

        <form
            action="auth.php"
            method="POST"
        >


            <!-- EMAIL -->

            <div class="mb-3">


                <label
                    for="email"
                    class="form-label"
                >

                    Email Address

                </label>


                <input
                    type="email"
                    id="email"
                    name="email"
                    class="form-control"
                    placeholder="Enter your email"
                    autocomplete="username"
                    required
                >


            </div>



            <!-- PASSWORD -->

            <div class="mb-4">


                <label
                    for="password"
                    class="form-label"
                >

                    Password

                </label>


                <div class="password-wrap">


                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="form-control"
                        placeholder="Enter your password"
                        autocomplete="current-password"
                        required
                    >


                    <button
                        type="button"
                        class="password-toggle"
                        id="togglePassword"
                    >

                        Show

                    </button>


                </div>


            </div>



            <!-- LOGIN BUTTON -->

            <button
                type="submit"
                class="btn btn-primary login-btn w-100"
            >

                Login to ERP

            </button>


        </form>



        <!-- =================================================
             FOOTER
        ================================================== -->

        <div class="login-footer">

            Unicorn Technoflex · Product Tracking ERP

        </div>


    </div>


</div>


</div>



<!-- =====================================================
     SHOW / HIDE PASSWORD
===================================================== -->

<script>

const togglePassword =
    document.getElementById(
        "togglePassword"
    );


const passwordInput =
    document.getElementById(
        "password"
    );


if (
    togglePassword &&
    passwordInput
) {

    togglePassword.addEventListener(
        "click",
        function () {

            const isPassword =
                passwordInput.type ===
                "password";


            passwordInput.type =
                isPassword
                    ? "text"
                    : "password";


            this.textContent =
                isPassword
                    ? "Hide"
                    : "Show";

        }
    );

}

</script>


</body>

</html>