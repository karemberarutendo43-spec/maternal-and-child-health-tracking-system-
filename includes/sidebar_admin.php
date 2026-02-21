<?php
require_once __DIR__ . "/../config/session.php";
mch_session_start();
require_once __DIR__ . "/../config/db.php";

$userName = $_SESSION['full_name'] ?? 'Admin';
$userPhoto = null;
$adminLevel = (string)($_SESSION['admin_level'] ?? 'SUPER');
$adminClinicId = (int)($_SESSION['clinic_id'] ?? 0);
$adminAllClinics = ($adminLevel === 'SUPER' || $adminLevel === 'DISTRICT');
$adminRoleLabel = $adminLevel === 'SUPER' ? 'Super Admin' : ($adminLevel === 'DISTRICT' ? 'District Admin' : 'Clinical Admin');
$adminClinicName = '';
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

if (!$adminAllClinics && $adminClinicId > 0) {
    try {
        $c = $pdo->prepare("SELECT clinic_name FROM clinics WHERE id = ? LIMIT 1");
        $c->execute([$adminClinicId]);
        $adminClinicName = (string)($c->fetchColumn() ?: '');
    } catch (Throwable $e) {
        $adminClinicName = '';
    }
}
?>

<aside id="mchSidebarAdmin" class="fixed left-0 top-0 h-full w-64 bg-slate-900 text-slate-100 shadow-2xl flex flex-col overflow-y-auto scrollbar-thin">

    <div class="p-6 text-lg font-semibold border-b border-white/10 tracking-wide">
        MCH Admin Panel
    </div>

    <div class="px-4 mt-4">
        <div class="flex items-center gap-3 rounded-xl bg-white/10 px-3 py-2">
            <?php if ($userPhoto): ?>
                <img src="<?= htmlspecialchars($userPhoto) ?>" alt="Profile" class="w-9 h-9 rounded-full object-cover border border-white/30">
            <?php else: ?>
                <div class="w-9 h-9 rounded-full bg-slate-700 flex items-center justify-center font-bold text-sm">
                    <?= htmlspecialchars($initials) ?>
                </div>
            <?php endif; ?>
            <div>
                <div class="text-sm font-semibold"><?= htmlspecialchars($userName) ?></div>
                <div class="text-xs text-slate-300">
                    <?= htmlspecialchars($adminRoleLabel) ?>
                    <?php if ($adminClinicName !== ''): ?>
                        <span class="text-slate-400">&bull;</span> <?= htmlspecialchars($adminClinicName) ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <style>
        .sidebar-section-toggle {
            transition: color 0.2s ease, border-color 0.2s ease;
        }
        .sidebar-section-toggle.active {
            color: #fff;
            border-color: rgba(255, 255, 255, 0.6);
        }
        #mchSidebarAdmin .section-link {
            padding-left: 1rem;
        }
    </style>
    <nav class="mt-6 space-y-2 px-4 flex-1 overflow-y-auto text-sm">
        <a href="/mch_system/admin_portal/dashboard.php" class="block px-4 py-2 rounded-lg hover:bg-white/10">
            Dashboard
        </a>
        <div class="space-y-1">
            <button type="button" class="w-full flex items-center justify-between px-4 py-2 rounded-lg text-left hover:bg-white/10 border border-transparent focus:outline-none focus:ring-2 focus:ring-white sidebar-section-toggle" data-sidebar-toggle="users_admin">
                <span>Users</span>
                <span aria-hidden="true">▸</span>
            </button>
            <div id="users_admin" class="space-y-1 px-2 hidden">
                <a href="/mch_system/admin_portal/users.php" class="block px-4 py-2 rounded-lg hover:bg-white/10 section-link">
                    Manage Users
                </a>
                <a href="/mch_system/admin_portal/create_user.php" class="block px-4 py-2 rounded-lg hover:bg-white/10 section-link">
                    Create User
                </a>
            </div>
        </div>
        <div class="space-y-1">
            <button type="button" class="w-full flex items-center justify-between px-4 py-2 rounded-lg text-left hover:bg-white/10 border border-transparent focus:outline-none focus:ring-2 focus:ring-white sidebar-section-toggle" data-sidebar-toggle="queues_admin">
                <span>Queues</span>
                <span aria-hidden="true">▸</span>
            </button>
            <div id="queues_admin" class="space-y-1 px-2 hidden">
                <a href="/mch_system/admin_portal/activation_queue.php" class="block px-4 py-2 rounded-lg hover:bg-white/10 section-link">
                    Activation Queue
                </a>
                <a href="/mch_system/admin_portal/booking_queue.php" class="block px-4 py-2 rounded-lg hover:bg-white/10 section-link">
                    Booking Queue
                </a>
                <a href="/mch_system/admin_portal/transfers.php" class="block px-4 py-2 rounded-lg hover:bg-white/10 section-link">
                    Transfer Requests
                </a>
            </div>
        </div>
        <div class="space-y-1">
            <button type="button" class="w-full flex items-center justify-between px-4 py-2 rounded-lg text-left hover:bg-white/10 border border-transparent focus:outline-none focus:ring-2 focus:ring-white sidebar-section-toggle" data-sidebar-toggle="records_admin">
                <span>Registry</span>
                <span aria-hidden="true">▸</span>
            </button>
            <div id="records_admin" class="space-y-1 px-2 hidden">
                <a href="/mch_system/admin_portal/birth_confirmation_books.php" class="block px-4 py-2 rounded-lg hover:bg-white/10 section-link">
                    Birth Confirmation Books
                </a>
                <a href="/mch_system/admin_portal/stock.php" class="block px-4 py-2 rounded-lg hover:bg-white/10 section-link">
                    Stock &amp; Commodities
                </a>
                <a href="/mch_system/admin_portal/epi_backfill.php" class="block px-4 py-2 rounded-lg hover:bg-white/10 section-link">
                    EPI Backfill
                </a>
            </div>
        </div>
        <div class="space-y-1">
            <button type="button" class="w-full flex items-center justify-between px-4 py-2 rounded-lg text-left hover:bg-white/10 border border-transparent focus:outline-none focus:ring-2 focus:ring-white sidebar-section-toggle" data-sidebar-toggle="defaulters_admin">
                <span>Defaulters</span>
                <span aria-hidden="true">▸</span>
            </button>
            <div id="defaulters_admin" class="space-y-1 px-2 hidden">
                <a href="/mch_system/admin_portal/anc_defaulters.php" class="block px-4 py-2 rounded-lg hover:bg-white/10 section-link">
                    ANC Defaulters
                </a>
                <a href="/mch_system/admin_portal/immunization_defaulters.php" class="block px-4 py-2 rounded-lg hover:bg-white/10 section-link">
                    Immunization Defaulters
                </a>
            </div>
        </div>
        <div class="space-y-1">
            <button type="button" class="w-full flex items-center justify-between px-4 py-2 rounded-lg text-left hover:bg-white/10 border border-transparent focus:outline-none focus:ring-2 focus:ring-white sidebar-section-toggle" data-sidebar-toggle="reports_admin">
                <span>Reports</span>
                <span aria-hidden="true">▸</span>
            </button>
            <div id="reports_admin" class="space-y-1 px-2 hidden">
                <a href="/mch_system/admin_portal/reports.php" class="block px-4 py-2 rounded-lg hover:bg-white/10 section-link">
                    Monthly Reports
                </a>
                <a href="/mch_system/admin_portal/guidelines.php" class="block px-4 py-2 rounded-lg hover:bg-white/10 section-link">
                    Clinical Guidelines
                </a>
                <a href="/mch_system/admin_portal/epds_report.php" class="block px-4 py-2 rounded-lg hover:bg-white/10 section-link">
                    EPDS Report
                </a>
                <a href="/mch_system/admin_portal/coverage.php" class="block px-4 py-2 rounded-lg hover:bg-white/10 section-link">
                    Clinic Coverage
                </a>
                <a href="/mch_system/admin_portal/reports_anc_pdf.php" class="block px-4 py-2 rounded-lg hover:bg-white/10 section-link">
                    ANC Monthly PDF
                </a>
                <a href="/mch_system/admin_portal/audit.php" class="block px-4 py-2 rounded-lg hover:bg-white/10 section-link">
                    Audit Logs
                </a>
            </div>
        </div>
        <div class="space-y-1">
            <button type="button" class="w-full flex items-center justify-between px-4 py-2 rounded-lg text-left hover:bg-white/10 border border-transparent focus:outline-none focus:ring-2 focus:ring-white sidebar-section-toggle" data-sidebar-toggle="settings_admin">
                <span>Settings</span>
                <span aria-hidden="true">▸</span>
            </button>
            <div id="settings_admin" class="space-y-1 px-2 hidden">
                <?php if ($adminLevel === 'SUPER'): ?>
                    <a href="/mch_system/admin_portal/branding.php" class="block px-4 py-2 rounded-lg hover:bg-white/10 section-link">
                        Branding Settings
                    </a>
                <?php endif; ?>
                <a href="/mch_system/admin_portal/registration_data.php" class="block px-4 py-2 rounded-lg hover:bg-white/10 section-link">
                    Registration Data
                </a>
            </div>
        </div>
        <a href="/mch_system/help/user_manual.php" class="block px-4 py-2 rounded-lg hover:bg-white/10">
            User Manual
        </a>
    </nav>

