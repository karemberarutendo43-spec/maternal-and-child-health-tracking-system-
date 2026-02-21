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
$allClinics = ($adminLevel === 'SUPER' || $adminLevel === 'DISTRICT');
$selectedClinicId = $allClinics ? 0 : $adminClinicId;

$scalar = function (string $sql, array $params = []) use ($pdo) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
};
$all = function (string $sql, array $params = []) use ($pdo) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
};

$mothersBase = "
    FROM mothers m
    JOIN users u ON u.id = m.user_id
    LEFT JOIN clinics c ON u.clinic_id = c.id
";
$babiesBase = "
    FROM babies b
    JOIN mothers m ON b.mother_id = m.id
    JOIN users u ON u.id = m.user_id
    LEFT JOIN clinics c ON u.clinic_id = c.id
";

$makeWhereMothers = function (string $extra = '', array $extraParams = []) use ($selectedClinicId) {
    $where = 'WHERE 1=1';
    $params = [];
    if ($selectedClinicId > 0) {
        $where .= ' AND u.clinic_id = ?';
        $params[] = $selectedClinicId;
    }
    if ($extra !== '') {
        $where .= ' AND ' . $extra;
        $params = array_merge($params, $extraParams);
    }
    return [$where, $params];
};

$makeWhereBabies = function (string $extra = '', array $extraParams = []) use ($selectedClinicId) {
    $where = 'WHERE 1=1';
    $params = [];
    if ($selectedClinicId > 0) {
        $where .= ' AND u.clinic_id = ?';
        $params[] = $selectedClinicId;
    }
    if ($extra !== '') {
        $where .= ' AND ' . $extra;
        $params = array_merge($params, $extraParams);
    }
    return [$where, $params];
};

