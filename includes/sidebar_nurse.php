<?php
if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . "/../config/session.php";
    mch_session_start();
}
require_once __DIR__ . "/../config/db.php";

$userName = $_SESSION['full_name'] ?? 'Nurse';
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

<aside id="mchSidebarNurse" class="fixed left-0 top-0 h-full w-64 bg-teal-900 text-teal-50 shadow-2xl flex flex-col overflow-y-auto scrollbar-thin">
    <div class="p-6 text-lg font-semibold border-b border-white/10 tracking-wide">
        MCH Nurse Portal
    </div>

    <div class="px-4 mt-4">
        <div class="flex items-center gap-3 rounded-xl bg-white/10 px-3 py-2">
            <?php if ($userPhoto): ?>
                <img src="<?= htmlspecialchars($userPhoto) ?>" alt="Profile" class="w-9 h-9 rounded-full object-cover border border-white/30">
            <?php else: ?>
                <div class="w-9 h-9 rounded-full bg-teal-700 flex items-center justify-center font-bold text-sm">
                    <?= htmlspecialchars($initials) ?>
                </div>
            <?php endif; ?>
            <div>
                <div class="text-sm font-semibold"><?= htmlspecialchars($userName) ?></div>
                <div class="text-xs text-teal-200">Nurse</div>
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
        #mchSidebarNurse .section-link {
            padding-left: 1rem;
        }
    </style>
    <nav class="mt-6 space-y-2 px-4 flex-1 overflow-y-auto text-sm">
        <a href="/mch_system/nurse_portal/dashboard.php" class="block px-4 py-2 rounded-lg hover:bg-white/10">
            Dashboard
        </a>
        <div class="space-y-1">
            <button type="button" class="w-full flex items-center justify-between px-4 py-2 rounded-lg text-left hover:bg-white/10 border border-transparent focus:outline-none focus:ring-2 focus:ring-white" data-sidebar-toggle="patients">
                <span>Patients</span>
                <span aria-hidden="true">▸</span>
            </button>
            <div id="patients" class="space-y-1 px-2 hidden">
                <a href="/mch_system/nurse_portal/register_mother.php" class="block px-4 py-2 rounded-lg hover:bg-white/10">
                    Book Pregnancy
                </a>
                <a href="/mch_system/nurse_portal/mother_list.php" class="block px-4 py-2 rounded-lg hover:bg-white/10">
                    Mother List
                </a>
                <a href="/mch_system/nurse_portal/register_baby.php" class="block px-4 py-2 rounded-lg hover:bg-white/10">
                    Register Baby
                </a>
                <a href="/mch_system/nurse_portal/find_baby.php" class="block px-4 py-2 rounded-lg hover:bg-white/10">
                    Find Baby
                </a>
            </div>
        </div>
        <div class="space-y-1">
            <button type="button" class="w-full flex items-center justify-between px-4 py-2 rounded-lg text-left hover:bg-white/10 border border-transparent focus:outline-none focus:ring-2 focus:ring-white" data-sidebar-toggle="clinical">
                <span>Clinical</span>
                <span aria-hidden="true">▸</span>
            </button>
            <div id="clinical" class="space-y-1 px-2 hidden">
                <a href="/mch_system/nurse_portal/delivery_register.php" class="block px-4 py-2 rounded-lg hover:bg-white/10">
                    Delivery Register
                </a>
                <a href="/mch_system/nurse_portal/fch_queue.php" class="block px-4 py-2 rounded-lg hover:bg-white/10">
                    FCH Queue
                </a>
                <a href="/mch_system/nurse_portal/log_pnc_visit.php" class="block px-4 py-2 rounded-lg hover:bg-white/10">
                    Log PNC Visit
                </a>
                <a href="/mch_system/nurse_portal/pnc_schedule.php" class="block px-4 py-2 rounded-lg hover:bg-white/10">
                    PNC Schedule
                </a>
                <a href="/mch_system/nurse_portal/log_family_planning.php" class="block px-4 py-2 rounded-lg hover:bg-white/10">
                    Family Planning
                </a>
                <a href="/mch_system/nurse_portal/guidelines.php" class="block px-4 py-2 rounded-lg hover:bg-white/10">
                    Clinical Guidelines
                </a>
                <a href="/mch_system/nurse_portal/babies_list.php" class="block px-4 py-2 rounded-lg hover:bg-white/10">
                    Baby Weights
                </a>
            </div>
        </div>
        <div class="space-y-1">
            <button type="button" class="w-full flex items-center justify-between px-4 py-2 rounded-lg text-left hover:bg-white/10 border border-transparent focus:outline-none focus:ring-2 focus:ring-white" data-sidebar-toggle="queues">
                <span>Queues</span>
                <span aria-hidden="true">▸</span>
            </button>
            <div id="queues" class="space-y-1 px-2 hidden">
                <a href="/mch_system/nurse_portal/activation_queue.php" class="block px-4 py-2 rounded-lg hover:bg-white/10">
                    Activation Queue
                </a>
                <a href="/mch_system/nurse_portal/pending_mothers.php" class="block px-4 py-2 rounded-lg hover:bg-white/10">
                    Booking Queue
                </a>
                <a href="/mch_system/nurse_portal/transfer_requests.php" class="block px-4 py-2 rounded-lg hover:bg-white/10">
                    Transfer Requests
                </a>
            </div>
        </div>
        <div class="space-y-1">
            <button type="button" class="w-full flex items-center justify-between px-4 py-2 rounded-lg text-left hover:bg-white/10 border border-transparent focus:outline-none focus:ring-2 focus:ring-white" data-sidebar-toggle="defaulters">
                <span>Defaulters</span>
                <span aria-hidden="true">▸</span>
            </button>
            <div id="defaulters" class="space-y-1 px-2 hidden">
                <a href="/mch_system/nurse_portal/anc_defaulters.php" class="block px-4 py-2 rounded-lg hover:bg-white/10">
                    ANC Defaulters
                </a>
                <a href="/mch_system/nurse_portal/immunization_defaulters.php" class="block px-4 py-2 rounded-lg hover:bg-white/10">
                    Immunization Defaulters
                </a>
                <a href="/mch_system/nurse_portal/tasks.php" class="block px-4 py-2 rounded-lg hover:bg-white/10">
                    Follow-up Tasks
                </a>
            </div>
        </div>
        <div class="space-y-1">
            <button type="button" class="w-full flex items-center justify-between px-4 py-2 rounded-lg text-left hover:bg-white/10 border border-transparent focus:outline-none focus:ring-2 focus:ring-white" data-sidebar-toggle="tools">
                <span>Tools</span>
                <span aria-hidden="true">▸</span>
            </button>
            <div id="tools" class="space-y-1 px-2 hidden">
                <a href="/mch_system/nurse_portal/scan_baby_card.php" class="block px-4 py-2 rounded-lg hover:bg-white/10">
                    Scan Baby Card
                </a>
                <a href="/mch_system/nurse_portal/stock.php" class="block px-4 py-2 rounded-lg hover:bg-white/10">
                    Stock (Supplies)
                </a>
            </div>
        </div>
        <a href="/mch_system/nurse_portal/reports.php" class="block px-4 py-2 rounded-lg hover:bg-white/10">
            Monthly Reports
        </a>
        <a href="/mch_system/help/user_manual.php" class="block px-4 py-2 rounded-lg hover:bg-white/10">
            User Manual
        </a>
    </nav>
