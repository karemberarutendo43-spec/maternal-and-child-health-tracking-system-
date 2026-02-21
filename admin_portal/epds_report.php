<?php
require_once __DIR__ . "/../config/session.php";
mch_session_start();
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/cards.php";

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: /mch_system/auth/login.php");
    exit;
}

$adminLevel = (string)($_SESSION['admin_level'] ?? 'SUPER');
$adminClinicId = (int)($_SESSION['clinic_id'] ?? 0);
$adminAllClinics = ($adminLevel === 'SUPER' || $adminLevel === 'DISTRICT');
if (!$adminAllClinics && $adminClinicId <= 0) {
    die("Clinic admin is not assigned to a clinic.");
}

$enabled = false;
try {
    $enabled = (bool)$pdo->query("SHOW TABLES LIKE 'epds_screenings'")->fetchColumn();
} catch (Throwable $e) {
    $enabled = false;
}

$month = (string)($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\\d{4}-\\d{2}$/', $month)) {
    $month = date('Y-m');
}
$start = $month . '-01';
$end = date('Y-m-t', strtotime($start));

$selectedClinicId = $adminAllClinics ? (int)($_GET['clinic_id'] ?? 0) : $adminClinicId;
if (!$adminAllClinics) {
    $selectedClinicId = $adminClinicId;
}

