<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/scope.php";
require_once __DIR__ . "/../config/csrf.php";
require_once __DIR__ . "/../config/audit.php";

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'nurse'], true)) {
    header("Location: /mch_system/auth/login.php");
    exit;
}
$uid = (int)($_SESSION['user_id'] ?? 0);
$role = (string)($_SESSION['role'] ?? '');
$scopedClinicId = mch_scope_scoped_clinic_id();
if ($role === 'nurse' && $scopedClinicId <= 0) {
    http_response_code(403);
    $error = "Your account is not assigned to a clinic. Please contact Admin.";
}

$tasksEnabled = false;
try {
    $tasksEnabled = (bool)$pdo->query("SHOW TABLES LIKE 'follow_up_tasks'")->fetchColumn();
} catch (Throwable $e) {
    $tasksEnabled = false;
}

$error = $error ?? '';
$success = '';
if (!empty($_SESSION['flash_tasks_success'])) {
    $success = (string)$_SESSION['flash_tasks_success'];
    unset($_SESSION['flash_tasks_success']);
}
if (!empty($_SESSION['flash_tasks_error'])) {
    $error = (string)$_SESSION['flash_tasks_error'];
    unset($_SESSION['flash_tasks_error']);
}

$status = strtoupper(trim((string)($_GET['status'] ?? 'OPEN')));
if (!in_array($status, ['OPEN', 'DONE', 'CANCELLED'], true)) {
    $status = 'OPEN';
}

