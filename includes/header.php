<?php
  require_once __DIR__ . "/../config/session.php";
  mch_session_start();

  $tailwindCssRel = '/mch_system/assets/output.css';
  $tailwindCssAbsolute = __DIR__ . '/../assets/output.css';
  $tailwindCssAvailable = is_file($tailwindCssAbsolute);

  $requestUri = $_SERVER['REQUEST_URI'] ?? '';
  $isNursePortal = (strpos($requestUri, '/nurse_portal/') !== false);
  $isAdminPortal = (strpos($requestUri, '/admin_portal/') !== false);
  $isMotherPortal = (strpos($requestUri, '/mother_portal/') !== false);
  $isHealthInfoPortal = (strpos($requestUri, '/health_info_portal/') !== false);

  // Non-portal pages (e.g. /help/) should still adopt the current user's portal theme.
  if (!$isNursePortal && !$isAdminPortal && !$isMotherPortal && !$isHealthInfoPortal) {
    $role = (string)($_SESSION['role'] ?? '');
    if ($role === 'nurse' || $role === 'doctor') {
      $isNursePortal = true;
    } elseif ($role === 'admin') {
      $isAdminPortal = true;
    } elseif ($role === 'health_info') {
      $isHealthInfoPortal = true;
    } elseif ($role === 'mother') {
      $isMotherPortal = true;
    }
  }

  $nurseBgUrl = '/mch_system/assets/images/nurse.jpg';
  $adminBgUrl = '/mch_system/assets/images/admin.jpg';
  $motherBgUrl = '/mch_system/assets/images/mother_baby.jpg';
  if (isset($pdo) && ($isNursePortal || $isAdminPortal || $isMotherPortal)) {
    require_once __DIR__ . '/../config/app_settings.php';
    $nurseBgUrl = mch_settings_get($pdo, 'branding.bg_nurse', $nurseBgUrl) ?? $nurseBgUrl;
    $adminBgUrl = mch_settings_get($pdo, 'branding.bg_admin', $adminBgUrl) ?? $adminBgUrl;
    $motherBgUrl = mch_settings_get($pdo, 'branding.bg_mother', $motherBgUrl) ?? $motherBgUrl;
  }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#0f172a">
  <link rel="manifest" href="/mch_system/public/manifest.json">
  <title>MCHTS</title>
  <script>
    // Theme: follows OS preference by default; can be overridden via localStorage.
    (function () {
      const storageKey = 'mch_theme'; // 'system' | 'light' | 'dark'
      const safeGet = () => {
        try { return localStorage.getItem(storageKey) || 'system'; } catch (e) { return 'system'; }
      };
      const safeSet = (v) => {
        try { localStorage.setItem(storageKey, v); } catch (e) {}
      };

      const mql = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
      const systemIsDark = () => !!(mql && mql.matches);

      const apply = (theme) => {
        const shouldDark = theme === 'dark' || (theme === 'system' && systemIsDark());
        document.documentElement.classList.toggle('dark', shouldDark);
        document.documentElement.dataset.theme = theme;
      };

      window.mchGetTheme = () => safeGet();
      window.mchSetTheme = (theme) => {
        const t = (theme === 'light' || theme === 'dark' || theme === 'system') ? theme : 'system';
        safeSet(t);
        apply(t);
      };

      const initial = safeGet();
      apply(initial);

      if (mql) {
        const onChange = () => {
          if (safeGet() === 'system') apply('system');
        };
        try {
          mql.addEventListener('change', onChange);
        } catch (e) {
          // Safari fallback
          try { mql.addListener(onChange); } catch (e2) {}
        }
      }
    })();
  </script>
  <script>
    // Preserve main page scroll position on same-page POST/redirects.
    (function () {
      const key = 'mch_scroll:' + (location.pathname || '');

      function save() {
        try {
          sessionStorage.setItem(key, String(window.scrollY || 0));
        } catch (e) {}
      }

      function shouldRestore() {
        if (location.hash) return false; // anchor navigation should win
        if (!document.referrer) return false;
        try {
          const ref = new URL(document.referrer);
          const sameOrigin = ref.origin === location.origin;
          const samePath = ref.pathname === location.pathname;
          if (sameOrigin && samePath) return true;
        } catch (e) {}

        try {
          const nav = performance.getEntriesByType && performance.getEntriesByType('navigation');
          const type = nav && nav[0] && nav[0].type;
          return type === 'reload' || type === 'back_forward';
        } catch (e) {}

        return false;
      }

      document.addEventListener('DOMContentLoaded', () => {
        if (!shouldRestore()) return;
        try {
          const y = parseInt(sessionStorage.getItem(key) || '0', 10) || 0;
          if (y > 0) window.scrollTo({ top: y, left: 0, behavior: 'instant' });
        } catch (e) {}
      });

      window.addEventListener('pagehide', save);
    })();
  </script>
  <?php if ($tailwindCssAvailable): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($tailwindCssRel) ?>">
  <?php else: ?>
    <script>
      window.tailwind = window.tailwind || {};
      window.tailwind.config = Object.assign({}, window.tailwind.config || {}, { darkMode: 'class' });
      console.warn('Tailwind CSS build is missing; run `npm install` + `npm run build:css` to recompile.');
    </script>
    <script src="https://cdn.tailwindcss.com"></script>
  <?php endif; ?>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap');
    :root {
      --mch-primary: #0f766e;
      --mch-accent: #f97316;
      --mch-soft: #f8fafc;
      --mch-ink: #0f172a;
      --mch-muted: #64748b;
    }
    html, body {
      font-family: 'Plus Jakarta Sans', system-ui, -apple-system, Segoe UI, sans-serif;
      color: var(--mch-ink);
      background: radial-gradient(1200px 600px at 10% -10%, #e0f2fe 0%, transparent 60%),
                  radial-gradient(1000px 500px at 110% 0%, #fef3c7 0%, transparent 55%),
                  var(--mch-soft);
    }

    html.dark { color-scheme: dark; }
    html.dark, html.dark body {
      color: #e2e8f0;
      background: radial-gradient(1200px 600px at 10% -10%, rgba(2,132,199,0.22) 0%, transparent 60%),
                  radial-gradient(1000px 500px at 110% 0%, rgba(249,115,22,0.14) 0%, transparent 55%),
                  #0b1220;
    }

    /* Portal background images (start after fixed sidebar width: w-64 / 16rem). */
    body.mch-portal-bg { position: relative; }
    body.mch-portal-bg::before,
    body.mch-portal-bg::after {
      content: "";
      position: fixed;
      top: 0;
      bottom: 0;
      right: 0;
      left: 16rem;
      pointer-events: none;
      z-index: 0;
    }
    body.mch-portal-bg::before {
      background-image: var(--mch-portal-bg-image);
      background-size: cover;
      background-position: center;
      background-repeat: no-repeat;
      opacity: 0.35;
    }
    body.mch-portal-bg::after {
      background: linear-gradient(180deg, rgba(248, 250, 252, 0.40) 0%, rgba(248, 250, 252, 0.18) 55%, rgba(248, 250, 252, 0.46) 100%);
    }
    html.dark body.mch-portal-bg::before {
      opacity: 0.52;
    }
    html.dark body.mch-portal-bg::after {
      background: linear-gradient(180deg, rgba(11, 18, 32, 0.48) 0%, rgba(11, 18, 32, 0.30) 55%, rgba(11, 18, 32, 0.54) 100%);
    }
    body.mch-portal-bg > .flex { position: relative; z-index: 1; }
    body.mch-nurse-portal { --mch-portal-bg-image: url(<?= json_encode($nurseBgUrl, JSON_UNESCAPED_SLASHES) ?>); }
    body.mch-admin-portal { --mch-portal-bg-image: url(<?= json_encode($adminBgUrl, JSON_UNESCAPED_SLASHES) ?>); }
    body.mch-mother-portal { --mch-portal-bg-image: url(<?= json_encode($motherBgUrl, JSON_UNESCAPED_SLASHES) ?>); }
    html.dark .mch-card {
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.45);
      border-color: rgba(148, 163, 184, 0.18) !important;
      background: rgba(15, 23, 42, 0.88) !important;
    }

    /* Common Tailwind utility overrides for dark mode (keep changes small & global). */
    html.dark .bg-white { background-color: rgba(15, 23, 42, 0.88) !important; }
    html.dark .bg-white\/90 { background-color: rgba(15, 23, 42, 0.88) !important; }
    html.dark .bg-slate-50 { background-color: rgba(15, 23, 42, 0.60) !important; }
    html.dark .bg-slate-100 { background-color: rgba(30, 41, 59, 0.70) !important; }
    html.dark .bg-slate-200 { background-color: rgba(148, 163, 184, 0.16) !important; }
    html.dark .bg-emerald-50 { background-color: rgba(16, 185, 129, 0.12) !important; }
    html.dark .bg-emerald-100 { background-color: rgba(16, 185, 129, 0.16) !important; }
    html.dark .bg-teal-50 { background-color: rgba(15, 118, 110, 0.14) !important; }
    html.dark .bg-teal-100 { background-color: rgba(15, 118, 110, 0.18) !important; }
    html.dark .bg-rose-50 { background-color: rgba(244, 63, 94, 0.12) !important; }
    html.dark .bg-rose-100 { background-color: rgba(244, 63, 94, 0.16) !important; }
    html.dark .bg-sky-50 { background-color: rgba(56, 189, 248, 0.12) !important; }
    html.dark .bg-sky-100 { background-color: rgba(56, 189, 248, 0.16) !important; }
    html.dark .bg-amber-50 { background-color: rgba(245, 158, 11, 0.12) !important; }
    html.dark .bg-amber-100 { background-color: rgba(245, 158, 11, 0.16) !important; }
    html.dark .bg-purple-50 { background-color: rgba(168, 85, 247, 0.12) !important; }
    html.dark .bg-purple-100 { background-color: rgba(168, 85, 247, 0.16) !important; }

    html.dark .text-slate-900 { color: #e2e8f0 !important; }
    html.dark .text-slate-800 { color: #e2e8f0 !important; }
    html.dark .text-slate-700 { color: #cbd5e1 !important; }
    html.dark .text-slate-600 { color: #94a3b8 !important; }
    html.dark .text-slate-500 { color: #94a3b8 !important; }
    html.dark .text-slate-400 { color: #94a3b8 !important; }

    html.dark .border-slate-100,
    html.dark .border-slate-200,
    html.dark .border-slate-300 {
      border-color: rgba(148, 163, 184, 0.18) !important;
    }
    html.dark .border-emerald-200,
    html.dark .border-teal-200,
    html.dark .border-sky-200,
    html.dark .border-amber-200,
    html.dark .border-rose-200,
    html.dark .border-purple-200,
    html.dark .border-sky-100,
    html.dark .border-amber-100,
    html.dark .border-rose-100,
    html.dark .border-purple-100 {
      border-color: rgba(148, 163, 184, 0.22) !important;
    }

    /* Hover utility classes used across pages (keep action tiles readable in dark mode). */
    html.dark .hover\:bg-slate-100:hover { background-color: rgba(30, 41, 59, 0.78) !important; }
    html.dark .hover\:bg-slate-200:hover { background-color: rgba(148, 163, 184, 0.16) !important; }
    html.dark .hover\:bg-emerald-100:hover { background-color: rgba(16, 185, 129, 0.18) !important; }
    html.dark .hover\:bg-teal-100:hover { background-color: rgba(15, 118, 110, 0.22) !important; }
    html.dark .hover\:bg-rose-100:hover { background-color: rgba(244, 63, 94, 0.18) !important; }
    html.dark .hover\:bg-sky-100:hover { background-color: rgba(56, 189, 248, 0.18) !important; }
    html.dark .hover\:bg-amber-100:hover { background-color: rgba(245, 158, 11, 0.18) !important; }
    html.dark .hover\:bg-purple-100:hover { background-color: rgba(168, 85, 247, 0.18) !important; }

    html.dark input,
    html.dark select,
    html.dark textarea {
      background-color: rgba(30, 41, 59, 0.55) !important;
      color: #e2e8f0 !important;
      border-color: rgba(148, 163, 184, 0.35) !important;
    }
    html.dark ::placeholder { color: rgba(148, 163, 184, 0.85) !important; }
    html.dark input:disabled,
    html.dark select:disabled,
    html.dark textarea:disabled {
      background-color: rgba(30, 41, 59, 0.35) !important;
      color: rgba(148, 163, 184, 0.9) !important;
      border-color: rgba(148, 163, 184, 0.22) !important;
      cursor: not-allowed;
    }
    html.dark select option {
      background: #0b1220;
      color: #e2e8f0;
    }

    /* Header initials badge */
    html.dark .mch-initials-badge {
      background-color: rgba(148, 163, 184, 0.14) !important;
      border: 1px solid rgba(148, 163, 184, 0.22);
      color: #e2e8f0 !important;
    }
    html.dark code { background: rgba(148, 163, 184, 0.12); padding: 0 4px; border-radius: 6px; }

    html.dark .mch-table thead {
      background: rgba(30, 41, 59, 0.85) !important;
    }
    html.dark .mch-table thead th {
      color: #e2e8f0 !important;
    }
    .mch-card {
      border-radius: 16px;
      box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08);
    }
    .mch-btn {
      background: var(--mch-primary);
      color: #fff;
      border-radius: 12px;
      transition: transform 0.12s ease, box-shadow 0.12s ease;
      box-shadow: 0 8px 18px rgba(15, 118, 110, 0.25);
    }
    .mch-btn:hover {
      transform: translateY(-1px);
      box-shadow: 0 12px 24px rgba(15, 118, 110, 0.28);
    }
  .mch-table thead {
      background: #eef2ff;
  }
  .mch-scroll::-webkit-scrollbar { width: 8px; height: 8px; }
  .mch-scroll::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 999px; }
  .mch-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 999px; }
  .mch-scroll { scrollbar-width: thin; scrollbar-color: #cbd5e1 #f1f5f9; }
  .scrollbar-thin::-webkit-scrollbar { width: 6px; height: 6px; }
  .scrollbar-thin::-webkit-scrollbar-track { background: rgba(255,255,255,0.08); }
  .scrollbar-thin::-webkit-scrollbar-thumb { background: rgba(148,163,184,0.6); border-radius: 999px; }
  .scrollbar-thin { scrollbar-width: thin; scrollbar-color: rgba(148,163,184,0.6) transparent; }
  </style>
</head>
<body class="min-h-screen <?= $isNursePortal ? 'mch-portal-bg mch-nurse-portal' : '' ?> <?= $isAdminPortal ? 'mch-portal-bg mch-admin-portal' : '' ?> <?= $isHealthInfoPortal ? 'mch-portal-bg mch-admin-portal' : '' ?> <?= $isMotherPortal ? 'mch-portal-bg mch-mother-portal' : '' ?>">

<div id="mch_offline_banner" class="hidden fixed top-0 left-0 right-0 z-50">
  <div class="mx-auto max-w-7xl px-4 py-2 text-sm bg-amber-50 border-b border-amber-200 text-amber-900">
    You are offline. Changes cannot be saved until you are back online.
  </div>
</div>

<script>
  (function () {
    const banner = document.getElementById('mch_offline_banner');
    const apply = () => {
      if (!banner) return;
      const online = (typeof navigator !== 'undefined') ? navigator.onLine : true;
      banner.classList.toggle('hidden', !!online);
    };
    window.addEventListener('online', apply);
    window.addEventListener('offline', apply);
    apply();

    // Minimal offline-first cache (static assets only)
    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.register('/mch_system/public/sw.js', { scope: '/mch_system/' }).catch(() => {});
    }
  })();
</script>

<?php
require_once __DIR__ . "/../config/cards.php";

$topUser = $_SESSION['full_name'] ?? 'User';
$topRole = $_SESSION['role'] ?? '';
$topUserId = $_SESSION['user_id'] ?? null;
$topAdminLevel = $_SESSION['admin_level'] ?? 'SUPER';
$topClinic = '';
$topPhoto = null;
if ($topRole === 'admin') {
  $notifLink = '/mch_system/admin_portal/activation_queue.php';
} elseif ($topRole === 'nurse') {
  $notifLink = '/mch_system/nurse_portal/activation_queue.php';
} elseif ($topRole === 'mother') {
  $notifLink = '/mch_system/mother_portal/notifications.php';
} else {
  $notifLink = '/mch_system/mother_portal/dashboard.php';
}
$notifCount = 0;
$notifVerified = 0;
$notifUnverified = 0;
$notifDisabledMothers = 0;
$notifDisabledNurses = 0;
$notifUnlinkedMothers = 0;
 $notifMotherMsgs = 0;
 $notifRejectedTransfers = 0;
 $notifApprovedTransfers = 0;
 $notifPendingTransfers = 0;
 $notifOpenTasks = 0;
 if ($topRole === 'admin') {
  $adminClinicId = (int)($_SESSION['clinic_id'] ?? 0);
  try {
    $adminAllClinics = ($topAdminLevel === 'SUPER' || $topAdminLevel === 'DISTRICT');
    if ($adminAllClinics) {
      $stmtN = $pdo->query("
          SELECT 
            SUM(CASE WHEN email_verified = 1 THEN 1 ELSE 0 END) AS verified,
            SUM(CASE WHEN email_verified = 0 OR email_verified IS NULL THEN 1 ELSE 0 END) AS unverified
          FROM users
          WHERE role = 'mother' AND status IN ('PENDING','APPROVED')
      ");
      $rowN = $stmtN->fetch(PDO::FETCH_ASSOC);
      $notifVerified = (int)($rowN['verified'] ?? 0);
      $notifUnverified = (int)($rowN['unverified'] ?? 0);

      $stmtD = $pdo->query("
          SELECT role, COUNT(*) AS cnt
          FROM users
          WHERE status = 'DISABLED' AND role IN ('mother','nurse')
          GROUP BY role
      ");
      $disabled = $stmtD->fetchAll(PDO::FETCH_KEY_PAIR);
      $notifDisabledMothers = (int)($disabled['mother'] ?? 0);
      $notifDisabledNurses = (int)($disabled['nurse'] ?? 0);
    } elseif ($adminClinicId > 0) {
      $stmtN = $pdo->prepare("
          SELECT 
            SUM(CASE WHEN email_verified = 1 THEN 1 ELSE 0 END) AS verified,
            SUM(CASE WHEN email_verified = 0 OR email_verified IS NULL THEN 1 ELSE 0 END) AS unverified
          FROM users
          WHERE role = 'mother'
            AND status IN ('PENDING','APPROVED')
            AND clinic_id = ?
      ");
      $stmtN->execute([$adminClinicId]);
      $rowN = $stmtN->fetch(PDO::FETCH_ASSOC);
      $notifVerified = (int)($rowN['verified'] ?? 0);
      $notifUnverified = (int)($rowN['unverified'] ?? 0);

      $stmtD = $pdo->prepare("
          SELECT role, COUNT(*) AS cnt
          FROM users
          WHERE status = 'DISABLED'
            AND role IN ('mother','nurse')
            AND clinic_id = ?
          GROUP BY role
      ");
      $stmtD->execute([$adminClinicId]);
      $disabled = $stmtD->fetchAll(PDO::FETCH_KEY_PAIR);
      $notifDisabledMothers = (int)($disabled['mother'] ?? 0);
      $notifDisabledNurses = (int)($disabled['nurse'] ?? 0);
    }
  } catch (Throwable $e) {
    $notifVerified = 0;
    $notifUnverified = 0;
    $notifDisabledMothers = 0;
    $notifDisabledNurses = 0;
  }

  // Transfer requests pending (from nurses)
  try {
    $hasTransfers = (bool)$pdo->query("SHOW TABLES LIKE 'mother_transfers'")->fetchColumn();
    if ($hasTransfers) {
      if ($adminAllClinics) {
        $notifPendingTransfers = (int)$pdo->query("SELECT COUNT(*) FROM mother_transfers WHERE status = 'PENDING'")->fetchColumn();
      } elseif ($adminClinicId > 0) {
        $tstmt = $pdo->prepare("
          SELECT COUNT(*)
          FROM mother_transfers
          WHERE status = 'PENDING'
            AND (from_clinic_id = ? OR to_clinic_id = ?)
        ");
        $tstmt->execute([$adminClinicId, $adminClinicId]);
        $notifPendingTransfers = (int)$tstmt->fetchColumn();
      }
    }
  } catch (Throwable $e2) {
    $notifPendingTransfers = 0;
  }

  $notifCount = $notifVerified + $notifUnverified + $notifDisabledMothers + $notifDisabledNurses + $notifPendingTransfers;
 } elseif ($topRole === 'nurse') {
  $clinic_id = (int)($_SESSION['clinic_id'] ?? 0);
  try {
    $stmtN = $pdo->prepare("
        SELECT 
          SUM(CASE WHEN email_verified = 1 THEN 1 ELSE 0 END) AS verified,
          SUM(CASE WHEN email_verified = 0 OR email_verified IS NULL THEN 1 ELSE 0 END) AS unverified
        FROM users
        WHERE role = 'mother'
          AND status IN ('PENDING','APPROVED')
          AND (clinic_id IS NULL OR clinic_id = ?)
    ");
    $stmtN->execute([$clinic_id]);
    $rowN = $stmtN->fetch(PDO::FETCH_ASSOC);
    $notifVerified = (int)($rowN['verified'] ?? 0);
    $notifUnverified = (int)($rowN['unverified'] ?? 0);

    $unlinkedStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM users u
        LEFT JOIN mothers m ON m.user_id = u.id
        WHERE u.role = 'mother'
          AND u.status = 'ACTIVE'
          AND m.id IS NULL
          AND (u.clinic_id IS NULL OR u.clinic_id = ?)
    ");
    $unlinkedStmt->execute([$clinic_id]);
    $notifUnlinkedMothers = (int)$unlinkedStmt->fetchColumn();

    // Transfer rejections (for this nurse)
    try {
      $hasTransfers = (bool)$pdo->query("SHOW TABLES LIKE 'mother_transfers'")->fetchColumn();
      if ($hasTransfers && !empty($topUserId)) {
        if (db_has_column($pdo, 'mother_transfers', 'nurse_seen_at')) {
          $tstmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM mother_transfers
            WHERE requested_by = ?
              AND status = 'REJECTED'
              AND nurse_seen_at IS NULL
          ");
          $tstmt->execute([(int)$topUserId]);
          $notifRejectedTransfers = (int)$tstmt->fetchColumn();
        } else {
          // Backward-compatible: show recent rejections (7 days) when seen tracking isn't available.
          $tstmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM mother_transfers
            WHERE requested_by = ?
              AND status = 'REJECTED'
              AND approved_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
          ");
          $tstmt->execute([(int)$topUserId]);
          $notifRejectedTransfers = (int)$tstmt->fetchColumn();
        }
      }
    } catch (Throwable $e2) {
      $notifRejectedTransfers = 0;
    }

    // Follow-up tasks (for this nurse)
    try {
      $tasksEnabled = (bool)$pdo->query("SHOW TABLES LIKE 'follow_up_tasks'")->fetchColumn();
      if ($tasksEnabled && !empty($topUserId)) {
        $uid = (int)$topUserId;
        if ($clinic_id > 0) {
          $tstmt = $pdo->prepare("SELECT COUNT(*) FROM follow_up_tasks WHERE status = 'OPEN' AND clinic_id = ? AND assigned_to = ?");
          $tstmt->execute([$clinic_id, $uid]);
        } else {
          $tstmt = $pdo->prepare("SELECT COUNT(*) FROM follow_up_tasks WHERE status = 'OPEN' AND assigned_to = ?");
          $tstmt->execute([$uid]);
        }
        $notifOpenTasks = (int)$tstmt->fetchColumn();
      }
    } catch (Throwable $e3) {
      $notifOpenTasks = 0;
    }
  } catch (Throwable $e) {
    $notifVerified = 0;
    $notifUnverified = 0;
    $notifUnlinkedMothers = 0;
    $notifOpenTasks = 0;
  }

  $notifCount = $notifVerified + $notifUnverified + $notifUnlinkedMothers + $notifRejectedTransfers + $notifOpenTasks;
 } elseif ($topRole === 'mother') {
  try {
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid > 0) {
      $mstmt = $pdo->prepare("SELECT id FROM mothers WHERE user_id = ? LIMIT 1");
      $mstmt->execute([$uid]);
      $motherId = (int)$mstmt->fetchColumn();
      if ($motherId > 0) {
        try {
          $cstmt = $pdo->prepare("SELECT COUNT(*) FROM anc_sms_logs WHERE mother_id = ? AND seen_at IS NULL");
          $cstmt->execute([$motherId]);
          $notifMotherMsgs = (int)$cstmt->fetchColumn();
        } catch (Throwable $e) {
          // Backward-compatible: if anc_sms_logs exists but doesn't have seen_at yet, fallback to "today" count.
          try {
            $cstmt = $pdo->prepare("
              SELECT COUNT(*)
              FROM anc_sms_logs
              WHERE mother_id = ?
                AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ");
            $cstmt->execute([$motherId]);
            $notifMotherMsgs = (int)$cstmt->fetchColumn();
          } catch (Throwable $e2) {
            $notifMotherMsgs = 0;
          }
        }

        // Transfer approvals (for this mother)
        try {
          $hasTransfers = (bool)$pdo->query("SHOW TABLES LIKE 'mother_transfers'")->fetchColumn();
          if ($hasTransfers) {
            if (db_has_column($pdo, 'mother_transfers', 'mother_seen_at')) {
              $tstmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM mother_transfers
                WHERE mother_id = ?
                  AND status = 'APPROVED'
                  AND mother_seen_at IS NULL
              ");
              $tstmt->execute([$motherId]);
              $notifApprovedTransfers = (int)$tstmt->fetchColumn();
            } else {
              // Backward-compatible: show recent approvals (7 days) when seen tracking isn't available.
              $tstmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM mother_transfers
                WHERE mother_id = ?
                  AND status = 'APPROVED'
                  AND approved_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
              ");
              $tstmt->execute([$motherId]);
              $notifApprovedTransfers = (int)$tstmt->fetchColumn();
            }
          }
        } catch (Throwable $e3) {
          $notifApprovedTransfers = 0;
        }
      }
    }
  } catch (Throwable $e) {
    $notifMotherMsgs = 0;
  }

  $notifCount = $notifMotherMsgs + $notifApprovedTransfers;
}

// Improve notification link target based on what's pending.
if ($topRole === 'admin' && $notifPendingTransfers > 0) {
  $notifLink = '/mch_system/admin_portal/transfers.php';
}
if ($topRole === 'nurse' && $notifOpenTasks > 0) {
  $notifLink = '/mch_system/nurse_portal/tasks.php?status=OPEN&from=bell';
}
if ($topRole === 'nurse' && $notifRejectedTransfers > 0 && $notifOpenTasks <= 0) {
  $notifLink = '/mch_system/nurse_portal/transfer_requests.php';
}
if ($topRole === 'mother' && $notifApprovedTransfers > 0) {
  // Show the approval message on the dashboard.
  $notifLink = '/mch_system/mother_portal/dashboard.php';
}
if (!empty($topUserId)) {
  $stmt = $pdo->prepare("SELECT profile_photo, clinic_id FROM users WHERE id = ? LIMIT 1");
  $stmt->execute([$topUserId]);
  $u = $stmt->fetch(PDO::FETCH_ASSOC);
  if ($u) {
    $topPhoto = $u['profile_photo'] ?? null;
    if (!empty($u['clinic_id'])) {
      $cstmt = $pdo->prepare("SELECT clinic_name FROM clinics WHERE id = ? LIMIT 1");
      $cstmt->execute([$u['clinic_id']]);
      $c = $cstmt->fetch(PDO::FETCH_ASSOC);
      $topClinic = $c['clinic_name'] ?? '';
    }
  }
}
$initials = strtoupper(substr(preg_replace('/\\s+/', '', $topUser), 0, 2));
?>

<div class="flex min-h-screen">
  <div class="flex-1">
    <div class="ml-64 px-6 py-4">
      <div class="mch-card bg-white/90 p-3 border border-slate-100 flex items-center justify-between">
        <div class="flex items-center gap-3">
          <?php if ($topPhoto): ?>
            <img src="<?= htmlspecialchars($topPhoto) ?>" alt="Profile" class="w-9 h-9 rounded-full object-cover border border-slate-200">
          <?php else: ?>
            <div class="mch-initials-badge w-9 h-9 rounded-full bg-slate-200 text-slate-700 flex items-center justify-center text-xs font-bold">
              <?= htmlspecialchars($initials) ?>
            </div>
          <?php endif; ?>
          <div class="text-sm text-slate-600">
            <div class="font-semibold text-slate-900">
              <?= htmlspecialchars($topUser) ?><?= $topRole ? " &middot; " . htmlspecialchars(ucfirst($topRole)) : "" ?>
            </div>
            <?php if ($topClinic): ?>
              <div class="text-xs text-slate-500"><?= htmlspecialchars($topClinic) ?></div>
            <?php endif; ?>
          </div>
        </div>
        <div class="flex items-center gap-3">
          <div class="relative group">
            <?php
              $notifTitle = "Notifications: {$notifCount} total";
              if ($topRole === 'admin') {
                $notifTitle .= " (Mothers queue: " . ($notifVerified + $notifUnverified) . ", Disabled mothers: {$notifDisabledMothers}, Disabled nurses: {$notifDisabledNurses}, Transfers pending: {$notifPendingTransfers})";
              } elseif ($topRole === 'nurse') {
                $notifTitle .= " (Booking queue: " . ($notifVerified + $notifUnverified) . ", Unlinked mothers: {$notifUnlinkedMothers}, Follow-ups: {$notifOpenTasks}, Transfers rejected: {$notifRejectedTransfers})";
              } elseif ($topRole === 'mother') {
                $notifTitle .= " (ANC reminders: {$notifMotherMsgs}, Transfers approved: {$notifApprovedTransfers})";
              }
            ?>
            <a id="mch_notif_bell" href="<?= htmlspecialchars($notifLink) ?>" class="inline-flex items-center justify-center w-9 h-9 rounded-full bg-slate-100 border border-slate-200 hover:bg-slate-200 <?= $notifCount > 0 ? 'animate-pulse' : '' ?>" title="<?= htmlspecialchars($notifTitle) ?>">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-slate-700" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M12 22a2.5 2.5 0 01-2.45-2h4.9A2.5 2.5 0 0112 22zm8-6V11a8 8 0 10-16 0v5l-2 2v1h20v-1l-2-2z"/>
              </svg>
            </a>
            <div id="mch_notif_tooltip" class="absolute right-0 mt-2 text-[11px] text-slate-600 bg-white border border-slate-200 rounded-lg px-2 py-1 shadow whitespace-nowrap opacity-0 invisible group-hover:opacity-100 group-hover:visible transition">
              <?php if ($topRole === 'admin'): ?>
                <span class="text-slate-900 font-semibold"><?= (int)($notifVerified + $notifUnverified) ?></span> mothers queue &middot;
                <span class="text-rose-600 font-semibold"><?= (int)$notifDisabledMothers ?></span> disabled mothers &middot;
                <span class="text-rose-600 font-semibold"><?= (int)$notifDisabledNurses ?></span> disabled nurses &middot;
                <span class="text-amber-700 font-semibold"><?= (int)$notifPendingTransfers ?></span> transfers pending
              <?php elseif ($topRole === 'nurse'): ?>
                <span class="text-slate-900 font-semibold"><?= (int)($notifVerified + $notifUnverified) ?></span> booking queue &middot;
                <span class="text-rose-600 font-semibold"><?= (int)$notifUnlinkedMothers ?></span> unlinked mothers &middot;
                <span class="text-amber-700 font-semibold"><?= (int)$notifOpenTasks ?></span> follow-ups &middot;
                <span class="text-rose-600 font-semibold"><?= (int)$notifRejectedTransfers ?></span> transfers rejected
              <?php elseif ($topRole === 'mother'): ?>
                <span class="text-slate-900 font-semibold"><?= (int)$notifMotherMsgs ?></span> ANC reminders &middot;
                <span class="text-emerald-700 font-semibold"><?= (int)$notifApprovedTransfers ?></span> transfers approved
              <?php else: ?>
                <span class="text-slate-500">No notifications</span>
              <?php endif; ?>
            </div>
            <span id="mch_notif_badge" class="absolute -top-1 -right-1 min-w-[1.25rem] h-5 px-1 rounded-full bg-rose-600 text-white text-xs font-semibold flex items-center justify-center <?= $notifCount > 0 ? '' : 'hidden' ?>">
              <?= $notifCount > 0 ? (int)$notifCount : '' ?>
            </span>
            <?php if (false): ?>
              <div class="absolute right-0 mt-2 text-[11px] text-slate-600 bg-white border border-slate-200 rounded-lg px-2 py-1 shadow">
                <span class="text-emerald-600 font-semibold"><?= $notifVerified ?></span> verified ·
                <span class="text-amber-600 font-semibold"><?= $notifUnverified ?></span> unverified
              </div>
            <?php endif; ?>
            <?php if (false): ?>
              <div class="absolute right-0 mt-2 text-[11px] text-slate-600 bg-white border border-slate-200 rounded-lg px-2 py-1 shadow">
                <span class="text-slate-900 font-semibold"><?= (int)($notifVerified + $notifUnverified) ?></span> mothers queue &middot;
                <span class="text-rose-600 font-semibold"><?= (int)$notifDisabledMothers ?></span> disabled mothers &middot;
                <span class="text-rose-600 font-semibold"><?= (int)$notifDisabledNurses ?></span> disabled nurses
              </div>
            <?php endif; ?>
          </div>
          <a href="/mch_system/account/profile.php" class="text-sm text-slate-600 hover:underline">Profile</a>
          <a href="/mch_system/auth/logout.php" class="text-sm text-rose-600 hover:underline">Logout</a>
        </div>
      </div>

    <script>
      (function () {
        const bell = document.getElementById('mch_notif_bell');
        const badge = document.getElementById('mch_notif_badge');
        if (!bell) return;

        let inFlight = false;
        async function refreshNotif() {
          if (inFlight) return;
          inFlight = true;
          try {
            const res = await fetch('/mch_system/api/notifications_counts.php', {
              method: 'GET',
              headers: { 'Accept': 'application/json' },
              cache: 'no-store'
            });
            if (!res.ok) return;
            const data = await res.json();
            if (!data || !data.ok) return;

            const count = Number(data.notifCount || 0);
            if (data.notifLink) bell.setAttribute('href', data.notifLink);
            if (data.notifTitle) bell.setAttribute('title', data.notifTitle);

            bell.classList.toggle('animate-pulse', count > 0);

            if (badge) {
              badge.textContent = count > 0 ? String(count) : '';
              badge.classList.toggle('hidden', !(count > 0));
            }
          } catch (e) {
            // ignore
          } finally {
            inFlight = false;
          }
        }

        refreshNotif();
        const interval = window.setInterval(refreshNotif, 30000);
        document.addEventListener('visibilitychange', () => {
          if (!document.hidden) refreshNotif();
        });
        window.addEventListener('pagehide', () => {
          try { window.clearInterval(interval); } catch (e) {}
        });
      })();
    </script>

    </div>