</aside>

<script>
  (function () {
    const sidebar = document.getElementById('mchSidebarAdmin');
    if (!sidebar) return;
    const key = 'mch_sidebar_scroll_admin';

    try {
      const saved = sessionStorage.getItem(key);
      if (saved !== null) sidebar.scrollTop = parseInt(saved, 10) || 0;
    } catch (e) {}

    window.addEventListener('beforeunload', () => {
      try { sessionStorage.setItem(key, String(sidebar.scrollTop || 0)); } catch (e) {}
    });
  })();

  (function () {
    const toggles = Array.from(document.querySelectorAll('[data-sidebar-toggle]'));
    const prefix = 'mch_sidebar_admin_section_';

    const entries = toggles
      .map(btn => {
        const targetId = btn.dataset.sidebarToggle;
        const target = document.getElementById(targetId);
        if (!target) return null;
        const arrow = btn.querySelector('[aria-hidden="true"]');
        const keySection = prefix + targetId;

        const applyState = (open, persist = true) => {
          target.classList.toggle('hidden', !open);
          btn.setAttribute('aria-expanded', String(open));
          btn.classList.toggle('active', open);
          if (arrow) arrow.textContent = open ? '▾' : '▸';
          if (persist) {
            try {
              sessionStorage.setItem(keySection, open ? '1' : '0');
            } catch (e) {}
          }
        };

        return { btn, target, targetId, applyState };
      })
      .filter(Boolean);

    const closeOthers = (exceptId) => {
      entries.forEach(entry => {
        if (entry.targetId === exceptId) return;
        if (!entry.target.classList.contains('hidden')) {
          entry.applyState(false);
        }
      });
    };

    entries.forEach(entry => {
      const saved = (() => {
        try {
          return sessionStorage.getItem(prefix + entry.targetId);
        } catch (e) {
          return null;
        }
      })();

      entry.applyState(saved === '1', false);

      entry.btn.addEventListener('click', () => {
        const willOpen = entry.target.classList.contains('hidden');
        if (willOpen) {
          closeOthers(entry.targetId);
        }
        entry.applyState(willOpen);
      });
    });
  })();
</script>
