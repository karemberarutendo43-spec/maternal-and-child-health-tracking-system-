<?php
require_once __DIR__ . "/../config/session.php";
mch_session_start();
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/scope.php";
require_once __DIR__ . "/../config/csrf.php";
require_once __DIR__ . "/../config/cards.php";

if (!isset($_SESSION['user_id']) || (string)($_SESSION['role'] ?? '') !== 'nurse') {
    header("Location: /mch_system/auth/login.php");
    exit;
}

$page = 'fch_queue';

$scopedClinicId = mch_scope_scoped_clinic_id();
if ($scopedClinicId <= 0) {
    http_response_code(403);
    $error = "Your account is not assigned to a clinic. Please contact Admin.";
    include __DIR__ . "/../includes/header.php";
    include __DIR__ . "/../includes/sidebar_nurse.php";
    ?>
    <div class="ml-64 p-6">
        <div class="mch-card bg-white/90 p-6 border border-slate-100 max-w-2xl">
            <h2 class="text-2xl font-semibold text-slate-900 mb-2">FCH Queue</h2>
            <div class="bg-rose-100 text-rose-700 p-3 rounded-lg"><?= htmlspecialchars($error) ?></div>
        </div>
    </div>
    <?php
    include __DIR__ . "/../includes/footer.php";
    exit;
}

$today = date('Y-m-d');
$view = (string)($_GET['view'] ?? 'all');
$allowedViews = ['all', 'waiting', 'pnc', 'epi'];
if (!in_array($view, $allowedViews, true)) {
    $view = 'all';
}

$supportsDeliveryDate = db_has_column($pdo, 'mothers', 'delivery_date');
$supportsEdd = db_has_column($pdo, 'mothers', 'edd');

function ymd_add_days(string $ymd, int $days): string
{
    $op = $days >= 0 ? "+" : "-";
    $ts = strtotime($ymd . " " . $op . " " . abs($days) . " days");
    return $ts ? date('Y-m-d', $ts) : '';
}

function find_visit_in_window(array $visitDates, string $dueYmd, int $windowDays): ?string
{
    $start = ymd_add_days($dueYmd, -$windowDays);
    $end = ymd_add_days($dueYmd, $windowDays);
    foreach ($visitDates as $d) {
        if ($d >= $start && $d <= $end) {
            return $d;
        }
    }
    return null;
}

