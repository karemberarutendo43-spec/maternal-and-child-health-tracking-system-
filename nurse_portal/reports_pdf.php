<?php
require_once __DIR__ . "/../config/session.php";
mch_session_start();
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../libs/dompdf/autoload.inc.php";

use Dompdf\Dompdf;

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'nurse'], true)) {
    die("Unauthorized");
}

$month = $_GET['month'] ?? '';
$export = isset($_GET['export']) && $_GET['export'] === '1';
if (!$month || !preg_match('/^\d{4}-\d{2}$/', $month)) {
    die("Invalid or missing month. Please go back and select a month.");
}

$start = $month . "-01";
$end   = date("Y-m-t", strtotime($start));
$clinic_id = $_SESSION['clinic_id'] ?? null;

$clinicFilter = '';
$params = [$start, $end];
if (!empty($clinic_id)) {
    $clinicFilter = " AND u.clinic_id = ? ";
    $params[] = $clinic_id;
}

// ANC data
$stmt = $pdo->prepare("
    SELECT m.fullname, m.hospital_number,
           a.visit_date, a.next_visit_date, a.status
    FROM anc_visits a
    JOIN mothers m ON a.mother_id = m.id
    LEFT JOIN users u ON m.user_id = u.id
    WHERE a.visit_date BETWEEN ? AND ?
    {$clinicFilter}
    ORDER BY a.visit_date
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total = count($rows);
$completed = count(array_filter($rows, fn($r) => $r['status'] === 'COMPLETED'));
$pending = $total - $completed;

// Immunizations data (due within the month) - raw rows for accurate totals, grouped by baby for display
$immunStmt = $pdo->prepare("
    SELECT
      b.id AS baby_id,
      b.baby_name,
      m.fullname AS mother_name,
      i.vaccine,
      i.due_date,
      i.status
    FROM immunizations i
    JOIN babies b ON i.baby_id = b.id
    JOIN mothers m ON b.mother_id = m.id
    LEFT JOIN users u ON m.user_id = u.id
    WHERE i.due_date BETWEEN ? AND ?
    {$clinicFilter}
    ORDER BY i.due_date ASC, i.id ASC
");
$immunStmt->execute($params);
$immunRowsRaw = $immunStmt->fetchAll(PDO::FETCH_ASSOC);

$immunTotal = count($immunRowsRaw);
$immunGiven = count(array_filter($immunRowsRaw, fn($r) => $r['status'] === 'GIVEN'));
$immunPending = $immunTotal - $immunGiven;

$byBaby = [];
foreach ($immunRowsRaw as $r) {
    $babyId = (int)($r['baby_id'] ?? 0);
    if ($babyId <= 0) continue;
    if (!isset($byBaby[$babyId])) {
        $byBaby[$babyId] = [
            'baby_name' => (string)($r['baby_name'] ?? ''),
            'mother_name' => (string)($r['mother_name'] ?? ''),
            'items' => [],
            'has_pending' => false,
            'has_given' => false,
        ];
    }
    $st = (string)($r['status'] ?? '');
    if ($st === 'GIVEN') $byBaby[$babyId]['has_given'] = true;
    if ($st === 'PENDING') $byBaby[$babyId]['has_pending'] = true;
    $byBaby[$babyId]['items'][] = [
        'vaccine' => (string)($r['vaccine'] ?? ''),
        'due_date' => (string)($r['due_date'] ?? ''),
    ];
}
$immunRows = array_values($byBaby);

$clinicName = 'All Clinics';
if (!empty($clinic_id)) {
    $cstmt = $pdo->prepare("SELECT clinic_name FROM clinics WHERE id = ? LIMIT 1");
    $cstmt->execute([$clinic_id]);
    $crow = $cstmt->fetch(PDO::FETCH_ASSOC);
    if ($crow && !empty($crow['clinic_name'])) {
        $clinicName = $crow['clinic_name'];
    }
}

$html = "
<h2 style='text-align:center'>Monthly ANC Report</h2>
<p><strong>Clinic:</strong> {$clinicName}</p>
<p><strong>Period:</strong> {$month}</p>
<p><strong>Generated:</strong> " . date('d M Y') . "</p>

<hr>

<p>
Total Visits: <b>{$total}</b><br>
Completed: <b>{$completed}</b><br>
Pending: <b>{$pending}</b>
</p>

<p>
Immunizations: <b>{$immunTotal}</b><br>
Given: <b>{$immunGiven}</b><br>
Pending: <b>{$immunPending}</b>
</p>

<div style='margin: 12px 0;'>
  <div style='font-size:12px; margin-bottom:6px;'>Monthly Snapshot</div>
  <div style='display:flex; gap:12px;'>
    <div style='flex:1;'>
      <div style='font-size:11px;'>ANC Completed</div>
      <div style='height:10px; background:#e2e8f0; border-radius:6px; overflow:hidden;'>
        <div style='height:10px; width:" . ($total ? round(($completed / $total) * 100) : 0) . "%; background:#10b981;'></div>
      </div>
    </div>
    <div style='flex:1;'>
      <div style='font-size:11px;'>Immunizations Given</div>
      <div style='height:10px; background:#e2e8f0; border-radius:6px; overflow:hidden;'>
        <div style='height:10px; width:" . ($immunTotal ? round(($immunGiven / $immunTotal) * 100) : 0) . "%; background:#0ea5e9;'></div>
      </div>
    </div>
  </div>
</div>

<table width='100%' border='1' cellspacing='0' cellpadding='5'>
<tr style='background:#eee'>
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
        <td colspan='5' style='text-align:center;'>No ANC visits recorded for this month.</td>
    </tr>";
} else {
    foreach ($rows as $r) {
        $html .= "
        <tr>
            <td>{$r['fullname']}</td>
            <td>{$r['hospital_number']}</td>
            <td>{$r['visit_date']}</td>
            <td>{$r['next_visit_date']}</td>
            <td>{$r['status']}</td>
        </tr>";
    }
}

$html .= "</table>";

$html .= "
<br>
<h3>Immunizations Due</h3>
<table width='100%' border='1' cellspacing='0' cellpadding='5'>
<tr style='background:#eee'>
    <th>Baby</th>
    <th>Mother</th>
    <th>Vaccines / Due Dates</th>
    <th>Status</th>
</tr>
";

if (empty($immunRows)) {
    $html .= "
    <tr>
        <td colspan='5' style='text-align:center;'>No immunizations due for this month.</td>
    </tr>";
} else {
    foreach ($immunRows as $r) {
        $status = !empty($r['has_pending']) ? 'PENDING' : (!empty($r['has_given']) ? 'GIVEN' : 'PENDING');
        $lines = '';
        $items = is_array($r['items'] ?? null) ? $r['items'] : [];
        foreach ($items as $it) {
            $v = htmlspecialchars((string)($it['vaccine'] ?? ''), ENT_QUOTES);
            $d = htmlspecialchars((string)($it['due_date'] ?? ''), ENT_QUOTES);
            $lines .= "<div>{$v} ({$d})</div>";
        }
        $html .= "
        <tr>
            <td>{$r['baby_name']}</td>
            <td>{$r['mother_name']}</td>
            <td>{$lines}</td>
            <td>{$status}</td>
        </tr>";
    }
}

$html .= "</table>";

$dompdf = new Dompdf();
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$output = $dompdf->output();

if ($export) {
    $reportDir = __DIR__ . "/../reports";
    if (!is_dir($reportDir)) {
        @mkdir($reportDir, 0775, true);
    }
    $clinicSlug = preg_replace('/[^A-Za-z0-9_-]+/', '_', $clinicName);
    $clinicSlug = trim($clinicSlug, '_');
    $fileName = "Nurse_Report_{$clinicSlug}_{$month}_" . date('Ymd_His') . ".pdf";
    $filePath = $reportDir . "/" . $fileName;
    @file_put_contents($filePath, $output);
}

$dompdf->stream("ANC_Report_{$month}.pdf", ["Attachment" => false]);
