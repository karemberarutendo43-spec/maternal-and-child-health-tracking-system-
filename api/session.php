<?php
require_once __DIR__ . "/_bootstrap.php";

$uid = (int)($_SESSION['user_id'] ?? 0);
$role = (string)($_SESSION['role'] ?? '');
$clinicId = (int)($_SESSION['clinic_id'] ?? 0);

api_ok([
    'authenticated' => $uid > 0,
    'user_id' => $uid > 0 ? $uid : null,
    'role' => $role !== '' ? $role : null,
    'clinic_id' => $uid > 0 ? $clinicId : null,
    'scoped_clinic_id' => $uid > 0 ? mch_scope_scoped_clinic_id() : null,
]);

