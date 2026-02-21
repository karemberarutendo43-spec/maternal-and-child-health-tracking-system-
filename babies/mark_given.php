<?php
require_once __DIR__ . "/../config/session.php";
mch_session_start();
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/scope.php";
require_once __DIR__ . "/../config/audit.php";

auth_guard(['admin', 'nurse']);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        header("Location: ../immunizations/defaulters.php?error=1");
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
        header("Location: ../immunizations/defaulters.php?error=1");
        exit;
    }
    if ($scopedClinicId > 0 && (int)($meta['baby_clinic_id'] ?? 0) !== $scopedClinicId) {
        header("Location: ../immunizations/defaulters.php?forbidden=1");
        exit;
    }

    $dueDateRaw = (string)($meta['due_date'] ?? '');
    try {
        $today = new DateTimeImmutable('today');
        $dueDate = $dueDateRaw !== '' ? new DateTimeImmutable($dueDateRaw) : null;
        if ($dueDate instanceof DateTimeImmutable && $today < $dueDate) {
            header("Location: ../immunizations/defaulters.php?early=1");
            exit;
        }
    } catch (Throwable $e) {
        // ignore parse issues
    }

    $stmt = $pdo->prepare("UPDATE immunizations SET status='GIVEN', given_date=CURDATE() WHERE id=? LIMIT 1");
    $stmt->execute([$id]);

    mch_audit_log(
        $pdo,
        'IMMUN_GIVEN',
        'immunizations',
        (int)$id,
        "Immunization marked GIVEN: baby_id={$meta['baby_id']}; vaccine={$meta['vaccine']}; due_date={$dueDateRaw}"
    );

    header("Location: ../immunizations/defaulters.php?success=1");
    exit;
}
?>
