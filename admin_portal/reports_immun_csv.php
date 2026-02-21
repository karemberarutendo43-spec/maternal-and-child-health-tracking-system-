<?php
require_once __DIR__ . "/../config/session.php";
mch_session_start();
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/cards.php";
require_once __DIR__ . "/../config/epi.php";

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

$hasGivenDate = db_has_column($pdo, 'immunizations', 'given_date');

$dateExpr = $hasGivenDate ? "IFNULL(i.given_date, i.due_date)" : "i.due_date";
$clinicWhere = $adminAllClinics ? "" : " AND u.clinic_id = :clinic_id";
$sql = "
    SELECT
      COALESCE(c.id, 0) AS clinic_id,
      COALESCE(c.clinic_name, 'Unknown') AS clinic_name,
      i.vaccine AS vaccine_label,
      COUNT(*) AS given_count
    FROM immunizations i
    JOIN babies b ON i.baby_id = b.id
    JOIN mothers m ON b.mother_id = m.id
    LEFT JOIN users u ON m.user_id = u.id
    LEFT JOIN clinics c ON u.clinic_id = c.id
    WHERE i.status = 'GIVEN'
      AND $dateExpr BETWEEN :start AND :end
      {$clinicWhere}
    GROUP BY clinic_id, clinic_name, i.vaccine
    ORDER BY clinic_name, i.vaccine
";

$stmt = $pdo->prepare($sql);
$params = [':start' => $start, ':end' => $end];
if (!$adminAllClinics) {
    $params[':clinic_id'] = $adminClinicId;
}
$stmt->execute($params);
$raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

$codeToLabel = [];
foreach (mch_epi_schedule_items() as $it) {
    $codeToLabel[(string)$it['code']] = (string)$it['label'];
}

$agg = [];
foreach ($raw as $r) {
    $clinicId = (int)($r['clinic_id'] ?? 0);
    $clinicName = (string)($r['clinic_name'] ?? 'Unknown');
    $vaccineLabel = (string)($r['vaccine_label'] ?? '');
    $count = (int)($r['given_count'] ?? 0);

    $code = mch_epi_normalize_vaccine_code($vaccineLabel);
    $label = $codeToLabel[$code] ?? $vaccineLabel;

    $key = $clinicId . '|' . $clinicName;
    $agg[$key] ??= [
        'clinic_id' => $clinicId,
        'clinic_name' => $clinicName,
        'rows' => []
    ];
    $agg[$key]['rows'][$code] ??= [
        'vaccine_code' => $code,
        'vaccine_label' => $label,
        'given_count' => 0
    ];
    $agg[$key]['rows'][$code]['given_count'] += $count;
}

usort($agg, static function ($a, $b) {
    return strcmp((string)$a['clinic_name'], (string)$b['clinic_name']);
});

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="immunizations_' . $month . '.csv"');
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');
fputcsv($out, ['month', 'clinic_id', 'clinic_name', 'vaccine_code', 'vaccine_label', 'given_count']);

foreach ($agg as $clinic) {
    $rows = $clinic['rows'];
    ksort($rows);
    foreach ($rows as $row) {
        fputcsv($out, [
            $month,
            $clinic['clinic_id'],
            $clinic['clinic_name'],
            $row['vaccine_code'],
            $row['vaccine_label'],
            $row['given_count'],
        ]);
    }
}

fclose($out);
exit;
