<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";

auth_guard(['nurse', 'admin']);

$mother_id = (int)($_GET['mother_id'] ?? 0);
if ($mother_id <= 0) {
    http_response_code(400);
    echo "<h1>400 Bad Request</h1><p>Missing or invalid mother_id.</p>";
    exit;
}

$mStmt = $pdo->prepare("SELECT id, fullname, hospital_number, pregnancy_status FROM mothers WHERE id = ? LIMIT 1");
$mStmt->execute([$mother_id]);
$mother = $mStmt->fetch(PDO::FETCH_ASSOC);
if (!$mother) {
    http_response_code(404);
    echo "<h1>404 Not Found</h1><p>Mother not found.</p>";
    exit;
}

$ancStmt = $pdo->prepare("
    SELECT id, visit_number, visit_date, next_visit_date, status, notes
    FROM anc_visits
    WHERE mother_id = ?
    ORDER BY COALESCE(visit_number, 999) ASC, COALESCE(visit_date, created_at) ASC, id ASC
");
$ancStmt->execute([$mother_id]);
$ancVisits = $ancStmt->fetchAll(PDO::FETCH_ASSOC);

$page = 'anc_visits';
include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_nurse.php";
?>

<div class="ml-64 p-6 max-w-6xl space-y-6">
    <div class="mch-card bg-white/90 p-6 border border-slate-100">
        <div class="flex items-start justify-between gap-3 flex-wrap">
            <div>
                <h1 class="text-2xl font-semibold text-slate-900 mb-1">ANC Visits</h1>
                <div class="text-sm text-slate-700">
                    <span class="font-semibold"><?= htmlspecialchars((string)$mother['fullname']) ?></span>
                    · Hospital #: <?= htmlspecialchars((string)($mother['hospital_number'] ?? '-')) ?>
                    · Status: <?= htmlspecialchars((string)($mother['pregnancy_status'] ?? '-')) ?>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <a href="/mch_system/nurse_portal/log_anc_visit.php?mother_id=<?= (int)$mother_id ?>"
                   class="inline-flex items-center px-3 py-2 rounded-lg bg-amber-50 border border-amber-200 hover:bg-amber-100 text-sm">
                    Log ANC Visit
                </a>
                <a href="/mch_system/nurse_portal/mother_history.php?mother_id=<?= (int)$mother_id ?>"
                   class="inline-flex items-center px-3 py-2 rounded-lg bg-slate-50 border border-slate-200 hover:bg-slate-100 text-sm">
                    Mother History
                </a>
                <a href="/mch_system/nurse_portal/mother_list.php"
                   class="inline-flex items-center px-3 py-2 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-sm">
                    Back
                </a>
            </div>
        </div>
    </div>

    <div class="mch-card bg-white/90 p-6 border border-slate-100">
        <?php if (empty($ancVisits)): ?>
            <p class="text-slate-500">No ANC visits recorded for this mother.</p>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm mch-table mch-scroll">
                    <thead>
                        <tr>
                            <th class="p-2 text-left">Visit #</th>
                            <th class="p-2 text-left">Visit Date</th>
                            <th class="p-2 text-left">Next Visit</th>
                            <th class="p-2 text-left">Status</th>
                            <th class="p-2 text-left">Notes</th>
                            <th class="p-2 text-left">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ancVisits as $v): ?>
                            <?php
                                $status = (string)($v['status'] ?? 'PENDING');
                                $isComplete = $status === 'COMPLETED';
                            ?>
                            <tr class="border-t border-slate-200">
                                <td class="p-2"><?= htmlspecialchars((string)($v['visit_number'] ?? '-')) ?></td>
                                <td class="p-2"><?= htmlspecialchars((string)($v['visit_date'] ?? '-')) ?></td>
                                <td class="p-2"><?= htmlspecialchars((string)($v['next_visit_date'] ?? '-')) ?></td>
                                <td class="p-2">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs <?= $isComplete ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' ?>">
                                        <?= htmlspecialchars($status) ?>
                                    </span>
                                </td>
                                <td class="p-2"><?= htmlspecialchars(trim((string)($v['notes'] ?? '')) !== '' ? (string)$v['notes'] : 'N/A') ?></td>
                                <td class="p-2">
                                    <button type="button"
                                            class="anc-toggle px-3 py-1 rounded-lg border text-sm <?= $isComplete ? 'bg-slate-50 border-slate-200' : 'bg-emerald-50 border-emerald-200 text-emerald-700' ?>"
                                            data-visit-id="<?= (int)$v['id'] ?>"
                                            data-visit-action="<?= $isComplete ? 'undo' : 'complete' ?>">
                                        <?= $isComplete ? 'Undo' : 'Attend Visit' ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
  async function postAncToggle(payload) {
    const res = await fetch('/mch_system/anc/toggle_anc.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const data = await res.json().catch(() => ({}));
    return { res, data };
  }

  document.querySelectorAll('.anc-toggle').forEach(btn => {
    btn.addEventListener('click', async () => {
      const id = btn.getAttribute('data-visit-id');
      const action = btn.getAttribute('data-visit-action');
      if (!id || !action) return;

      try {
        let { data } = await postAncToggle({ id, action });

        if (!data.success && data.code === 'EARLY' && action === 'complete') {
          const reason = prompt((data.error || 'This ANC visit is scheduled for a future date.') + "\n\nEnter override reason (min 10 characters) to mark as completed today:");
          if (!reason) return;
          ({ data } = await postAncToggle({ id, action, override: true, override_reason: reason }));
        }

        if (!data.success) {
          alert(data.error || 'Failed to update visit.');
          return;
        }
        window.location.reload();
      } catch (e) {
        alert('Network error while updating visit.');
      }
    });
  });
</script>

<?php include __DIR__ . "/../includes/footer.php"; ?>
