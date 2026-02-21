<?php
require_once __DIR__ . "/../config/session.php";
mch_session_start();
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: /mch_system/auth/login.php");
    exit;
}

$adminLevel = $_SESSION['admin_level'] ?? 'SUPER';
$adminClinicId = (int)($_SESSION['clinic_id'] ?? 0);
if ($adminLevel !== 'SUPER' && $adminClinicId <= 0) {
    die("Clinic admin is not assigned to a clinic.");
}

$motherId = (int)($_GET['mother_id'] ?? 0);
if ($motherId <= 0) {
    die("Invalid mother id.");
}

// Load mother + clinic (if any)
$mStmt = $pdo->prepare("
    SELECT
      m.id,
      m.fullname,
      m.hospital_number,
      m.phone,
      u.clinic_id,
      c.clinic_name
    FROM mothers m
    LEFT JOIN users u ON m.user_id = u.id
    LEFT JOIN clinics c ON u.clinic_id = c.id
    WHERE m.id = ?
    LIMIT 1
");
$mStmt->execute([$motherId]);
$mother = $mStmt->fetch(PDO::FETCH_ASSOC);
if (!$mother) {
    die("Mother not found.");
}

if ($adminLevel !== 'SUPER') {
    $motherClinicId = (int)($mother['clinic_id'] ?? 0);
    if ($motherClinicId <= 0 || $motherClinicId !== $adminClinicId) {
        http_response_code(403);
        die("Forbidden.");
    }
}

$vStmt = $pdo->prepare("
    SELECT
      id,
      visit_number,
      visit_date,
      next_visit_date,
      status,
      notes
    FROM anc_visits
    WHERE mother_id = ?
    ORDER BY visit_date ASC, id ASC
");
$vStmt->execute([$motherId]);
$visits = $vStmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_admin.php";
?>

<div class="ml-64 p-6 space-y-6">
  <div class="mch-card bg-white/90 p-6 border border-slate-100">
    <div class="flex items-start justify-between gap-4">
      <div class="flex items-center gap-3">
      <h2 class="text-2xl font-semibold text-slate-900">ANC Visits</h2>
       <div class="text-sm text-slate-600 mt-1">
         <span class="font-semibold text-slate-900"><?= htmlspecialchars($mother['fullname']) ?></span>
          &middot; Hospital #: <?= htmlspecialchars((string)($mother['hospital_number'] ?? '')) ?>
          <?php if (!empty($mother['clinic_name'])): ?>
            &middot; Clinic: <?= htmlspecialchars((string)$mother['clinic_name']) ?>
          <?php endif; ?>
        </div>
      </div>
      <div class="mt-3">
        <a href="/mch_system/admin_portal/dashboard.php#recent-flags"
           class="px-4 py-2 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-sm inline-flex items-center gap-2">
          <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-rose-600" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm-1.707-4.293a1 1 0 011.414 0L12 13.586V9a1 1 0 012 0v5a1 1 0 01-1 1h-5a1 1 0 110-2h4.586l-1.293-1.293a1 1 0 010-1.414z" clip-rule="evenodd" />
          </svg>
          Back to Recent Flags
        </a>
      </div>
    </div>

    <?php if (empty($visits)): ?>
      <div class="bg-amber-100 text-amber-800 p-3 rounded-lg mt-4">
        No ANC visits recorded for this mother.
      </div>
    <?php else: ?>
      <div class="mt-4 overflow-x-auto mch-scroll">
        <table class="w-full text-sm mch-table">
          <thead>
            <tr>
              <th class="p-2 text-left">Visit #</th>
              <th class="p-2 text-left">Visit Date</th>
              <th class="p-2 text-left">Next Visit</th>
              <th class="p-2 text-left">Status</th>
              <th class="p-2 text-left">Notes</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($visits as $v): ?>
              <?php
                $isPending = strtoupper((string)($v['status'] ?? '')) === 'PENDING';
                $next = (string)($v['next_visit_date'] ?? '');
                $isOverdue = $isPending && $next !== '' && strtotime($next) < strtotime(date('Y-m-d'));
                $rowClass = $isOverdue ? 'bg-rose-50' : '';
              ?>
              <tr class="border-t border-slate-200 <?= $rowClass ?>">
                <td class="p-2"><?= htmlspecialchars((string)($v['visit_number'] ?? '')) ?></td>
                <td class="p-2"><?= htmlspecialchars((string)($v['visit_date'] ?? '')) ?></td>
                <td class="p-2 <?= $isOverdue ? 'text-rose-700 font-semibold' : '' ?>">
                  <?= htmlspecialchars($next !== '' ? $next : '-') ?>
                </td>
                <td class="p-2">
                  <?php if ($isOverdue): ?>
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-rose-100 text-rose-700">OVERDUE</span>
                  <?php elseif ($isPending): ?>
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-amber-100 text-amber-700">PENDING</span>
                  <?php else: ?>
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-700">
                      <?= htmlspecialchars((string)($v['status'] ?? '')) ?>
                    </span>
                  <?php endif; ?>
                </td>
                <td class="p-2 text-slate-600">
                  <?php $notes = trim((string)($v['notes'] ?? '')); ?>
                  <?= htmlspecialchars($notes !== '' ? $notes : 'N/A') ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . "/../includes/footer.php"; ?>
