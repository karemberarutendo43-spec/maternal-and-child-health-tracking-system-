<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";

$page = 'mothers_list';
include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_nurse.php";

// Fetch all mothers
$mothers = $pdo->query("
    SELECT id, hospital_number, fullname, dob, pregnancy_status, gravida, parity, phone
    FROM mothers
    ORDER BY fullname
")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="ml-64 p-6">
  <div class="mch-card bg-white/90 p-6 border border-slate-100">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-2xl font-semibold text-slate-900">Registered Mothers</h2>
      <div class="flex items-center gap-3 text-xs font-semibold">
        <a href="/mch_system/nurse_portal/reports.php" class="text-slate-600 hover:text-slate-900">
          Back to Reports
        </a>
        <a href="/mch_system/nurse_portal/dashboard.php#quick_links" class="text-slate-600 hover:text-slate-900" id="return_quick_links">
          Back to Quick Links
        </a>
      </div>
    </div>

    <?php if (count($mothers) === 0): ?>
      <p class="text-slate-500">No mothers registered.</p>
    <?php else: ?>
      <table class="w-full text-sm mch-table mch-scroll">
        <thead>
          <tr>
            <th class="p-2 text-left">Hospital #</th>
            <th class="p-2 text-left">Full Name</th>
            <th class="p-2 text-left">DOB</th>
            <th class="p-2 text-left">Pregnancy Status</th>
            <th class="p-2 text-left">Gravida</th>
            <th class="p-2 text-left">Parity</th>
            <th class="p-2 text-left">Phone</th>
            <th class="p-2 text-left">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($mothers as $m): ?>
            <tr class="border-t border-slate-200">
              <td class="p-2"><?= htmlspecialchars($m['hospital_number']) ?></td>
              <td class="p-2"><?= htmlspecialchars($m['fullname']) ?></td>
              <td class="p-2"><?= $m['dob'] ?></td>
              <td class="p-2"><?= $m['pregnancy_status'] ?></td>
              <td class="p-2"><?= $m['gravida'] ?></td>
              <td class="p-2"><?= $m['parity'] ?></td>
              <td class="p-2"><?= htmlspecialchars($m['phone']) ?></td>
              <td class="p-2 space-x-2">
                <a href="anc_visits.php?mother_id=<?= $m['id'] ?>" class="inline-flex items-center px-3 py-1 rounded-lg bg-amber-50 border border-amber-200 hover:bg-amber-100 text-sm">View ANC</a>
                <?php if ($m['pregnancy_status'] === 'Pregnant'): ?>
                  <a href="log_delivery.php?mother_id=<?= $m['id'] ?>" class="inline-flex items-center px-3 py-1 rounded-lg bg-emerald-50 border border-emerald-200 hover:bg-emerald-100 text-sm">Log Delivery</a>
                <?php endif; ?>
                <a href="transfer_mother.php?mother_id=<?= $m['id'] ?>" class="inline-flex items-center px-3 py-1 rounded-lg bg-slate-50 border border-slate-200 hover:bg-slate-100 text-sm">Transfer</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . "/../includes/footer.php"; ?>
