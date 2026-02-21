<?php
require_once __DIR__ . "/../config/session.php";
mch_session_start();
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/scope.php";
require_once __DIR__ . "/../config/audit.php";

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'nurse') {
    header("Location: /mch_system/auth/login.php");
    exit;
}

$hasRegisterTable = false;
try {
    $hasRegisterTable = (bool)$pdo->query("SHOW TABLES LIKE 'delivery_register'")->fetchColumn();
} catch (Throwable $e) {
    $hasRegisterTable = false;
}
if (!$hasRegisterTable) {
    http_response_code(500);
    echo "Delivery Register table is not installed. Run sql/delivery_register.sql.";
    exit;
}

$date = (string)($_GET['date'] ?? date('Y-m-d'));
if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $date)) {
    $date = date('Y-m-d');
}
$format = strtolower((string)($_GET['format'] ?? 'html'));
if (!in_array($format, ['html', 'csv'], true)) {
    $format = 'html';
}

$scopedClinicId = mch_scope_scoped_clinic_id();
$from = $date . " 00:00:00";
$to = $date . " 23:59:59";

$q = "SELECT * FROM delivery_register WHERE delivery_datetime BETWEEN ? AND ?";
$params = [$from, $to];
if ($scopedClinicId > 0) {
    $q .= " AND (clinic_id = ? OR clinic_id IS NULL)";
    $params[] = $scopedClinicId;
}
$q .= " ORDER BY delivery_datetime ASC, id ASC";
$stmt = $pdo->prepare($q);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

mch_audit_log($pdo, 'DELIVERY_REGISTER_EXPORT', 'delivery_register', null, "Exported delivery register for {$date} ({$format}), rows=" . count($rows));

