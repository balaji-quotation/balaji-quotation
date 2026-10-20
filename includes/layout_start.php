<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: /quotation/login.php");
    exit;
}

include __DIR__ . "/header.php";
include __DIR__ . "/sidebar.php";
?>