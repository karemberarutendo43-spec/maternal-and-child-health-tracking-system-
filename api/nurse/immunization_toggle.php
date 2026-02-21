<?php
require_once __DIR__ . "/../_bootstrap.php";

api_require_role('nurse');
api_csrf_require_valid('api_nurse_immun_toggle');

$scopedClinicId = mch_scope_scoped_clinic_id();
if ($scopedClinicId <= 0) {
    api_error('Your account is not assigned to a clinic.', 403);
}

$id = (int)($_POST['id'] ?? 0);
$action = (string)($_POST['action'] ?? '');
if ($id <= 0 || !in_array($action, ['complete', 'undo'], true)) {
    api_error('Invalid request', 400);
}

$metaStmt = $pdo->prepare("
    SELECT i.id, i.baby_id, i.vaccine, i.due_date, i.status, i.given_date,
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
    api_error('Immunization not found', 404);
}
if ((int)($meta['baby_clinic_id'] ?? 0) !== $scopedClinicId) {
    api_error('Forbidden', 403);
}

if ($action === 'complete') {
    $dueDateRaw = (string)($meta['due_date'] ?? '');
    $today = new DateTimeImmutable('today');
    $dueDate = null;
    try {
        if ($dueDateRaw !== '') $dueDate = new DateTimeImmutable($dueDateRaw);
    } catch (Throwable $e) {
        $dueDate = null;
    }

    $isEarly = ($dueDate instanceof DateTimeImmutable) && ($today < $dueDate);
    if ($isEarly) {
        $override = !empty($_POST['override']);
        $overrideReason = trim((string)($_POST['override_reason'] ?? ''));
        if (!$override) {
            api_json([
                'ok' => false,
                'code' => 'EARLY',
                'error' => "This immunization is not due yet. Due date: {$dueDateRaw}.",
                'due_date' => $dueDateRaw,
            ], 409);
        }
        if (mb_strlen($overrideReason) < 10) {
            api_error('Override reason must be at least 10 characters.', 400);
        }

        mch_audit_log(
            $pdo,
            'IMMUN_OVERRIDE_EARLY',
            'immunizations',
            (int)$id,
            "Early immunization override: baby_id={$meta['baby_id']}; vaccine={$meta['vaccine']}; due_date={$dueDateRaw}; reason={$overrideReason}"
        );
    }

    $stmt = $pdo->prepare("UPDATE immunizations SET status = 'GIVEN', given_date = CURDATE() WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);

    mch_audit_log(
        $pdo,
        'IMMUN_GIVEN',
        'immunizations',
        (int)$id,
        "Immunization marked GIVEN: baby_id={$meta['baby_id']}; vaccine={$meta['vaccine']}; due_date={$dueDateRaw}"
    );
} else {
    $stmt = $pdo->prepare("UPDATE immunizations SET status = 'PENDING', given_date = NULL WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);

    mch_audit_log(
        $pdo,
        'IMMUN_UNDO',
        'immunizations',
        (int)$id,
        "Immunization marked PENDING (undo): baby_id={$meta['baby_id']}; vaccine={$meta['vaccine']}"
    );
}

$fetch = $pdo->prepare("SELECT status, given_date FROM immunizations WHERE id = ? LIMIT 1");
$fetch->execute([$id]);
$row = $fetch->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    api_error('Immunization not found', 404);
}

api_ok([
    'status' => (string)($row['status'] ?? ''),
    'given_date' => $row['given_date'] ?? null,
]);

