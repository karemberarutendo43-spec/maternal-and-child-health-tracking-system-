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
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    http_response_code(400);
    echo "<h1>400 Bad Request</h1><p>Missing or invalid month.</p>";
    exit;
}

$clinicId = (int)($_GET['clinic_id'] ?? 0);
if (($adminLevel !== 'SUPER' && $adminLevel !== 'DISTRICT') && $clinicId !== $adminClinicId) {
    http_response_code(403);
    echo "<h1>403 Forbidden</h1><p>You do not have permission to access this clinic.</p>";
    exit;
}

$statusFilter = strtoupper(trim((string)($_GET['status'] ?? 'ALL')));
if (!in_array($statusFilter, ['ALL', 'GIVEN', 'PENDING'], true)) {
    $statusFilter = 'ALL';
}

$start = $month . "-01";
$end = date("Y-m-t", strtotime($start));

$params = [$start, $end];
$clinicWhere = '';
if ($clinicId > 0) {
    $clinicWhere = " AND u.clinic_id = ? ";
    $params[] = $clinicId;
}

$statusWhere = '';
if ($statusFilter !== 'ALL') {
    $statusWhere = " AND i.status = ? ";
    $params[] = $statusFilter;
}

$clinicName = 'All Clinics';
if ($clinicId > 0) {
    $cstmt = $pdo->prepare("SELECT clinic_name FROM clinics WHERE id = ? LIMIT 1");
    $cstmt->execute([$clinicId]);
    $clinicName = (string)($cstmt->fetchColumn() ?: 'Clinic');
}

