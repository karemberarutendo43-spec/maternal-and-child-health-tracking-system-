<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/cards.php";

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'nurse'], true)) {
    header("Location: /mch_system/auth/login.php");
    exit;
}

$page = 'pnc_schedule';
include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_nurse.php";

$view = ($_GET['view'] ?? 'all') === 'defaulters' ? 'defaulters' : 'all';

$milestones = [
    ['key' => '48h', 'label' => '48 Hours', 'days' => 2, 'window' => 2],
    ['key' => '2w', 'label' => '2 Weeks', 'days' => 14, 'window' => 3],
    ['key' => '6w', 'label' => '6 Weeks', 'days' => 42, 'window' => 5],
];

$supportsDeliveryDate = db_has_column($pdo, 'mothers', 'delivery_date');
$deliveryDateSelect = $supportsDeliveryDate ? "m.delivery_date" : "NULL AS delivery_date";

function ymd_add_days(string $ymd, int $days): string
{
    $ts = strtotime($ymd . " +" . $days . " days");
    return $ts ? date('Y-m-d', $ts) : '';
}

function ymd_add_days_signed(string $ymd, int $days): string
{
    $op = $days >= 0 ? "+" : "-";
    $ts = strtotime($ymd . " " . $op . " " . abs($days) . " days");
    return $ts ? date('Y-m-d', $ts) : '';
}

function find_visit_in_window(array $visitDates, string $dueYmd, int $windowDays): ?string
{
    $start = ymd_add_days_signed($dueYmd, -$windowDays);
    $end = ymd_add_days_signed($dueYmd, $windowDays);
    foreach ($visitDates as $d) {
        if ($d >= $start && $d <= $end) {
            return $d;
        }
    }
    return null;
}

