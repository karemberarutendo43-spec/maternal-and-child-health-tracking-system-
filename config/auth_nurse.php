<?php
require_once __DIR__ . "/session.php";
mch_session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'nurse') {
    header("Location: /mch_system/auth/nurse_login.php");
    exit;
}
