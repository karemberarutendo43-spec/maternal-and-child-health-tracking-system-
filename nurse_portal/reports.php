<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";

$page = 'reports';
include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_nurse.php";

$month = (string)($_GET['month'] ?? '');
$hasMonth = (bool)preg_match('/^\d{4}-\d{2}$/', $month);

$clinic_id = $_SESSION['clinic_id'] ?? null;
$clinicFilter = '';
$clinicParams = [];
if (!empty($clinic_id)) {
    $clinicFilter = " AND u.clinic_id = ? ";
    $clinicParams[] = $clinic_id;
}

$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM mothers m
    LEFT JOIN users u ON m.user_id = u.id
    WHERE 1=1 {$clinicFilter}
");
$stmt->execute($clinicParams);
$totalMothers = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM babies b
    JOIN mothers m ON b.mother_id = m.id
    LEFT JOIN users u ON m.user_id = u.id
    WHERE 1=1 {$clinicFilter}
");
$stmt->execute($clinicParams);
$totalBabies = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM anc_visits a
    JOIN mothers m ON a.mother_id = m.id
    LEFT JOIN users u ON m.user_id = u.id
    WHERE a.next_visit_date < CURDATE()
      AND a.status = 'PENDING'
      {$clinicFilter}
");
$stmt->execute($clinicParams);
$missedANC = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM immunizations i
    JOIN babies b ON i.baby_id = b.id
    JOIN mothers m ON b.mother_id = m.id
    LEFT JOIN users u ON m.user_id = u.id
    WHERE i.due_date < CURDATE()
      AND i.status = 'PENDING'
      {$clinicFilter}
");
$stmt->execute($clinicParams);
$missedImmun = (int)$stmt->fetchColumn();

$ancRows = [];
$ancTotal = 0;
$ancCompleted = 0;
$ancPending = 0;

$immunRows = [];
$immunTotal = 0;
$immunGiven = 0;
$immunPending = 0;

