<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/cards.php";

$healthTips = [
  "Exclusive breastfeeding for 6 months helps your baby grow strong and healthy.",
  "Wash hands with soap before feeding your baby to prevent diarrhea.",
  "Attend all ANC visits so the clinic can monitor you and your baby.",
  "If your baby has fever, difficulty breathing, or poor feeding, go to the clinic immediately.",
  "Use an insecticide-treated net every night to prevent malaria during pregnancy.",
  "Take iron/folate tablets as advised to prevent anemia.",
  "Keep your baby's vaccinations up to date to prevent serious illness.",
  "Eat a balanced diet: add beans, eggs, vegetables, and fruits when possible.",
  "After delivery, attend PNC visits to check mother and baby health.",
  "If you notice swelling, severe headache, or blurred vision during pregnancy, seek care urgently."
];
$tipSeed = date('Y-m-d');
$tipIndex = (int)(sprintf('%u', crc32($tipSeed))) % max(1, count($healthTips));
$healthTipOfDay = $healthTips[$tipIndex] ?? $healthTips[0];

$page = 'dashboard';
include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_mother.php";

// Fetch mother profile
$user_id = $_SESSION['user_id'];
$motherStmt = $pdo->prepare("SELECT * FROM mothers WHERE user_id = ? LIMIT 1");
$motherStmt->execute([$user_id]);
$mother = $motherStmt->fetch(PDO::FETCH_ASSOC);

if (!$mother) {
  $error = "Your profile is not yet linked to a mother record. Please visit the clinic for activation.";
  $mother_id = null;
} else {
  $mother_id = $mother['id'];
}

