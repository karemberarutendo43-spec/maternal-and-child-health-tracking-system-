<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_mother.php";

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
    $ancStmt = $pdo->prepare("SELECT * FROM anc_visits WHERE mother_id = ? ORDER BY visit_date ASC");
    $ancStmt->execute([$mother_id]);
    $ancVisits = $ancStmt->fetchAll(PDO::FETCH_ASSOC);
}

function daysAgoLabel($dateStr) {
    $ts = strtotime($dateStr);
    if (!$ts) return '';
    $days = (int)floor((time() - $ts) / 86400);
    if ($days <= 0) return 'today';
    if ($days === 1) return '1 day ago';
    return $days . ' days ago';
}

function daysUntilLabel($dateStr) {
    $ts = strtotime($dateStr);
    if (!$ts) return '';
    $days = (int)ceil(($ts - time()) / 86400);
    if ($days <= 0) return 'today';
    if ($days === 1) return 'in 1 day';
    return 'in ' . $days . ' days';
}
?>

<div class="ml-64 p-6">
    <div class="mch-card bg-white/90 p-6 border border-slate-100">
        <div class="flex items-start justify-between gap-4 mb-4">
            <h1 class="text-2xl font-semibold text-slate-900">My ANC Visits</h1>
            <a href="/mch_system/mother_portal/dashboard.php" class="text-xs font-semibold text-slate-600 hover:text-slate-900">
                Back to Dashboard
            </a>
        </div>

        <?php if (!empty($error)): ?>
            <div class="bg-amber-100 text-amber-800 p-3 rounded-lg mb-4"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (empty($ancVisits)): ?>
            <p class="text-slate-500">No ANC visits recorded</p>
        <?php else: ?>
            <table class="w-full text-sm mch-table mch-scroll">
                <thead>
                    <tr>
                        <th class="p-2 text-left">Visit #</th>
                        <th class="p-2 text-left">Date</th>
                        <th class="p-2 text-left">Next Visit</th>
                        <th class="p-2 text-left">Notes</th>
                        <th class="p-2 text-left">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ancVisits as $v): ?>
                        <tr class="border-t border-slate-200">
                            <td class="p-2"><?= $v['visit_number'] ?? '-' ?></td>
                            <td class="p-2">
                                <?= $v['visit_date'] ?? '-' ?>
                                <?php if (!empty($v['visit_date'])): ?>
                                    <div class="text-xs text-slate-500"><?= daysAgoLabel($v['visit_date']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="p-2">
                                <?= $v['next_visit_date'] ?? '-' ?>
                                <?php if (!empty($v['next_visit_date'])): ?>
                                    <div class="text-xs text-slate-500"><?= daysUntilLabel($v['next_visit_date']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="p-2"><?= htmlspecialchars(trim((string)($v['notes'] ?? '')) !== '' ? (string)$v['notes'] : 'N/A') ?></td>
                            <td class="p-2">
                                <span class="<?= ($v['status'] ?? 'PENDING') === 'COMPLETED' ? 'text-emerald-600 font-semibold' : 'text-rose-600 font-semibold' ?>">
                                    <?= $v['status'] ?? 'PENDING' ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . "/../includes/footer.php"; ?>