function mch_band_marks(string $band): array
{
    $bands = ['10-14', '15-19', '20-24', '25-29', '30-49', '>=50'];
    $out = [];
    foreach ($bands as $b) $out[] = ($band === $b) ? '1' : '';
    return $out;
}

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="delivery_register_' . $date . '.csv"');
    $out = fopen('php://output', 'w');

    $header = [
        'Serial', 'Hospital #', 'ANC No',
        'Name', 'Address', 'Phone',
        'Age', '10-14', '15-19', '20-24', '25-29', '30-49', '>=50',
        'GA admission (weeks)', 'B/U', 'G', 'P', 'Children alive',
        'Past obstetric complications',
        'Admission date/time', 'Presentation/position', 'Abnormalities on admission',
        'Onset of labour date/time', 'Duration stages', 'Drugs/GA (times)',
        'Delivery date/time', 'Method', 'EBL (ml)', 'Perineum', 'Placenta/membranes',
        'Delivery by (qual)', 'Assisted by',
        'Outcome', 'Sex', 'Birth weight (g)', 'Apgar', 'Tetanus',
        'HIV', 'T&C tested', 'T&C result',
        'PMTCT ARVs', 'Vitamin A', 'Feeding', 'BF 1hr', 'TEO', 'Comments',
    ];
    fputcsv($out, $header);

    foreach ($rows as $r) {
        $marks = mch_band_marks((string)($r['age_band'] ?? ''));
        $pmtct = (string)($r['maternal_pmtct_arvs'] ?? '');
        if ($pmtct === 'OTHER' && !empty($r['maternal_pmtct_other'])) {
            $pmtct .= ':' . (string)$r['maternal_pmtct_other'];
        }

        $line = [
            $r['serial_no'] ?? '',
            $r['hospital_number'] ?? '',
            $r['anc_no'] ?? '',
            $r['mother_name'] ?? '',
            $r['mother_address'] ?? '',
            $r['mother_phone'] ?? '',
            $r['age_years'] ?? '',
            ...$marks,
            $r['ga_admission_weeks'] ?? '',
            $r['booked_status'] ?? '',
            $r['gravida'] ?? '',
            $r['parity'] ?? '',
            $r['children_alive'] ?? '',
            $r['past_obstetric_complications'] ?? '',
            $r['admission_datetime'] ?? '',
            $r['presentation_position'] ?? '',
            $r['abnormalities_on_admission'] ?? '',
            $r['onset_of_labour_datetime'] ?? '',
            $r['duration_of_stages'] ?? '',
            $r['drugs_given_in_labour'] ?? '',
            $r['delivery_datetime'] ?? '',
            $r['delivery_method'] ?? '',
            $r['estimated_blood_loss_ml'] ?? '',
            $r['perineum'] ?? '',
            $r['placenta_membranes'] ?? '',
            $r['delivered_by_qualification'] ?? '',
            $r['assisted_by'] ?? '',
            $r['birth_outcome'] ?? '',
            $r['baby_sex'] ?? '',
            $r['birth_weight_grams'] ?? '',
            $r['apgar_score'] ?? '',
            $r['tetanus_protected'] ?? '',
            $r['hiv_status_on_admission'] ?? '',
            $r['tc_tested'] ?? '',
            $r['tc_result'] ?? '',
            $pmtct,
            $r['maternal_vitamin_a'] ?? '',
            $r['infant_feeding_method'] ?? '',
            $r['infant_breastfed_first_hour'] ?? '',
            $r['infant_teo_given'] ?? '',
            $r['comments'] ?? '',
        ];
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Delivery Register <?= htmlspecialchars($date) ?></title>
  <style>
    @page { size: landscape; margin: 10mm; }
    body { font-family: Arial, sans-serif; color: #111; }
    h1 { font-size: 14px; margin: 0 0 6px; }
    .meta { font-size: 11px; margin-bottom: 10px; }
    table { border-collapse: collapse; width: 100%; font-size: 9px; }
    th, td { border: 1px solid #333; padding: 3px; vertical-align: top; }
    th { background: #f3f4f6; font-weight: 700; text-align: center; }
    .nowrap { white-space: nowrap; }
  </style>
</head>
<body>
  <h1>Daily Delivery Register — <?= htmlspecialchars($date) ?></h1>
  <div class="meta">Generated: <?= htmlspecialchars(date('Y-m-d H:i')) ?> • Rows: <?= count($rows) ?></div>

  <table>
    <thead>
      <tr>
        <th rowspan="2">1<br>Serial</th>
        <th rowspan="2">2<br>Hospital #</th>
        <th rowspan="2">3<br>ANC No</th>
        <th rowspan="2">4<br>Name / Address / Phone</th>
        <th colspan="7">5 Age (completed years)</th>
        <th rowspan="2">6<br>GA</th>
        <th rowspan="2">7<br>B/U</th>
        <th rowspan="2">8<br>G</th>
        <th rowspan="2">9<br>P</th>
        <th rowspan="2">10<br>Alive</th>
        <th rowspan="2">11 Past obstetric complications</th>
        <th rowspan="2">12 Admission</th>
        <th rowspan="2">13 Presentation/Position</th>
        <th rowspan="2">14 Abnormalities</th>
        <th rowspan="2">15 Onset</th>
        <th rowspan="2">16 Duration</th>
        <th rowspan="2">17 Drugs/GA</th>
        <th rowspan="2">18 Delivery</th>
        <th rowspan="2">19 Method</th>
        <th rowspan="2">20 EBL</th>
        <th rowspan="2">21 Perineum</th>
        <th rowspan="2">22 Placenta</th>
        <th colspan="2">23 Delivery by</th>
        <th colspan="3">24 Outcome</th>
        <th rowspan="2">25 Sex</th>
        <th rowspan="2">26 Wt</th>
        <th rowspan="2">27 Apgar</th>
        <th rowspan="2">28 Tetanus</th>
        <th rowspan="2">29 HIV</th>
        <th colspan="2">30 T&amp;C</th>
        <th rowspan="2">31 PMTCT</th>
        <th rowspan="2">32 Vit A</th>
        <th rowspan="2">33 Feeding</th>
        <th rowspan="2">35 BF 1hr</th>
        <th rowspan="2">36 TEO</th>
        <th rowspan="2">Comments</th>
      </tr>
      <tr>
        <th>Age</th><th>10-14</th><th>15-19</th><th>20-24</th><th>25-29</th><th>30-49</th><th>&ge;50</th>
        <th>i) Qual</th><th>ii) Assist</th>
        <th>Live</th><th>FSB</th><th>MSB</th>
        <th>Test</th><th>Res</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <?php
          $marks = mch_band_marks((string)($r['age_band'] ?? ''));
          $pmtct = (string)($r['maternal_pmtct_arvs'] ?? '');
          if ($pmtct === 'OTHER' && !empty($r['maternal_pmtct_other'])) $pmtct .= ':' . (string)$r['maternal_pmtct_other'];
          $out = (string)($r['birth_outcome'] ?? '');
        ?>
        <tr>
          <td class="nowrap"><?= htmlspecialchars((string)($r['serial_no'] ?? '')) ?></td>
          <td class="nowrap"><?= htmlspecialchars((string)($r['hospital_number'] ?? '')) ?></td>
          <td class="nowrap"><?= htmlspecialchars((string)($r['anc_no'] ?? '')) ?></td>
          <td><?= htmlspecialchars((string)($r['mother_name'] ?? '')) ?><br><?= htmlspecialchars((string)($r['mother_address'] ?? '')) ?><br><?= htmlspecialchars((string)($r['mother_phone'] ?? '')) ?></td>
          <td class="nowrap"><?= htmlspecialchars((string)($r['age_years'] ?? '')) ?></td>
          <td><?= $marks[0] ?></td><td><?= $marks[1] ?></td><td><?= $marks[2] ?></td><td><?= $marks[3] ?></td><td><?= $marks[4] ?></td><td><?= $marks[5] ?></td>
          <td class="nowrap"><?= htmlspecialchars((string)($r['ga_admission_weeks'] ?? '')) ?></td>
          <td class="nowrap"><?= htmlspecialchars((string)($r['booked_status'] ?? '')) ?></td>
          <td class="nowrap"><?= htmlspecialchars((string)($r['gravida'] ?? '')) ?></td>
          <td class="nowrap"><?= htmlspecialchars((string)($r['parity'] ?? '')) ?></td>
          <td class="nowrap"><?= htmlspecialchars((string)($r['children_alive'] ?? '')) ?></td>
          <td><?= htmlspecialchars((string)($r['past_obstetric_complications'] ?? '')) ?></td>
          <td class="nowrap"><?= htmlspecialchars((string)($r['admission_datetime'] ?? '')) ?></td>
          <td><?= htmlspecialchars((string)($r['presentation_position'] ?? '')) ?></td>
          <td><?= htmlspecialchars((string)($r['abnormalities_on_admission'] ?? '')) ?></td>
          <td class="nowrap"><?= htmlspecialchars((string)($r['onset_of_labour_datetime'] ?? '')) ?></td>
          <td><?= htmlspecialchars((string)($r['duration_of_stages'] ?? '')) ?></td>
          <td><?= htmlspecialchars((string)($r['drugs_given_in_labour'] ?? '')) ?></td>
          <td class="nowrap"><?= htmlspecialchars((string)($r['delivery_datetime'] ?? '')) ?></td>
          <td class="nowrap"><?= htmlspecialchars((string)($r['delivery_method'] ?? '')) ?></td>
          <td class="nowrap"><?= htmlspecialchars((string)($r['estimated_blood_loss_ml'] ?? '')) ?></td>
          <td><?= htmlspecialchars((string)($r['perineum'] ?? '')) ?></td>
          <td class="nowrap"><?= htmlspecialchars((string)($r['placenta_membranes'] ?? '')) ?></td>
          <td><?= htmlspecialchars((string)($r['delivered_by_qualification'] ?? '')) ?></td>
          <td><?= htmlspecialchars((string)($r['assisted_by'] ?? '')) ?></td>
          <td><?= $out === 'LIVE' ? '1' : '' ?></td><td><?= $out === 'FSB' ? '1' : '' ?></td><td><?= $out === 'MSB' ? '1' : '' ?></td>
          <td class="nowrap"><?= htmlspecialchars((string)($r['baby_sex'] ?? '')) ?></td>
          <td class="nowrap"><?= htmlspecialchars((string)($r['birth_weight_grams'] ?? '')) ?></td>
          <td class="nowrap"><?= htmlspecialchars((string)($r['apgar_score'] ?? '')) ?></td>
          <td class="nowrap"><?= htmlspecialchars((string)($r['tetanus_protected'] ?? '')) ?></td>
          <td class="nowrap"><?= htmlspecialchars((string)($r['hiv_status_on_admission'] ?? '')) ?></td>
          <td class="nowrap"><?= htmlspecialchars((string)($r['tc_tested'] ?? '')) ?></td>
          <td class="nowrap"><?= htmlspecialchars((string)($r['tc_result'] ?? '')) ?></td>
          <td><?= htmlspecialchars($pmtct) ?></td>
          <td class="nowrap"><?= htmlspecialchars((string)($r['maternal_vitamin_a'] ?? '')) ?></td>
          <td class="nowrap"><?= htmlspecialchars((string)($r['infant_feeding_method'] ?? '')) ?></td>
          <td class="nowrap"><?= htmlspecialchars((string)($r['infant_breastfed_first_hour'] ?? '')) ?></td>
          <td class="nowrap"><?= htmlspecialchars((string)($r['infant_teo_given'] ?? '')) ?></td>
          <td><?= htmlspecialchars((string)($r['comments'] ?? '')) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</body>
</html>
