<?php
require_once __DIR__ . "/../config/session.php";
mch_session_start();
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: /mch_system/auth/login.php");
    exit;
}

$adminLevel = (string)($_SESSION['admin_level'] ?? 'SUPER');
$adminClinicId = (int)($_SESSION['clinic_id'] ?? 0);
if ($adminLevel !== 'SUPER' && $adminLevel !== 'DISTRICT' && $adminClinicId <= 0) {
    die("Clinic admin is not assigned to a clinic.");
}

$month = trim((string)($_GET['month'] ?? ''));
if ($month === '') {
    $month = date('Y-m');
}
$hasMonth = (bool)preg_match('/^\d{4}-\d{2}$/', $month);

$selectedClinicId = 0;
if ($adminLevel === 'SUPER' || $adminLevel === 'DISTRICT') {
    $selectedClinicId = max(0, (int)($_GET['clinic_id'] ?? 0));
} else {
    $selectedClinicId = $adminClinicId;
}

$clinics = [];
if ($adminLevel === 'SUPER' || $adminLevel === 'DISTRICT') {
    try {
        $clinics = $pdo->query("SELECT id, clinic_name FROM clinics ORDER BY clinic_name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $clinics = [];
    }
}

$rows = [];
$totals = [
    'due_shots' => 0,
    'given_shots' => 0,
    'pending_shots' => 0,
    'babies_due' => 0,
    'babies_fully_given' => 0,
];

if ($hasMonth) {
    $start = $month . "-01";
    $end = date("Y-m-t", strtotime($start));

    $clinicFilter = '';
    $params = [$start, $end];
    if ($selectedClinicId > 0) {
        $clinicFilter = " AND u.clinic_id = ? ";
        $params[] = $selectedClinicId;
    }

    // Shot-based coverage (due vs given).
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(c.clinic_name, 'Unassigned') AS clinic_name,
            COALESCE(u.clinic_id, 0) AS clinic_id,
            COUNT(*) AS due_shots,
            SUM(CASE WHEN i.status = 'GIVEN' THEN 1 ELSE 0 END) AS given_shots,
            SUM(CASE WHEN i.status = 'PENDING' THEN 1 ELSE 0 END) AS pending_shots
        FROM immunizations i
        JOIN babies b ON i.baby_id = b.id
        JOIN mothers m ON b.mother_id = m.id
        LEFT JOIN users u ON m.user_id = u.id
        LEFT JOIN clinics c ON u.clinic_id = c.id
        WHERE i.due_date BETWEEN ? AND ?
        {$clinicFilter}
        GROUP BY COALESCE(u.clinic_id, 0), COALESCE(c.clinic_name, 'Unassigned')
        ORDER BY clinic_name ASC
    ");
    $stmt->execute($params);
    $shotRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Baby-based coverage: a baby is "covered" if all shots due in the month are given.
    $stmt = $pdo->prepare("
        SELECT
            clinic_id,
            COUNT(*) AS babies_due,
            SUM(CASE WHEN has_pending = 0 THEN 1 ELSE 0 END) AS babies_fully_given
        FROM (
            SELECT
                COALESCE(u.clinic_id, 0) AS clinic_id,
                b.id AS baby_id,
                MAX(CASE WHEN i.status = 'PENDING' THEN 1 ELSE 0 END) AS has_pending
            FROM immunizations i
            JOIN babies b ON i.baby_id = b.id
            JOIN mothers m ON b.mother_id = m.id
            LEFT JOIN users u ON m.user_id = u.id
            WHERE i.due_date BETWEEN ? AND ?
            {$clinicFilter}
            GROUP BY COALESCE(u.clinic_id, 0), b.id
        ) t
        GROUP BY clinic_id
    ");
    $stmt->execute($params);
    $babyRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $babyMap = [];
    foreach ($babyRows as $r) {
        $babyMap[(int)($r['clinic_id'] ?? 0)] = [
            'babies_due' => (int)($r['babies_due'] ?? 0),
            'babies_fully_given' => (int)($r['babies_fully_given'] ?? 0),
        ];
    }

    foreach ($shotRows as $r) {
        $cid = (int)($r['clinic_id'] ?? 0);
        $due = (int)($r['due_shots'] ?? 0);
        $given = (int)($r['given_shots'] ?? 0);
        $pending = (int)($r['pending_shots'] ?? 0);
        $babyDue = (int)($babyMap[$cid]['babies_due'] ?? 0);
        $babyFull = (int)($babyMap[$cid]['babies_fully_given'] ?? 0);

        $rows[] = [
            'clinic_id' => $cid,
            'clinic_name' => (string)($r['clinic_name'] ?? ''),
            'due_shots' => $due,
            'given_shots' => $given,
            'pending_shots' => $pending,
            'shot_coverage' => $due > 0 ? round(($given / $due) * 100, 1) : null,
            'babies_due' => $babyDue,
            'babies_fully_given' => $babyFull,
            'baby_coverage' => $babyDue > 0 ? round(($babyFull / $babyDue) * 100, 1) : null,
        ];

        $totals['due_shots'] += $due;
        $totals['given_shots'] += $given;
        $totals['pending_shots'] += $pending;
        $totals['babies_due'] += $babyDue;
        $totals['babies_fully_given'] += $babyFull;
    }
}

include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_admin.php";
?>

<div class="ml-64 p-6 max-w-7xl space-y-6">
    <div class="flex items-start justify-between gap-3 flex-wrap">
        <div>
            <h1 class="text-3xl font-semibold text-slate-900 mb-1">Clinic Coverage (Immunizations)</h1>
            <p class="text-slate-600">Decision support: due vs given immunizations per clinic for a selected month.</p>
        </div>
        <a href="/mch_system/admin_portal/reports.php" class="px-4 py-2 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-sm">Back to Reports</a>
    </div>

    <div class="mch-card bg-white/90 p-6 border border-slate-100">
        <form method="GET" class="flex flex-col lg:flex-row gap-3 items-start lg:items-end">
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1">Month</label>
                <input type="month" name="month" value="<?= htmlspecialchars($month) ?>" required class="border border-slate-200 p-2 rounded-lg bg-white">
            </div>

            <?php if ($adminLevel === 'SUPER' || $adminLevel === 'DISTRICT'): ?>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1">Clinic</label>
                    <select name="clinic_id" class="border border-slate-200 p-2 rounded-lg bg-white">
                        <option value="0">All clinics</option>
                        <?php foreach ($clinics as $c): ?>
                            <option value="<?= (int)$c['id'] ?>" <?= (int)$selectedClinicId === (int)$c['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string)$c['clinic_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <button class="mch-btn px-4 py-2">View Coverage</button>
            <a href="/mch_system/admin_portal/coverage.php" class="px-4 py-2 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-sm">Reset</a>
        </form>

        <div class="text-xs text-slate-500 mt-3">
            Note: This “coverage” is computed from immunizations <span class="font-semibold">due in the month</span> vs <span class="font-semibold">marked given</span> in the system. It is not a population target estimate.
        </div>
    </div>

    <?php if (!$hasMonth): ?>
        <div class="mch-card bg-rose-50 border border-rose-200 text-rose-700 p-4">
            Invalid month format. Use YYYY-MM.
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
            <?php
                $overallShots = $totals['due_shots'] > 0 ? round(($totals['given_shots'] / $totals['due_shots']) * 100, 1) : null;
                $overallBabies = $totals['babies_due'] > 0 ? round(($totals['babies_fully_given'] / $totals['babies_due']) * 100, 1) : null;
            ?>
            <div class="mch-card bg-white/90 p-5 border border-slate-100">
                <p class="text-xs uppercase tracking-wide text-slate-500">Due Shots (Month)</p>
                <p class="text-3xl font-semibold text-slate-900 mt-2"><?= (int)$totals['due_shots'] ?></p>
            </div>
            <div class="mch-card bg-white/90 p-5 border border-slate-100">
                <p class="text-xs uppercase tracking-wide text-slate-500">Given Shots (Month)</p>
                <p class="text-3xl font-semibold text-slate-900 mt-2"><?= (int)$totals['given_shots'] ?></p>
            </div>
            <div class="mch-card bg-emerald-50 p-5 border border-emerald-200">
                <p class="text-xs uppercase tracking-wide text-emerald-700">Shot Coverage</p>
                <p class="text-3xl font-semibold text-emerald-800 mt-2"><?= $overallShots === null ? '-' : htmlspecialchars((string)$overallShots . '%') ?></p>
            </div>
            <div class="mch-card bg-sky-50 p-5 border border-sky-200">
                <p class="text-xs uppercase tracking-wide text-sky-700">Baby Coverage</p>
                <p class="text-3xl font-semibold text-sky-800 mt-2"><?= $overallBabies === null ? '-' : htmlspecialchars((string)$overallBabies . '%') ?></p>
            </div>
        </div>

        <div class="mch-card bg-white/90 p-6 border border-slate-100">
            <div class="flex items-center justify-between gap-3 flex-wrap mb-4">
                <h2 class="text-xl font-semibold text-slate-900">Coverage by Clinic</h2>
                <div class="text-sm text-slate-600">Month: <span class="font-semibold"><?= htmlspecialchars($month) ?></span></div>
            </div>

            <?php if (empty($rows)): ?>
                <p class="text-slate-500">No immunizations due for this month in the selected scope.</p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm mch-table mch-scroll">
                        <thead>
                            <tr>
                                <th class="p-2 text-left">Clinic</th>
                                <th class="p-2 text-right">Due</th>
                                <th class="p-2 text-right">Given</th>
                                <th class="p-2 text-right">Pending</th>
                                <th class="p-2 text-right">Shot %</th>
                                <th class="p-2 text-right">Babies Due</th>
                                <th class="p-2 text-right">Babies Fully Given</th>
                                <th class="p-2 text-right">Baby %</th>
                                <th class="p-2 text-left">Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r): ?>
                                <?php
                                    $shotPct = $r['shot_coverage'];
                                    $babyPct = $r['baby_coverage'];
                                    $shotBadge = $shotPct === null ? 'bg-slate-100 text-slate-700' : ($shotPct >= 80 ? 'bg-emerald-100 text-emerald-700' : ($shotPct >= 50 ? 'bg-amber-100 text-amber-700' : 'bg-rose-100 text-rose-700'));
                                    $babyBadge = $babyPct === null ? 'bg-slate-100 text-slate-700' : ($babyPct >= 80 ? 'bg-sky-100 text-sky-700' : ($babyPct >= 50 ? 'bg-amber-100 text-amber-700' : 'bg-rose-100 text-rose-700'));
                                    $q = [
                                        'month' => $month,
                                        'clinic_id' => (int)$r['clinic_id'],
                                    ];
                                ?>
                                <tr class="border-t border-slate-200">
                                    <td class="p-2 font-semibold"><?= htmlspecialchars((string)$r['clinic_name']) ?></td>
                                    <td class="p-2 text-right"><?= (int)$r['due_shots'] ?></td>
                                    <td class="p-2 text-right"><?= (int)$r['given_shots'] ?></td>
                                    <td class="p-2 text-right"><?= (int)$r['pending_shots'] ?></td>
                                    <td class="p-2 text-right">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold <?= $shotBadge ?>">
                                            <?= $shotPct === null ? '-' : htmlspecialchars((string)$shotPct . '%') ?>
                                        </span>
                                    </td>
                                    <td class="p-2 text-right"><?= (int)$r['babies_due'] ?></td>
                                    <td class="p-2 text-right"><?= (int)$r['babies_fully_given'] ?></td>
                                    <td class="p-2 text-right">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold <?= $babyBadge ?>">
                                            <?= $babyPct === null ? '-' : htmlspecialchars((string)$babyPct . '%') ?>
                                        </span>
                                    </td>
                                    <td class="p-2">
                                        <a href="/mch_system/admin_portal/coverage_details.php?<?= http_build_query($q) ?>"
                                           class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-xs font-semibold">
                                            View shots
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . "/../includes/footer.php"; ?>