$clinics = [];
if ($adminAllClinics) {
    $clinics = $pdo->query("SELECT id, clinic_name FROM clinics ORDER BY clinic_name")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare("SELECT id, clinic_name FROM clinics WHERE id = ? LIMIT 1");
    $stmt->execute([$adminClinicId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $clinics = $row ? [$row] : [];
}

$counts = [];
$cases = [];

if ($enabled) {
    $countStmt = $pdo->prepare("
        SELECT
          e.risk_flag,
          COUNT(*) AS cnt
        FROM epds_screenings e
        JOIN mothers m ON e.mother_id = m.id
        LEFT JOIN users u ON m.user_id = u.id
        WHERE e.screening_date BETWEEN :start AND :end
          AND (:clinic_id = 0 OR u.clinic_id = :clinic_id)
        GROUP BY e.risk_flag
    ");
    $countStmt->execute([
        ':start' => $start,
        ':end' => $end,
        ':clinic_id' => $selectedClinicId,
    ]);
    $counts = $countStmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

    // Show urgent/high cases for supervisor follow-up (latest per mother in month)
    // Note: MySQL-safe (no correlated LIMIT subquery).
    $caseStmt = $pdo->prepare("
        SELECT
          e.screening_date,
          e.total_score,
          e.risk_flag,
          e.notes,
          m.id AS mother_id,
          m.fullname,
          m.hospital_number,
          m.phone,
          COALESCE(c.clinic_name, 'Unknown') AS clinic_name
        FROM epds_screenings e
        JOIN (
          SELECT mother_id, MAX(CONCAT(screening_date, '|', LPAD(id, 10, '0'))) AS max_key
          FROM epds_screenings
          WHERE screening_date BETWEEN :start AND :end
          GROUP BY mother_id
        ) last ON last.mother_id = e.mother_id
             AND CONCAT(e.screening_date, '|', LPAD(e.id, 10, '0')) = last.max_key
        JOIN mothers m ON e.mother_id = m.id
        LEFT JOIN users u ON m.user_id = u.id
        LEFT JOIN clinics c ON u.clinic_id = c.id
        WHERE e.screening_date BETWEEN :start AND :end
          AND e.risk_flag IN ('URGENT','HIGH')
          AND (:clinic_id = 0 OR u.clinic_id = :clinic_id)
        ORDER BY e.risk_flag DESC, e.total_score DESC, e.screening_date DESC
        LIMIT 200
    ");
    $caseStmt->execute([
        ':start' => $start,
        ':end' => $end,
        ':clinic_id' => $selectedClinicId,
    ]);
    $cases = $caseStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$low = (int)($counts['LOW'] ?? 0);
$moderate = (int)($counts['MODERATE'] ?? 0);
$high = (int)($counts['HIGH'] ?? 0);
$urgent = (int)($counts['URGENT'] ?? 0);
$total = $low + $moderate + $high + $urgent;

include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_admin.php";
?>

<div class="ml-64 p-6 max-w-7xl space-y-6">
    <div class="flex flex-col gap-2 md:flex-row md:items-end md:justify-between">
        <div>
            <h1 class="text-3xl font-semibold text-slate-900 mb-1">EPDS Report</h1>
            <p class="text-slate-600">Maternal mental health screening summary (clinic-scoped).</p>
        </div>
        <div class="text-sm text-slate-500">
            Month: <span class="font-semibold"><?= htmlspecialchars($month) ?></span>
        </div>
    </div>

    <?php if (!$enabled): ?>
        <div class="mch-card bg-amber-50 border border-amber-200 text-amber-800 p-4">
            EPDS module is not configured yet. Run <span class="font-mono">sql/epds_screenings.sql</span> on the database.
        </div>
    <?php else: ?>
        <div class="mch-card bg-amber-50 border border-amber-200 text-amber-900 p-4 text-sm">
            This report is a <span class="font-semibold">screening summary</span>. Follow your facility protocol for interpretation and referral.
        </div>

        <div class="mch-card bg-white/90 border border-slate-100 p-6 space-y-4">
            <form method="GET" class="flex flex-wrap gap-3 items-end">
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1">Month</label>
                    <input type="month" name="month" value="<?= htmlspecialchars($month) ?>" class="border border-slate-200 p-2 rounded-lg bg-white" required>
                </div>
                <?php if ($adminAllClinics): ?>
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Clinic</label>
                        <select name="clinic_id" class="border border-slate-200 p-2 rounded-lg bg-white">
                            <option value="0">All Clinics</option>
                            <?php foreach ($clinics as $c): ?>
                                <option value="<?= (int)$c['id'] ?>" <?= $selectedClinicId === (int)$c['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c['clinic_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <button class="px-4 py-2 rounded-lg bg-slate-900 text-white text-sm">Apply</button>
            </form>

            <div class="grid grid-cols-1 md:grid-cols-5 gap-3">
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                    <div class="text-xs uppercase tracking-wide text-slate-500">Total</div>
                    <div class="text-2xl font-semibold text-slate-900 mt-1"><?= (int)$total ?></div>
                </div>
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4">
                    <div class="text-xs uppercase tracking-wide text-emerald-700">Low</div>
                    <div class="text-2xl font-semibold text-emerald-800 mt-1"><?= (int)$low ?></div>
                </div>
                <div class="rounded-xl border border-sky-200 bg-sky-50 p-4">
                    <div class="text-xs uppercase tracking-wide text-sky-700">Moderate</div>
                    <div class="text-2xl font-semibold text-sky-800 mt-1"><?= (int)$moderate ?></div>
                </div>
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4">
                    <div class="text-xs uppercase tracking-wide text-amber-700">High</div>
                    <div class="text-2xl font-semibold text-amber-800 mt-1"><?= (int)$high ?></div>
                </div>
                <div class="rounded-xl border border-rose-200 bg-rose-50 p-4">
                    <div class="text-xs uppercase tracking-wide text-rose-700">Urgent</div>
                    <div class="text-2xl font-semibold text-rose-800 mt-1"><?= (int)$urgent ?></div>
                </div>
            </div>
        </div>

        <div class="mch-card bg-white/90 border border-slate-100 p-6">
            <h2 class="text-lg font-semibold text-slate-900 mb-3">Urgent / High Cases (Latest in Month)</h2>
            <?php if (empty($cases)): ?>
                <p class="text-slate-500">No urgent/high EPDS cases found for this month.</p>
            <?php else: ?>
                <table class="w-full text-sm mch-table mch-scroll">
                    <thead>
                        <tr>
                            <th class="p-2 text-left">Clinic</th>
                            <th class="p-2 text-left">Mother</th>
                            <th class="p-2 text-left">Hospital #</th>
                            <th class="p-2 text-left">Phone</th>
                            <th class="p-2 text-left">Date</th>
                            <th class="p-2 text-left">Total</th>
                            <th class="p-2 text-left">Flag</th>
                            <th class="p-2 text-left">Notes</th>
                            <th class="p-2 text-left">Open</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cases as $c): ?>
                            <?php
                              $flag = (string)($c['risk_flag'] ?? 'LOW');
                              $flagClass = $flag === 'URGENT' ? 'text-rose-700 font-semibold' : 'text-amber-700 font-semibold';
                            ?>
                            <tr class="border-t border-slate-200">
                                <td class="p-2"><?= htmlspecialchars((string)$c['clinic_name']) ?></td>
                                <td class="p-2 font-semibold text-slate-900"><?= htmlspecialchars((string)$c['fullname']) ?></td>
                                <td class="p-2"><?= htmlspecialchars((string)($c['hospital_number'] ?? '')) ?></td>
                                <td class="p-2"><?= htmlspecialchars((string)($c['phone'] ?? '')) ?></td>
                                <td class="p-2"><?= htmlspecialchars((string)$c['screening_date']) ?></td>
                                <td class="p-2 font-semibold"><?= (int)($c['total_score'] ?? 0) ?></td>
                                <td class="p-2 <?= $flagClass ?>"><?= htmlspecialchars($flag) ?></td>
                                <td class="p-2"><?= htmlspecialchars((string)($c['notes'] ?? '-')) ?></td>
                                <td class="p-2">
                                    <a class="text-sm font-semibold text-teal-700 hover:underline"
                                       href="/mch_system/nurse_portal/mother_history.php?mother_id=<?= (int)$c['mother_id'] ?>">
                                        Mother History
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . "/../includes/footer.php"; ?>
