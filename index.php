<?php
session_start();

// Already logged in → Dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: quotations/index.php");
    exit;
}

// Not logged in → Login
header("Location: login.php");
exit;