$export = (string)($_GET['export'] ?? '');
if ($export === 'mothers' || $export === 'babies') {
    if ($export === 'mothers') {
        [$where, $params] = $makeWhereMothers();
        $rows = $pdo->prepare("
            SELECT m.fullname, m.phone, m.hospital_number, m.pregnancy_status, m.created_at, COALESCE(c.clinic_name, 'Unassigned') AS clinic_name
            FROM mothers m
            JOIN users u ON u.id = m.user_id
            LEFT JOIN clinics c ON u.clinic_id = c.id
            {$where}
            ORDER BY m.created_at DESC
        ");
        $rows->execute($params);
        $filename = 'registration_mothers.csv';
        $headers = ['Full Name', 'Phone', 'Hospital Number', 'Status', 'Registered At', 'Clinic'];
    } else {
        [$where, $params] = $makeWhereBabies();
        $rows = $pdo->prepare("
            SELECT b.baby_name, b.dob, b.gender, b.birth_weight, m.fullname AS mother_name, COALESCE(c.clinic_name, 'Unassigned') AS clinic_name
            FROM babies b
            JOIN mothers m ON b.mother_id = m.id
            JOIN users u ON u.id = m.user_id
            LEFT JOIN clinics c ON u.clinic_id = c.id
            {$where}
            ORDER BY b.dob DESC
        ");
        $rows->execute($params);
        $filename = 'registration_babies.csv';
        $headers = ['Baby Name', 'DOB', 'Gender', 'Birth Weight', 'Mother Name', 'Clinic'];
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    fputcsv($output, $headers);
    while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}

[$whereMothers, $paramsMothers] = $makeWhereMothers();
$totalMothers = (int)$scalar("SELECT COUNT(*) {$mothersBase} {$whereMothers}", $paramsMothers);
[$wherePregnant, $paramsPregnant] = $makeWhereMothers("m.pregnancy_status = 'Pregnant'");
$pregnantMothers = (int)$scalar("SELECT COUNT(*) {$mothersBase} {$wherePregnant}", $paramsPregnant);
[$whereBabies, $paramsBabies] = $makeWhereBabies();
$totalBabies = (int)$scalar("SELECT COUNT(*) {$babiesBase} {$whereBabies}", $paramsBabies);
$windowDate = date('Y-m-d H:i:s', strtotime('-30 days'));
[$whereRecentMothers, $paramsRecentMothers] = $makeWhereMothers("m.created_at >= ?", [$windowDate]);
$recentMothers = (int)$scalar("SELECT COUNT(*) {$mothersBase} {$whereRecentMothers}", $paramsRecentMothers);
[$whereRecentBabies, $paramsRecentBabies] = $makeWhereBabies("b.dob >= ?", [$windowDate]);
$recentBabies = (int)$scalar("SELECT COUNT(*) {$babiesBase} {$whereRecentBabies}", $paramsRecentBabies);
$latestRegistrations = $all("
    SELECT m.id, m.fullname, m.hospital_number, m.pregnancy_status, m.created_at, COALESCE(c.clinic_name, 'Unassigned') AS clinic_name
    FROM mothers m
    JOIN users u ON u.id = m.user_id
    LEFT JOIN clinics c ON u.clinic_id = c.id
    {$whereMothers}
    ORDER BY m.created_at DESC
    LIMIT 10
", $paramsMothers);
$topClinics = $all("
    SELECT COALESCE(c.clinic_name, 'Unassigned') AS clinic_name, COUNT(*) AS registrations
    {$mothersBase}
    {$whereRecentMothers}
    GROUP BY clinic_name
    ORDER BY registrations DESC
    LIMIT 5
", $paramsRecentMothers);

$page = 'registration_data';
include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_admin.php";
?>

<style>
    .card-link {
        display: block;
        text-decoration: none;
    }

    .card-link:hover .card-icon,
    .card-link:focus-visible .card-icon {
        transform: translateY(-1px) scale(1.05);
    }

    .card-link .card-icon {
        transition: transform 0.2s ease;
    }
</style>

<div class="ml-64 p-6 space-y-6">
    <div class="mch-card bg-white/90 p-6 border border-slate-100 flex flex-col gap-2">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-semibold text-slate-900">Registration Data</h1>
                <p class="text-sm text-slate-500">Operational data and quick exports focused on registrations and recent activity.</p>
            </div>
            <div class="text-sm text-slate-500">
                <?= date("l, d M Y") ?>
            </div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 mt-4">
            <a href="/mch_system/admin_portal/users.php" class="card-link" aria-label="View mothers list">
                <div class="mch-card bg-slate-50 p-4 rounded-xl border border-slate-100 shadow-sm group">
                    <div class="card-icon flex h-10 w-10 items-center justify-center rounded-full bg-white text-slate-900 shadow-sm mb-3">
                        🧑‍🤝‍🧑
                    </div>
                    <p class="text-xs uppercase tracking-wide text-slate-400">Mothers</p>
                    <p class="text-3xl font-semibold text-slate-900"><?= number_format($totalMothers) ?></p>
                    <p class="text-xs text-slate-500 mt-1"><?= number_format($recentMothers) ?> registered in last 30 days</p>
                </div>
            </a>
            <a href="/mch_system/nurse_portal/mother_list.php" class="card-link" aria-label="View pregnant mother records">
                <div class="mch-card bg-slate-50 p-4 rounded-xl border border-slate-100 shadow-sm group">
                    <div class="card-icon flex h-10 w-10 items-center justify-center rounded-full bg-white text-slate-900 shadow-sm mb-3">
                        🤰
                    </div>
                    <p class="text-xs uppercase tracking-wide text-slate-400">Pregnant</p>
                    <p class="text-3xl font-semibold text-slate-900"><?= number_format($pregnantMothers) ?></p>
                    <p class="text-xs text-slate-500 mt-1">Current pregnancy bookings</p>
                </div>
            </a>
            <a href="/mch_system/nurse_portal/babies_list.php" class="card-link" aria-label="View baby records">
                <div class="mch-card bg-slate-50 p-4 rounded-xl border border-slate-100 shadow-sm group">
                    <div class="card-icon flex h-10 w-10 items-center justify-center rounded-full bg-white text-slate-900 shadow-sm mb-3">
                        👶
                    </div>
                    <p class="text-xs uppercase tracking-wide text-slate-400">Babies</p>
                    <p class="text-3xl font-semibold text-slate-900"><?= number_format($totalBabies) ?></p>
                    <p class="text-xs text-slate-500 mt-1"><?= number_format($recentBabies) ?> born in last 30 days</p>
                </div>
            </a>
            <a href="#export_section" class="card-link" aria-label="Jump to export section">
                <div class="mch-card bg-slate-50 p-4 rounded-xl border border-slate-100 shadow-sm group">
                    <div class="card-icon flex h-10 w-10 items-center justify-center rounded-full bg-white text-slate-900 shadow-sm mb-3">
                        📂
                    </div>
                    <p class="text-xs uppercase tracking-wide text-slate-400">Clinic</p>
                    <p class="text-3xl font-semibold text-slate-900"><?= $selectedClinicId > 0 ? 'Clinic scope' : 'All clinics' ?></p>
                    <p class="text-xs text-slate-500 mt-1">Use exports below for raw downloads</p>
                </div>
            </a>
        </div>
        <p class="text-xs text-slate-500 mt-3">
            Mother and baby lists are maintained in the nurse portal; the admin versions currently hold placeholder data, so please use the nurse lists for full records.
        </p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div class="mch-card bg-white/90 p-6 border border-slate-100 space-y-3">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold text-slate-900">Latest Pregnancies</h2>
                <span class="text-xs text-slate-500">Updated live</span>
            </div>
            <div class="space-y-2">
                <?php if (empty($latestRegistrations)): ?>
                    <p class="text-sm text-slate-500">No recent registrations.</p>
                <?php else: ?>
                    <ul class="space-y-2 text-sm">
                        <?php foreach ($latestRegistrations as $m): ?>
                            <li class="flex justify-between">
                                <div>
                                    <div class="font-semibold text-slate-900"><?= htmlspecialchars($m['fullname']) ?></div>
                                    <div class="text-xs text-slate-500"><?= htmlspecialchars($m['pregnancy_status']) ?> • <?= htmlspecialchars($m['clinic_name']) ?></div>
                                </div>
                                <div class="text-xs text-slate-400"><?= date("d M", strtotime($m['created_at'])) ?></div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
        <div class="mch-card bg-white/90 p-6 border border-slate-100 space-y-3 lg:col-span-2">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold text-slate-900">Top Clinics (last 30 days)</h2>
                <span class="text-xs text-slate-500"><?= number_format($recentMothers) ?> new mothers</span>
            </div>
            <div class="space-y-2">
                <?php if (empty($topClinics)): ?>
                    <p class="text-sm text-slate-500">No clinic activity recorded.</p>
                <?php else: ?>
                    <div class="space-y-2 text-sm">
                        <?php foreach ($topClinics as $clinic): ?>
                            <div class="flex items-center justify-between">
                                <span><?= htmlspecialchars($clinic['clinic_name']) ?></span>
                                <span class="text-slate-600 font-semibold"><?= htmlspecialchars($clinic['registrations']) ?> regs</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div id="export_section" class="mch-card bg-white/90 p-6 border border-slate-100 space-y-4">
        <div class="flex items-center justify-between">
            <h2 class="text-lg font-semibold text-slate-900">Export Data</h2>
            <span class="text-xs text-slate-400">Downloads respect your clinic scope</span>
        </div>
        <div class="flex flex-wrap gap-3">
            <a href="/mch_system/admin_portal/registration_data.php?export=mothers" class="mch-btn px-4 py-2 bg-teal-700 text-white rounded-lg hover:bg-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-500">
                Download Mothers CSV
            </a>
            <a href="/mch_system/admin_portal/registration_data.php?export=babies" class="mch-btn px-4 py-2 bg-slate-800 text-white rounded-lg hover:bg-slate-700 focus:outline-none focus:ring-2 focus:ring-slate-600">
                Download Babies CSV
            </a>
        </div>
        <p class="text-xs text-slate-500">Use these exports for quick audits or bulk reviews.</p>
    </div>
</div>

<?php include __DIR__ . "/../includes/footer.php"; ?>
