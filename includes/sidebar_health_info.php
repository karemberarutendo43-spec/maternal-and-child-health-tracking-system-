<?php
require_once __DIR__ . "/../config/session.php";
mch_session_start();

$userName = $_SESSION['full_name'] ?? 'Health Info';
$initials = strtoupper(substr(preg_replace('/\\s+/', '', (string)$userName), 0, 2));
?>

<aside id="mchSidebarHealthInfo" class="fixed left-0 top-0 h-full w-64 bg-indigo-950 text-indigo-50 shadow-2xl flex flex-col overflow-y-auto scrollbar-thin">
    <div class="p-6 text-lg font-semibold border-b border-white/10 tracking-wide">
        MCH Health Info
    </div>

    <div class="px-4 mt-4">
        <div class="flex items-center gap-3 rounded-xl bg-white/10 px-3 py-2">
            <div class="w-9 h-9 rounded-full bg-indigo-800 flex items-center justify-center font-bold text-sm">
                <?= htmlspecialchars($initials) ?>
            </div>
            <div>
                <div class="text-sm font-semibold"><?= htmlspecialchars((string)$userName) ?></div>
                <div class="text-xs text-indigo-200">Birth Records</div>
            </div>
        </div>
    </div>

    <nav class="mt-6 space-y-1 px-4 flex-1 overflow-y-auto text-sm">
        <a href="/mch_system/health_info_portal/search_birth_records.php" class="block px-4 py-2 rounded-lg hover:bg-white/10">
            Search Birth Records
        </a>
        <a href="/mch_system/auth/logout.php" class="block px-4 py-2 rounded-lg hover:bg-white/10">
            Logout
        </a>
    </nav>
</aside>

<script>
  (function () {
    const sidebar = document.getElementById('mchSidebarHealthInfo');
    if (!sidebar) return;
    const key = 'mch_sidebar_scroll_health_info';

    try {
      const saved = sessionStorage.getItem(key);
      if (saved !== null) sidebar.scrollTop = parseInt(saved, 10) || 0;
    } catch (e) {}

    window.addEventListener('beforeunload', () => {
      try { sessionStorage.setItem(key, String(sidebar.scrollTop || 0)); } catch (e) {}
    });
  })();
</script>