$isPrint = ((int)($_GET['print'] ?? 0)) === 1;
$printUrl = '/mch_system/nurse_portal/tasks.php?' . http_build_query(array_merge($_GET, ['status' => $status, 'print' => 1]));

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    mch_csrf_require_valid('nurse_tasks');
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'complete') {
        $taskId = (int)($_POST['task_id'] ?? 0);
        $completionNote = trim((string)($_POST['completion_note'] ?? ''));
        if ($completionNote !== '' && mb_strlen($completionNote) > 255) {
            $completionNote = mb_substr($completionNote, 0, 255);
        }

        if (!$tasksEnabled) {
            $_SESSION['flash_tasks_error'] = "Tasks are not enabled yet. Run sql/follow_up_tasks.sql on the database.";
            header("Location: /mch_system/nurse_portal/tasks.php");
            exit;
        }
        if ($taskId <= 0) {
            $_SESSION['flash_tasks_error'] = "Invalid task.";
            header("Location: /mch_system/nurse_portal/tasks.php");
            exit;
        }

        try {
            $tStmt = $pdo->prepare("
                SELECT id, clinic_id, assigned_to, status, task_type, subject_type, subject_id
                FROM follow_up_tasks
                WHERE id = ?
                LIMIT 1
            ");
            $tStmt->execute([$taskId]);
            $t = $tStmt->fetch(PDO::FETCH_ASSOC);
            if (!$t) {
                throw new Exception("Task not found.");
            }

            $taskClinicId = (int)($t['clinic_id'] ?? 0);
            $assignedTo = (int)($t['assigned_to'] ?? 0);
            $taskStatus = (string)($t['status'] ?? '');
            if ($taskStatus !== 'OPEN') {
                throw new Exception("Only OPEN tasks can be completed.");
            }

            if ($scopedClinicId > 0 && $taskClinicId !== $scopedClinicId) {
                throw new Exception("Forbidden.");
            }
            if ($role === 'nurse' && $assignedTo !== $uid) {
                throw new Exception("This task is not assigned to you.");
            }

            $upd = $pdo->prepare("
                UPDATE follow_up_tasks
                SET status = 'DONE', completed_at = NOW()
                WHERE id = ? AND status = 'OPEN'
            ");
            $upd->execute([$taskId]);
            if ($upd->rowCount() < 1) {
                throw new Exception("Task update failed (it may have been completed already).");
            }

            $desc = "Completed task #{$taskId}";
            if ($completionNote !== '') {
                $desc .= " note={$completionNote}";
            }
            mch_audit_log($pdo, 'TASK_DONE', 'follow_up_tasks', $taskId, $desc);
            $_SESSION['flash_tasks_success'] = "Task marked as done.";
        } catch (Throwable $e) {
            $_SESSION['flash_tasks_error'] = $e->getMessage() ?: 'Failed to update task.';
        }

        header("Location: /mch_system/nurse_portal/tasks.php?status=" . urlencode($status));
        exit;
    }

    if ($action === 'undo_complete') {
        $taskId = (int)($_POST['task_id'] ?? 0);

        if (!$tasksEnabled) {
            $_SESSION['flash_tasks_error'] = "Tasks are not enabled yet. Run sql/follow_up_tasks.sql on the database.";
            header("Location: /mch_system/nurse_portal/tasks.php");
            exit;
        }
        if ($taskId <= 0) {
            $_SESSION['flash_tasks_error'] = "Invalid task.";
            header("Location: /mch_system/nurse_portal/tasks.php");
            exit;
        }

        try {
            $tStmt = $pdo->prepare("
                SELECT id, clinic_id, assigned_to, status
                FROM follow_up_tasks
                WHERE id = ?
                LIMIT 1
            ");
            $tStmt->execute([$taskId]);
            $t = $tStmt->fetch(PDO::FETCH_ASSOC);
            if (!$t) {
                throw new Exception("Task not found.");
            }

            $taskClinicId = (int)($t['clinic_id'] ?? 0);
            $assignedTo = (int)($t['assigned_to'] ?? 0);
            $taskStatus = (string)($t['status'] ?? '');
            if ($taskStatus !== 'DONE') {
                throw new Exception("Only DONE tasks can be reopened.");
            }

            if ($scopedClinicId > 0 && $taskClinicId !== $scopedClinicId) {
                throw new Exception("Forbidden.");
            }
            if ($role === 'nurse' && $assignedTo !== $uid) {
                throw new Exception("This task is not assigned to you.");
            }

            $upd = $pdo->prepare("
                UPDATE follow_up_tasks
                SET status = 'OPEN', completed_at = NULL
                WHERE id = ? AND status = 'DONE'
            ");
            $upd->execute([$taskId]);
            if ($upd->rowCount() < 1) {
                throw new Exception("Task update failed (it may have been reopened already).");
            }

            mch_audit_log($pdo, 'TASK_REOPEN', 'follow_up_tasks', $taskId, "Reopened task #{$taskId}");
            $_SESSION['flash_tasks_success'] = "Task reopened.";
        } catch (Throwable $e) {
            $_SESSION['flash_tasks_error'] = $e->getMessage() ?: 'Failed to reopen task.';
        }

        header("Location: /mch_system/nurse_portal/tasks.php?status=OPEN");
        exit;
    }
}

$tasks = [];
if ($tasksEnabled && $error === '') {
    $where = ["t.status = ?"];
    $params = [$status];

    if ($scopedClinicId > 0) {
        $where[] = "t.clinic_id = ?";
        $params[] = $scopedClinicId;
    }
    if ($role === 'nurse') {
        $where[] = "t.assigned_to = ?";
        $params[] = $uid;
    }

    $whereSql = "WHERE " . implode(" AND ", $where);
    $sql = "
        SELECT
            t.*,
            COALESCE(c.clinic_name, '') AS clinic_name,
            COALESCE(n.full_name, '') AS nurse_name,
            COALESCE(m.fullname, b.baby_name, '') AS subject_name,
            m.phone AS mother_phone,
            b.id AS baby_id
        FROM follow_up_tasks t
        LEFT JOIN clinics c ON c.id = t.clinic_id
        LEFT JOIN users n ON n.id = t.assigned_to
        LEFT JOIN mothers m ON (t.subject_type = 'mother' AND t.subject_id = m.id)
        LEFT JOIN babies b ON (t.subject_type = 'baby' AND t.subject_id = b.id)
        {$whereSql}
        ORDER BY (t.due_date IS NULL) ASC, t.due_date ASC, t.priority DESC, t.created_at DESC, t.id DESC
        LIMIT 200
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$page = 'tasks';
include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_nurse.php";
?>
 
<?php if ($isPrint): ?>
  <style>
    /* Print view is opened in the same portal shell; hide the fixed sidebar so the table doesn't sit underneath it. */
    aside[id^="mchSidebar"] { display: none !important; }
    .ml-64 { margin-left: 0 !important; }
    @media print {
      .mch-no-print { display: none !important; }
      body { background: #fff !important; }
      body.mch-portal-bg::before,
      body.mch-portal-bg::after { display: none !important; }
      a { color: #000 !important; text-decoration: none !important; }
      .mch-actions-col { display: none !important; }
    }
  </style>
<?php endif; ?>

<div class="<?= $isPrint ? 'p-6 max-w-6xl' : 'ml-64 p-6 max-w-6xl' ?>">
  <div class="mch-card bg-white/90 p-6 border border-slate-100">
    <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between mb-4">
      <div>
        <h2 class="text-2xl font-semibold text-slate-900">Follow-up Tasks</h2>
        <p class="text-sm text-slate-600">Internal task assignment (no SMS cost). Nurses complete tasks after follow-up.</p>
      </div>
      <div class="mch-no-print flex items-center gap-2 text-sm">
        <a class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 <?= $status === 'OPEN' ? 'font-semibold' : '' ?>"
           href="/mch_system/nurse_portal/tasks.php?status=OPEN">Open</a>
        <a class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 <?= $status === 'DONE' ? 'font-semibold' : '' ?>"
           href="/mch_system/nurse_portal/tasks.php?status=DONE">Done</a>
        <a class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50"
           href="<?= htmlspecialchars($printUrl) ?>">Print / Save PDF</a>
      </div>
    </div>

    <?php if (!$tasksEnabled): ?>
      <div class="bg-amber-50 border border-amber-200 text-amber-800 p-3 rounded-lg mb-4">
        Tasks are not enabled yet. Run <span class="font-mono">sql/follow_up_tasks.sql</span> on the database.
      </div>
    <?php endif; ?>

    <?php if ($error): ?>
      <div class="bg-rose-100 text-rose-700 p-3 rounded-lg mb-4"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
      <div class="bg-emerald-100 text-emerald-700 p-3 rounded-lg mb-4"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <?php if ($tasksEnabled && empty($tasks) && $error === ''): ?>
      <div class="text-slate-500">No tasks found.</div>
    <?php elseif ($tasksEnabled && $error === ''): ?>
      <div class="overflow-x-auto">
        <table class="w-full text-sm mch-table">
          <thead>
            <tr>
              <?php if ($scopedClinicId === 0): ?><th class="p-2 text-left">Clinic</th><?php endif; ?>
              <th class="p-2 text-left">Type</th>
              <th class="p-2 text-left">Subject</th>
              <th class="p-2 text-left">Due</th>
              <th class="p-2 text-left">Priority</th>
              <?php if ($role !== 'nurse'): ?><th class="p-2 text-left">Assigned</th><?php endif; ?>
              <th class="p-2 text-left">Status</th>
              <th class="p-2 text-left mch-actions-col">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($tasks as $t): ?>
            <?php
              $type = (string)($t['task_type'] ?? '');
              $subjectType = (string)($t['subject_type'] ?? '');
              $subjectId = (int)($t['subject_id'] ?? 0);
              $subjectName = (string)($t['subject_name'] ?? '');
              $due = (string)($t['due_date'] ?? '');
              $prio = (string)($t['priority'] ?? 'MEDIUM');
              $st = (string)($t['status'] ?? '');
              $clinicName = (string)($t['clinic_name'] ?? '');
              $nurseName = (string)($t['nurse_name'] ?? '');
              $openLink = '#';
              if ($subjectType === 'mother') {
                $openLink = "/mch_system/nurse_portal/mother_history.php?mother_id={$subjectId}";
              } elseif ($subjectType === 'baby') {
                $openLink = "/mch_system/nurse_portal/baby_history.php?baby_id={$subjectId}";
              }
            ?>
            <tr class="border-t border-slate-200">
              <?php if ($scopedClinicId === 0): ?><td class="p-2 text-slate-600"><?= htmlspecialchars($clinicName ?: '-') ?></td><?php endif; ?>
              <td class="p-2 font-semibold"><?= htmlspecialchars($type) ?></td>
              <td class="p-2">
                <a class="text-teal-700 font-semibold hover:underline" href="<?= htmlspecialchars($openLink) ?>">
                  <?= htmlspecialchars($subjectName !== '' ? $subjectName : ($subjectType . ' #' . $subjectId)) ?>
                </a>
              </td>
              <td class="p-2 text-slate-600"><?= htmlspecialchars($due ?: '-') ?></td>
              <td class="p-2">
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold
                  <?= $prio === 'HIGH' ? 'bg-rose-100 text-rose-700' : ($prio === 'LOW' ? 'bg-slate-100 text-slate-700' : 'bg-amber-100 text-amber-700') ?>">
                  <?= htmlspecialchars($prio) ?>
                </span>
              </td>
              <?php if ($role !== 'nurse'): ?><td class="p-2 text-slate-600"><?= htmlspecialchars($nurseName ?: '-') ?></td><?php endif; ?>
              <td class="p-2">
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold
                  <?= $st === 'OPEN' ? 'bg-amber-100 text-amber-700' : ($st === 'DONE' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-700') ?>">
                  <?= htmlspecialchars($st) ?>
                </span>
              </td>
              <td class="p-2 mch-actions-col">
                <?php if ($st === 'OPEN'): ?>
                  <form method="POST" class="inline-flex items-center gap-2">
                    <?= mch_csrf_input('nurse_tasks') ?>
                    <input type="hidden" name="action" value="complete">
                    <input type="hidden" name="task_id" value="<?= (int)$t['id'] ?>">
                    <input type="text" name="completion_note" class="border border-slate-200 p-1.5 rounded-lg bg-white text-xs" placeholder="Optional note" maxlength="255">
                    <button class="px-3 py-1.5 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs font-semibold hover:bg-emerald-100">
                      Mark done
                    </button>
                  </form>
                <?php elseif ($st === 'DONE'): ?>
                  <form method="POST" class="inline-flex items-center gap-2"
                        onsubmit="return confirm('Undo completion and reopen this task?');">
                    <?= mch_csrf_input('nurse_tasks') ?>
                    <input type="hidden" name="action" value="undo_complete">
                    <input type="hidden" name="task_id" value="<?= (int)$t['id'] ?>">
                    <button class="px-3 py-1.5 rounded-lg bg-amber-50 border border-amber-200 text-amber-700 text-xs font-semibold hover:bg-amber-100">
                      Undo
                    </button>
                  </form>
                <?php else: ?>
                  <span class="text-slate-500 text-xs">—</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($isPrint): ?>
  <script>
    window.addEventListener('load', () => {
      try { window.print(); } catch (e) {}
    });
  </script>
<?php endif; ?>

<?php include __DIR__ . "/../includes/footer.php"; ?>
