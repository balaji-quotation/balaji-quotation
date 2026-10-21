<?php

// =====================================================
// SESSION START
// =====================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


// =====================================================
// LOGIN SECURITY
// =====================================================

if (empty($_SESSION['user_id'])) {
    header("Location: /quotation/login.php");
    exit;
}


// =====================================================
// 30 MINUTE INACTIVITY TIMEOUT
// =====================================================

$session_timeout = 30 * 60; // 30 minutes

if (
    isset($_SESSION['last_activity']) &&
    (time() - $_SESSION['last_activity']) > $session_timeout
) {

    // Clear all session data
    $_SESSION = [];

    // Delete session cookie
    if (ini_get("session.use_cookies")) {

        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    // Destroy session
    session_destroy();

    // Redirect to login
    header("Location: /quotation/login.php?error=timeout");
    exit;
}


// =====================================================
// UPDATE LAST ACTIVITY
// =====================================================

$_SESSION['last_activity'] = time();


// =====================================================
// BASE URL
// =====================================================

$base_url = "/quotation/dist/";


// =====================================================
// LOGGED IN USER
// =====================================================

$logged_user_name = 'Admin';

if (!empty($_SESSION['name'])) {

    $logged_user_name = $_SESSION['name'];

} elseif (!empty($_SESSION['username'])) {

    $logged_user_name = $_SESSION['username'];

} elseif (!empty($_SESSION['user_name'])) {

    $logged_user_name = $_SESSION['user_name'];
}

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="utf-8" />

    <title>Quotation SaaS | Balaji Webtech</title>

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <meta name="description"
          content="Balaji Webtech Quotation SaaS">

    <meta name="author"
          content="Balaji Webtech">

    <meta http-equiv="X-UA-Compatible"
          content="IE=edge" />


    <!-- Favicon -->
    <link rel="shortcut icon"
          href="/quotation/dist/assets/images/logo-dark.png">


    <!-- Icons CSS -->
    <link href="/quotation/dist/assets/css/icons.min.css"
          rel="stylesheet"
          type="text/css">


    <!-- App CSS -->
    <link href="/quotation/dist/assets/css/app.min.css"
          rel="stylesheet"
          type="text/css">


    <style>

    /* =========================================================
       TOPBAR FIX
    ========================================================= */

    .topbar-custom {
        min-height: 28px;
        display: flex;
        align-items: center;
        background: #ffffff;
        border-bottom: 1px solid #edf0f3;

        left: 210px !important;
        width: calc(100% - 210px) !important;

        padding-left: 0 !important;
        margin-left: 0 !important;
    }


    .topbar-custom > .container-fluid {
        width: 100%;
        max-width: none !important;

        margin-left: 0 !important;
        margin-right: 0 !important;

        padding-left: 10px !important;
        padding-right: 18px !important;
    }


    .topbar-inner {
        min-height: 28px;
        width: 100%;

        display: flex;
        align-items: center;
        justify-content: space-between;

        gap: 18px;

        margin: 0 !important;
        padding: 0 !important;
    }


    .topbar-left {
        min-width: 0;

        flex: 1 1 auto;

        display: flex;
        align-items: center;

        gap: 8px;

        margin: 0 !important;
        padding: 0 !important;
    }


    /* =========================================================
       MENU BUTTON
    ========================================================= */

    .button-toggle-menu {
        width: 30px;
        height: 30px;

        flex: 0 0 30px;

        display: inline-flex;
        align-items: center;
        justify-content: center;

        padding: 0 !important;

        margin: 0 !important;
        margin-left: 0 !important;

        border: 0;

        background: transparent;

        border-radius: 8px;

        color: #435466;
    }


    .button-toggle-menu:hover {
        background: #f5f7f9;
    }


    .button-toggle-menu .noti-icon {
        width: 22px;
        height: 22px;
    }


    /* =========================================================
       GLOBAL SEARCH
    ========================================================= */

    .global-search-form {
        flex: 1 1 auto;

        width: 100%;

        max-width: 620px;
        min-width: 260px;

        margin: 0;
    }


    .global-search-form .input-group {
        width: 100%;
        height: 30px;

        flex-wrap: nowrap;
    }


    .global-search-form .input-group-text {
        width: 46px;

        padding: 0;

        display: flex;
        align-items: center;
        justify-content: center;

        border: 1px solid #e5eaf0 !important;
        border-right: 0 !important;

        background: #f5f7fa !important;

        border-radius: 9px 0 0 9px !important;

        color: #617184;
    }


    .global-search-form .form-control {
        height: 30px;

        padding: 0 14px 0 4px;

        border: 1px solid #e5eaf0 !important;
        border-left: 0 !important;

        background: #f5f7fa !important;

        border-radius: 0 9px 9px 0 !important;

        box-shadow: none !important;

        color: #344357;

        font-size: 14px;
    }


    .global-search-form .form-control::placeholder {
        color: #a4afbb;
        opacity: 1;
    }


    .global-search-form .form-control:focus,
    .global-search-form .form-control:focus + * {
        box-shadow: none !important;
    }


    .global-search-form .input-group:focus-within .input-group-text,
    .global-search-form .input-group:focus-within .form-control {

        border-color: #b9d3f6 !important;

        background: #ffffff !important;
    }


    /* =========================================================
       TOPBAR RIGHT
    ========================================================= */

    .topbar-right {
        flex: 0 0 auto;
        margin-left: auto;
    }


    .topnav-menu {
        margin: 0 !important;
        padding: 0 !important;
    }


    .topbar-dropdown {
        margin: 0 !important;
    }


    /* =========================================================
       USER
    ========================================================= */

    .nav-user {
        min-height: 28px;

        padding: 4px 8px !important;

        border-radius: 10px;

        white-space: nowrap;
    }


    .nav-user:hover {
        background: #f7f9fb;
    }


    .nav-user .avatar-sm {
        width: 28px;
        height: 28px;

        flex: 0 0 28px;
    }


    .nav-user .avatar-title {
        width: 28px;
        height: 28px;
    }


    /* =========================================================
       SESSION TIMEOUT COUNTDOWN
    ========================================================= */

    .session-timeout-countdown {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        margin-right: 8px;
        padding: 5px 9px;
        border: 1px solid #e5eaf0;
        border-radius: 8px;
        background: #f8fafc;
        color: #435466;
        font-size: 12px;
        font-weight: 700;
        line-height: 1;
        white-space: nowrap;
    }

    .session-timeout-countdown.warning {
        border-color: #f6d88b;
        background: #fff9e8;
        color: #8a6200;
    }

    .session-timeout-countdown.danger {
        border-color: #f3b7bd;
        background: #fff5f5;
        color: #b4232f;
    }

    .session-timeout-countdown .session-countdown-time {
        min-width: 38px;
        text-align: center;
        font-variant-numeric: tabular-nums;
    }

    @media (max-width: 767.98px) {
        .session-timeout-countdown {
            margin-right: 3px;
            padding: 5px 6px;
            font-size: 11px;
        }

        .session-timeout-countdown .session-countdown-label {
            display: none;
        }
    }


    .pro-user-name {

        display: inline-flex;

        align-items: center;

        gap: 3px;

        font-size: 14px;

        color: #435466;

        line-height: 1;
    }


    /* =========================================================
       RESPONSIVE
    ========================================================= */

    @media (max-width: 991.98px) {

        .global-search-form {
            max-width: 480px;
        }

    }


    @media (max-width: 767.98px) {

        .topbar-custom {

            left: 0 !important;

            width: 100% !important;
        }


        .topbar-custom,
        .topbar-inner {

            min-height: 64px;
        }


        .topbar-custom > .container-fluid {

            padding-left: 6px !important;

            padding-right: 10px !important;
        }


        .topbar-left {

            flex: 0 0 auto;
        }


        .global-search-form {

            display: none !important;
        }


        .pro-user-name {

            display: none;
        }


        .nav-user {

            padding: 2px 4px !important;
        }

    }

    </style>

    <script>
    function erpToggleMobileSidebar(event) {
        if (window.innerWidth > 991.98) {
            return true;
        }

        if (event) {
            event.preventDefault();
            event.stopPropagation();
            if (typeof event.stopImmediatePropagation === 'function') {
                event.stopImmediatePropagation();
            }
        }

        document.body.classList.toggle('erp-mobile-sidebar-open');
        return false;
    }

    document.addEventListener('DOMContentLoaded', function () {
        var backdrop = document.querySelector('.erp-mobile-sidebar-backdrop');
        var sidebar = document.querySelector('.app-sidebar-menu');

        if (backdrop) {
            backdrop.addEventListener('click', function () {
                document.body.classList.remove('erp-mobile-sidebar-open');
            });
        }

        if (sidebar) {
            sidebar.querySelectorAll('a').forEach(function (link) {
                link.addEventListener('click', function () {
                    if (window.innerWidth <= 991.98) {
                        document.body.classList.remove('erp-mobile-sidebar-open');
                    }
                });
            });
        }

        window.addEventListener('resize', function () {
            if (window.innerWidth > 991.98) {
                document.body.classList.remove('erp-mobile-sidebar-open');
            }
        });
    });


    // =========================================================
    // SESSION TIMEOUT COUNTDOWN + ACTIVITY RESET
    // =========================================================
    document.addEventListener('DOMContentLoaded', function () {

        var countdownBox = document.getElementById('session_timeout_countdown');
        var countdownTime = document.getElementById('session_countdown_time');

        if (!countdownBox || !countdownTime) {
            return;
        }

        var timeoutSeconds = 30 * 60;
        var remainingSeconds = timeoutSeconds;
        var activityRequestInProgress = false;
        var activityResetQueued = false;

        function formatTime(seconds) {
            seconds = Math.max(0, Math.floor(seconds));
            var minutes = Math.floor(seconds / 60);
            var secs = seconds % 60;
            return String(minutes).padStart(2, '0') + ':' + String(secs).padStart(2, '0');
        }

        function renderCountdown() {
            countdownTime.textContent = formatTime(remainingSeconds);

            countdownBox.classList.remove('warning', 'danger');

            if (remainingSeconds <= 60) {
                countdownBox.classList.add('danger');
            } else if (remainingSeconds <= 5 * 60) {
                countdownBox.classList.add('warning');
            }
        }

        function resetCountdown() {
            remainingSeconds = timeoutSeconds;
            renderCountdown();
        }

        function pingServerActivity() {
            if (activityRequestInProgress) {
                activityResetQueued = true;
                return;
            }

            activityRequestInProgress = true;

            fetch('/quotation/session_activity.php', {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: 'activity=1'
            })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Session activity request failed');
                }
                return response.json();
            })
            .then(function (data) {
                if (data && data.active === true) {
                    resetCountdown();
                }
            })
            .catch(function () {
                // Keep the local countdown running if the activity ping fails.
            })
            .finally(function () {
                activityRequestInProgress = false;

                if (activityResetQueued) {
                    activityResetQueued = false;
                    pingServerActivity();
                }
            });
        }

        function registerActivity() {
            resetCountdown();
            pingServerActivity();
        }

        renderCountdown();

        // Any click counts as activity.
        document.addEventListener('click', registerActivity, true);

        // Also reset for keyboard/touch interaction, useful for forms and mobile.
        document.addEventListener('keydown', registerActivity, true);
        document.addEventListener('touchstart', registerActivity, { passive: true, capture: true });

        setInterval(function () {
            remainingSeconds--;
            renderCountdown();

            if (remainingSeconds <= 0) {
                window.location.href = '/quotation/login.php?error=timeout';
            }
        }, 1000);

    });
    </script>