if ($hasMonth) {
    $start = $month . "-01";
    $end   = date("Y-m-t", strtotime($start));
    $params = [$start, $end];
    if (!empty($clinic_id)) {
        $params[] = $clinic_id;
    }

    // ANC data (same query as reports_pdf.php)
    $stmt = $pdo->prepare("
        SELECT m.fullname, m.hospital_number,
               a.visit_date, a.next_visit_date, a.status
        FROM anc_visits a
        JOIN mothers m ON a.mother_id = m.id
        LEFT JOIN users u ON m.user_id = u.id
        WHERE a.visit_date BETWEEN ? AND ?
        {$clinicFilter}
        ORDER BY a.visit_date ASC, a.id ASC
    ");
    $stmt->execute($params);
    $ancRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $ancTotal = count($ancRows);
    $ancCompleted = count(array_filter($ancRows, fn($r) => (($r['status'] ?? '') === 'COMPLETED')));
    $ancPending = $ancTotal - $ancCompleted;

    // Immunizations data (raw rows for accurate totals, then grouped for display by baby)
    $stmt = $pdo->prepare("
        SELECT
          b.id AS baby_id,
          b.baby_name,
          m.fullname AS mother_name,
          i.vaccine,
          i.due_date,
          i.status
        FROM immunizations i
        JOIN babies b ON i.baby_id = b.id
        JOIN mothers m ON b.mother_id = m.id
        LEFT JOIN users u ON m.user_id = u.id
        WHERE i.due_date BETWEEN ? AND ?
        {$clinicFilter}
        ORDER BY i.due_date ASC, i.id ASC
    ");
    $stmt->execute($params);
    $immunRowsRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $immunTotal = count($immunRowsRaw);
    $immunGiven = count(array_filter($immunRowsRaw, fn($r) => (($r['status'] ?? '') === 'GIVEN')));
    $immunPending = $immunTotal - $immunGiven;

    // Group by baby so one baby/mother appears once in the details table.
    $byBaby = [];
    foreach ($immunRowsRaw as $r) {
        $babyId = (int)($r['baby_id'] ?? 0);
        if ($babyId <= 0) continue;

        if (!isset($byBaby[$babyId])) {
            $byBaby[$babyId] = [
                'baby_id' => $babyId,
                'baby_name' => (string)($r['baby_name'] ?? ''),
                'mother_name' => (string)($r['mother_name'] ?? ''),
                'items' => [],
                'has_pending' => false,
                'has_given' => false,
            ];
        }

        $st = (string)($r['status'] ?? '');
        if ($st === 'GIVEN') $byBaby[$babyId]['has_given'] = true;
        if ($st === 'PENDING') $byBaby[$babyId]['has_pending'] = true;

        $byBaby[$babyId]['items'][] = [
            'vaccine' => (string)($r['vaccine'] ?? ''),
            'due_date' => (string)($r['due_date'] ?? ''),
            'status' => $st,
        ];
    }

    $immunRows = array_values($byBaby);
    usort($immunRows, static function ($a, $b) {
        $aFirst = $a['items'][0]['due_date'] ?? '';
        $bFirst = $b['items'][0]['due_date'] ?? '';
        if ($aFirst === $bFirst) {
            return strcmp((string)($a['baby_name'] ?? ''), (string)($b['baby_name'] ?? ''));
        }
        return strcmp((string)$aFirst, (string)$bFirst);
    });
}
?>

<div class="ml-64 p-6 space-y-6">
  <div>
    <h2 class="text-2xl font-semibold text-slate-900 mb-1">Monthly Clinical Report</h2>
    <p class="text-slate-600">Snapshot of key indicators for the clinic.</p>
  </div>

  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
    <a class="mch-card bg-white/90 p-5 border border-slate-100 block hover:bg-white transition" href="/mch_system/nurse_portal/mother_list.php">
      <p class="text-xs uppercase tracking-wide text-slate-500">Total Mothers</p>
      <p class="text-3xl font-semibold text-slate-900 mt-2"><?= $totalMothers ?></p>
      <p class="text-xs text-slate-500 mt-2">Click to view list</p>
    </a>
    <a class="mch-card bg-white/90 p-5 border border-slate-100 block hover:bg-white transition" href="/mch_system/nurse_portal/find_baby.php">
      <p class="text-xs uppercase tracking-wide text-slate-500">Total Babies</p>
      <p class="text-3xl font-semibold text-slate-900 mt-2"><?= $totalBabies ?></p>
      <p class="text-xs text-slate-500 mt-2">Click to view details</p>
    </a>
    <a class="mch-card bg-white/90 p-5 border border-rose-100 block hover:bg-white transition" href="/mch_system/nurse_portal/anc_defaulters.php">
      <p class="text-xs uppercase tracking-wide text-rose-500">Missed ANC Visits</p>
      <p class="text-3xl font-semibold text-rose-700 mt-2"><?= $missedANC ?></p>
      <p class="text-xs text-rose-600 mt-2">Click to view defaulters</p>
    </a>
    <a class="mch-card bg-white/90 p-5 border border-rose-100 block hover:bg-white transition" href="/mch_system/nurse_portal/immunization_defaulters.php">
      <p class="text-xs uppercase tracking-wide text-rose-500">Missed Immunizations</p>
      <p class="text-3xl font-semibold text-rose-700 mt-2"><?= $missedImmun ?></p>
      <p class="text-xs text-rose-600 mt-2">Click to view defaulters</p>
    </a>
  </div>

  <div class="mch-card bg-white/90 p-6 border border-slate-100">
    <h3 class="text-lg font-semibold text-slate-900 mb-2">Monthly Report</h3>
    <p class="text-slate-600 mb-4">Preview the exact details used in the PDF, then generate/export.</p>
    <form method="GET" action="/mch_system/nurse_portal/reports.php" class="flex flex-col sm:flex-row gap-3 items-start sm:items-end">
      <div>
        <label class="block text-sm font-semibold text-slate-700 mb-1">Select Month</label>
        <input type="month" name="month" required class="border border-slate-200 p-2 rounded-lg bg-white" value="<?= htmlspecialchars($hasMonth ? $month : '') ?>">
      </div>
      <button class="px-4 py-2 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-sm">Preview Details</button>
      <button class="mch-btn px-4 py-2" formaction="/mch_system/nurse_portal/reports_pdf.php">Generate PDF</button>
      <button name="export" value="1" class="px-4 py-2 rounded-lg border border-emerald-200 bg-emerald-50 hover:bg-emerald-100 text-sm" formaction="/mch_system/nurse_portal/reports_pdf.php">
        Export and Save
      </button>
    </form>
  </div>

  <?php if ($hasMonth): ?>
    <div class="mch-card bg-white/90 p-6 border border-slate-100">
      <div class="flex items-start justify-between gap-3 flex-wrap">
        <div>
          <h3 class="text-lg font-semibold text-slate-900 mb-1">Monthly Details Preview</h3>
          <p class="text-slate-600 text-sm">These counts and tables match what the PDF generator uses for <span class="font-semibold"><?= htmlspecialchars($month) ?></span>.</p>
        </div>
        <div class="flex items-center gap-2">
          <a class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-sm"
             href="/mch_system/nurse_portal/reports_pdf.php?month=<?= urlencode($month) ?>" target="_blank" rel="noopener">Open PDF</a>
        </div>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mt-5">
        <a href="#anc_details" class="mch-card bg-slate-50 p-4 border border-slate-200 hover:bg-white transition block">
          <div class="text-xs uppercase tracking-wide text-slate-500">ANC Visits (Month)</div>
          <div class="text-2xl font-semibold text-slate-900 mt-2"><?= (int)$ancTotal ?></div>
          <div class="text-xs text-slate-500 mt-1">Click to view details</div>
        </a>
        <a href="#anc_details" class="mch-card bg-emerald-50 p-4 border border-emerald-200 hover:bg-white transition block">
          <div class="text-xs uppercase tracking-wide text-emerald-700">ANC Completed</div>
          <div class="text-2xl font-semibold text-emerald-800 mt-2"><?= (int)$ancCompleted ?></div>
          <div class="text-xs text-emerald-700 mt-1">Click to view details</div>
        </a>
        <a href="#anc_details" class="mch-card bg-amber-50 p-4 border border-amber-200 hover:bg-white transition block">
          <div class="text-xs uppercase tracking-wide text-amber-700">ANC Pending</div>
          <div class="text-2xl font-semibold text-amber-800 mt-2"><?= (int)$ancPending ?></div>
          <div class="text-xs text-amber-700 mt-1">Click to view details</div>
        </a>

        <a href="#immun_details" class="mch-card bg-slate-50 p-4 border border-slate-200 hover:bg-white transition block">
          <div class="text-xs uppercase tracking-wide text-slate-500">Immunizations Due (Month)</div>
          <div class="text-2xl font-semibold text-slate-900 mt-2"><?= (int)$immunTotal ?></div>
          <div class="text-xs text-slate-500 mt-1">Click to view details</div>
        </a>
        <a href="#immun_details" class="mch-card bg-sky-50 p-4 border border-sky-200 hover:bg-white transition block">
          <div class="text-xs uppercase tracking-wide text-sky-700">Immunizations Given</div>
          <div class="text-2xl font-semibold text-sky-800 mt-2"><?= (int)$immunGiven ?></div>
          <div class="text-xs text-sky-700 mt-1">Click to view details</div>
        </a>
        <a href="#immun_details" class="mch-card bg-amber-50 p-4 border border-amber-200 hover:bg-white transition block">
          <div class="text-xs uppercase tracking-wide text-amber-700">Immunizations Pending</div>
          <div class="text-2xl font-semibold text-amber-800 mt-2"><?= (int)$immunPending ?></div>
          <div class="text-xs text-amber-700 mt-1">Click to view details</div>
        </a>
      </div>
    </div>

    <div id="anc_details" class="mch-card bg-white/90 p-6 border border-slate-100">
      <div class="flex items-start justify-between gap-3 flex-wrap">
        <div>
          <h3 class="text-lg font-semibold text-slate-900 mb-1">ANC Visits Details</h3>
          <p class="text-sm text-slate-600">Visit dates within the selected month.</p>
        </div>
        <div class="flex items-center gap-2 text-sm">
          <button type="button" class="anc-filter px-3 py-1.5 rounded-full border border-slate-200 bg-white hover:bg-slate-50" data-filter="ALL">All</button>
          <button type="button" class="anc-filter px-3 py-1.5 rounded-full border border-emerald-200 bg-emerald-50 hover:bg-emerald-100 text-emerald-700" data-filter="COMPLETED">Completed</button>
          <button type="button" class="anc-filter px-3 py-1.5 rounded-full border border-amber-200 bg-amber-50 hover:bg-amber-100 text-amber-700" data-filter="PENDING">Pending</button>
        </div>
      </div>

      <?php if (empty($ancRows)): ?>
        <p class="text-slate-500 mt-4">No ANC visits recorded for this month.</p>
      <?php else: ?>
        <div class="overflow-x-auto mt-4">
          <table class="w-full text-sm mch-table mch-scroll">
            <thead>
              <tr>
                <th class="p-2 text-left">Mother</th>
                <th class="p-2 text-left">Hospital #</th>
                <th class="p-2 text-left">Visit Date</th>
                <th class="p-2 text-left">Next Visit</th>
                <th class="p-2 text-left">Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($ancRows as $r): ?>
                <?php $st = (string)($r['status'] ?? ''); ?>
                <tr class="border-t border-slate-200 anc-row" data-status="<?= htmlspecialchars($st) ?>">
                  <td class="p-2"><?= htmlspecialchars((string)($r['fullname'] ?? '')) ?></td>
                  <td class="p-2"><?= htmlspecialchars((string)($r['hospital_number'] ?? '')) ?></td>
                  <td class="p-2"><?= htmlspecialchars((string)($r['visit_date'] ?? '')) ?></td>
                  <td class="p-2"><?= htmlspecialchars((string)($r['next_visit_date'] ?? '')) ?></td>
                  <td class="p-2">
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs <?= $st === 'COMPLETED' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' ?>">
                      <?= htmlspecialchars($st ?: '-') ?>
                    </span>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <div id="immun_details" class="mch-card bg-white/90 p-6 border border-slate-100">
      <div class="flex items-start justify-between gap-3 flex-wrap">
        <div>
          <h3 class="text-lg font-semibold text-slate-900 mb-1">Immunizations Due Details</h3>
          <p class="text-sm text-slate-600">Grouped by baby (each baby appears once).</p>
        </div>
        <div class="flex items-center gap-2 text-sm">
          <button type="button" class="immun-filter px-3 py-1.5 rounded-full border border-slate-200 bg-white hover:bg-slate-50" data-filter="ALL">All</button>
          <button type="button" class="immun-filter px-3 py-1.5 rounded-full border border-sky-200 bg-sky-50 hover:bg-sky-100 text-sky-700" data-filter="GIVEN">Given</button>
          <button type="button" class="immun-filter px-3 py-1.5 rounded-full border border-amber-200 bg-amber-50 hover:bg-amber-100 text-amber-700" data-filter="PENDING">Pending</button>
        </div>
      </div>

      <?php if (empty($immunRows)): ?>
        <p class="text-slate-500 mt-4">No immunizations due for this month.</p>
      <?php else: ?>
        <div class="overflow-x-auto mt-4">
          <table class="w-full text-sm mch-table mch-scroll">
            <thead>
              <tr>
                <th class="p-2 text-left">Baby</th>
                <th class="p-2 text-left">Mother</th>
                <th class="p-2 text-left">Vaccines / Due Dates</th>
                <th class="p-2 text-left">Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($immunRows as $r): ?>
                <?php
                  $hasPending = !empty($r['has_pending']);
                  $hasGiven = !empty($r['has_given']);
                  $st = $hasPending ? 'PENDING' : ($hasGiven ? 'GIVEN' : 'PENDING');
                  $items = is_array($r['items'] ?? null) ? $r['items'] : [];
                ?>
                <tr class="border-t border-slate-200 immun-row" data-status="<?= htmlspecialchars($st) ?>">
                  <td class="p-2"><?= htmlspecialchars((string)($r['baby_name'] ?? '')) ?></td>
                  <td class="p-2"><?= htmlspecialchars((string)($r['mother_name'] ?? '')) ?></td>
                  <td class="p-2">
                    <?php if (empty($items)): ?>
                      <span class="text-slate-400">—</span>
                    <?php else: ?>
                      <div class="space-y-1">
                        <?php foreach ($items as $it): ?>
                          <div class="text-xs">
                            <span class="font-semibold"><?= htmlspecialchars((string)($it['vaccine'] ?? '')) ?></span>
                            <span class="text-slate-500">· <?= htmlspecialchars((string)($it['due_date'] ?? '')) ?></span>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td class="p-2">
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs <?= $st === 'GIVEN' ? 'bg-sky-100 text-sky-700' : 'bg-amber-100 text-amber-700' ?>">
                      <?= htmlspecialchars($st ?: '-') ?>
                    </span>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <script>
      (function () {
        const ancButtons = Array.from(document.querySelectorAll('.anc-filter'));
        const ancRows = Array.from(document.querySelectorAll('.anc-row'));
        const immunButtons = Array.from(document.querySelectorAll('.immun-filter'));
        const immunRows = Array.from(document.querySelectorAll('.immun-row'));

        const applyFilter = (rows, filter) => {
          rows.forEach(row => {
            const st = (row.getAttribute('data-status') || '').toUpperCase();
            const ok = filter === 'ALL' || st === filter;
            row.classList.toggle('hidden', !ok);
          });
        };

        ancButtons.forEach(btn => {
          btn.addEventListener('click', () => applyFilter(ancRows, (btn.getAttribute('data-filter') || 'ALL').toUpperCase()));
        });
        immunButtons.forEach(btn => {
          btn.addEventListener('click', () => applyFilter(immunRows, (btn.getAttribute('data-filter') || 'ALL').toUpperCase()));
        });
      })();
    </script>
  <?php endif; ?>
</div>

<?php include __DIR__ . "/../includes/footer.php"; ?>