$rows = $pdo->query("
    SELECT
      m.id AS mother_id,
      m.fullname,
      m.hospital_number,
      m.phone,
      {$deliveryDateSelect},
      c.clinic_name,
      b.id AS baby_id,
      b.baby_name,
      b.dob
    FROM mothers m
    LEFT JOIN users u ON m.user_id = u.id
    LEFT JOIN clinics c ON u.clinic_id = c.id
    LEFT JOIN babies b ON b.id = (
      SELECT bb.id
      FROM babies bb
      WHERE bb.mother_id = m.id
      ORDER BY bb.dob DESC, bb.id DESC
      LIMIT 1
    )
    WHERE m.pregnancy_status = 'Postnatal'
    ORDER BY m.fullname
")->fetchAll(PDO::FETCH_ASSOC);

$motherIds = array_values(array_filter(array_map(static fn ($r) => (int)($r['mother_id'] ?? 0), $rows)));
$pncByMother = [];

if (!empty($motherIds)) {
    $placeholders = implode(',', array_fill(0, count($motherIds), '?'));
    $stmt = $pdo->prepare("
        SELECT mother_id, visit_date
        FROM pnc_visits
        WHERE mother_id IN ($placeholders)
        ORDER BY visit_date ASC
    ");
    $stmt->execute($motherIds);
    while ($v = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $mid = (int)$v['mother_id'];
        $pncByMother[$mid] ??= [];
        $pncByMother[$mid][] = (string)$v['visit_date'];
    }
}

$today = date('Y-m-d');
?>

<div class="ml-64 p-6">
  <div class="max-w-6xl space-y-4">
    <div class="flex items-center justify-between gap-3 flex-wrap">
      <div>
        <h2 class="text-2xl font-semibold text-slate-900">PNC Schedule</h2>
        <p class="text-slate-600 text-sm">
          Expected milestones: 48 hours, 2 weeks, 6 weeks after delivery. A visit counts if it falls within a small window around the due date.
        </p>
      </div>
      <div class="flex items-center gap-2">
        <a href="/mch_system/nurse_portal/log_pnc_visit.php" class="mch-btn px-4 py-2">Log PNC Visit</a>
      </div>
    </div>

    <div class="flex items-center gap-2 text-sm">
      <a href="?view=all"
         class="px-3 py-1 rounded-lg border <?= $view === 'all' ? 'bg-teal-50 border-teal-200 text-teal-700' : 'bg-white border-slate-200 text-slate-700' ?>">
        All
      </a>
      <a href="?view=defaulters"
         class="px-3 py-1 rounded-lg border <?= $view === 'defaulters' ? 'bg-rose-50 border-rose-200 text-rose-700' : 'bg-white border-slate-200 text-slate-700' ?>">
        Defaulters
      </a>
    </div>

    <div class="mch-card bg-white/90 p-5 border border-slate-100 overflow-x-auto">
      <table class="w-full text-sm mch-table">
        <thead>
          <tr>
            <th class="p-2 text-left">Mother</th>
            <th class="p-2 text-left">Clinic</th>
            <th class="p-2 text-left">Baby / DOB</th>
            <?php foreach ($milestones as $ms): ?>
              <th class="p-2 text-left"><?= htmlspecialchars($ms['label']) ?></th>
            <?php endforeach; ?>
            <th class="p-2 text-left">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <?php
              $mid = (int)($r['mother_id'] ?? 0);
              $baseDate = trim((string)($r['delivery_date'] ?? ''));
              if ($baseDate === '') {
                  $baseDate = trim((string)($r['dob'] ?? ''));
              }
              $visitDates = $pncByMother[$mid] ?? [];
              $milestoneOut = [];
              $hasMissed = false;

              if ($baseDate !== '') {
                  foreach ($milestones as $ms) {
                      $due = ymd_add_days($baseDate, (int)$ms['days']);
                      $window = (int)$ms['window'];
                      $hit = find_visit_in_window($visitDates, $due, $window);
                      $late = ymd_add_days($due, $window);
                      $status = 'UPCOMING';
                      if ($hit) {
                          $status = 'DONE';
                      } elseif ($today > $late) {
                          $status = 'MISSED';
                          $hasMissed = true;
                      }
                      $milestoneOut[] = [
                          'due' => $due,
                          'hit' => $hit,
                          'status' => $status,
                      ];
                  }
              }

              if ($view === 'defaulters' && !$hasMissed) {
                  continue;
              }
            ?>
            <tr class="border-t border-slate-200">
              <td class="p-2">
                <div class="font-semibold text-slate-900"><?= htmlspecialchars($r['fullname'] ?? '-') ?></div>
                <div class="text-xs text-slate-500"><?= htmlspecialchars($r['hospital_number'] ?? '-') ?><?= !empty($r['phone']) ? ' • ' . htmlspecialchars($r['phone']) : '' ?></div>
              </td>
              <td class="p-2"><?= htmlspecialchars($r['clinic_name'] ?? '—') ?></td>
              <td class="p-2">
                <?php if ($baseDate === ''): ?>
                  <div class="text-amber-700">No delivery date recorded</div>
                <?php else: ?>
                  <div class="text-slate-900"><?= htmlspecialchars($r['baby_name'] ?? '-') ?></div>
                  <div class="text-xs text-slate-500"><?= htmlspecialchars($baseDate) ?></div>
                <?php endif; ?>
              </td>

              <?php foreach ($milestones as $idx => $ms): ?>
                <td class="p-2">
                  <?php if ($baseDate === ''): ?>
                    <span class="text-slate-400">—</span>
                  <?php else: ?>
                    <?php $o = $milestoneOut[$idx]; ?>
                    <?php if ($o['status'] === 'DONE'): ?>
                      <div class="inline-flex items-center gap-2">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-emerald-100 text-emerald-700">Done</span>
                        <span class="text-xs text-slate-500"><?= htmlspecialchars($o['hit']) ?></span>
                      </div>
                      <div class="text-xs text-slate-400">Due <?= htmlspecialchars($o['due']) ?></div>
                    <?php elseif ($o['status'] === 'MISSED'): ?>
                      <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-rose-100 text-rose-700">Missed</span>
                      <div class="text-xs text-slate-400">Due <?= htmlspecialchars($o['due']) ?></div>
                    <?php else: ?>
                      <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-amber-100 text-amber-700">Upcoming</span>
                      <div class="text-xs text-slate-400">Due <?= htmlspecialchars($o['due']) ?></div>
                    <?php endif; ?>
                  <?php endif; ?>
                </td>
              <?php endforeach; ?>

              <td class="p-2">
                <div class="flex items-center gap-2 flex-wrap">
                  <a href="/mch_system/nurse_portal/mother_history.php?mother_id=<?= $mid ?>"
                     class="px-3 py-1 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-slate-700">
                    Mother
                  </a>
                  <a href="/mch_system/nurse_portal/log_pnc_visit.php"
                     class="px-3 py-1 rounded-lg border border-teal-200 bg-teal-50 hover:bg-teal-100 text-teal-700">
                    Log PNC
                  </a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php include __DIR__ . "/../includes/footer.php"; ?>
