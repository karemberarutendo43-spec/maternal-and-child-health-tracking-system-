<?php
require_once __DIR__ . "/../_bootstrap.php";

api_require_role('nurse');

$scopedClinicId = mch_scope_scoped_clinic_id();
if ($scopedClinicId <= 0) {
    api_error('Your account is not assigned to a clinic.', 403);
}

$today = date('Y-m-d');

$supportsDeliveryDate = db_has_column($pdo, 'mothers', 'delivery_date');
$supportsEdd = db_has_column($pdo, 'mothers', 'edd');

// Waiting (pregnant) mothers count
$waitingCount = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM mothers m
        JOIN users u ON u.id = m.user_id
        WHERE u.clinic_id = ?
          AND m.pregnancy_status = 'Pregnant'
    ");
    $stmt->execute([$scopedClinicId]);
    $waitingCount = (int)$stmt->fetchColumn();
} catch (Throwable $e) {
    $waitingCount = 0;
}

// PNC due buckets using delivery_date (preferred) else baby DOB, matching the UI logic loosely.
$pncCounts = ['0_3_days' => 0, '7_days' => 0, '6_weeks' => 0];
try {
    $deliverySelect = $supportsDeliveryDate ? "m.delivery_date" : "NULL AS delivery_date";
    $rowsStmt = $pdo->prepare("
        SELECT
          m.id AS mother_id,
          {$deliverySelect},
          b.dob
        FROM mothers m
        JOIN users u ON u.id = m.user_id
        LEFT JOIN babies b ON b.id = (
          SELECT bb.id
          FROM babies bb
          WHERE bb.mother_id = m.id
          ORDER BY bb.dob DESC, bb.id DESC
          LIMIT 1
        )
        WHERE u.clinic_id = ?
          AND m.pregnancy_status = 'Postnatal'
        LIMIT 600
    ");
    $rowsStmt->execute([$scopedClinicId]);
    $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $base = trim((string)($r['delivery_date'] ?? ''));
        if ($base === '') {
            $base = trim((string)($r['dob'] ?? ''));
        }
        if ($base === '') continue;

        $daysSince = (int)floor(((strtotime($today) ?: 0) - (strtotime($base) ?: 0)) / 86400);
        if ($daysSince >= 0 && $daysSince <= 3) {
            $pncCounts['0_3_days']++;
        }

        $due7 = date('Y-m-d', (strtotime($base . ' +7 days') ?: 0));
        if ($due7 !== '1970-01-01' && $today >= date('Y-m-d', strtotime($due7 . ' -1 days')) && $today <= date('Y-m-d', strtotime($due7 . ' +1 days'))) {
            $pncCounts['7_days']++;
        }

        $due6w = date('Y-m-d', (strtotime($base . ' +42 days') ?: 0));
        if ($due6w !== '1970-01-01' && $today >= date('Y-m-d', strtotime($due6w . ' -5 days')) && $today <= date('Y-m-d', strtotime($due6w . ' +5 days'))) {
            $pncCounts['6_weeks']++;
        }
    }
} catch (Throwable $e) {
    $pncCounts = ['0_3_days' => 0, '7_days' => 0, '6_weeks' => 0];
}

// EPI due today count + overdue count
$epiDueToday = 0;
$epiOverdue = 0;
try {
    $stmt = $pdo->prepare("
        SELECT
          SUM(CASE WHEN i.due_date = ? THEN 1 ELSE 0 END) AS due_today,
          SUM(CASE WHEN i.due_date < ? THEN 1 ELSE 0 END) AS overdue
        FROM immunizations i
        JOIN babies b ON i.baby_id = b.id
        JOIN mothers m ON b.mother_id = m.id
        JOIN users u ON m.user_id = u.id
        WHERE u.clinic_id = ?
          AND i.status = 'PENDING'
    ");
    $stmt->execute([$today, $today, $scopedClinicId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $epiDueToday = (int)($row['due_today'] ?? 0);
    $epiOverdue = (int)($row['overdue'] ?? 0);
} catch (Throwable $e) {
    $epiDueToday = 0;
    $epiOverdue = 0;
}

api_ok([
    'today' => $today,
    'supports' => [
        'mothers_delivery_date' => $supportsDeliveryDate,
        'mothers_edd' => $supportsEdd,
    ],
    'waiting_mothers' => [
        'count' => $waitingCount,
    ],
    'pnc_due' => $pncCounts,
    'epi' => [
        'due_today' => $epiDueToday,
        'overdue' => $epiOverdue,
    ],
]);

