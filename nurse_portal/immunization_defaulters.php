<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/cards.php";

$page = 'immunization_defaulters';
include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_nurse.php";

$today = date('Y-m-d');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(10, min(100, (int)($_GET['per_page'] ?? 25)));
$offset = ($page - 1) * $perPage;

// Optional: keep clinical_status (0/1/2) in sync for CDSS-style reporting.
if (db_has_column($pdo, 'immunizations', 'clinical_status')) {
    try {
        $pdo->query("UPDATE immunizations SET clinical_status = 2 WHERE status = 'PENDING' AND due_date < CURDATE()");
    } catch (Throwable $e) {
        // ignore
    }
}

$baseImmunSql = "
    FROM immunizations i
    JOIN babies b ON i.baby_id = b.id
    JOIN mothers m ON b.mother_id = m.id
    WHERE i.status = 'PENDING'
      AND i.due_date < ?
";

$countImmun = $pdo->prepare("SELECT COUNT(DISTINCT b.id) {$baseImmunSql}");
$countImmun->execute([$today]);
$totalShots = (int)$countImmun->fetchColumn();
$totalPages = max(1, (int)ceil($totalShots / $perPage));

$topStmt = $pdo->prepare("
    SELECT 
        b.id AS baby_id,
        b.baby_name,
        m.fullname AS mother_name,
        MIN(i.due_date) AS next_due,
        GROUP_CONCAT(CONCAT(i.vaccine, ' (', DATE_FORMAT(i.due_date, '%d %b'), ')') ORDER BY i.due_date ASC SEPARATOR ', ') AS pending_vaccines,
        COUNT(*) AS pending_count,
        MAX(DATEDIFF(?, i.due_date)) AS max_overdue
    {$baseImmunSql}
    GROUP BY b.id, b.baby_name, m.fullname
    ORDER BY max_overdue DESC, next_due ASC
    LIMIT 25
");
$topStmt->execute([$today, $today]);
$topShots = $topStmt->fetchAll(PDO::FETCH_ASSOC);

 $stmt = $pdo->prepare("
    SELECT 
        b.id AS baby_id,
        b.baby_name,
        m.fullname AS mother_name,
        MIN(i.due_date) AS next_due,
        GROUP_CONCAT(CONCAT(i.vaccine, ' (', DATE_FORMAT(i.due_date, '%d %b'), ')') ORDER BY i.due_date ASC SEPARATOR ', ') AS pending_vaccines,
        COUNT(*) AS pending_count,
        MAX(DATEDIFF(?, i.due_date)) AS max_overdue
    {$baseImmunSql}
    GROUP BY b.id, b.baby_name, m.fullname
    ORDER BY next_due ASC
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
      <h2 class="text-2xl font-semibold text-slate-900">Immunization Defaulters</h2>
      <a href="/mch_system/nurse_portal/reports.php" class="px-4 py-2 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-sm">Back to Reports</a>
    </div>

    <?php if (count($defaulters) === 0 && count($topShots) === 0): ?>
      <div class="bg-emerald-100 text-emerald-700 p-3 rounded-lg">No immunization defaulters</div>
    <?php else: ?>
      <div class="space-y-4">
        <div class="flex items-center gap-2 border border-slate-200 bg-slate-50 rounded-full p-1 text-sm font-semibold">
          <button type="button" class="px-4 py-1 rounded-full transition tab-trigger active:bg-white" data-panel="top">Top 25</button>
          <button type="button" class="px-4 py-1 rounded-full transition tab-trigger" data-panel="all">All Defaulters</button>
        </div>
        <div class="tab-panel" data-panel="top">
          <div class="overflow-x-auto">
            <table class="w-full text-sm mch-table">
              <thead>
                <tr>
                  <th class="p-2 text-left">Baby</th>
                  <th class="p-2 text-left">Vaccine</th>
                  <th class="p-2 text-left">Due Date</th>
                  <th class="p-2 text-left">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($topShots as $s): ?>
                  <?php
                    $nextDue = (string)($s['next_due'] ?? '');
                    $daysOverdue = (int)($s['max_overdue'] ?? 0);
                    $badge = 'bg-rose-100 text-rose-700';
                    $label = 'STRICTLY PENDING';
                    $dotClass = 'bg-rose-600 animate-pulse';
                    if ($nextDue === date('Y-m-d')) {
                      $badge = 'bg-amber-100 text-amber-700';
                      $label = 'DUE TODAY';
                      $dotClass = 'bg-amber-600';
                    }
                    if ($daysOverdue <= 0 && $nextDue !== date('Y-m-d')) {
                      $badge = 'bg-slate-100 text-slate-700';
                      $label = 'COMING UP';
                      $dotClass = 'bg-slate-500';
                    }
                    $pendingList = trim((string)($s['pending_vaccines'] ?? ''));
                    $pendingSnippet = $pendingList;
                    if ($pendingList !== '') {
                      if (function_exists('mb_strlen') && mb_strlen($pendingList) > 80) {
                        $pendingSnippet = mb_substr($pendingList, 0, 80) . '…';
                      } elseif (!function_exists('mb_strlen') && strlen($pendingList) > 80) {
                        $pendingSnippet = substr($pendingList, 0, 80) . '…';
                      }
                    }
                  ?>
                  <tr class="border-t border-slate-200">
                    <td class="p-2 font-semibold"><?= htmlspecialchars($s['baby_name']) ?></td>
                    <td class="p-2 text-slate-600"><?= htmlspecialchars($s['mother_name'] ?? '') ?></td>
                    <td class="p-2"><?= htmlspecialchars($nextDue ?: '-') ?></td>
                    <td class="p-2">
                      <div class="text-sm font-semibold text-slate-900"><?= (int)($s['pending_count'] ?? 0) ?> vaccine<?= ((int)($s['pending_count'] ?? 0) === 1) ? '' : 's' ?> pending</div>
                      <?php if ($pendingList): ?>
                        <div class="text-xs text-slate-500 mt-1" title="<?= htmlspecialchars($pendingList) ?>">
                          <?= htmlspecialchars($pendingSnippet) ?>
                        </div>
                      <?php endif; ?>
                    </td>
                    <td class="p-2">
                      <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-semibold <?= $badge ?>">
                        <span class="inline-block w-2 h-2 rounded-full <?= $dotClass ?>"></span>
                        <?= $label ?>
                      </span>
                      <?php if ($daysOverdue > 0): ?>
                        <div class="text-[10px] text-rose-600 mt-1"><?= $daysOverdue ?> day<?= $daysOverdue === 1 ? '' : 's' ?> overdue</div>
                      <?php endif; ?>
                    </td>
                    <td class="p-2">
                      <a class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-xs font-semibold"
                         href="/mch_system/nurse_portal/baby_history.php?baby_id=<?= (int)($s['baby_id'] ?? 0) ?>">
                        View vaccines
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
            <div class="bg-emerald-100 text-emerald-700 p-3 rounded-lg">No immunization defaulters for this page.</div>
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
                  <a class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50" href="/mch_system/nurse_portal/immunization_defaulters.php?<?= http_build_query($query) ?>">
                    Prev
                  </a>
                <?php endif; ?>
                <?php if ($page < $totalPages): ?>
                  <?php $query['page'] = $page + 1; ?>
                  <a class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50" href="/mch_system/nurse_portal/immunization_defaulters.php?<?= http_build_query($query) ?>">
                    Next
                  </a>
                <?php endif; ?>
              </div>
            </div>
            <div class="overflow-x-auto">
              <table class="w-full text-sm mch-table mch-scroll">
                <thead>
                  <tr>
                    <th class="p-2 text-left">Baby</th>
                    <th class="p-2 text-left">Mother</th>
                    <th class="p-2 text-left">Next due</th>
                    <th class="p-2 text-left">Pending vaccines</th>
                    <th class="p-2 text-left">Status</th>
                    <th class="p-2 text-center">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($defaulters as $d): ?>
                    <?php
                      $nextDue = (string)($d['next_due'] ?? '');
                      $daysOverdue = (int)($d['max_overdue'] ?? 0);
                      $badge = 'bg-rose-100 text-rose-700';
                      $label = 'STRICTLY PENDING';
                      $dotClass = 'bg-rose-600 animate-pulse';
                      if ($nextDue === date('Y-m-d')) {
                        $badge = 'bg-amber-100 text-amber-700';
                        $label = 'DUE TODAY';
                        $dotClass = 'bg-amber-600';
                      }
                      if ($daysOverdue <= 0 && $nextDue !== date('Y-m-d')) {
                        $badge = 'bg-slate-100 text-slate-700';
                        $label = 'COMING UP';
                        $dotClass = 'bg-slate-500';
                      }
                      $pendingList = trim((string)($d['pending_vaccines'] ?? ''));
                      $pendingSnippet = $pendingList;
                      if ($pendingList !== '') {
                        if (function_exists('mb_strlen') && mb_strlen($pendingList) > 80) {
                          $pendingSnippet = mb_substr($pendingList, 0, 80) . '…';
                        } elseif (!function_exists('mb_strlen') && strlen($pendingList) > 80) {
                          $pendingSnippet = substr($pendingList, 0, 80) . '…';
                        }
                      }
                    ?>
                    <tr class="border-t border-slate-200">
                      <td class="p-2 font-semibold"><?= htmlspecialchars($d['baby_name']) ?></td>
                      <td class="p-2 text-slate-600"><?= htmlspecialchars($d['mother_name'] ?? '') ?></td>
                      <td class="p-2"><?= htmlspecialchars($nextDue ?: '-') ?></td>
                      <td class="p-2">
                        <div class="text-sm font-semibold text-slate-900"><?= (int)($d['pending_count'] ?? 0) ?> vaccine<?= ((int)($d['pending_count'] ?? 0) === 1) ? '' : 's' ?> pending</div>
                        <?php if ($pendingList): ?>
                          <div class="text-xs text-slate-500 mt-1" title="<?= htmlspecialchars($pendingList) ?>">
                            <?= htmlspecialchars($pendingSnippet) ?>
                          </div>
                        <?php endif; ?>
                      </td>
                      <td class="p-2">
                        <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-semibold <?= $badge ?>">
                          <span class="inline-block w-2 h-2 rounded-full <?= $dotClass ?>"></span>
                          <?= $label ?>
                        </span>
                        <?php if ($daysOverdue > 0): ?>
                          <div class="text-[10px] text-rose-600 mt-1"><?= $daysOverdue ?> day<?= $daysOverdue === 1 ? '' : 's' ?> overdue</div>
                        <?php endif; ?>
                      </td>
                      <td class="p-2 text-center">
                        <a class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-xs font-semibold"
                           href="/mch_system/nurse_portal/baby_history.php?baby_id=<?= (int)($d['baby_id'] ?? 0) ?>">
                          View vaccines
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
    function activate(target) {
      triggers.forEach(btn => btn.classList.toggle('active', btn.dataset.panel === target));
      panels.forEach(panel => panel.classList.toggle('hidden', panel.dataset.panel !== target));
    }
    triggers.forEach(btn => btn.addEventListener('click', () => activate(btn.dataset.panel)));
    activate('top');
  })();
</script>
