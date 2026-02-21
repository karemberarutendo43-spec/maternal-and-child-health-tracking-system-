<?php
require_once __DIR__ . "/../config/session.php";
mch_session_start();
require_once __DIR__ . "/../config/db.php";

$userName = $_SESSION['full_name'] ?? 'Mother';
$userPhoto = null;
if (!empty($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT full_name, profile_photo FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($u) {
        $userName = $u['full_name'] ?: $userName;
        $userPhoto = $u['profile_photo'] ?? null;
    }
}
$initials = strtoupper(substr(preg_replace('/\\s+/', '', $userName), 0, 2));
?>

<aside id="mchSidebarMother" class="fixed left-0 top-0 h-full w-64 bg-rose-900 text-rose-50 shadow-2xl flex flex-col overflow-y-auto scrollbar-thin">
    <div class="p-6 text-lg font-semibold border-b border-white/10 tracking-wide">
        MCH Mother Portal
    </div>

    <div class="px-4 mt-4">
        <div class="flex items-center gap-3 rounded-xl bg-white/10 px-3 py-2">
            <?php if ($userPhoto): ?>
                <img src="<?= htmlspecialchars($userPhoto) ?>" alt="Profile" class="w-9 h-9 rounded-full object-cover border border-white/30">
            <?php else: ?>
                <div class="w-9 h-9 rounded-full bg-rose-700 flex items-center justify-center font-bold text-sm">
                    <?= htmlspecialchars($initials) ?>
                </div>
            <?php endif; ?>
            <div>
                <div class="text-sm font-semibold"><?= htmlspecialchars($userName) ?></div>
                <div class="text-xs text-rose-200">Mother</div>
            </div>
        </div>
    </div>

    <nav class="mt-6 space-y-1 px-4 flex-1 overflow-y-auto text-sm">
        <a href="/mch_system/mother_portal/dashboard.php" class="block px-4 py-2 rounded-lg hover:bg-white/10">
            My Dashboard
        </a>
        <a href="/mch_system/mother_portal/handbook.php" class="block px-4 py-2 rounded-lg hover:bg-white/10">
            My Handbook
        </a>
        <a href="/mch_system/mother_portal/notifications.php" class="block px-4 py-2 rounded-lg hover:bg-white/10">
            Notifications
        </a>
        <a href="/mch_system/mother_portal/anc_visits.php" class="block px-4 py-2 rounded-lg hover:bg-white/10">
            My ANC Visits
        </a>
        <a href="/mch_system/mother_portal/pnc_visits.php" class="block px-4 py-2 rounded-lg hover:bg-white/10">
            My PNC Visits
        </a>
        <a href="/mch_system/mother_portal/family_planning.php" class="block px-4 py-2 rounded-lg hover:bg-white/10">
            Family Planning
        </a>
        <a href="/mch_system/mother_portal/babies.php" class="block px-4 py-2 rounded-lg hover:bg-white/10">
            Babies &amp; Immunizations
        </a>
        <a href="/mch_system/mother_portal/foods.php" class="block px-4 py-2 rounded-lg hover:bg-white/10">
            Foods to Eat
        </a>
        <a href="/mch_system/help/user_manual.php" class="block px-4 py-2 rounded-lg hover:bg-white/10">
            User Manual
        </a>
    </nav>
</aside>

<script>
  (function () {
    const sidebar = document.getElementById('mchSidebarMother');
    if (!sidebar) return;
    const key = 'mch_sidebar_scroll_mother';

    try {
      const saved = sessionStorage.getItem(key);
      if (saved !== null) sidebar.scrollTop = parseInt(saved, 10) || 0;
    } catch (e) {}

    window.addEventListener('beforeunload', () => {
      try { sessionStorage.setItem(key, String(sidebar.scrollTop || 0)); } catch (e) {}
    });
  })();
</script>
