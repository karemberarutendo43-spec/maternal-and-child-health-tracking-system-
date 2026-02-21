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
$adminAllClinics = ($adminLevel === 'SUPER' || $adminLevel === 'DISTRICT');
if (!$adminAllClinics && $adminClinicId <= 0) {
    die("Clinic admin is not assigned to a clinic.");
}

$month = (string)($_GET['month'] ?? '');
if (!preg_match('/^\\d{4}-\\d{2}$/', $month)) {
    $_SESSION['flash_reports_error'] = 'month';
    header("Location: /mch_system/admin_portal/reports.php");
    exit;
}

$start = $month . '-01';
$end = date('Y-m-t', strtotime($start));

$clinicId = $adminAllClinics ? (int)($_GET['clinic_id'] ?? 0) : $adminClinicId;
if (!$adminAllClinics) {
    $clinicId = $adminClinicId;
}

// PNC visits are recorded per mother (and optionally baby). Export counts per clinic.
$sql = "
    SELECT
      COALESCE(c.id, 0) AS clinic_id,
      COALESCE(c.clinic_name, 'Unknown') AS clinic_name,
      COUNT(*) AS visits_count
    FROM pnc_visits p
    JOIN mothers m ON p.mother_id = m.id
    LEFT JOIN users u ON m.user_id = u.id
    LEFT JOIN clinics c ON u.clinic_id = c.id
    WHERE p.visit_date BETWEEN :start AND :end
      AND (:clinic_id = 0 OR u.clinic_id = :clinic_id)
    GROUP BY clinic_id, clinic_name
    ORDER BY clinic_name
";
$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':start' => $start,
    ':end' => $end,
    ':clinic_id' => $clinicId,
]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="pnc_visits_' . $month . '.csv"');
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');
fputcsv($out, ['month', 'clinic_id', 'clinic_name', 'pnc_visits_count']);
foreach ($rows as $r) {
    fputcsv($out, [
        $month,
        (int)($r['clinic_id'] ?? 0),
        (string)($r['clinic_name'] ?? 'Unknown'),
        (int)($r['visits_count'] ?? 0),
    ]);
}
fclose($out);
exit;