// 1) Waiting mothers (Pregnant)
$waitingMothers = [];
try {
    $eddSelect = $supportsEdd ? "m.edd" : "NULL AS edd";
    $eddOrder = $supportsEdd ? "ORDER BY (m.edd IS NULL) ASC, m.edd ASC, m.fullname ASC" : "ORDER BY m.fullname ASC";
    $stmt = $pdo->prepare("
        SELECT m.id, m.fullname, m.hospital_number, m.phone, {$eddSelect}
        FROM mothers m
        JOIN users u ON u.id = m.user_id
        WHERE u.clinic_id = ?
          AND m.pregnancy_status = 'Pregnant'
        {$eddOrder}
        LIMIT 250
    ");
    $stmt->execute([$scopedClinicId]);
    $waitingMothers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $waitingMothers = [];
}

// 2) PNC due buckets (0-3 days, 7 days, 6 weeks)
$pncBuckets = [
    '0_3' => ['label' => '0–3 days', 'rows' => []],
    'd7' => ['label' => '7 days', 'rows' => []],
    'w6' => ['label' => '6 weeks', 'rows' => []],
];
$pncMissingDate = [];

try {
    $deliverySelect = $supportsDeliveryDate ? "m.delivery_date" : "NULL AS delivery_date";
    $rows = $pdo->prepare("
        SELECT
          m.id AS mother_id,
          m.fullname,
          m.hospital_number,
          m.phone,
          {$deliverySelect},
          b.id AS baby_id,
          b.baby_name,
          b.dob
        FROM mothers m
        JOIN users u ON u.id = m.user_id
        LEFT JOIN babies b ON b.id = (
          SELECT bb.id
          FROM babies bb
          WHERE bb.mother_id = m.id
          ORDER BY bb.dob DESC, bb.id DESC
          LIMIT 1
        )
        WHERE u.clinic_id = ?
          AND m.pregnancy_status = 'Postnatal'
        ORDER BY m.fullname ASC
        LIMIT 400
    ");
    $rows->execute([$scopedClinicId]);
    $pncRows = $rows->fetchAll(PDO::FETCH_ASSOC);

    $motherIds = array_values(array_filter(array_map(static fn ($r) => (int)($r['mother_id'] ?? 0), $pncRows)));
    $pncByMother = [];
    if (!empty($motherIds)) {
        $ph = implode(',', array_fill(0, count($motherIds), '?'));
        $vstmt = $pdo->prepare("
            SELECT mother_id, visit_date
            FROM pnc_visits
            WHERE mother_id IN ($ph)
            ORDER BY visit_date ASC
        ");
        $vstmt->execute($motherIds);
        while ($v = $vstmt->fetch(PDO::FETCH_ASSOC)) {
            $mid = (int)$v['mother_id'];
            $pncByMother[$mid] ??= [];
            $pncByMother[$mid][] = (string)$v['visit_date'];
        }
    }

    foreach ($pncRows as $r) {
        $mid = (int)($r['mother_id'] ?? 0);
        $baseDate = trim((string)($r['delivery_date'] ?? ''));
        if ($baseDate === '') {
            $baseDate = trim((string)($r['dob'] ?? ''));
        }
        if ($baseDate === '') {
            $pncMissingDate[] = $r;
            continue;
        }

        $visitDates = $pncByMother[$mid] ?? [];

        $daysSince = (int)floor(((strtotime($today) ?: 0) - (strtotime($baseDate) ?: 0)) / 86400);
        if ($daysSince >= 0 && $daysSince <= 3) {
            $pncBuckets['0_3']['rows'][] = $r + ['base_date' => $baseDate, 'due' => $today];
        }

        $due7 = ymd_add_days($baseDate, 7);
        if ($due7 !== '') {
            $hit7 = find_visit_in_window($visitDates, $due7, 1);
            if (!$hit7 && $today >= ymd_add_days($due7, -1) && $today <= ymd_add_days($due7, 1)) {
                $pncBuckets['d7']['rows'][] = $r + ['base_date' => $baseDate, 'due' => $due7];
            }
        }

        $due6w = ymd_add_days($baseDate, 42);
        if ($due6w !== '') {
            $hit6w = find_visit_in_window($visitDates, $due6w, 5);
            if (!$hit6w && $today >= ymd_add_days($due6w, -5) && $today <= ymd_add_days($due6w, 5)) {
                $pncBuckets['w6']['rows'][] = $r + ['base_date' => $baseDate, 'due' => $due6w];
            }
        }
    }
} catch (Throwable $e) {
    // If tables aren't installed yet (pnc_visits etc.), keep bucket arrays empty.
}

// 3) EPI due today (immunizations)
$epiDueToday = [];
$epiOverdueCount = 0;
try {
    $c1 = $pdo->prepare("
        SELECT COUNT(*)
        FROM immunizations i
        JOIN babies b ON i.baby_id = b.id
        JOIN mothers m ON b.mother_id = m.id
        JOIN users u ON m.user_id = u.id
        WHERE u.clinic_id = ?
          AND i.status = 'PENDING'
          AND i.due_date < ?
    ");
    $c1->execute([$scopedClinicId, $today]);
    $epiOverdueCount = (int)$c1->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT
          i.id AS immunization_id,
          i.vaccine,
          i.due_date,
          b.id AS baby_id,
          b.baby_name,
          m.fullname AS mother_name
        FROM immunizations i
        JOIN babies b ON i.baby_id = b.id
        JOIN mothers m ON b.mother_id = m.id
        JOIN users u ON m.user_id = u.id
        WHERE u.clinic_id = ?
          AND i.status = 'PENDING'
          AND i.due_date = ?
        ORDER BY b.baby_name ASC, i.vaccine ASC
        LIMIT 250
    ");
    $stmt->execute([$scopedClinicId, $today]);
    $epiDueToday = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $epiDueToday = [];
}

include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_nurse.php";
?>

<div class="ml-64 p-6">
  <div class="max-w-6xl space-y-5">
    <div class="flex items-end justify-between gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold text-slate-900">FCH Queue</h1>
        <p class="text-sm text-slate-600">Clinic queue for today (<?= htmlspecialchars($today) ?>).</p>
      </div>
      <div class="flex items-center gap-2 flex-wrap">
        <a href="/mch_system/nurse_portal/delivery_register.php?date=<?= urlencode($today) ?>"
           class="px-4 py-2 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-sm font-semibold">
          Delivery Register
        </a>
        <a href="/mch_system/nurse_portal/pnc_schedule.php"
           class="px-4 py-2 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-sm font-semibold">
          PNC Schedule
        </a>
        <a href="/mch_system/nurse_portal/immunization_defaulters.php"
           class="px-4 py-2 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-sm font-semibold">
          Immunization Defaulters
        </a>
      </div>
    </div>

    <div class="flex items-center gap-2 text-sm">
      <a href="?view=all"
         class="px-3 py-1 rounded-lg border <?= $view === 'all' ? 'bg-teal-50 border-teal-200 text-teal-700' : 'bg-white border-slate-200 text-slate-700' ?>">
        All
      </a>
      <a href="?view=waiting"
         class="px-3 py-1 rounded-lg border <?= $view === 'waiting' ? 'bg-teal-50 border-teal-200 text-teal-700' : 'bg-white border-slate-200 text-slate-700' ?>">
        Waiting to deliver
      </a>
      <a href="?view=pnc"
         class="px-3 py-1 rounded-lg border <?= $view === 'pnc' ? 'bg-teal-50 border-teal-200 text-teal-700' : 'bg-white border-slate-200 text-slate-700' ?>">
        PNC due
      </a>
      <a href="?view=epi"
         class="px-3 py-1 rounded-lg border <?= $view === 'epi' ? 'bg-teal-50 border-teal-200 text-teal-700' : 'bg-white border-slate-200 text-slate-700' ?>">
        EPI due today
      </a>
    </div>

    <?php if ($view === 'all' || $view === 'waiting'): ?>
      <div class="mch-card bg-white/90 p-5 border border-slate-100 overflow-x-auto">
        <div class="flex items-center justify-between gap-3 flex-wrap mb-3">
          <h2 class="text-lg font-semibold text-slate-900">Waiting mothers (Pregnant)</h2>
          <div class="text-sm text-slate-600"><?= count($waitingMothers) ?> in clinic</div>
        </div>
        <?php if (empty($waitingMothers)): ?>
          <p class="text-slate-500">No pregnant mothers found in your clinic.</p>
        <?php else: ?>
          <table class="w-full text-sm mch-table mch-scroll">
            <thead>
              <tr>
                <th class="p-2 text-left">Mother</th>
                <th class="p-2 text-left">Phone</th>
                <?php if ($supportsEdd): ?><th class="p-2 text-left">EDD</th><?php endif; ?>
                <th class="p-2 text-left">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($waitingMothers as $m): ?>
                <tr class="border-t border-slate-200">
                  <td class="p-2">
                    <div class="font-semibold text-slate-900"><?= htmlspecialchars((string)($m['fullname'] ?? '-')) ?></div>
                    <div class="text-xs text-slate-500"><?= htmlspecialchars((string)($m['hospital_number'] ?? '-')) ?></div>
                  </td>
                  <td class="p-2"><?= htmlspecialchars((string)($m['phone'] ?? '-')) ?></td>
                  <?php if ($supportsEdd): ?><td class="p-2"><?= htmlspecialchars((string)($m['edd'] ?? '-')) ?></td><?php endif; ?>
                  <td class="p-2">
                    <div class="flex items-center gap-2 flex-wrap">
                      <a href="/mch_system/nurse_portal/mother_history.php?mother_id=<?= (int)$m['id'] ?>"
                         class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-xs font-semibold">
                        Mother record
                      </a>
                      <a href="/mch_system/nurse_portal/delivery_register.php?date=<?= urlencode($today) ?>"
                         class="px-3 py-1.5 rounded-lg border border-teal-200 bg-teal-50 hover:bg-teal-100 text-xs font-semibold text-teal-700">
                        Delivery register
                      </a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($view === 'all' || $view === 'pnc'): ?>
      <div class="mch-card bg-white/90 p-5 border border-slate-100 space-y-4">
        <div class="flex items-center justify-between gap-3 flex-wrap">
          <h2 class="text-lg font-semibold text-slate-900">Postnatal due today</h2>
          <a href="/mch_system/nurse_portal/log_pnc_visit.php" class="mch-btn px-4 py-2">Log PNC Visit</a>
        </div>

        <?php if (!empty($pncMissingDate)): ?>
          <div class="bg-amber-50 border border-amber-200 text-amber-800 p-3 rounded-lg text-sm">
            Some postnatal mothers are missing a delivery date / baby DOB, so due checks cannot be calculated for them.
          </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
          <?php foreach ($pncBuckets as $key => $b): ?>
            <div class="border border-slate-200 rounded-xl p-4 bg-white">
              <div class="text-sm text-slate-600 mb-1"><?= htmlspecialchars($b['label']) ?></div>
              <div class="text-2xl font-semibold text-slate-900"><?= count($b['rows']) ?></div>
            </div>
          <?php endforeach; ?>
        </div>

        <?php foreach ($pncBuckets as $key => $b): ?>
          <div class="overflow-x-auto">
            <h3 class="text-sm font-semibold text-slate-900 mb-2"><?= htmlspecialchars($b['label']) ?></h3>
            <?php if (empty($b['rows'])): ?>
              <p class="text-slate-500 text-sm">None due in this bucket today.</p>
            <?php else: ?>
              <table class="w-full text-sm mch-table mch-scroll">
                <thead>
                  <tr>
                    <th class="p-2 text-left">Mother</th>
                    <th class="p-2 text-left">Baby</th>
                    <th class="p-2 text-left">Base date</th>
                    <th class="p-2 text-left">Due</th>
                    <th class="p-2 text-left">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($b['rows'] as $r): ?>
                    <?php $babyId = (int)($r['baby_id'] ?? 0); ?>
                    <tr class="border-t border-slate-200">
                      <td class="p-2">
                        <div class="font-semibold text-slate-900"><?= htmlspecialchars((string)($r['fullname'] ?? '-')) ?></div>
                        <div class="text-xs text-slate-500"><?= htmlspecialchars((string)($r['hospital_number'] ?? '-')) ?></div>
                      </td>
                      <td class="p-2">
                        <?php if ($babyId > 0): ?>
                          <div class="text-slate-900"><?= htmlspecialchars((string)($r['baby_name'] ?? '-')) ?></div>
                        <?php else: ?>
                          <span class="text-slate-400">—</span>
                        <?php endif; ?>
                      </td>
                      <td class="p-2 text-slate-700"><?= htmlspecialchars((string)($r['base_date'] ?? '-')) ?></td>
                      <td class="p-2 text-slate-700"><?= htmlspecialchars((string)($r['due'] ?? '-')) ?></td>
                      <td class="p-2">
                        <div class="flex items-center gap-2 flex-wrap">
                          <a href="/mch_system/nurse_portal/mother_history.php?mother_id=<?= (int)($r['mother_id'] ?? 0) ?>"
                             class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-xs font-semibold">
                            Mother
                          </a>
                          <?php if ($babyId > 0): ?>
                            <a href="/mch_system/nurse_portal/baby_history.php?baby_id=<?= $babyId ?>"
                               class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-xs font-semibold">
                              Baby
                            </a>
                          <?php endif; ?>
                          <a href="/mch_system/nurse_portal/log_pnc_visit.php"
                             class="px-3 py-1.5 rounded-lg border border-teal-200 bg-teal-50 hover:bg-teal-100 text-xs font-semibold text-teal-700">
                            Log PNC
                          </a>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($view === 'all' || $view === 'epi'): ?>
      <div class="mch-card bg-white/90 p-5 border border-slate-100 overflow-x-auto">
        <div class="flex items-center justify-between gap-3 flex-wrap mb-3">
          <h2 class="text-lg font-semibold text-slate-900">EPI due today</h2>
          <div class="text-sm text-slate-600">
            <?= count($epiDueToday) ?> due today · <?= (int)$epiOverdueCount ?> overdue
          </div>
        </div>
        <?php if (empty($epiDueToday)): ?>
          <p class="text-slate-500">No immunizations due today in your clinic.</p>
        <?php else: ?>
          <table class="w-full text-sm mch-table mch-scroll">
            <thead>
              <tr>
                <th class="p-2 text-left">Baby</th>
                <th class="p-2 text-left">Mother</th>
                <th class="p-2 text-left">Vaccine</th>
                <th class="p-2 text-left">Due date</th>
                <th class="p-2 text-left">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($epiDueToday as $i): ?>
                <tr class="border-t border-slate-200">
                  <td class="p-2 font-semibold text-slate-900"><?= htmlspecialchars((string)($i['baby_name'] ?? '-')) ?></td>
                  <td class="p-2 text-slate-700"><?= htmlspecialchars((string)($i['mother_name'] ?? '-')) ?></td>
                  <td class="p-2"><?= htmlspecialchars((string)($i['vaccine'] ?? '-')) ?></td>
                  <td class="p-2"><?= htmlspecialchars((string)($i['due_date'] ?? '-')) ?></td>
                  <td class="p-2">
                    <a class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-xs font-semibold"
                       href="/mch_system/nurse_portal/baby_history.php?baby_id=<?= (int)($i['baby_id'] ?? 0) ?>">
                      Baby History
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="text-xs text-slate-500">
      Note: This queue is read-only (links only). All save actions are handled inside their modules (Delivery Register, PNC, Baby History) with CSRF protection on POST.
    </div>
  </div>
</div>

<?php include __DIR__ . "/../includes/footer.php"; ?>
