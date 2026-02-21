<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/scope.php";
require_once __DIR__ . "/../config/audit.php";

mch_session_start();
auth_guard(['admin', 'nurse']);

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo "Invalid request";
    exit;
}

$scopedClinicId = mch_scope_scoped_clinic_id();
$metaStmt = $pdo->prepare("
    SELECT i.id, i.baby_id, i.vaccine, i.due_date,
           u.clinic_id AS baby_clinic_id
    FROM immunizations i
    JOIN babies b ON i.baby_id = b.id
    JOIN mothers m ON b.mother_id = m.id
    JOIN users u ON m.user_id = u.id
    WHERE i.id = ?
    LIMIT 1
");
$metaStmt->execute([$id]);
$meta = $metaStmt->fetch(PDO::FETCH_ASSOC);
if (!$meta) {
    http_response_code(404);
    echo "Immunization not found";
    exit;
}
if ($scopedClinicId > 0 && (int)($meta['baby_clinic_id'] ?? 0) !== $scopedClinicId) {
    http_response_code(403);
    echo "Forbidden";
    exit;
}

$dueDateRaw = (string)($meta['due_date'] ?? '');
try {
    $today = new DateTimeImmutable('today');
    $dueDate = $dueDateRaw !== '' ? new DateTimeImmutable($dueDateRaw) : null;
    if ($dueDate instanceof DateTimeImmutable && $today < $dueDate) {
        http_response_code(409);
        echo "Too early. Due date: {$dueDateRaw}";
        exit;
    }
} catch (Throwable $e) {
    // ignore parse issues
}

$stmt = $pdo->prepare("
    UPDATE immunizations 
    SET status='GIVEN', given_date=CURDATE() 
    WHERE id=?
");

if ($stmt->execute([(int)$id])) {
    mch_audit_log(
        $pdo,
        'IMMUN_GIVEN',
        'immunizations',
        (int)$id,
        "Immunization marked GIVEN: baby_id={$meta['baby_id']}; vaccine={$meta['vaccine']}; due_date={$dueDateRaw}"
    );
    echo "Immunization marked as GIVEN";
} else {
    echo "Error updating record";
}