</head>


<body data-menu-color="dark"
      data-sidebar="default">


<div id="app-layout">


<!-- =========================================================
     TOPBAR START
========================================================= -->

<div class="topbar-custom">

    <div class="container-fluid">

        <div class="topbar-inner">


            <!-- =====================================================
                 LEFT SIDE
            ====================================================== -->

            <div class="topbar-left">


                <!-- Sidebar Toggle -->

                <button
                    type="button"
                    class="button-toggle-menu nav-link"
                    aria-label="Toggle sidebar"
                    onclick="return erpToggleMobileSidebar(event);"
                >

                    <i
                        data-feather="menu"
                        class="noti-icon"
                    ></i>

                </button>


                <!-- Global Tracking Search -->

                <form
                    action="/quotation/search.php"
                    method="GET"
                    class="global-search-form d-none d-md-flex align-items-center"
                >

                    <div class="input-group">

                        <span class="input-group-text">

                            <i class="mdi mdi-magnify"></i>

                        </span>


                        <input
                            type="text"
                            name="q"
                            class="form-control"
                            placeholder="Search PO / Part / Product / Client..."
                            autocomplete="off"
                            required
                        >

                    </div>

                </form>

            </div>


            <!-- =====================================================
                 RIGHT SIDE
            ====================================================== -->

            <div class="topbar-right">

                <ul class="list-unstyled topnav-menu d-flex align-items-center">


                    <!-- SESSION TIMEOUT COUNTDOWN -->

                    <li class="d-flex align-items-center">

                        <span
                            id="session_timeout_countdown"
                            class="session-timeout-countdown"
                            title="Auto logout after 30 minutes of inactivity"
                            aria-live="polite"
                        >
                            <i class="mdi mdi-clock-outline"></i>
                            <span class="session-countdown-label">Session</span>
                            <span
                                id="session_countdown_time"
                                class="session-countdown-time"
                            >30:00</span>
                        </span>

                    </li>


                    <!-- USER DROPDOWN -->

                    <li class="dropdown notification-list topbar-dropdown">


                        <a
                            class="nav-link dropdown-toggle nav-user me-0 d-flex align-items-center"
                            data-bs-toggle="dropdown"
                            href="#"
                            role="button"
                            aria-haspopup="false"
                            aria-expanded="false"
                        >


                            <!-- User Icon -->

                            <span class="avatar-sm me-2">

                                <span
                                    class="avatar-title bg-primary-subtle text-primary rounded-circle"
                                >

                                    <i class="mdi mdi-account fs-20"></i>

                                </span>

                            </span>


                            <!-- User Name -->

                            <span class="pro-user-name">

                                <?= htmlspecialchars(
                                    $logged_user_name,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ); ?>

                                <i class="mdi mdi-chevron-down"></i>

                            </span>

                        </a>


                        <!-- DROPDOWN -->

                        <div
                            class="dropdown-menu dropdown-menu-end profile-dropdown"
                        >


                            <!-- Header -->

                            <div class="dropdown-header noti-title">

                                <h6 class="text-overflow m-0">

                                    Welcome,

                                    <?= htmlspecialchars(
                                        $logged_user_name,
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ); ?>

                                </h6>

                            </div>


                            <div class="dropdown-divider"></div>


                            <!-- Logout -->

                            <a
                                href="/quotation/logout.php"
                                class="dropdown-item notify-item"
                            >

                                <i class="mdi mdi-logout me-1"></i>

                                <span>Logout</span>

                            </a>


                        </div>

                    </li>

                </ul>

            </div>

        </div>

    </div>

</div>

<!-- =========================================================
     TOPBAR END
========================================================= -->