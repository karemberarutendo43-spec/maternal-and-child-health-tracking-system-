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

function ymd_add_days_api(string $ymd, int $days): string
{
    $op = $days >= 0 ? "+" : "-";
    $ts = strtotime($ymd . " " . $op . " " . abs($days) . " days");
    return $ts ? date('Y-m-d', $ts) : '';
}

function find_visit_in_window_api(array $visitDates, string $dueYmd, int $windowDays): ?string
{
    $start = ymd_add_days_api($dueYmd, -$windowDays);
    $end = ymd_add_days_api($dueYmd, $windowDays);
    foreach ($visitDates as $d) {
        if ($d >= $start && $d <= $end) return $d;
    }
    return null;
}

// Waiting mothers (Pregnant)
$waitingMothers = [];
try {
    $eddSelect = $supportsEdd ? "m.edd" : "NULL AS edd";
    $eddOrder = $supportsEdd ? "ORDER BY (m.edd IS NULL) ASC, m.edd ASC, m.fullname ASC" : "ORDER BY m.fullname ASC";
    $stmt = $pdo->prepare("
        SELECT m.id, m.fullname, m.hospital_number, m.phone, {$eddSelect}
        FROM mothers m
        JOIN users u ON u.id = m.user_id
        WHERE u.clinic_id = ?
          AND m.pregnancy_status = 'Pregnant'
        {$eddOrder}
        LIMIT 120
    ");
    $stmt->execute([$scopedClinicId]);
    $waitingMothers = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $waitingMothers = [];
}

// PNC due buckets
$pncBuckets = [
    '0_3_days' => [],
    '7_days' => [],
    '6_weeks' => [],
];
$pncMissingDate = 0;

try {
    $deliverySelect = $supportsDeliveryDate ? "m.delivery_date" : "NULL AS delivery_date";
    $rowsStmt = $pdo->prepare("
        SELECT
          m.id AS mother_id,
          m.fullname,
          m.hospital_number,
          m.phone,
          {$deliverySelect},
          b.id AS baby_id,
          b.baby_name,
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
        ORDER BY m.fullname ASC
        LIMIT 300
    ");
    $rowsStmt->execute([$scopedClinicId]);
    $pncRows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $motherIds = array_values(array_filter(array_map(static fn($r) => (int)($r['mother_id'] ?? 0), $pncRows)));
    $pncByMother = [];
    if (!empty($motherIds)) {
        $ph = implode(',', array_fill(0, count($motherIds), '?'));
        $vstmt = $pdo->prepare("
            SELECT mother_id, visit_date
            FROM pnc_visits
            WHERE mother_id IN ($ph)
            ORDER BY visit_date ASC
        ");
        $vstmt->execute($motherIds);
        while ($v = $vstmt->fetch(PDO::FETCH_ASSOC)) {
            $mid = (int)($v['mother_id'] ?? 0);
            if ($mid <= 0) continue;
            $pncByMother[$mid] ??= [];
            $pncByMother[$mid][] = (string)$v['visit_date'];
        }
    }

    foreach ($pncRows as $r) {
        $mid = (int)($r['mother_id'] ?? 0);
        $baseDate = trim((string)($r['delivery_date'] ?? ''));
        if ($baseDate === '') $baseDate = trim((string)($r['dob'] ?? ''));
        if ($baseDate === '') {
            $pncMissingDate++;
            continue;
        }

        $visitDates = $pncByMother[$mid] ?? [];
        $daysSince = (int)floor(((strtotime($today) ?: 0) - (strtotime($baseDate) ?: 0)) / 86400);

        $rowOut = [
            'mother_id' => $mid,
            'fullname' => (string)($r['fullname'] ?? ''),
            'hospital_number' => (string)($r['hospital_number'] ?? ''),
            'phone' => (string)($r['phone'] ?? ''),
            'baby_id' => (int)($r['baby_id'] ?? 0),
            'baby_name' => (string)($r['baby_name'] ?? ''),
            'base_date' => $baseDate,
        ];

        if ($daysSince >= 0 && $daysSince <= 3) {
            $pncBuckets['0_3_days'][] = $rowOut + ['due' => $today];
        }

        $due7 = ymd_add_days_api($baseDate, 7);
        if ($due7 !== '') {
            $hit7 = find_visit_in_window_api($visitDates, $due7, 1);
            if (!$hit7 && $today >= ymd_add_days_api($due7, -1) && $today <= ymd_add_days_api($due7, 1)) {
                $pncBuckets['7_days'][] = $rowOut + ['due' => $due7];
            }
        }

        $due6w = ymd_add_days_api($baseDate, 42);
        if ($due6w !== '') {
            $hit6w = find_visit_in_window_api($visitDates, $due6w, 5);
            if (!$hit6w && $today >= ymd_add_days_api($due6w, -5) && $today <= ymd_add_days_api($due6w, 5)) {
                $pncBuckets['6_weeks'][] = $rowOut + ['due' => $due6w];
            }
        }
    }
} catch (Throwable $e) {
    $pncBuckets = ['0_3_days' => [], '7_days' => [], '6_weeks' => []];
}

// EPI due today list
$epiDueToday = [];
$epiOverdueCount = 0;
try {
    $c1 = $pdo->prepare("
        SELECT COUNT(*)
        FROM immunizations i
        JOIN babies b ON i.baby_id = b.id
        JOIN mothers m ON b.mother_id = m.id
        JOIN users u ON m.user_id = u.id
        WHERE u.clinic_id = ?
          AND i.status = 'PENDING'
          AND i.due_date < ?
    ");
    $c1->execute([$scopedClinicId, $today]);
    $epiOverdueCount = (int)$c1->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT
          i.id AS immunization_id,
          i.vaccine,
          i.due_date,
          b.id AS baby_id,
          b.baby_name,
          m.fullname AS mother_name
        FROM immunizations i
        JOIN babies b ON i.baby_id = b.id
        JOIN mothers m ON b.mother_id = m.id
        JOIN users u ON m.user_id = u.id
        WHERE u.clinic_id = ?
          AND i.status = 'PENDING'
          AND i.due_date = ?
        ORDER BY b.baby_name ASC, i.vaccine ASC
        LIMIT 200
    ");
    $stmt->execute([$scopedClinicId, $today]);
    $epiDueToday = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $epiDueToday = [];
}

api_ok([
    'today' => $today,
    'supports' => [
        'mothers_delivery_date' => $supportsDeliveryDate,
        'mothers_edd' => $supportsEdd,
    ],
    'waiting_mothers' => [
        'count' => count($waitingMothers),
        'rows' => $waitingMothers,
    ],
    'pnc_due' => [
        'missing_base_date' => $pncMissingDate,
        'buckets' => $pncBuckets,
    ],
    'epi' => [
        'due_today_count' => count($epiDueToday),
        'due_today_rows' => $epiDueToday,
        'overdue_count' => $epiOverdueCount,
    ],
]);