$stmt = $pdo->prepare("
    SELECT
        i.id,
        i.baby_id,
        b.baby_name,
        m.fullname AS mother_name,
        m.hospital_number,
        COALESCE(c.clinic_name, 'Unassigned') AS clinic_name,
        i.vaccine,
        i.due_date,
        i.status,
        i.given_date
    FROM immunizations i
    JOIN babies b ON i.baby_id = b.id
    JOIN mothers m ON b.mother_id = m.id
    LEFT JOIN users u ON m.user_id = u.id
    LEFT JOIN clinics c ON u.clinic_id = c.id
    WHERE i.due_date BETWEEN ? AND ?
    {$clinicWhere}
    {$statusWhere}
    ORDER BY i.due_date ASC, b.baby_name ASC, i.id ASC
");
$stmt->execute($params);
$shots = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Totals for the clinic/month (ignoring status filter)
$countParams = [$start, $end];
if ($clinicId > 0) $countParams[] = $clinicId;
$countStmt = $pdo->prepare("
    SELECT
        COUNT(*) AS due_shots,
        SUM(CASE WHEN i.status = 'GIVEN' THEN 1 ELSE 0 END) AS given_shots,
        SUM(CASE WHEN i.status = 'PENDING' THEN 1 ELSE 0 END) AS pending_shots
    FROM immunizations i
    JOIN babies b ON i.baby_id = b.id
    JOIN mothers m ON b.mother_id = m.id
    LEFT JOIN users u ON m.user_id = u.id
    WHERE i.due_date BETWEEN ? AND ?
    {$clinicWhere}
");
$countStmt->execute($countParams);
$tot = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$due = (int)($tot['due_shots'] ?? 0);
$given = (int)($tot['given_shots'] ?? 0);
$pending = (int)($tot['pending_shots'] ?? 0);
$pct = $due > 0 ? round(($given / $due) * 100, 1) : null;

include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_admin.php";
?>

<div class="ml-64 p-6 max-w-7xl space-y-6">
    <div class="flex items-start justify-between gap-3 flex-wrap">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900 mb-1">Coverage Details (Immunizations)</h1>
            <p class="text-slate-600 text-sm">
                Clinic: <span class="font-semibold"><?= htmlspecialchars($clinicName) ?></span>
                · Month: <span class="font-semibold"><?= htmlspecialchars($month) ?></span>
            </p>
        </div>
        <a href="/mch_system/admin_portal/coverage.php?<?= http_build_query(['month' => $month, 'clinic_id' => $clinicId]) ?>"
           class="px-4 py-2 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-sm">Back to Coverage</a>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
        <div class="mch-card bg-white/90 p-5 border border-slate-100">
            <p class="text-xs uppercase tracking-wide text-slate-500">Due Shots</p>
            <p class="text-3xl font-semibold text-slate-900 mt-2"><?= $due ?></p>
        </div>
        <div class="mch-card bg-white/90 p-5 border border-slate-100">
            <p class="text-xs uppercase tracking-wide text-slate-500">Given</p>
            <p class="text-3xl font-semibold text-slate-900 mt-2"><?= $given ?></p>
        </div>
        <div class="mch-card bg-white/90 p-5 border border-slate-100">
            <p class="text-xs uppercase tracking-wide text-slate-500">Pending</p>
            <p class="text-3xl font-semibold text-slate-900 mt-2"><?= $pending ?></p>
        </div>
        <div class="mch-card bg-emerald-50 p-5 border border-emerald-200">
            <p class="text-xs uppercase tracking-wide text-emerald-700">Shot Coverage</p>
            <p class="text-3xl font-semibold text-emerald-800 mt-2"><?= $pct === null ? '-' : htmlspecialchars((string)$pct . '%') ?></p>
        </div>
    </div>

    <div class="mch-card bg-white/90 p-6 border border-slate-100">
        <div class="flex items-center justify-between gap-3 flex-wrap mb-4">
            <div class="text-sm text-slate-600">
                Filter:
                <?php
                    $base = ['month' => $month, 'clinic_id' => $clinicId];
                    $mk = fn($st) => "/mch_system/admin_portal/coverage_details.php?" . http_build_query($base + ['status' => $st]);
                ?>
                <a class="px-3 py-1.5 rounded-full border border-slate-200 bg-white hover:bg-slate-50 text-xs font-semibold <?= $statusFilter === 'ALL' ? 'ring-2 ring-slate-300' : '' ?>" href="<?= htmlspecialchars($mk('ALL')) ?>">All</a>
                <a class="px-3 py-1.5 rounded-full border border-sky-200 bg-sky-50 hover:bg-sky-100 text-xs font-semibold <?= $statusFilter === 'GIVEN' ? 'ring-2 ring-sky-300' : '' ?>" href="<?= htmlspecialchars($mk('GIVEN')) ?>">Given</a>
                <a class="px-3 py-1.5 rounded-full border border-amber-200 bg-amber-50 hover:bg-amber-100 text-xs font-semibold <?= $statusFilter === 'PENDING' ? 'ring-2 ring-amber-300' : '' ?>" href="<?= htmlspecialchars($mk('PENDING')) ?>">Pending</a>
            </div>
            <div class="text-sm text-slate-600">
                Rows: <span class="font-semibold"><?= (int)count($shots) ?></span>
            </div>
        </div>

        <?php if (empty($shots)): ?>
            <p class="text-slate-500">No records found for this filter.</p>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm mch-table mch-scroll">
                    <thead>
                        <tr>
                            <?php if ($clinicId === 0): ?>
                                <th class="p-2 text-left">Clinic</th>
                            <?php endif; ?>
                            <th class="p-2 text-left">Baby</th>
                            <th class="p-2 text-left">Mother</th>
                            <th class="p-2 text-left">Hospital #</th>
                            <th class="p-2 text-left">Vaccine</th>
                            <th class="p-2 text-left">Due Date</th>
                            <th class="p-2 text-left">Status</th>
                            <th class="p-2 text-left">Given Date</th>
                            <th class="p-2 text-left">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($shots as $s): ?>
                            <?php $st = (string)($s['status'] ?? ''); ?>
                            <tr class="border-t border-slate-200">
                                <?php if ($clinicId === 0): ?>
                                    <td class="p-2"><?= htmlspecialchars((string)($s['clinic_name'] ?? '')) ?></td>
                                <?php endif; ?>
                                <td class="p-2 font-semibold"><?= htmlspecialchars((string)($s['baby_name'] ?? '')) ?></td>
                                <td class="p-2"><?= htmlspecialchars((string)($s['mother_name'] ?? '')) ?></td>
                                <td class="p-2"><?= htmlspecialchars((string)($s['hospital_number'] ?? '')) ?></td>
                                <td class="p-2"><?= htmlspecialchars((string)($s['vaccine'] ?? '')) ?></td>
                                <td class="p-2"><?= htmlspecialchars((string)($s['due_date'] ?? '')) ?></td>
                                <td class="p-2">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs <?= $st === 'GIVEN' ? 'bg-sky-100 text-sky-700' : 'bg-amber-100 text-amber-700' ?>">
                                        <?= htmlspecialchars($st ?: '-') ?>
                                    </span>
                                </td>
                                <td class="p-2 text-slate-600"><?= htmlspecialchars((string)($s['given_date'] ?? '-')) ?></td>
                                <td class="p-2">
                                    <a class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-xs font-semibold"
                                       href="/mch_system/nurse_portal/baby_history.php?baby_id=<?= (int)($s['baby_id'] ?? 0) ?>">
                                        Baby History
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

<?php include __DIR__ . "/../includes/footer.php"; ?>
