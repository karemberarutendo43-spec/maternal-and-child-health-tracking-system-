<?php
require_once __DIR__ . "/../config/session.php";
mch_session_start();
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'mother') {
    header("Location: /mch_system/auth/login.php");
    exit;
}

$flashPopup = '';
if (!empty($_SESSION['flash_pending_popup'])) {
    $flashPopup = (string)$_SESSION['flash_pending_popup'];
    unset($_SESSION['flash_pending_popup']);
}

// Fetch clinic name (if assigned)
$clinicName = null;
if (!empty($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("
        SELECT c.clinic_name
        FROM users u
        LEFT JOIN clinics c ON u.clinic_id = c.id
        WHERE u.id = ?
        LIMIT 1
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['clinic_name'])) {
        $clinicName = $row['clinic_name'];
    }
}
include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_mother.php";
?>

<?php if ($flashPopup !== ''): ?>
    <div id="pendingPopup" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4">
        <div class="mch-card w-full max-w-md bg-white/95 p-6 border border-slate-200">
            <div class="text-sm font-semibold text-teal-700">Registration received</div>
            <div class="mt-2 text-slate-700">
                <?= htmlspecialchars($flashPopup) ?>
            </div>
            <div class="mt-5 flex items-center justify-end gap-2">
                <button type="button" id="pendingPopupClose" class="mch-btn px-4 py-2 text-sm font-semibold">OK</button>
            </div>
        </div>
    </div>

    <script>
      (function () {
        const overlay = document.getElementById('pendingPopup');
        const closeBtn = document.getElementById('pendingPopupClose');
        if (!overlay || !closeBtn) return;

        function close() {
          overlay.remove();
        }

        closeBtn.addEventListener('click', close);
        overlay.addEventListener('click', (e) => {
          if (e.target === overlay) close();
        });
        window.addEventListener('keydown', (e) => {
          if (e.key === 'Escape') close();
        });
      })();
    </script>
<?php endif; ?>

<div class="ml-64 p-6 max-w-2xl">
    <div class="mch-card bg-white/90 p-6 border border-slate-100">
        <h2 class="text-2xl font-semibold text-slate-900 mb-2">Verification Pending</h2>
        <p class="text-slate-600">
            We have received your registration. Please wait for activation at the clinic.
        </p>
        <div class="mt-4 p-4 bg-rose-50 rounded-lg text-rose-800 text-sm">
            Please visit your nearest clinic for your first booking. A nurse will activate your account and start ANC tracking.
            <?php if ($clinicName): ?>
                <div class="mt-2 font-semibold">Assigned Clinic: <?= htmlspecialchars($clinicName) ?></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . "/../includes/footer.php"; ?>
