<?php
require_once __DIR__ . "/../config/session.php";
mch_session_start();
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: /mch_system/auth/login.php");
    exit;
}
include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_admin.php";

$err = '';
if (!empty($_SESSION['flash_reports_error'])) {
    $err = (string)$_SESSION['flash_reports_error'];
    unset($_SESSION['flash_reports_error']);
}
?>

<div class="ml-64 p-6 max-w-xl space-y-4">
    <div>
        <h2 class="text-2xl font-semibold text-slate-900">Generate Monthly Reports</h2>
        <p class="text-slate-600">Select a month to generate reports.</p>
    </div>

    <?php if ($err === 'month'): ?>
        <div class="mch-card bg-rose-50 border border-rose-200 text-rose-700 p-4">
            Invalid or missing month. Please select a month and try again.
        </div>
    <?php endif; ?>

    <form action="reports_anc_pdf.php" method="GET" class="mch-card bg-white/90 p-6 border border-slate-100 space-y-4">
        <div>
            <label class="block mb-2 text-sm font-semibold text-slate-700">Select Month</label>
            <input type="month" name="month" required class="border border-slate-200 p-2 rounded-lg w-full bg-white">
        </div>

        <button class="mch-btn px-4 py-2">
            Generate ANC Report (PDF)
        </button>
    </form>

    <form action="reports_immun_csv.php" method="GET" class="mch-card bg-white/90 p-6 border border-slate-100 space-y-4">
        <div>
            <label class="block mb-2 text-sm font-semibold text-slate-700">Select Month</label>
            <input type="month" name="month" required class="border border-slate-200 p-2 rounded-lg w-full bg-white">
            <p class="text-xs text-slate-500 mt-2">Exports a DHIS2/T5-style monthly immunization aggregate (CSV) grouped by clinic and vaccine code.</p>
        </div>

        <button class="mch-btn px-4 py-2">
            Download Immunization Report (CSV)
        </button>
    </form>

    <form action="reports_anc_csv.php" method="GET" class="mch-card bg-white/90 p-6 border border-slate-100 space-y-4">
        <div>
            <label class="block mb-2 text-sm font-semibold text-slate-700">Select Month</label>
            <input type="month" name="month" required class="border border-slate-200 p-2 rounded-lg w-full bg-white">
            <p class="text-xs text-slate-500 mt-2">Exports an ANC completed-visits aggregate (CSV) grouped by clinic and ANC visit number.</p>
        </div>

        <button class="mch-btn px-4 py-2">
            Download ANC Aggregate (CSV)
        </button>
    </form>

    <form action="reports_pnc_csv.php" method="GET" class="mch-card bg-white/90 p-6 border border-slate-100 space-y-4">
        <div>
            <label class="block mb-2 text-sm font-semibold text-slate-700">Select Month</label>
            <input type="month" name="month" required class="border border-slate-200 p-2 rounded-lg w-full bg-white">
            <p class="text-xs text-slate-500 mt-2">Exports a PNC visits aggregate (CSV) grouped by clinic.</p>
        </div>

        <button class="mch-btn px-4 py-2">
            Download PNC Aggregate (CSV)
        </button>
    </form>
</div>

<?php include __DIR__ . "/../includes/footer.php"; ?>

<script>
  // If an old bookmarked URL still has ?err=..., remove it so refresh doesn't keep showing the message.
  (function () {
    try {
      const url = new URL(window.location.href);
      if (url.searchParams.has('err')) {
        url.searchParams.delete('err');
        window.history.replaceState({}, '', url.toString());
      }
    } catch (e) {}
  })();
</script>
