<?php
require_once __DIR__ . "/../_bootstrap.php";

api_require_role('nurse');

$scopedClinicId = mch_scope_scoped_clinic_id();
if ($scopedClinicId <= 0) {
    api_error('Your account is not assigned to a clinic.', 403);
}

$motherId = (int)($_GET['mother_id'] ?? 0);
if ($motherId <= 0) {
    api_error('Missing mother_id', 400);
}

$supportsDeliveryDate = db_has_column($pdo, 'mothers', 'delivery_date');
$deliverySelect = $supportsDeliveryDate ? "m.delivery_date" : "NULL AS delivery_date";

$mStmt = $pdo->prepare("
    SELECT
      m.id,
      m.fullname,
      m.hospital_number,
      m.dob,
      m.phone,
      m.address,
      m.pregnancy_status,
      {$deliverySelect},
      u.clinic_id
    FROM mothers m
    JOIN users u ON u.id = m.user_id
    WHERE m.id = ?
    LIMIT 1
");
$mStmt->execute([$motherId]);
$mother = $mStmt->fetch(PDO::FETCH_ASSOC);
if (!$mother) {
    api_error('Mother not found', 404);
}
if ((int)($mother['clinic_id'] ?? 0) !== $scopedClinicId) {
    api_error('Forbidden', 403);
}

$babies = [];
try {
    $bStmt = $pdo->prepare("
        SELECT id AS baby_id, baby_name, dob, gender, birth_weight
        FROM babies
        WHERE mother_id = ?
        ORDER BY dob DESC, id DESC
    ");
    $bStmt->execute([$motherId]);
    $babies = $bStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $babies = [];
}

$ancVisits = [];
try {
    $hasAnc = (bool)$pdo->query("SHOW TABLES LIKE 'anc_visits'")->fetchColumn();
    if ($hasAnc) {
        $aStmt = $pdo->prepare("
            SELECT id AS anc_id, visit_number, visit_date, next_visit_date, status
            FROM anc_visits
            WHERE mother_id = ?
            ORDER BY COALESCE(visit_number, 999) ASC, COALESCE(visit_date, created_at) ASC, id ASC
            LIMIT 30
        ");
        $aStmt->execute([$motherId]);
        $ancVisits = $aStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    $ancVisits = [];
}

$pncVisits = [];
try {
    $hasPnc = (bool)$pdo->query("SHOW TABLES LIKE 'pnc_visits'")->fetchColumn();
    if ($hasPnc) {
        $pStmt = $pdo->prepare("
            SELECT p.id AS pnc_id, p.visit_date, p.status, b.id AS baby_id, b.baby_name
            FROM pnc_visits p
            LEFT JOIN babies b ON p.baby_id = b.id
            WHERE p.mother_id = ?
            ORDER BY p.visit_date DESC, p.id DESC
            LIMIT 30
        ");
        $pStmt->execute([$motherId]);
        $pncVisits = $pStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    $pncVisits = [];
}

api_ok([
    'mother' => [
        'id' => (int)($mother['id'] ?? 0),
        'fullname' => (string)($mother['fullname'] ?? ''),
        'hospital_number' => $mother['hospital_number'] ?? null,
        'dob' => $mother['dob'] ?? null,
        'phone' => $mother['phone'] ?? null,
        'address' => $mother['address'] ?? null,
        'pregnancy_status' => $mother['pregnancy_status'] ?? null,
        'delivery_date' => $mother['delivery_date'] ?? null,
    ],
    'babies' => $babies,
    'anc_visits' => $ancVisits,
    'pnc_visits' => $pncVisits,
]);

