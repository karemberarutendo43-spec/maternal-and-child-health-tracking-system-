<?php
require_once __DIR__ . "/../_bootstrap.php";

api_require_role('nurse');

$uid = (int)($_SESSION['user_id'] ?? 0);
$scopedClinicId = mch_scope_scoped_clinic_id();
if ($scopedClinicId <= 0) {
    api_error('Your account is not assigned to a clinic.', 403);
}

$tasksEnabled = false;
try {
    $tasksEnabled = (bool)$pdo->query("SHOW TABLES LIKE 'follow_up_tasks'")->fetchColumn();
} catch (Throwable $e) {
    $tasksEnabled = false;
}
if (!$tasksEnabled) {
    api_ok(['enabled' => false, 'tasks' => []]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $status = strtoupper(trim((string)($_GET['status'] ?? 'OPEN')));
    if (!in_array($status, ['OPEN', 'DONE', 'CANCELLED'], true)) {
        $status = 'OPEN';
    }

    $stmt = $pdo->prepare("
        SELECT
          t.id AS task_id,
          t.task_type,
          t.subject_type,
          t.subject_id,
          t.due_date,
          t.priority,
          t.status,
          t.note,
          t.created_at,
          t.completed_at,
          COALESCE(m.fullname, b.baby_name, '') AS subject_name
        FROM follow_up_tasks t
        LEFT JOIN mothers m ON (t.subject_type = 'mother' AND t.subject_id = m.id)
        LEFT JOIN babies b ON (t.subject_type = 'baby' AND t.subject_id = b.id)
        WHERE t.status = ?
          AND t.clinic_id = ?
          AND t.assigned_to = ?
        ORDER BY (t.due_date IS NULL) ASC, t.due_date ASC, t.priority DESC, t.created_at DESC, t.id DESC
        LIMIT 200
    ");
    $stmt->execute([$status, $scopedClinicId, $uid]);
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    api_ok([
        'enabled' => true,
        'status' => $status,
        'tasks' => $tasks,
    ]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    api_csrf_require_valid('api_nurse_tasks');

    $action = (string)($_POST['action'] ?? '');
    $taskId = (int)($_POST['task_id'] ?? 0);
    if ($taskId <= 0) {
        api_error('Invalid task', 400);
    }
    if (!in_array($action, ['complete', 'undo_complete'], true)) {
        api_error('Invalid action', 400);
    }

    $tStmt = $pdo->prepare("
        SELECT id, clinic_id, assigned_to, status
        FROM follow_up_tasks
        WHERE id = ?
        LIMIT 1
    ");
    $tStmt->execute([$taskId]);
    $t = $tStmt->fetch(PDO::FETCH_ASSOC);
    if (!$t) {
        api_error('Task not found', 404);
    }

    $taskClinicId = (int)($t['clinic_id'] ?? 0);
    $assignedTo = (int)($t['assigned_to'] ?? 0);
    $taskStatus = (string)($t['status'] ?? '');
    if ($taskClinicId !== $scopedClinicId || $assignedTo !== $uid) {
        api_error('Forbidden', 403);
    }

    if ($action === 'complete') {
        if ($taskStatus !== 'OPEN') {
            api_error('Only OPEN tasks can be completed.', 400);
        }
        $completionNote = trim((string)($_POST['completion_note'] ?? ''));
        if ($completionNote !== '' && mb_strlen($completionNote) > 255) {
            $completionNote = mb_substr($completionNote, 0, 255);
        }

        $upd = $pdo->prepare("
            UPDATE follow_up_tasks
            SET status = 'DONE', completed_at = NOW()
            WHERE id = ? AND status = 'OPEN'
        ");
        $upd->execute([$taskId]);
        if ($upd->rowCount() < 1) {
            api_error('Task update failed.', 409);
        }

        $desc = "Completed task #{$taskId}";
        if ($completionNote !== '') {
            $desc .= " note={$completionNote}";
        }
        mch_audit_log($pdo, 'TASK_DONE', 'follow_up_tasks', $taskId, $desc);

        api_ok(['task_id' => $taskId, 'status' => 'DONE']);
    }

    if ($action === 'undo_complete') {
        if ($taskStatus !== 'DONE') {
            api_error('Only DONE tasks can be reopened.', 400);
        }

        $upd = $pdo->prepare("
            UPDATE follow_up_tasks
            SET status = 'OPEN', completed_at = NULL
            WHERE id = ? AND status = 'DONE'
        ");
        $upd->execute([$taskId]);
        if ($upd->rowCount() < 1) {
            api_error('Task update failed.', 409);
        }

        mch_audit_log($pdo, 'TASK_REOPEN', 'follow_up_tasks', $taskId, "Reopened task #{$taskId}");

        api_ok(['task_id' => $taskId, 'status' => 'OPEN']);
    }
}

api_error('Method not allowed', 405);

