<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";

// Route convenience: older links point to /immunizations/defaulters.php
// Actual pages live in the role portals.
$role = $_SESSION['role'] ?? '';
if ($role === 'admin') {
    header("Location: /mch_system/admin_portal/immunization_defaulters.php");
    exit;
}

if ($role === 'nurse') {
    header("Location: /mch_system/nurse_portal/immunization_defaulters.php");
    exit;
}

http_response_code(403);
echo "<h1>403 Forbidden</h1>";
echo "<p>You do not have permission to access this page.</p>";
exit;

