<?php
require_once __DIR__ . "/../config/session.php";
mch_session_start();
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/cards.php";

// =====================
// Access Control
// =====================
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: /mch_system/auth/login.php");
    exit;
}

// =====================
// Scope (District vs Clinic)
// =====================
$adminLevel = (string)($_SESSION['admin_level'] ?? 'SUPER');
$adminClinicId = (int)($_SESSION['clinic_id'] ?? 0);
$allClinics = ($adminLevel === 'SUPER' || $adminLevel === 'DISTRICT');
if (!$allClinics && $adminClinicId <= 0) {
    die("Clinic admin is not assigned to a clinic.");
}
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

// =====================
// Dashboard Statistics
// =====================

// Core counts
$totalMothers = $selectedClinicId > 0
    ? (int)$scalar("SELECT COUNT(*) FROM mothers m JOIN users u ON u.id = m.user_id WHERE u.clinic_id = ?", [$selectedClinicId])
    : (int)$scalar("SELECT COUNT(*) FROM mothers");
$totalBabies = $selectedClinicId > 0
    ? (int)$scalar("
        SELECT COUNT(*)
        FROM babies b
        JOIN mothers m ON b.mother_id = m.id
        JOIN users u ON u.id = m.user_id
        WHERE u.clinic_id = ?
      ", [$selectedClinicId])
    : (int)$scalar("SELECT COUNT(*) FROM babies");
$totalNurses = $selectedClinicId > 0
    ? (int)$scalar("SELECT COUNT(*) FROM users WHERE role='nurse' AND clinic_id = ?", [$selectedClinicId])
    : (int)$scalar("SELECT COUNT(*) FROM users WHERE role='nurse'");

// ANC Defaulters
$ancDefaulters = $selectedClinicId > 0
    ? (int)$scalar("
        SELECT COUNT(*)
        FROM anc_visits a
        JOIN mothers m ON a.mother_id = m.id
        JOIN users u ON m.user_id = u.id
        WHERE a.next_visit_date < CURDATE()
          AND a.status = 'PENDING'
          AND u.clinic_id = ?
      ", [$selectedClinicId])
    : (int)$scalar("
        SELECT COUNT(*)
        FROM anc_visits
        WHERE next_visit_date < CURDATE()
          AND status = 'PENDING'
      ");

// Immunization Defaulters
$immunDefaulters = $selectedClinicId > 0
    ? (int)$scalar("
        SELECT COUNT(*)
        FROM immunizations i
        JOIN babies b ON i.baby_id = b.id
        JOIN mothers m ON b.mother_id = m.id
        JOIN users u ON m.user_id = u.id
        WHERE i.due_date < CURDATE()
          AND i.status = 'PENDING'
          AND u.clinic_id = ?
      ", [$selectedClinicId])
    : (int)$scalar("
        SELECT COUNT(*)
        FROM immunizations
        WHERE due_date < CURDATE()
          AND status = 'PENDING'
      ");

// Phase 2: District pulse metrics
$totalImmun = $selectedClinicId > 0
    ? (int)$scalar("
        SELECT COUNT(*)
        FROM immunizations i
        JOIN babies b ON i.baby_id = b.id
        JOIN mothers m ON b.mother_id = m.id
        JOIN users u ON m.user_id = u.id
        WHERE u.clinic_id = ?
      ", [$selectedClinicId])
    : (int)$scalar("SELECT COUNT(*) FROM immunizations");
$givenImmun = $selectedClinicId > 0
    ? (int)$scalar("
        SELECT COUNT(*)
        FROM immunizations i
        JOIN babies b ON i.baby_id = b.id
        JOIN mothers m ON b.mother_id = m.id
        JOIN users u ON m.user_id = u.id
        WHERE i.status = 'GIVEN'
          AND u.clinic_id = ?
      ", [$selectedClinicId])
    : (int)$scalar("SELECT COUNT(*) FROM immunizations WHERE status = 'GIVEN'");
$coverage = $totalImmun > 0 ? round(($givenImmun / $totalImmun) * 100) : 0;

$topClinicName = 'N/A';
if ($selectedClinicId > 0) {
    $cstmt = $pdo->prepare("SELECT clinic_name FROM clinics WHERE id = ? LIMIT 1");
    $cstmt->execute([$selectedClinicId]);
    $topClinicName = (string)($cstmt->fetchColumn() ?: 'N/A');
} else {
    $topClinic = $pdo->query("
        SELECT c.clinic_name, COUNT(m.id) AS mothers_count
        FROM clinics c
        LEFT JOIN users u ON u.clinic_id = c.id
        LEFT JOIN mothers m ON m.user_id = u.id
        GROUP BY c.id
        ORDER BY mothers_count DESC
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);
    $topClinicName = $topClinic['clinic_name'] ?? 'N/A';
}

$criticalStock = 0;
try {
    $hasStock = (bool)$pdo->query("SHOW TABLES LIKE 'commodity_stock_levels'")->fetchColumn();
    if ($hasStock) {
        $critStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM commodity_stock_levels l
            JOIN commodities cm ON cm.id = l.commodity_id
            WHERE cm.active = 1
              AND l.reorder_level > 0
              AND l.on_hand <= l.reorder_level
              AND (? = 0 OR l.clinic_id = ?)
        ");
        $critStmt->execute([$selectedClinicId, $selectedClinicId]);
        $criticalStock = (int)$critStmt->fetchColumn();
    }
} catch (Throwable $e) {
    $criticalStock = 0;
}

$recentFlags = $selectedClinicId > 0
    ? $all("
        SELECT
          m.id,
          m.fullname,
          m.hospital_number,
          MIN(a.next_visit_date) AS overdue_since,
          COUNT(*) AS overdue_count
        FROM anc_visits a
        JOIN mothers m ON a.mother_id = m.id
        JOIN users u ON m.user_id = u.id
        WHERE a.next_visit_date < CURDATE()
          AND a.status = 'PENDING'
          AND u.clinic_id = ?
        GROUP BY m.id, m.fullname, m.hospital_number
        ORDER BY overdue_since ASC
        LIMIT 5
      ", [$selectedClinicId])
    : $pdo->query("
        SELECT
          m.id,
          m.fullname,
          m.hospital_number,
          MIN(a.next_visit_date) AS overdue_since,
          COUNT(*) AS overdue_count
        FROM anc_visits a
        JOIN mothers m ON a.mother_id = m.id
        WHERE a.next_visit_date < CURDATE() AND a.status = 'PENDING'
        GROUP BY m.id, m.fullname, m.hospital_number
        ORDER BY overdue_since ASC
        LIMIT 5
      ")->fetchAll(PDO::FETCH_ASSOC);

// Chart: mothers by clinic (top 6)
$clinicStats = $selectedClinicId > 0
    ? $all("
        SELECT c.clinic_name, COUNT(m.id) AS mothers_count
        FROM clinics c
        LEFT JOIN users u ON u.clinic_id = c.id
        LEFT JOIN mothers m ON m.user_id = u.id
        WHERE c.id = ?
        GROUP BY c.id
        LIMIT 1
      ", [$selectedClinicId])
    : $pdo->query("
        SELECT c.clinic_name, COUNT(m.id) AS mothers_count
        FROM clinics c
        LEFT JOIN users u ON u.clinic_id = c.id
        LEFT JOIN mothers m ON m.user_id = u.id
        GROUP BY c.id
        ORDER BY mothers_count DESC
        LIMIT 6
      ")->fetchAll(PDO::FETCH_ASSOC);

// =====================
// Auto-Calculated High Risk Mothers
// Logic:
// - No ANC visits at all OR
// - Next ANC visit overdue
// =====================
$highRiskMothers = $selectedClinicId > 0
    ? (int)$scalar("
        SELECT COUNT(*)
        FROM mothers m
        JOIN users u ON u.id = m.user_id
        WHERE u.clinic_id = ?
          AND (
            NOT EXISTS (SELECT 1 FROM anc_visits a WHERE a.mother_id = m.id)
            OR EXISTS (
              SELECT 1
              FROM anc_visits a
              WHERE a.mother_id = m.id
                AND a.next_visit_date < CURDATE()
                AND a.status = 'PENDING'
            )
          )
      ", [$selectedClinicId])
    : (int)$scalar("
        SELECT COUNT(*)
        FROM mothers m
        WHERE NOT EXISTS (SELECT 1 FROM anc_visits a WHERE a.mother_id = m.id)
           OR EXISTS (
              SELECT 1
              FROM anc_visits a
              WHERE a.mother_id = m.id
                AND a.next_visit_date < CURDATE()
                AND a.status = 'PENDING'
           )
      ");

$recentMothers = $selectedClinicId > 0
    ? $all("
        SELECT m.id, m.fullname, m.hospital_number, m.pregnancy_status, m.created_at
        FROM mothers m
        JOIN users u ON m.user_id = u.id
        WHERE u.clinic_id = ?
        ORDER BY m.created_at DESC
        LIMIT 10
      ", [$selectedClinicId])
    : $pdo->query("
        SELECT id, fullname, hospital_number, pregnancy_status, created_at
        FROM mothers
        ORDER BY created_at DESC
        LIMIT 10
      ")->fetchAll(PDO::FETCH_ASSOC);

$recentBabies = $selectedClinicId > 0
    ? $all("
        SELECT b.id, b.baby_name, b.dob, b.gender, m.fullname AS mother_name
        FROM babies b
        JOIN mothers m ON b.mother_id = m.id
        JOIN users u ON m.user_id = u.id
        WHERE u.clinic_id = ?
        ORDER BY b.id DESC
        LIMIT 10
      ", [$selectedClinicId])
    : $pdo->query("
        SELECT b.id, b.baby_name, b.dob, b.gender, m.fullname AS mother_name
        FROM babies b
        JOIN mothers m ON b.mother_id = m.id
        ORDER BY b.id DESC
        LIMIT 10
      ")->fetchAll(PDO::FETCH_ASSOC);

$recentNurses = $selectedClinicId > 0
    ? $all("
        SELECT id, full_name, phone, created_at
        FROM users
        WHERE role = 'nurse'
          AND clinic_id = ?
        ORDER BY created_at DESC, id DESC
        LIMIT 10
      ", [$selectedClinicId])
    : $pdo->query("
        SELECT id, full_name, phone, created_at
        FROM users
        WHERE role = 'nurse'
        ORDER BY created_at DESC, id DESC
        LIMIT 10
      ")->fetchAll(PDO::FETCH_ASSOC);

$ancDefaultersList = $selectedClinicId > 0
    ? $all("
        SELECT
          m.id,
          m.fullname,
          m.hospital_number,
          MIN(a.next_visit_date) AS overdue_since,
          COUNT(*) AS overdue_count
        FROM anc_visits a
        JOIN mothers m ON a.mother_id = m.id
        JOIN users u ON m.user_id = u.id
        WHERE a.next_visit_date < CURDATE()
          AND a.status = 'PENDING'
          AND u.clinic_id = ?
        GROUP BY m.id, m.fullname, m.hospital_number
        ORDER BY overdue_since ASC
        LIMIT 10
      ", [$selectedClinicId])
    : $pdo->query("
        SELECT
          m.id,
          m.fullname,
          m.hospital_number,
          MIN(a.next_visit_date) AS overdue_since,
          COUNT(*) AS overdue_count
        FROM anc_visits a
        JOIN mothers m ON a.mother_id = m.id
        WHERE a.next_visit_date < CURDATE() AND a.status = 'PENDING'
        GROUP BY m.id, m.fullname, m.hospital_number
        ORDER BY overdue_since ASC
        LIMIT 10
      ")->fetchAll(PDO::FETCH_ASSOC);

$immunDefaultersList = $selectedClinicId > 0
    ? $all("
        SELECT b.id, b.baby_name, m.fullname AS mother_name, i.vaccine, i.due_date
        FROM immunizations i
        JOIN babies b ON i.baby_id = b.id
        JOIN mothers m ON b.mother_id = m.id
        JOIN users u ON m.user_id = u.id
        WHERE i.due_date < CURDATE()
          AND i.status = 'PENDING'
          AND u.clinic_id = ?
        ORDER BY i.due_date ASC
        LIMIT 10
      ", [$selectedClinicId])
    : $pdo->query("
        SELECT b.id, b.baby_name, m.fullname AS mother_name, i.vaccine, i.due_date
        FROM immunizations i
        JOIN babies b ON i.baby_id = b.id
        JOIN mothers m ON b.mother_id = m.id
        WHERE i.due_date < CURDATE() AND i.status = 'PENDING'
        ORDER BY i.due_date ASC
        LIMIT 10
      ")->fetchAll(PDO::FETCH_ASSOC);

$highRiskMothersList = $selectedClinicId > 0
    ? $all("
        SELECT
          m.id,
          m.fullname,
          m.hospital_number,
          (
            SELECT COUNT(*)
            FROM anc_visits a
            WHERE a.mother_id = m.id
          ) AS anc_count,
          (
            SELECT MIN(a.next_visit_date)
            FROM anc_visits a
            WHERE a.mother_id = m.id
              AND a.status = 'PENDING'
              AND a.next_visit_date < CURDATE()
          ) AS overdue_since
        FROM mothers m
        JOIN users u ON m.user_id = u.id
        WHERE u.clinic_id = ?
          AND (
            (SELECT COUNT(*) FROM anc_visits a WHERE a.mother_id = m.id) = 0
            OR EXISTS (
              SELECT 1
              FROM anc_visits a
              WHERE a.mother_id = m.id
                AND a.status = 'PENDING'
                AND a.next_visit_date < CURDATE()
            )
          )
        ORDER BY (overdue_since IS NULL) ASC, overdue_since ASC, m.fullname ASC
        LIMIT 10
      ", [$selectedClinicId])
    : $pdo->query("
        SELECT
          m.id,
          m.fullname,
          m.hospital_number,
          (
            SELECT COUNT(*)
            FROM anc_visits a
            WHERE a.mother_id = m.id
          ) AS anc_count,
          (
            SELECT MIN(a.next_visit_date)
            FROM anc_visits a
            WHERE a.mother_id = m.id
              AND a.status = 'PENDING'
              AND a.next_visit_date < CURDATE()
          ) AS overdue_since
        FROM mothers m
        WHERE
          (SELECT COUNT(*) FROM anc_visits a WHERE a.mother_id = m.id) = 0
          OR EXISTS (
            SELECT 1
            FROM anc_visits a
            WHERE a.mother_id = m.id
              AND a.status = 'PENDING'
              AND a.next_visit_date < CURDATE()
          )
        ORDER BY (overdue_since IS NULL) ASC, overdue_since ASC, m.fullname ASC
        LIMIT 10
      ")->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_admin.php";
?>

<div class="ml-64 p-6 space-y-8">

    <div class="mch-card bg-white/90 p-4 border border-slate-100 flex flex-col md:flex-row md:items-center md:justify-between gap-3">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-slate-900 text-white flex items-center justify-center font-semibold">
                MC
            </div>
            <div>
                <div class="text-sm text-slate-500">MT DARWIN DISTRICT</div>
                <div class="text-lg font-semibold text-slate-900">MCHTS Command Centre</div>
            </div>
        </div>
        <div class="flex items-center gap-3 text-sm">
            <span class="text-slate-600"><?= date("l, d M Y") ?></span>
            <span id="mch_conn_badge" class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200" title="Connection status">
                <span id="mch_conn_dot" class="w-2 h-2 rounded-full bg-emerald-500"></span>
                <span id="mch_conn_label">Online</span>
            </span>
        </div>
    </div>

    <!-- =====================
         STAT CARDS
    ====================== -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-5">
        <button type="button" data-modal-target="modal-registered-mothers" data-modal-title="Registered Mothers" class="mch-card bg-white/90 p-5 border border-slate-100 text-left hover:ring-2 hover:ring-emerald-200 transition">
            <p class="text-xs uppercase tracking-wide text-slate-500">Registered Mothers</p>
            <p id="mch_admin_stat_mothers" class="text-3xl font-semibold text-slate-900 mt-2"><?= $totalMothers ?></p>
            <p class="text-xs text-slate-400 mt-2">Click to view details</p>
        </button>
        <button type="button" data-modal-target="modal-registered-babies" data-modal-title="Registered Babies" class="mch-card bg-white/90 p-5 border border-slate-100 text-left hover:ring-2 hover:ring-emerald-200 transition">
            <p class="text-xs uppercase tracking-wide text-slate-500">Registered Babies</p>
            <p id="mch_admin_stat_babies" class="text-3xl font-semibold text-slate-900 mt-2"><?= $totalBabies ?></p>
            <p class="text-xs text-slate-400 mt-2">Click to view details</p>
        </button>
        <button type="button" data-modal-target="modal-registered-nurses" data-modal-title="Nurses" class="mch-card bg-white/90 p-5 border border-slate-100 text-left hover:ring-2 hover:ring-emerald-200 transition">
            <p class="text-xs uppercase tracking-wide text-slate-500">Nurses</p>
            <p id="mch_admin_stat_nurses" class="text-3xl font-semibold text-slate-900 mt-2"><?= $totalNurses ?></p>
            <p class="text-xs text-slate-400 mt-2">Click to view details</p>
        </button>
        <button type="button" data-modal-target="modal-anc-defaulters" data-modal-title="ANC Defaulters" class="mch-card bg-white/90 p-5 border border-rose-100 text-left hover:ring-2 hover:ring-rose-200 transition">
            <p class="text-xs uppercase tracking-wide text-rose-500">ANC Defaulters</p>
            <p id="mch_admin_stat_anc_defaulters" class="text-3xl font-semibold text-rose-700 mt-2"><?= $ancDefaulters ?></p>
            <p class="text-xs text-rose-400 mt-2">Click to view details</p>
        </button>
        <button type="button" data-modal-target="modal-immun-defaulters" data-modal-title="Immunization Defaulters" class="mch-card bg-white/90 p-5 border border-amber-100 text-left hover:ring-2 hover:ring-amber-200 transition">
            <p class="text-xs uppercase tracking-wide text-amber-500">Immunization Defaulters</p>
            <p id="mch_admin_stat_immun_defaulters" class="text-3xl font-semibold text-amber-700 mt-2"><?= $immunDefaulters ?></p>
            <p class="text-xs text-amber-400 mt-2">Click to view details</p>
        </button>
        <button type="button" data-modal-target="modal-high-risk" data-modal-title="High Risk Mothers" class="mch-card bg-white/90 p-5 border border-rose-100 text-left hover:ring-2 hover:ring-rose-200 transition">
            <p class="text-xs uppercase tracking-wide text-rose-500">High Risk Mothers</p>
            <p id="mch_admin_stat_high_risk" class="text-3xl font-semibold text-rose-700 mt-2"><?= $highRiskMothers ?></p>
            <p class="text-xs text-rose-400 mt-2">Click to view details</p>
        </button>
    </div>

    <div class="flex flex-wrap items-center gap-2 text-xs text-slate-600">
        <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200">
            <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
            Up to date
        </span>
        <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-amber-50 text-amber-700 border border-amber-200">
            <span class="w-2 h-2 rounded-full bg-amber-500"></span>
            Upcoming
        </span>
        <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-rose-50 text-rose-700 border border-rose-200">
            <span class="w-2 h-2 rounded-full bg-rose-500"></span>
            Overdue / High risk
        </span>
    </div>

    <div id="recent-flags" class="mch-card bg-white/90 p-6 border border-slate-100">
        <h2 class="text-lg font-semibold text-slate-800 mb-4">District Pulse</h2>
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                <p class="text-xs uppercase tracking-wide text-slate-500">Registered Mothers</p>
                <p id="mch_admin_pulse_mothers" class="text-3xl font-semibold text-slate-900 mt-2"><?= $totalMothers ?></p>
            </div>
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4">
                <p class="text-xs uppercase tracking-wide text-emerald-600">Immunization Coverage</p>
                <p id="mch_admin_pulse_coverage" class="text-3xl font-semibold text-emerald-700 mt-2"><?= $coverage ?>%</p>
                <div class="mt-2 h-2 bg-emerald-100 rounded-full overflow-hidden">
                    <div id="mch_admin_pulse_coverage_bar" class="h-full bg-emerald-500" style="width: <?= $coverage ?>%"></div>
                </div>
            </div>
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-4">
                <p class="text-xs uppercase tracking-wide text-amber-600">Top Facility</p>
                <p id="mch_admin_pulse_top_facility" class="text-lg font-semibold text-amber-700 mt-2"><?= htmlspecialchars($topClinicName) ?></p>
            </div>
            <div class="rounded-xl border border-rose-200 bg-rose-50 p-4">
                <p class="text-xs uppercase tracking-wide text-rose-600">Critical Stock Alerts</p>
                <p id="mch_admin_pulse_critical_stock" class="text-3xl font-semibold text-rose-700 mt-2"><?= $criticalStock ?></p>
            </div>
        </div>
    </div>

    <div class="mch-card bg-white/90 p-6 border border-slate-100">
        <h2 class="text-lg font-semibold text-slate-800 mb-4">Recent Flags</h2>
        <?php if (empty($recentFlags)): ?>
            <p class="text-slate-500">No recent flags</p>
        <?php else: ?>
            <div class="space-y-3">
                <?php foreach ($recentFlags as $f): ?>
                    <div class="flex items-center justify-between gap-3 p-3 rounded-xl border border-rose-100 bg-rose-50">
                        <div>
                            <div class="font-semibold text-slate-900"><?= htmlspecialchars($f['fullname']) ?></div>
                            <div class="text-xs text-slate-600">Hospital #: <?= htmlspecialchars($f['hospital_number']) ?></div>
                        </div>
                        <div class="text-right">
                            <div class="text-sm text-rose-700 font-semibold">
                                Overdue: <?= htmlspecialchars($f['overdue_since']) ?>
                                <span class="text-xs text-rose-600">(<?= (int)($f['overdue_count'] ?? 0) ?>)</span>
                            </div>
                            <div class="mt-2">
                                <a class="inline-flex items-center px-3 py-1 rounded-lg bg-white border border-rose-200 hover:bg-rose-100 text-sm text-rose-700"
                                    href="/mch_system/admin_portal/mother_anc_visits.php?mother_id=<?= (int)($f['id'] ?? 0) ?>&source=recent_flags">
                                    View Overdue
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="mch-card bg-white/90 p-6 border border-slate-100">
        <h2 class="text-lg font-semibold text-slate-800 mb-4">Clinic Performance (Top Facilities)</h2>
        <?php if (empty($clinicStats)): ?>
            <p class="text-slate-500">No clinic data available.</p>
        <?php else: ?>
            <div class="h-64">
                <canvas id="clinicChart"></canvas>
            </div>
        <?php endif; ?>
    </div>

</div>

<div id="dashModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-slate-900/40" data-modal-close></div>
    <div class="relative mx-auto my-8 w-[min(900px,94vw)] bg-white rounded-2xl shadow-xl border border-slate-200 p-6">
        <div class="flex items-center justify-between gap-4 mb-4">
            <h3 id="dashModalTitle" class="text-lg font-semibold text-slate-900">Details</h3>
            <button type="button" class="px-3 py-1 rounded-lg border border-slate-200 hover:bg-slate-100 text-sm" data-modal-close>Close</button>
        </div>
        <div id="dashModalBody" class="max-h-[70vh] overflow-y-auto"></div>
    </div>
</div>

<div id="modal-registered-mothers" class="hidden">
    <?php if (empty($recentMothers)): ?>
        <p class="text-slate-500">No mothers registered yet.</p>
    <?php else: ?>
        <table class="w-full text-sm mch-table">
            <thead>
                <tr>
                    <th class="p-2 border-b text-left">Hospital #</th>
                    <th class="p-2 border-b text-left">Full Name</th>
                    <th class="p-2 border-b text-left">Status</th>
                    <th class="p-2 border-b text-left">Registered</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentMothers as $m): ?>
                    <tr>
                        <td class="p-2 border-b"><?= htmlspecialchars($m['hospital_number']) ?></td>
                        <td class="p-2 border-b"><?= htmlspecialchars($m['fullname']) ?></td>
                        <td class="p-2 border-b"><?= htmlspecialchars($m['pregnancy_status']) ?></td>
                        <td class="p-2 border-b text-slate-500"><?= htmlspecialchars(date('d M Y', strtotime($m['created_at']))) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="text-xs text-slate-400 mt-3">Showing latest 10 mothers.</p>
    <?php endif; ?>
</div>

<div id="modal-registered-babies" class="hidden">
    <?php if (empty($recentBabies)): ?>
        <p class="text-slate-500">No babies registered yet.</p>
    <?php else: ?>
        <table class="w-full text-sm mch-table">
            <thead>
                <tr>
                    <th class="p-2 border-b text-left">Baby</th>
                    <th class="p-2 border-b text-left">DOB</th>
                    <th class="p-2 border-b text-left">Gender</th>
                    <th class="p-2 border-b text-left">Mother</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentBabies as $b): ?>
                    <tr>
                        <td class="p-2 border-b"><?= htmlspecialchars($b['baby_name']) ?></td>
                        <td class="p-2 border-b"><?= htmlspecialchars($b['dob']) ?></td>
                        <td class="p-2 border-b"><?= htmlspecialchars($b['gender']) ?></td>
                        <td class="p-2 border-b"><?= htmlspecialchars($b['mother_name']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="text-xs text-slate-400 mt-3">Showing latest 10 babies.</p>
    <?php endif; ?>
</div>

<div id="modal-registered-nurses" class="hidden">
    <?php if (empty($recentNurses)): ?>
        <p class="text-slate-500">No nurses found.</p>
    <?php else: ?>
        <table class="w-full text-sm mch-table">
            <thead>
                <tr>
                    <th class="p-2 border-b text-left">Full Name</th>
                    <th class="p-2 border-b text-left">Phone</th>
                    <th class="p-2 border-b text-left">Created</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentNurses as $n): ?>
                    <tr>
                        <td class="p-2 border-b"><?= htmlspecialchars($n['full_name']) ?></td>
                        <td class="p-2 border-b"><?= htmlspecialchars($n['phone'] ?? '-') ?></td>
                        <td class="p-2 border-b text-slate-500">
                            <?= htmlspecialchars(!empty($n['created_at']) ? date('d M Y', strtotime($n['created_at'])) : '-') ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="text-xs text-slate-400 mt-3">Showing latest 10 nurses.</p>
    <?php endif; ?>
</div>

<div id="modal-anc-defaulters" class="hidden">
    <?php if (empty($ancDefaultersList)): ?>
        <p class="text-slate-500">No ANC defaulters.</p>
    <?php else: ?>
        <table class="w-full text-sm mch-table">
            <thead>
                <tr>
                    <th class="p-2 border-b text-left">Hospital #</th>
                    <th class="p-2 border-b text-left">Mother</th>
                    <th class="p-2 border-b text-left">Overdue Since</th>
                    <th class="p-2 border-b text-left">Count</th>
                    <th class="p-2 border-b text-left">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($ancDefaultersList as $a): ?>
                    <tr>
                        <td class="p-2 border-b"><?= htmlspecialchars($a['hospital_number']) ?></td>
                        <td class="p-2 border-b"><?= htmlspecialchars($a['fullname']) ?></td>
                        <td class="p-2 border-b"><?= htmlspecialchars($a['overdue_since']) ?></td>
                        <td class="p-2 border-b"><?= (int)($a['overdue_count'] ?? 0) ?></td>
                        <td class="p-2 border-b">
                            <a class="inline-flex items-center px-3 py-1 rounded-lg bg-sky-50 border border-sky-200 hover:bg-sky-100 text-sm"
                                href="/mch_system/admin_portal/mother_anc_visits.php?mother_id=<?= (int)$a['id'] ?>">
                                <span class="inline-block w-2 h-2 rounded-full bg-rose-600 animate-pulse mr-2"></span>
                                View Overdue
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="text-xs text-slate-400 mt-3">Showing top 10 by earliest overdue.</p>
    <?php endif; ?>
</div>

<div id="modal-immun-defaulters" class="hidden">
    <?php if (empty($immunDefaultersList)): ?>
        <p class="text-slate-500">No immunization defaulters.</p>
    <?php else: ?>
        <table class="w-full text-sm mch-table">
            <thead>
                <tr>
                    <th class="p-2 border-b text-left">Baby</th>
                    <th class="p-2 border-b text-left">Mother</th>
                    <th class="p-2 border-b text-left">Vaccine</th>
                    <th class="p-2 border-b text-left">Overdue Since</th>
                    <th class="p-2 border-b text-left">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($immunDefaultersList as $i): ?>
                    <tr>
                        <td class="p-2 border-b"><?= htmlspecialchars($i['baby_name']) ?></td>
                        <td class="p-2 border-b"><?= htmlspecialchars($i['mother_name']) ?></td>
                        <td class="p-2 border-b"><?= htmlspecialchars($i['vaccine']) ?></td>
                        <td class="p-2 border-b"><?= htmlspecialchars($i['due_date']) ?></td>
                        <td class="p-2 border-b">
                            <a class="inline-flex items-center px-3 py-1 rounded-lg bg-sky-50 border border-sky-200 hover:bg-sky-100 text-sm"
                                href="/mch_system/nurse_portal/baby_history.php?baby_id=<?= (int)$i['id'] ?>">
                                View Records
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="text-xs text-slate-400 mt-3">Showing top 10 by earliest overdue.</p>
    <?php endif; ?>
</div>

<div id="modal-high-risk" class="hidden">
    <?php if (empty($highRiskMothersList)): ?>
        <p class="text-slate-500">No high-risk mothers found.</p>
    <?php else: ?>
        <table class="w-full text-sm mch-table">
            <thead>
                <tr>
                    <th class="p-2 border-b text-left">Hospital #</th>
                    <th class="p-2 border-b text-left">Mother</th>
                    <th class="p-2 border-b text-left">ANC Visits</th>
                    <th class="p-2 border-b text-left">Reason</th>
                    <th class="p-2 border-b text-left">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($highRiskMothersList as $m): ?>
                    <tr>
                        <td class="p-2 border-b"><?= htmlspecialchars($m['hospital_number']) ?></td>
                        <td class="p-2 border-b"><?= htmlspecialchars($m['fullname']) ?></td>
                        <td class="p-2 border-b"><?= (int)$m['anc_count'] ?></td>
                        <td class="p-2 border-b">
                            <?= ((int)$m['anc_count'] === 0) ? 'No ANC visits' : 'ANC overdue since ' . htmlspecialchars($m['overdue_since'] ?? '-') ?>
                        </td>
                        <td class="p-2 border-b">
                            <a class="inline-flex items-center px-3 py-1 rounded-lg bg-sky-50 border border-sky-200 hover:bg-sky-100 text-sm"
                                href="/mch_system/nurse_portal/mother_history.php?mother_id=<?= (int)$m['id'] ?>">
                                View History
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="text-xs text-slate-400 mt-3">Showing top 10 flagged by overdue or no ANC visits.</p>
    <?php endif; ?>
</div>

<script>
    (function() {
        const modal = document.getElementById('dashModal');
        const modalTitle = document.getElementById('dashModalTitle');
        const modalBody = document.getElementById('dashModalBody');
        if (!modal || !modalTitle || !modalBody) return;

        const openModal = (title, templateId) => {
            const template = document.getElementById(templateId);
            if (!template) return;
            modalTitle.textContent = title || 'Details';
            modalBody.innerHTML = template.innerHTML;
            modal.classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
        };

        const closeModal = () => {
            modal.classList.add('hidden');
            modalBody.innerHTML = '';
            document.body.classList.remove('overflow-hidden');
        };

        document.querySelectorAll('[data-modal-target]').forEach((btn) => {
            btn.addEventListener('click', () => {
                openModal(btn.getAttribute('data-modal-title'), btn.getAttribute('data-modal-target'));
            });
        });

        modal.querySelectorAll('[data-modal-close]').forEach((el) => {
            el.addEventListener('click', closeModal);
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && !modal.classList.contains('hidden')) {
                closeModal();
            }
        });
    })();
</script>

<?php if (!empty($clinicStats)): ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        const clinicLabels = <?= json_encode(array_column($clinicStats, 'clinic_name')) ?>;
        const clinicData = <?= json_encode(array_map('intval', array_column($clinicStats, 'mothers_count'))) ?>;
        const ctx = document.getElementById('clinicChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: clinicLabels,
                datasets: [{
                    label: 'Registered Mothers',
                    data: clinicData,
                    backgroundColor: 'rgba(15, 118, 110, 0.6)',
                    borderColor: 'rgba(15, 118, 110, 1)',
                    borderWidth: 1,
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });
    </script>
<?php endif; ?>

<script>
    (function() {
        const elMothers = document.getElementById('mch_admin_stat_mothers');
        const elBabies = document.getElementById('mch_admin_stat_babies');
        const elNurses = document.getElementById('mch_admin_stat_nurses');
        const elAncDef = document.getElementById('mch_admin_stat_anc_defaulters');
        const elImmunDef = document.getElementById('mch_admin_stat_immun_defaulters');
        const elHighRisk = document.getElementById('mch_admin_stat_high_risk');

        const elPulseMothers = document.getElementById('mch_admin_pulse_mothers');
        const elCoverage = document.getElementById('mch_admin_pulse_coverage');
        const elCoverageBar = document.getElementById('mch_admin_pulse_coverage_bar');
        const elTopFacility = document.getElementById('mch_admin_pulse_top_facility');
        const elCritical = document.getElementById('mch_admin_pulse_critical_stock');

        if (!elMothers || !elBabies || !elNurses || !elAncDef || !elImmunDef || !elHighRisk) return;

        let inFlight = false;
        async function refreshStats() {
            if (inFlight) return;
            inFlight = true;
            try {
                const res = await fetch('/mch_system/api/dashboard_admin_stats.php', {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json'
                    },
                    cache: 'no-store'
                });
                if (!res.ok) return;
                const data = await res.json();
                if (!data || !data.ok) return;

                elMothers.textContent = String(data.totalMothers ?? '');
                elBabies.textContent = String(data.totalBabies ?? '');
                elNurses.textContent = String(data.totalNurses ?? '');
                elAncDef.textContent = String(data.ancDefaulters ?? '');
                elImmunDef.textContent = String(data.immunDefaulters ?? '');
                elHighRisk.textContent = String(data.highRiskMothers ?? '');

                if (elPulseMothers) elPulseMothers.textContent = String(data.totalMothers ?? '');
                if (elCoverage) elCoverage.textContent = String((data.coverage ?? 0)) + '%';
                if (elCoverageBar) elCoverageBar.style.width = String((data.coverage ?? 0)) + '%';
                if (elTopFacility) elTopFacility.textContent = String(data.topClinicName ?? '');
                if (elCritical) elCritical.textContent = String(data.criticalStock ?? 0);
            } catch (e) {
                // ignore
            } finally {
                inFlight = false;
            }
        }

        refreshStats();
        const interval = window.setInterval(refreshStats, 45000);
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) refreshStats();
        });
        window.addEventListener('pagehide', () => {
            try {
                window.clearInterval(interval);
            } catch (e) {}
        });
    })();
</script>

<script>
    (function() {
        const badge = document.getElementById('mch_conn_badge');
        const dot = document.getElementById('mch_conn_dot');
        const label = document.getElementById('mch_conn_label');
        if (!badge || !dot || !label) return;

        const setState = (online) => {
            const isOnline = !!online;
            label.textContent = isOnline ? 'Online' : 'Offline';
            badge.classList.toggle('bg-emerald-50', isOnline);
            badge.classList.toggle('text-emerald-700', isOnline);
            badge.classList.toggle('border-emerald-200', isOnline);
            dot.classList.toggle('bg-emerald-500', isOnline);

            badge.classList.toggle('bg-rose-50', !isOnline);
            badge.classList.toggle('text-rose-700', !isOnline);
            badge.classList.toggle('border-rose-200', !isOnline);
            dot.classList.toggle('bg-rose-500', !isOnline);

            badge.title = isOnline ? 'Online' : 'Offline';
        };

        async function probe() {
            if (!navigator.onLine) {
                setState(false);
                return;
            }

            const controller = window.AbortController ? new AbortController() : null;
            const timeout = window.setTimeout(() => {
                try {
                    controller?.abort();
                } catch (e) {}
            }, 2500);

            try {
                const res = await fetch('/mch_system/api/ping.php', {
                    method: 'GET',
                    cache: 'no-store',
                    headers: {
                        'Accept': 'application/json'
                    },
                    signal: controller ? controller.signal : undefined
                });
                setState(!!res && res.ok);
            } catch (e) {
                setState(false);
            } finally {
                try {
                    window.clearTimeout(timeout);
                } catch (e) {}
            }
        }

        window.addEventListener('online', probe);
        window.addEventListener('offline', () => setState(false));
        probe();
        window.setInterval(probe, 15000);
    })();
</script>

<?php include __DIR__ . "/../includes/footer.php"; ?>
