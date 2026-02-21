<?php
require_once __DIR__ . "/../config/session.php";
mch_session_start();
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../libs/dompdf/autoload.inc.php";

use Dompdf\Dompdf;

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    die("Unauthorized");
}
$adminLevel = $_SESSION['admin_level'] ?? 'SUPER';
$adminClinicId = (int)($_SESSION['clinic_id'] ?? 0);
if ($adminLevel !== 'SUPER' && $adminClinicId <= 0) {
    die("Clinic admin is not assigned to a clinic.");
}

$monthRaw = $_GET['month'] ?? ($_POST['month'] ?? ''); // usually YYYY-MM from <input type="month">
$monthRaw = trim((string)$monthRaw);

// Accept YYYY-MM-DD and convert to YYYY-MM (some browsers / manual URLs).
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $monthRaw)) {
    $monthRaw = substr($monthRaw, 0, 7);
}

// Accept YYYY-M and normalize to YYYY-0M
if (preg_match('/^(\d{4})-(\d{1})$/', $monthRaw, $m)) {
    $monthRaw = $m[1] . '-0' . $m[2];
}

$month = $monthRaw; // YYYY-MM
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $_SESSION['flash_reports_error'] = 'month';
    header("Location: /mch_system/admin_portal/reports.php");
    exit;
}
$start = $month . "-01";
$end   = date("Y-m-t", strtotime($start));

// Fetch ANC data
$sql = "
    SELECT m.fullname, m.hospital_number,
           a.visit_date, a.next_visit_date, a.status
    FROM anc_visits a
    JOIN mothers m ON a.mother_id = m.id
";
$params = [$start, $end];
if ($adminLevel !== 'SUPER') {
    $sql .= " JOIN users u ON m.user_id = u.id ";
}
$sql .= " WHERE a.visit_date BETWEEN ? AND ? ";
if ($adminLevel !== 'SUPER') {
    $sql .= " AND u.clinic_id = ? ";
    $params[] = $adminClinicId;
}
$sql .= " ORDER BY a.visit_date ";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Stats
$total = count($rows);
$completed = count(array_filter($rows, fn($r) => $r['status'] === 'COMPLETED'));
$pending = $total - $completed;

// Build HTML
$reportDate = date('d M Y');
$safeMonth = htmlspecialchars($month, ENT_QUOTES, 'UTF-8');
$html = "
<style>
  body { font-family: DejaVu Sans, Arial, sans-serif; color: #0f172a; }
  .header { text-align: center; margin-bottom: 12px; }
  .title { font-size: 20px; font-weight: bold; margin: 0; }
  .meta { font-size: 12px; color: #64748b; margin-top: 4px; }
  .stats { margin: 12px 0 16px; background: #f8fafc; padding: 10px; border-radius: 6px; }
  .stats span { display: inline-block; margin-right: 16px; font-size: 12px; }
  table { width: 100%; border-collapse: collapse; font-size: 12px; }
  th { background: #e2e8f0; text-align: left; padding: 8px; }
  td { border-bottom: 1px solid #e2e8f0; padding: 8px; }
  .badge { padding: 2px 6px; border-radius: 10px; font-size: 11px; }
  .done { background: #dcfce7; color: #166534; }
  .pending { background: #fee2e2; color: #991b1b; }
</style>

<div class='header'>
  <div class='title'>Monthly ANC Report</div>
  <div class='meta'>Period: $safeMonth | Generated: $reportDate</div>
</div>

<div class='stats'>
  <span><strong>Total Visits:</strong> $total</span>
  <span><strong>Completed:</strong> $completed</span>
  <span><strong>Pending:</strong> $pending</span>
</div>

<table class='mch-scroll'>
  <tr>
    <th>Mother</th>
    <th>Hospital #</th>
    <th>Visit Date</th>
    <th>Next Visit</th>
    <th>Status</th>
  </tr>
";

if (empty($rows)) {
    $html .= "
    <tr>
        <td colspan='5' style='text-align:center; padding: 14px; color: #64748b;'>No ANC visits recorded for this month.</td>
    </tr>";
} else {
    foreach ($rows as $r) {
        $statusClass = ($r['status'] === 'COMPLETED') ? 'done' : 'pending';
        $statusLabel = htmlspecialchars($r['status']);
        $html .= "
        <tr>
            <td>{$r['fullname']}</td>
            <td>{$r['hospital_number']}</td>
            <td>{$r['visit_date']}</td>
            <td>{$r['next_visit_date']}</td>
            <td><span class='badge {$statusClass}'>{$statusLabel}</span></td>
        </tr>";
    }
}

$html .= "</table>";

// Generate PDF
$dompdf = new Dompdf();
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream("ANC_Report_{$month}.pdf", ["Attachment" => false]);
