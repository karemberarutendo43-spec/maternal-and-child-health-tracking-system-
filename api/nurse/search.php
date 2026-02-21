<?php
require_once __DIR__ . "/../_bootstrap.php";

api_require_role('nurse');

$scopedClinicId = mch_scope_scoped_clinic_id();
if ($scopedClinicId <= 0) {
    api_error('Your account is not assigned to a clinic.', 403);
}

$q = trim((string)($_GET['q'] ?? ''));
$type = strtolower(trim((string)($_GET['type'] ?? 'all')));
if (!in_array($type, ['all', 'mothers', 'babies'], true)) {
    $type = 'all';
}

if ($q === '' || (function_exists('mb_strlen') ? mb_strlen($q) : strlen($q)) < 2) {
    api_ok(['query' => $q, 'mothers' => [], 'babies' => []]);
}

$like = '%' . $q . '%';
$mothers = [];
$babies = [];

if ($type === 'all' || $type === 'mothers') {
    try {
        $stmt = $pdo->prepare("
            SELECT m.id, m.fullname, m.hospital_number, m.phone, m.pregnancy_status
            FROM mothers m
            JOIN users u ON u.id = m.user_id
            WHERE u.clinic_id = ?
              AND (
                m.fullname LIKE ?
                OR m.hospital_number LIKE ?
                OR m.phone LIKE ?
              )
            ORDER BY m.fullname ASC
            LIMIT 30
        ");
        $stmt->execute([$scopedClinicId, $like, $like, $like]);
        $mothers = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $mothers = [];
    }
}

if ($type === 'all' || $type === 'babies') {
    try {
        $stmt = $pdo->prepare("
            SELECT
              b.id,
              b.baby_name,
              b.dob,
              b.gender,
              m.fullname AS mother_name,
              m.hospital_number AS mother_hospital_number
            FROM babies b
            JOIN mothers m ON b.mother_id = m.id
            JOIN users u ON m.user_id = u.id
            WHERE u.clinic_id = ?
              AND (
                b.baby_name LIKE ?
                OR m.fullname LIKE ?
                OR m.hospital_number LIKE ?
              )
            ORDER BY b.baby_name ASC
            LIMIT 30
        ");
        $stmt->execute([$scopedClinicId, $like, $like, $like]);
        $babies = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $babies = [];
    }
}

api_ok([
    'query' => $q,
    'mothers' => $mothers,
    'babies' => $babies,
]);

