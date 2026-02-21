<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";

auth_guard(['admin', 'nurse']);

$role = $_SESSION['role'] ?? 'nurse';
$baby_id = (int)($_GET['baby_id'] ?? 0);
if ($baby_id <= 0) {
    http_response_code(400);
    echo "<h1>400 Bad Request</h1><p>Missing or invalid baby_id.</p>";
    exit;
}

$bStmt = $pdo->prepare("
    SELECT b.id, b.baby_name, b.dob, b.gender,
           m.fullname AS mother_name, m.hospital_number, m.id AS mother_id
    FROM babies b
    JOIN mothers m ON b.mother_id = m.id
    WHERE b.id = ?
    LIMIT 1
");
$bStmt->execute([$baby_id]);
$baby = $bStmt->fetch(PDO::FETCH_ASSOC);
if (!$baby) {
    http_response_code(404);
    echo "<h1>404 Not Found</h1><p>Baby not found.</p>";
    exit;
}

$iStmt = $pdo->prepare("
    SELECT id, vaccine, due_date, status, given_date
    FROM immunizations
    WHERE baby_id = ?
    ORDER BY due_date ASC, id ASC
");
$iStmt->execute([$baby_id]);
$immunizations = $iStmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . "/../includes/header.php";
if ($role === 'admin') {
    include __DIR__ . "/../includes/sidebar_admin.php";
} else {
    include __DIR__ . "/../includes/sidebar_nurse.php";
}
?>

<div class="ml-64 p-6 max-w-6xl space-y-6">
    <div class="mch-card bg-white/90 p-6 border border-slate-100">
        <div class="flex items-start justify-between gap-3 flex-wrap">
            <div>
                <h1 class="text-2xl font-semibold text-slate-900 mb-1">Immunizations</h1>
                <div class="text-sm text-slate-600">
                    Baby: <span class="font-medium"><?= htmlspecialchars($baby['baby_name']) ?></span>
                    · Mother: <span class="font-medium"><?= htmlspecialchars($baby['mother_name']) ?></span>
                </div>
                <div class="text-xs text-slate-500 mt-1">
                    DOB: <?= htmlspecialchars($baby['dob']) ?> · Gender: <?= htmlspecialchars($baby['gender']) ?> · Hospital #: <?= htmlspecialchars($baby['hospital_number'] ?? '-') ?>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <a href="/mch_system/nurse_portal/baby_history.php?baby_id=<?= (int)$baby['id'] ?>"
                   class="inline-flex items-center px-3 py-2 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-sm">
                    Baby History
                </a>
                <a href="/mch_system/nurse_portal/dashboard.php"
                   class="inline-flex items-center px-3 py-2 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-sm">
                    Dashboard
                </a>
            </div>
        </div>
    </div>

    <div class="mch-card bg-white/90 p-6 border border-slate-100">
        <?php if (empty($immunizations)): ?>
            <p class="text-slate-500">No immunizations found for this baby.</p>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm mch-table mch-scroll">
                    <thead>
                        <tr>
                            <th class="p-2 border-b text-left">Vaccine</th>
                            <th class="p-2 border-b text-left">Due Date</th>
                            <th class="p-2 border-b text-left">Status</th>
                            <th class="p-2 border-b text-left">Given Date</th>
                            <th class="p-2 border-b text-left">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($immunizations as $i): ?>
                            <?php $isGiven = (($i['status'] ?? '') === 'GIVEN'); ?>
                            <tr class="border-t border-slate-200">
                                <td class="p-2"><?= htmlspecialchars($i['vaccine']) ?></td>
                                <td class="p-2"><?= htmlspecialchars($i['due_date']) ?></td>
                                <td class="p-2" id="status-<?= (int)$i['id'] ?>">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs <?= $isGiven ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' ?>">
                                        <?= htmlspecialchars($i['status']) ?>
                                    </span>
                                </td>
                                <td class="p-2 text-slate-600" id="given-<?= (int)$i['id'] ?>"><?= htmlspecialchars($i['given_date'] ?? '-') ?></td>
                                <td class="p-2">
                                    <button type="button"
                                            class="toggle-btn px-3 py-1 rounded-lg border text-sm <?= $isGiven ? 'bg-slate-50 border-slate-200' : 'bg-sky-50 border-sky-200 text-sky-700' ?>"
                                            data-id="<?= (int)$i['id'] ?>">
                                        <?= $isGiven ? 'Undo' : 'Given' ?>
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
  async function postToggle(payload) {
    const res = await fetch('/mch_system/immunizations/toggle_immunization.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const data = await res.json().catch(() => ({}));
    return { res, data };
  }

  document.querySelectorAll('.toggle-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
      const immunizationId = btn.getAttribute('data-id');
      const statusCell = document.getElementById('status-' + immunizationId);
      const givenCell = document.getElementById('given-' + immunizationId);
      const isGiven = btn.textContent.trim() === 'Undo';
      const action = isGiven ? 'undo' : 'complete';

      try {
        let { data } = await postToggle({ id: immunizationId, action });

        if (!data.success && data.code === 'EARLY' && action === 'complete') {
          const reason = prompt((data.error || 'This immunization is not due yet.') + "\n\nEnter override reason (min 10 characters) to record it today:");
          if (!reason) return;
          ({ data } = await postToggle({ id: immunizationId, action, override: true, override_reason: reason }));
        }

        if (!data.success) {
          alert(data.error || 'Failed to update immunization.');
          return;
        }

        statusCell.innerHTML = `<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs ${data.status === 'GIVEN' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'}">${data.status}</span>`;
        givenCell.textContent = data.given_date || '-';
        btn.textContent = data.status === 'GIVEN' ? 'Undo' : 'Given';
        btn.className = 'toggle-btn px-3 py-1 rounded-lg border text-sm ' + (data.status === 'GIVEN' ? 'bg-slate-50 border-slate-200' : 'bg-sky-50 border-sky-200 text-sky-700');
      } catch (e) {
        alert('Network error while updating immunization.');
      }
    });
  });
</script>

<?php include __DIR__ . "/../includes/footer.php"; ?>
