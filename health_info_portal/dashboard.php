<?php
require_once __DIR__ . "/../config/session.php";
mch_session_start();
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'health_info') {
    header("Location: /mch_system/auth/login.php");
    exit;
}

include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_health_info.php";
?>

<div class="ml-64 p-6 space-y-6 max-w-4xl">
    <div class="mch-card bg-white/90 p-6 border border-slate-100">
        <h1 class="text-2xl font-semibold text-slate-900">Health Info Dashboard</h1>
        <p class="text-sm text-slate-600 mt-2">
            Use this area to search delivery register entries and issue birth records without going back to paper books.
        </p>
        <div class="mt-4">
            <a href="/mch_system/health_info_portal/search_birth_records.php" class="inline-flex items-center px-4 py-2 rounded-lg bg-slate-900 text-white hover:bg-slate-800">
                Search Birth Records
            </a>
        </div>
    </div>
</div>

<?php include __DIR__ . "/../includes/footer.php"; ?>