</aside>

<script>
  (function () {
    const sidebar = document.getElementById('mchSidebarNurse');
    if (!sidebar) return;
    const key = 'mch_sidebar_scroll_nurse';

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
    const prefix = 'mch_sidebar_nurse_section_';

    const entries = toggles
      .map(btn => {
        const targetId = btn.dataset.sidebarToggle;
        const target = document.getElementById(targetId);
        if (!target) return null;
        const arrow = btn.querySelector('[aria-hidden="true"]');
        const key = prefix + targetId;

        const applyState = (open, persist = true) => {
          target.classList.toggle('hidden', !open);
          btn.setAttribute('aria-expanded', String(open));
          btn.classList.toggle('active', open);
          if (arrow) arrow.textContent = open ? '▾' : '▸';
          if (persist) {
            try {
              sessionStorage.setItem(key, open ? '1' : '0');
            } catch (e) {}
          }
        };

        return { btn, target, id: key, targetId, applyState };
      })
      .filter(Boolean);

    const closeOthers = (except) => {
      entries.forEach(entry => {
        if (entry.id === except) return;
        if (!entry.target.classList.contains('hidden')) {
          entry.applyState(false);
        }
      });
    };

    const findActiveSection = () => {
      try {
        const path = String(window.location && window.location.pathname ? window.location.pathname : '').toLowerCase();
        for (const entry of entries) {
          const links = Array.from(entry.target.querySelectorAll('a[href]'));
          for (const a of links) {
            try {
              const p = new URL(a.getAttribute('href'), window.location.origin).pathname.toLowerCase();
              if (p === path) return entry;
            } catch (e) {}
          }
        }
      } catch (e) {}
      return null;
    };

    // Initial state: open only the section that contains the active page link,
    // otherwise keep all sections collapsed (prevents "Patients" staying open on Dashboard).
    const activeEntry = findActiveSection();
    if (activeEntry) {
      closeOthers(activeEntry.id);
      activeEntry.applyState(true, false);
    } else {
      entries.forEach(entry => entry.applyState(false, false));
    }

    entries.forEach(entry => {
      entry.btn.addEventListener('click', () => {
        const willOpen = entry.target.classList.contains('hidden');
        if (willOpen) {
          closeOthers(entry.id);
        }
        entry.applyState(willOpen);
      });
    });
  })();
</script>
