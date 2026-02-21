<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/scope.php";
require_once __DIR__ . "/../config/audit.php";

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . "/../config/session.php";
mch_session_start();

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['admin', 'nurse'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON body']);
    exit;
}

$id = (int)($data['id'] ?? 0);
$action = (string)($data['action'] ?? '');
if ($id <= 0 || !in_array($action, ['complete', 'undo'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

try {
    $scopedClinicId = mch_scope_scoped_clinic_id();
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
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Immunization not found']);
        exit;
    }
    if ($scopedClinicId > 0 && (int)($meta['baby_clinic_id'] ?? 0) !== $scopedClinicId) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Forbidden']);
        exit;
    }

    if ($action === 'complete') {
        $dueDateRaw = (string)($meta['due_date'] ?? '');
        $today = new DateTimeImmutable('today');
        $dueDate = null;
        try {
            if ($dueDateRaw !== '') {
                $dueDate = new DateTimeImmutable($dueDateRaw);
            }
        } catch (Throwable $e) {
            $dueDate = null;
        }

        $isEarly = ($dueDate instanceof DateTimeImmutable) && ($today < $dueDate);
        if ($isEarly) {
            $override = !empty($data['override']);
            $overrideReason = trim((string)($data['override_reason'] ?? ''));
            if (!$override) {
                http_response_code(409);
                echo json_encode([
                    'success' => false,
                    'code' => 'EARLY',
                    'error' => "This immunization is not due yet. Due date: {$dueDateRaw}.",
                    'due_date' => $dueDateRaw,
                ]);
                exit;
            }
            if (mb_strlen($overrideReason) < 10) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Override reason must be at least 10 characters.',
                ]);
                exit;
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
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Immunization not found']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'status' => (string)($row['status'] ?? ''),
        'given_date' => $row['given_date'] ?? null,
    ]);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
    exit;
}