// Fetch ANC Visits
$ancVisits = [];
if ($mother_id) {
  $ancVisitsStmt = $pdo->prepare("SELECT * FROM anc_visits WHERE mother_id = ? ORDER BY visit_date ASC");
  $ancVisitsStmt->execute([$mother_id]);
  $ancVisits = $ancVisitsStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch Babies & Immunizations
$babies = [];
if ($mother_id) {
  $babiesStmt = $pdo->prepare("SELECT * FROM babies WHERE mother_id = ?");
  $babiesStmt->execute([$mother_id]);
  $babies = $babiesStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Primary baby (first record)
$primaryBaby = $babies[0] ?? null;
$primaryShots = [];
$weightDates = [];
$weightValues = [];
if ($primaryBaby) {
  $shotsStmt = $pdo->prepare("SELECT vaccine, status FROM immunizations WHERE baby_id = ? ORDER BY due_date ASC");
  $shotsStmt->execute([$primaryBaby['id']]);
  $primaryShots = $shotsStmt->fetchAll(PDO::FETCH_ASSOC);

  $wStmt = $pdo->prepare("
      SELECT weight_kg, observed_date
      FROM baby_weights
      WHERE baby_id = ?
        AND deleted_at IS NULL
      ORDER BY observed_date ASC
  ");
  $wStmt->execute([$primaryBaby['id']]);
  $weights = $wStmt->fetchAll(PDO::FETCH_ASSOC);
  foreach ($weights as $w) {
    $weightDates[] = date('d M', strtotime($w['observed_date']));
    $weightValues[] = (float)$w['weight_kg'];
  }
}
$givenCount = 0;
$pendingCount = 0;
foreach ($primaryShots as $ps) {
  if ($ps['status'] === 'GIVEN') {
    $givenCount++;
  } else {
    $pendingCount++;
  }
}

$recentWeights = [];
if ($primaryBaby) {
  $rwStmt = $pdo->prepare("
      SELECT weight_kg, observed_date
      FROM baby_weights
      WHERE baby_id = ?
        AND deleted_at IS NULL
      ORDER BY observed_date DESC
      LIMIT 3
  ");
  $rwStmt->execute([$primaryBaby['id']]);
  $recentWeights = $rwStmt->fetchAll(PDO::FETCH_ASSOC);
}

function daysAgoLabel($dateStr)
{
  $ts = strtotime($dateStr);
  if (!$ts) return '';
  $days = (int)floor((time() - $ts) / 86400);
  if ($days <= 0) return 'today';
  if ($days === 1) return '1 day ago';
  return $days . ' days ago';
}

function daysUntilLabel($dateStr)
{
  $ts = strtotime($dateStr);
  if (!$ts) return '';
  $days = (int)ceil(($ts - time()) / 86400);
  if ($days <= 0) return 'today';
  if ($days === 1) return 'in 1 day';
  return 'in ' . $days . ' days';
}

// Next visit card
$nextVisit = null;
if ($mother_id) {
  $nextStmt = $pdo->prepare("
      SELECT next_visit_date
      FROM anc_visits
      WHERE mother_id = ? AND next_visit_date >= CURDATE()
      ORDER BY next_visit_date ASC
      LIMIT 1
  ");
  $nextStmt->execute([$mother_id]);
  $nextVisit = $nextStmt->fetch(PDO::FETCH_ASSOC);
}
$nextVisitDate = $nextVisit['next_visit_date'] ?? null;
$daysToNext = null;
if ($nextVisitDate) {
  $daysToNext = (int)floor((strtotime($nextVisitDate) - strtotime(date('Y-m-d'))) / 86400);
}

$transferHistory = [];
if ($mother_id) {
  $tStmt = $pdo->prepare("
      SELECT t.status, t.created_at, cf.clinic_name AS from_clinic, ct.clinic_name AS to_clinic
      FROM mother_transfers t
      LEFT JOIN clinics cf ON t.from_clinic_id = cf.id
      LEFT JOIN clinics ct ON t.to_clinic_id = ct.id
      WHERE t.mother_id = ?
      ORDER BY t.created_at DESC
      LIMIT 5
  ");
  $tStmt->execute([$mother_id]);
  $transferHistory = $tStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Transfer approval notification (shown on dashboard)
$transferApprovedNotice = null;
if ($mother_id) {
  try {
    $hasTransfers = (bool)$pdo->query("SHOW TABLES LIKE 'mother_transfers'")->fetchColumn();
    if ($hasTransfers) {
      $supportsSeen = db_has_column($pdo, 'mother_transfers', 'mother_seen_at');
      $sql = "
        SELECT t.id, t.approved_at, ct.clinic_name AS to_clinic
        FROM mother_transfers t
        LEFT JOIN clinics ct ON t.to_clinic_id = ct.id
        WHERE t.mother_id = ?
          AND t.status = 'APPROVED'
      ";
      if ($supportsSeen) {
        $sql .= " AND t.mother_seen_at IS NULL ";
      } else {
        $sql .= " AND t.approved_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) ";
      }
      $sql .= " ORDER BY t.approved_at DESC, t.id DESC LIMIT 1";

      $nStmt = $pdo->prepare($sql);
      $nStmt->execute([$mother_id]);
      $transferApprovedNotice = $nStmt->fetch(PDO::FETCH_ASSOC) ?: null;

      if ($transferApprovedNotice && $supportsSeen) {
        try {
          $pdo->prepare("UPDATE mother_transfers SET mother_seen_at = NOW() WHERE id = ? LIMIT 1")
              ->execute([(int)$transferApprovedNotice['id']]);
        } catch (Throwable $e2) {
          // ignore
        }
      }
    }
  } catch (Throwable $e) {
    $transferApprovedNotice = null;
  }
}
?>

<div class="ml-64 p-6 space-y-8">

  <?php if (!empty($error)): ?>
    <div class="bg-amber-100 text-amber-800 p-3 rounded-lg">
      <?= htmlspecialchars($error) ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($transferApprovedNotice)): ?>
    <div class="mch-card bg-emerald-50 border border-emerald-200 text-emerald-800 p-4">
      <div class="font-semibold">Transfer approved</div>
      <div class="text-sm mt-1">
        Your clinic transfer has been approved<?php if (!empty($transferApprovedNotice['to_clinic'])): ?> to <span class="font-semibold"><?= htmlspecialchars((string)$transferApprovedNotice['to_clinic']) ?></span><?php endif; ?>.
        You can now go to the new clinic for services.
      </div>
    </div>
  <?php endif; ?>

  <?php if ($mother): ?>
    <div class="mch-card bg-white/90 p-6 border border-slate-100">
      <h2 class="text-2xl font-semibold text-slate-900 mb-2">
        <?= htmlspecialchars($mother['fullname']) ?>
      </h2>

      <div class="grid grid-cols-2 md:grid-cols-3 gap-4 text-sm text-slate-700">
        <div><span class="font-semibold">Hospital #:</span> <?= $mother['hospital_number'] ?></div>
        <div><span class="font-semibold">DOB:</span> <?= $mother['dob'] ?></div>
        <div><span class="font-semibold">Gravida:</span> <?= $mother['gravida'] ?></div>
        <div><span class="font-semibold">Parity:</span> <?= $mother['parity'] ?></div>
        <div><span class="font-semibold">LMP:</span> <?= $mother['lmp'] ?></div>
        <div><span class="font-semibold">EDD:</span> <?= $mother['edd'] ?></div>
      </div>
    </div>
  <?php endif; ?>

  <div class="mch-card bg-white/90 p-6 border border-slate-100">
    <h3 class="text-xl font-semibold mb-2 text-slate-900">Next Step</h3>
    <?php if ($nextVisitDate): ?>
      <div class="text-2xl font-semibold text-emerald-700">
        Your next visit is in <span id="mch_mother_next_days"><?= $daysToNext ?></span> day<?= $daysToNext === 1 ? '' : 's' ?>
      </div>
      <div id="mch_mother_next_date" class="text-slate-600 mt-1"><?= date('l, d M Y', strtotime($nextVisitDate)) ?></div>
    <?php else: ?>
      <div class="text-slate-600">No upcoming visit scheduled yet.</div>
    <?php endif; ?>
  </div>

  <?php if (!empty($transferHistory)): ?>
    <div class="mch-card bg-white/90 p-6 border border-slate-100">
      <h3 class="text-xl font-semibold mb-2 text-slate-900">Transfer History</h3>
      <div class="space-y-3">
        <?php foreach ($transferHistory as $t): ?>
          <div class="flex items-center justify-between p-3 rounded-xl border border-slate-200 bg-slate-50">
            <div>
              <div class="text-sm text-slate-700">
                <?= htmlspecialchars($t['from_clinic'] ?? 'Unassigned') ?> →
                <?= htmlspecialchars($t['to_clinic'] ?? '-') ?>
              </div>
              <div class="text-xs text-slate-500"><?= date('d M Y', strtotime($t['created_at'])) ?></div>
            </div>
            <?php
            $status = $t['status'] ?? 'PENDING';
            $badgeClass = $status === 'APPROVED' ? 'bg-emerald-100 text-emerald-700' : ($status === 'REJECTED' ? 'bg-rose-100 text-rose-700' : 'bg-amber-100 text-amber-700');
            ?>
            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold <?= $badgeClass ?>">
              <?= htmlspecialchars($status) ?>
            </span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <div class="mch-card bg-white/90 p-6 border border-slate-100">
      <h3 class="text-xl font-semibold mb-4 text-slate-900">Baby's Growth</h3>
      <?php if ($primaryBaby && !empty($weightValues)): ?>
        <div class="h-48">
          <canvas id="growthChart"></canvas>
        </div>
        <p class="text-xs text-slate-500 mt-2">Weights are updated by the clinic during visits.</p>
      <?php else: ?>
        <p class="text-slate-500">No growth records yet.</p>
      <?php endif; ?>

      <?php if (!empty($recentWeights)): ?>
        <div class="mt-4">
          <div class="text-xs text-slate-500 mb-2">Recent Weights</div>
            <div class="flex flex-wrap gap-2">
              <?php foreach ($recentWeights as $rw): ?>
                <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-semibold bg-slate-100 text-slate-700">
                <?= htmlspecialchars($rw['weight_kg']) ?> kg &middot; <?= date('d M', strtotime($rw['observed_date'])) ?> &middot; <?= daysAgoLabel($rw['observed_date']) ?>
                </span>
              <?php endforeach; ?>
            </div>
        </div>
      <?php endif; ?>
    </div>

    <div class="mch-card bg-white/90 p-6 border border-slate-100">
      <h3 class="text-xl font-semibold mb-4 text-slate-900">Vaccine Shield</h3>
      <?php if ($primaryBaby && !empty($primaryShots)): ?>
        <div class="text-sm text-slate-600 mb-3">
          Received: <span id="mch_mother_given" class="font-semibold text-emerald-700"><?= $givenCount ?></span> &bull;
          Upcoming: <span id="mch_mother_pending" class="font-semibold text-slate-700"><?= $pendingCount ?></span>
        </div>
        <div class="flex flex-wrap gap-2">
          <?php foreach ($primaryShots as $ps): ?>
            <?php $isGiven = ($ps['status'] === 'GIVEN'); ?>
            <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-semibold <?= $isGiven ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' ?>">
              <?= htmlspecialchars($ps['vaccine']) ?>
            </span>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <p class="text-slate-500">No vaccine records yet.</p>
      <?php endif; ?>
    </div>
  </div>

  <div class="mch-card bg-white/90 p-6 border border-slate-100">
    <div class="flex items-start justify-between gap-4">
      <div>
        <h3 class="text-xl font-semibold text-slate-900">ANC Visits</h3>
        <p class="text-sm text-slate-600 mt-1">
          Total visits: <span class="font-semibold text-slate-900"><?= (int)count($ancVisits) ?></span>
          <?php if ($nextVisitDate): ?>
            &middot; Next: <span class="font-semibold text-emerald-700"><?= date('d M Y', strtotime($nextVisitDate)) ?></span>
          <?php endif; ?>
        </p>
      </div>
      <a href="/mch_system/mother_portal/anc_visits.php" class="text-xs font-semibold text-teal-700 hover:underline">View all</a>
    </div>
    <?php if (empty($ancVisits)): ?>
      <p class="text-sm text-slate-500 mt-3">No ANC visits recorded yet.</p>
    <?php else: ?>
      <p class="text-sm text-slate-500 mt-3">Open &ldquo;View all&rdquo; to see your full timeline.</p>
    <?php endif; ?>
  </div>

  <div class="mch-card bg-white/90 p-6 border border-slate-100">
    <h3 class="text-xl font-semibold mb-2 text-slate-900">Health Tip of the Day</h3>
    <p class="text-slate-600">
      <?= htmlspecialchars($healthTipOfDay) ?>
    </p>
  </div>

</div>

<script>
  (function () {
    const elDays = document.getElementById('mch_mother_next_days');
    const elDate = document.getElementById('mch_mother_next_date');
    const elGiven = document.getElementById('mch_mother_given');
    const elPending = document.getElementById('mch_mother_pending');
    if (!elGiven || !elPending) return;

    let inFlight = false;
    async function refreshStats() {
      if (inFlight) return;
      inFlight = true;
      try {
        const res = await fetch('/mch_system/api/dashboard_mother_stats.php', {
          method: 'GET',
          headers: { 'Accept': 'application/json' },
          cache: 'no-store'
        });
        if (!res.ok) return;
        const data = await res.json();
        if (!data || !data.ok || !data.linked) return;

        elGiven.textContent = String(data.givenCount ?? 0);
        elPending.textContent = String(data.pendingCount ?? 0);

        if (elDays && data.daysToNext !== null && data.daysToNext !== undefined) {
          elDays.textContent = String(data.daysToNext);
        }
        if (elDate) {
          if (data.nextVisitDate) {
            try {
              const d = new Date(String(data.nextVisitDate) + 'T00:00:00');
              elDate.textContent = d.toLocaleDateString(undefined, { weekday: 'long', day: '2-digit', month: 'short', year: 'numeric' });
            } catch (e) {}
          }
        }
      } catch (e) {
        // ignore
      } finally {
        inFlight = false;
      }
    }

    refreshStats();
    const interval = window.setInterval(refreshStats, 60000);
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) refreshStats();
    });
    window.addEventListener('pagehide', () => {
      try { window.clearInterval(interval); } catch (e) {}
    });
  })();
</script>

<?php include __DIR__ . "/../includes/footer.php"; ?>

<?php if ($primaryBaby && !empty($weightValues)): ?>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script>
    const ctx = document.getElementById('growthChart').getContext('2d');
    new Chart(ctx, {
      type: 'line',
      data: {
        labels: <?= json_encode($weightDates) ?>,
        datasets: [{
          label: 'Weight (kg)',
          data: <?= json_encode($weightValues) ?>,
          borderColor: 'rgba(16, 185, 129, 1)',
          backgroundColor: 'rgba(16, 185, 129, 0.2)',
          tension: 0.4,
          fill: true
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
          y: {
            beginAtZero: true
          }
        },
        plugins: {
          legend: {
            display: false
          }
        }
      }
    });
  </script>
<?php endif; ?>
