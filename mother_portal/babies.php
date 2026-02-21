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

// Fetch Babies
$babies = [];
if ($mother_id) {
    $babiesStmt = $pdo->prepare("SELECT * FROM babies WHERE mother_id = ?");
    $babiesStmt->execute([$mother_id]);
    $babies = $babiesStmt->fetchAll(PDO::FETCH_ASSOC);
}

$shotsByBaby = [];
if (!empty($babies)) {
    $ids = array_values(array_filter(array_map(static fn ($b) => (int)($b['id'] ?? 0), $babies), static fn ($id) => $id > 0));
    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $shotsStmt = $pdo->prepare("
            SELECT baby_id, vaccine, due_date, status, given_date
            FROM immunizations
            WHERE baby_id IN ({$placeholders})
            ORDER BY baby_id ASC, due_date ASC, id ASC
        ");
        $shotsStmt->execute($ids);
        $shots = $shotsStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($shots as $s) {
            $bid = (int)($s['baby_id'] ?? 0);
            if ($bid <= 0) continue;
            if (!isset($shotsByBaby[$bid])) $shotsByBaby[$bid] = [];
            $shotsByBaby[$bid][] = $s;
        }
    }
}

// Growth (weights) - optional
$weightsByBaby = [];
try {
    $hasWeights = (bool)$pdo->query("SHOW TABLES LIKE 'baby_weights'")->fetchColumn();
} catch (Throwable $e) {
    $hasWeights = false;
}
if (!empty($babies) && $hasWeights) {
    $ids = array_values(array_filter(array_map(static fn ($b) => (int)($b['id'] ?? 0), $babies), static fn ($id) => $id > 0));
    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $wStmt = $pdo->prepare("
            SELECT baby_id, weight_kg, observed_date
            FROM baby_weights
            WHERE baby_id IN ({$placeholders})
              AND deleted_at IS NULL
            ORDER BY baby_id ASC, observed_date DESC, id DESC
        ");
        $wStmt->execute($ids);
        foreach ($wStmt->fetchAll(PDO::FETCH_ASSOC) as $w) {
            $bid = (int)($w['baby_id'] ?? 0);
            if ($bid <= 0) continue;
            $weightsByBaby[$bid] ??= [];
            if (count($weightsByBaby[$bid]) < 5) {
                $weightsByBaby[$bid][] = $w;
            }
        }
    }
}
?>

<div class="ml-64 p-6">
    <div class="mch-card bg-white/90 p-6 border border-slate-100">
        <h1 class="text-2xl font-semibold mb-4 text-slate-900">Babies &amp; Immunizations</h1>

        <?php if (!empty($error)): ?>
            <div class="bg-amber-100 text-amber-800 p-3 rounded-lg mb-4"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (empty($babies)): ?>
            <p class="text-slate-500">No babies registered</p>
        <?php else: ?>
            <?php foreach ($babies as $baby): ?>
                <div class="border border-slate-200 rounded-xl p-4 mb-4 bg-white">
                    <p class="text-slate-900 font-semibold"><?= htmlspecialchars($baby['baby_name']) ?></p>
                    <p class="text-sm text-slate-600">DOB: <?= $baby['dob'] ?></p>
                    <div class="mt-2 grid grid-cols-2 md:grid-cols-4 gap-3 text-sm text-slate-700">
                        <div><span class="font-semibold">Gender:</span> <?= $baby['gender'] ?></div>
                        <div><span class="font-semibold">Birth Weight:</span> <?= $baby['birth_weight'] ?? '-' ?> kg</div>
                    </div>

                    <div class="mt-4">
                        <div class="text-sm font-semibold text-slate-900 mb-2">Immunizations</div>
                        <?php $babyShots = $shotsByBaby[(int)$baby['id']] ?? []; ?>
                        <?php if (empty($babyShots)): ?>
                            <p class="text-sm text-slate-500">No immunizations recorded</p>
                        <?php else: ?>
                            <table class="w-full text-sm mch-table mch-scroll">
                                <thead>
                                    <tr>
                                        <th class="p-2 text-left">Vaccine</th>
                                        <th class="p-2">Due Date</th>
                                        <th class="p-2">Status</th>
                                        <th class="p-2">Given Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($babyShots as $s): ?>
                                        <tr class="border-t border-slate-200">
                                            <td class="p-2"><?= htmlspecialchars((string)($s['vaccine'] ?? '')) ?></td>
                                            <td class="p-2">
                                                <?= !empty($s['due_date']) ? htmlspecialchars((string)$s['due_date']) : '-' ?>
                                                <?php if (!empty($s['due_date'])): ?>
                                                    <div class="text-xs text-slate-500">
                                                        <?= strtotime((string)$s['due_date']) >= strtotime(date('Y-m-d')) ? daysUntilLabel((string)$s['due_date']) : daysAgoLabel((string)$s['due_date']) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="p-2 font-semibold <?= (($s['status'] ?? '') === 'GIVEN') ? 'text-emerald-600' : 'text-rose-600' ?>">
                                                <?= htmlspecialchars((string)($s['status'] ?? '')) ?>
                                            </td>
                                            <td class="p-2">
                                                <?= !empty($s['given_date']) ? htmlspecialchars((string)$s['given_date']) : '-' ?>
                                                <?php if (!empty($s['given_date'])): ?>
                                                    <div class="text-xs text-slate-500"><?= daysAgoLabel((string)$s['given_date']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>

                    <div class="mt-6">
                        <div class="text-sm font-semibold text-slate-900 mb-2">Growth (Recent Weights)</div>
                        <?php $bw = $weightsByBaby[(int)$baby['id']] ?? []; ?>
                        <?php if (empty($bw)): ?>
                            <p class="text-sm text-slate-500">No weights recorded yet.</p>
                        <?php else: ?>
                            <div class="space-y-1 text-sm text-slate-700">
                                <?php foreach ($bw as $w): ?>
                                    <div class="flex items-center justify-between">
                                        <span><?= htmlspecialchars((string)$w['observed_date']) ?></span>
                                        <span class="font-semibold"><?= htmlspecialchars((string)$w['weight_kg']) ?> kg</span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . "/../includes/footer.php"; ?>
