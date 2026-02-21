<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/cards.php";

$page = 'anc_defaulters';
include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_nurse.php";

 $today = date('Y-m-d');
 $page = max(1, (int)($_GET['page'] ?? 1));
 $perPage = max(10, min(100, (int)($_GET['per_page'] ?? 25)));
 $offset = ($page - 1) * $perPage;

// Optional: keep clinical_status (0/1/2) in sync for CDSS-style reporting.
if (db_has_column($pdo, 'anc_visits', 'clinical_status')) {
    try {
        $pdo->query("UPDATE anc_visits SET clinical_status = 2 WHERE status = 'PENDING' AND next_visit_date < CURDATE()");
    } catch (Throwable $e) {
        // ignore
    }
}

$baseSql = "
    FROM anc_visits a
    JOIN mothers m ON a.mother_id = m.id
    WHERE a.next_visit_date < ?
      AND a.status = 'PENDING'
    GROUP BY m.id, m.fullname, m.hospital_number, m.phone
";

$countStmt = $pdo->prepare("SELECT COUNT(DISTINCT m.id) {$baseSql}");
$countStmt->execute([$today]);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$topStmt = $pdo->prepare("
    SELECT
        m.id AS mother_id,
        m.fullname,
        m.hospital_number,
        m.phone,
        MIN(a.next_visit_date) AS next_visit_date,
        DATEDIFF(?, MIN(a.next_visit_date)) AS days_overdue
    {$baseSql}
    ORDER BY days_overdue DESC, next_visit_date ASC
    LIMIT 25
");
$topStmt->execute([$today, $today]);
$topDefaulters = $topStmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT
        m.id AS mother_id,
        m.fullname,
        m.hospital_number,
        m.phone,
        MIN(a.next_visit_date) AS next_visit_date,
        DATEDIFF(?, MIN(a.next_visit_date)) AS days_overdue
    {$baseSql}
    ORDER BY days_overdue DESC, next_visit_date ASC
    LIMIT ?
    OFFSET ?
");
$stmt->bindValue(1, $today, PDO::PARAM_STR);
$stmt->bindValue(2, $today, PDO::PARAM_STR);
$stmt->bindValue(3, $perPage, PDO::PARAM_INT);
$stmt->bindValue(4, $offset, PDO::PARAM_INT);
$stmt->execute();
$defaulters = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="ml-64 p-6">
  <div class="mch-card bg-white/90 p-6 border border-slate-100">
    <div class="flex items-center justify-between gap-3 flex-wrap mb-4">
      <h2 class="text-2xl font-semibold text-slate-900">ANC Defaulters</h2>
      <a href="/mch_system/nurse_portal/reports.php" class="px-4 py-2 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-sm">Back to Reports</a>
    </div>

    <?php if (empty($topDefaulters) && empty($defaulters)): ?>
      <div class="bg-emerald-100 text-emerald-700 p-3 rounded-lg">No ANC defaulters</div>
    <?php else: ?>
      <div class="space-y-4">
        <div class="flex items-center gap-2 border border-slate-200 bg-slate-50 rounded-full p-1 text-sm font-semibold">
          <button type="button" class="px-4 py-1 rounded-full transition tab-trigger active:bg-white" data-panel="top">Top 25 Overdue</button>
          <button type="button" class="px-4 py-1 rounded-full transition tab-trigger" data-panel="all">All Defaulters</button>
        </div>

        <div class="tab-panel" data-panel="top">
          <div class="flex items-center justify-between text-xs text-slate-600 mb-2">
            <span>Most overdue mothers (by days overdue).</span>
            <a href="/mch_system/nurse_portal/mother_history.php?mother_id=0" class="text-xs font-semibold text-slate-600 hover:text-slate-900">
              View mother history
            </a>
          </div>
          <div class="overflow-x-auto">
            <table class="w-full text-sm mch-table">
              <thead>
                <tr>
                  <th class="p-2 text-left">Mother</th>
                  <th class="p-2 text-left">Clinic</th>
                  <th class="p-2 text-left">Next Visit</th>
                  <th class="p-2 text-left">Days Overdue</th>
                  <th class="p-2 text-left">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($topDefaulters as $d): ?>
                  <tr class="border-t border-slate-200">
                    <td class="p-2 font-semibold"><?= htmlspecialchars($d['fullname']) ?></td>
                    <td class="p-2 text-slate-600"><?= htmlspecialchars($d['clinic_name'] ?? '—') ?></td>
                    <td class="p-2 text-rose-700"><?= htmlspecialchars($d['next_visit_date'] ?? '-') ?></td>
                    <td class="p-2 font-semibold text-rose-700"><?= (int)($d['days_overdue'] ?? 0) ?></td>
                    <td class="p-2 flex flex-wrap gap-2">
                      <a class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-xs font-semibold"
                         href="/mch_system/nurse_portal/mother_history.php?mother_id=<?= (int)($d['mother_id'] ?? 0) ?>">
                        View
                      </a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="tab-panel hidden" data-panel="all">
          <?php if (count($defaulters) === 0): ?>
            <div class="bg-emerald-100 text-emerald-700 p-3 rounded-lg">No ANC defaulters for this page.</div>
          <?php else: ?>
            <div class="flex items-center justify-between mb-3 text-sm text-slate-600">
              <div>
                Page <?= (int)$page ?> of <?= (int)$totalPages ?>
              </div>
              <div class="flex gap-2 items-center">
                <?php
                  $query = $_GET;
                  $query['per_page'] = $perPage;
                ?>
                <?php if ($page > 1): ?>
                  <?php $query['page'] = $page - 1; ?>
                  <a class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50" href="/mch_system/nurse_portal/anc_defaulters.php?<?= http_build_query($query) ?>">
                    Prev
                  </a>
                <?php endif; ?>
                <?php if ($page < $totalPages): ?>
                  <?php $query['page'] = $page + 1; ?>
                  <a class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50" href="/mch_system/nurse_portal/anc_defaulters.php?<?= http_build_query($query) ?>">
                    Next
                  </a>
                <?php endif; ?>
              </div>
            </div>

            <div class="overflow-x-auto">
              <table class="w-full text-sm mch-table mch-scroll">
                <thead>
                  <tr>
                    <th class="p-2 text-left">Mother</th>
                    <th class="p-2 text-left">Hospital #</th>
                    <th class="p-2 text-left">Phone</th>
                    <th class="p-2 text-left">Next Visit</th>
                    <th class="p-2 text-left">Days Overdue</th>
                    <th class="p-2 text-left">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($defaulters as $d): ?>
                    <tr class="border-t border-slate-200">
                      <td class="p-2"><?= htmlspecialchars($d['fullname']) ?></td>
                      <td class="p-2"><?= $d['hospital_number'] ?></td>
                      <td class="p-2"><?= $d['phone'] ?></td>
                      <td class="p-2 text-rose-700 font-semibold"><?= htmlspecialchars($d['next_visit_date'] ?? '-') ?></td>
                      <td class="p-2 font-semibold text-rose-700"><?= (int)($d['days_overdue'] ?? 0) ?></td>
                      <td class="p-2">
                        <a class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-xs font-semibold"
                           href="/mch_system/nurse_portal/mother_history.php?mother_id=<?= (int)($d['mother_id'] ?? 0) ?>#anc-visits">
                          View
                        </a>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . "/../includes/footer.php"; ?>

<script>
  (function () {
    const triggers = Array.from(document.querySelectorAll('.tab-trigger'));
    const panels = Array.from(document.querySelectorAll('.tab-panel'));
    if (!triggers.length || !panels.length) return;
    const activate = (target) => {
      triggers.forEach(btn => btn.classList.toggle('active', btn.dataset.panel === target));
      panels.forEach(panel => panel.classList.toggle('hidden', panel.dataset.panel !== target));
    };
    triggers.forEach(btn => btn.addEventListener('click', () => activate(btn.dataset.panel)));
    activate('top');
  })();
</script>
