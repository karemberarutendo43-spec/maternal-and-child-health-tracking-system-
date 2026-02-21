<?php
require_once __DIR__ . "/../config/session.php";
mch_session_start();
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/csrf.php";

// Admin only
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: /mch_system/auth/login.php");
    exit;
}

$currentAdminLevel = $_SESSION['admin_level'] ?? 'SUPER';
$currentClinicId = (int)($_SESSION['clinic_id'] ?? 0);
$isSuperAdmin = ($currentAdminLevel === 'SUPER');

$hasAdminLevelColumn = false;
try {
    $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'admin_level'")->fetch(PDO::FETCH_ASSOC);
    $hasAdminLevelColumn = !empty($col);
} catch (Exception $e) {
    $hasAdminLevelColumn = false;
}

require_once __DIR__ . "/create_user_handler.php";
$createUserFormAction = '/mch_system/admin_portal/create_user.php';

include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_admin.php";
?>

<div class="ml-64 p-6 max-w-3xl space-y-6">
    <?php include __DIR__ . "/partials/create_user_form.php"; ?>
</div>

<?php include __DIR__ . "/../includes/footer.php"; ?>
