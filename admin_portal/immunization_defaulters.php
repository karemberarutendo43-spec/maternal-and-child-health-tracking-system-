<?php
require_once __DIR__ . "/../config/session.php";
mch_session_start();
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/sms.php";
require_once __DIR__ . "/../config/cards.php";
require_once __DIR__ . "/../config/audit.php";
require_once __DIR__ . "/../config/csrf.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: /mch_system/auth/login.php");
    exit;
}

$adminLevel = $_SESSION['admin_level'] ?? 'SUPER';
$adminClinicId = (int)($_SESSION['clinic_id'] ?? 0);
if ($adminLevel !== 'SUPER' && $adminClinicId <= 0) {
    die("Clinic admin is not assigned to a clinic.");
}

$tasksEnabled = false;
try {
    $tasksEnabled = (bool)$pdo->query("SHOW TABLES LIKE 'follow_up_tasks'")->fetchColumn();
} catch (Throwable $e) {
    $tasksEnabled = false;
}

$selectedClinicId = 0;
if ($adminLevel === 'SUPER') {
    $selectedClinicId = max(0, (int)($_GET['clinic_id'] ?? 0));
} else {
    $selectedClinicId = $adminClinicId;
}

// Optional: keep clinical_status (0/1/2) in sync for CDSS-style reporting.
if (db_has_column($pdo, 'immunizations', 'clinical_status')) {
    try {
        $pdo->query("UPDATE immunizations SET clinical_status = 2 WHERE status = 'PENDING' AND due_date < CURDATE()");
    } catch (Throwable $e) {
        // ignore
    }
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(10, min(100, (int)($_GET['per_page'] ?? 25)));
$offset = ($page - 1) * $perPage;

$notify_error = '';
$notify_success = '';
if (!empty($_SESSION['flash_immun_notify_error'])) {
    $notify_error = (string)$_SESSION['flash_immun_notify_error'];
    unset($_SESSION['flash_immun_notify_error']);
}
if (!empty($_SESSION['flash_immun_notify_success'])) {
    $notify_success = (string)$_SESSION['flash_immun_notify_success'];
    unset($_SESSION['flash_immun_notify_success']);
}

$task_error = '';
$task_success = '';
if (!empty($_SESSION['flash_immun_task_error'])) {
    $task_error = (string)$_SESSION['flash_immun_task_error'];
    unset($_SESSION['flash_immun_task_error']);
}
if (!empty($_SESSION['flash_immun_task_success'])) {
    $task_success = (string)$_SESSION['flash_immun_task_success'];
    unset($_SESSION['flash_immun_task_success']);
}

$clinics = [];
if ($adminLevel === 'SUPER') {
    $clinics = $pdo->query("SELECT id, clinic_name FROM clinics ORDER BY clinic_name")->fetchAll(PDO::FETCH_ASSOC);
}

$taskClinicId = ($adminLevel === 'SUPER') ? (int)$selectedClinicId : (int)$adminClinicId;
$taskNurses = [];
if ($tasksEnabled && $taskClinicId > 0) {
    try {
        $nstmt = $pdo->prepare("
            SELECT id, full_name
            FROM users
            WHERE role = 'nurse' AND clinic_id = ?
            ORDER BY full_name ASC, id ASC
        ");
        $nstmt->execute([$taskClinicId]);
        $taskNurses = $nstmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $taskNurses = [];
    }
}

$clinicJoin = " LEFT JOIN users u ON m.user_id = u.id LEFT JOIN clinics c ON u.clinic_id = c.id ";
$clinicFilter = '';
$clinicParams = [];
if ($selectedClinicId > 0) {
    $clinicFilter = " AND u.clinic_id = ? ";
    $clinicParams[] = $selectedClinicId;
}

$notifyNurses = function (int $clinicId, int $defaulterCount, array $topRows) use ($pdo): array {
    if ($clinicId <= 0) {
        return ['ok' => false, 'error' => 'Select a clinic to notify.'];
    }

    $nstmt = $pdo->prepare("
        SELECT id, full_name, phone
        FROM users
        WHERE role = 'nurse'
          AND clinic_id = ?
        ORDER BY id ASC
    ");
    $nstmt->execute([$clinicId]);
    $nurses = $nstmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$nurses) {
        return ['ok' => false, 'error' => 'No nurses found for this clinic.'];
    }

    $cstmt = $pdo->prepare("SELECT clinic_name FROM clinics WHERE id = ? LIMIT 1");
    $cstmt->execute([$clinicId]);
    $clinicName = (string)($cstmt->fetchColumn() ?: 'Clinic');

    $snippetParts = [];
    foreach (array_slice($topRows, 0, 3) as $r) {
        $b = trim((string)($r['baby_name'] ?? ''));
        $d = trim((string)($r['next_due'] ?? ''));
        if ($b === '') continue;
        $snippetParts[] = $d !== '' ? "{$b} ({$d})" : $b;
    }
    $snippet = $snippetParts ? (' Top: ' . implode('; ', $snippetParts) . '.') : '';

    $msg = "Admin alert ({$clinicName}): Immunization defaulters: {$defaulterCount}. Please follow up today." . $snippet;

    $sent = 0;
    $failed = 0;
    foreach ($nurses as $n) {
        $phone = trim((string)($n['phone'] ?? ''));
        if ($phone === '') continue;
        $ok = false;
        try {
            $ok = function_exists('sendSms') ? (bool)sendSms($phone, $msg) : false;
        } catch (Throwable $e) {
            $ok = false;
        }
        $ok ? $sent++ : $failed++;
    }

    return ($sent > 0)
        ? ['ok' => true, 'sent' => $sent, 'failed' => $failed, 'message' => $msg]
        : ['ok' => false, 'error' => 'No nurse phone numbers available.'];
};

$baseImmunSql = "
  FROM immunizations i
  JOIN babies b ON i.baby_id = b.id
  JOIN mothers m ON b.mother_id = m.id
  {$clinicJoin}
  WHERE i.status = 'PENDING'
    AND i.due_date <= CURDATE()
    {$clinicFilter}
";

// Handle "notify nurses" action (selected clinic only).
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'notify_nurses') {
    mch_csrf_require_valid('admin_immun_defaulters');
    $cid = (int)($_POST['clinic_id'] ?? 0);
    if ($adminLevel !== 'SUPER' && $cid !== $adminClinicId) {
        $_SESSION['flash_immun_notify_error'] = 'Forbidden.';
        header("Location: /mch_system/admin_portal/immunization_defaulters.php");
        exit;
    }
    if ($adminLevel === 'SUPER') {
        $cid = $selectedClinicId > 0 ? $selectedClinicId : $cid;
    } else {
        $cid = $adminClinicId;
    }

    if ($cid <= 0) {
        $_SESSION['flash_immun_notify_error'] = 'Select a clinic first.';
        header("Location: /mch_system/admin_portal/immunization_defaulters.php");
        exit;
    }

    // Count babies with overdue immunizations in this clinic.
    $baseNotifySql = "
      FROM immunizations i
      JOIN babies b ON i.baby_id = b.id
      JOIN mothers m ON b.mother_id = m.id
      LEFT JOIN users u ON m.user_id = u.id
      WHERE i.status = 'PENDING'
        AND i.due_date <= CURDATE()
        AND u.clinic_id = ?
    ";
    $countStmt = $pdo->prepare("SELECT COUNT(DISTINCT b.id) {$baseNotifySql}");
    $countStmt->execute([$cid]);
    $cnt = (int)$countStmt->fetchColumn();

    $topStmt2 = $pdo->prepare("
      SELECT
        b.baby_name,
        MIN(i.due_date) AS next_due,
        MAX(DATEDIFF(CURDATE(), i.due_date)) AS max_overdue
      {$baseNotifySql}
      GROUP BY b.id, b.baby_name
      ORDER BY max_overdue DESC, next_due ASC
      LIMIT 10
    ");
    $topStmt2->execute([$cid]);
    $topRows = $topStmt2->fetchAll(PDO::FETCH_ASSOC);

    $res = $notifyNurses($cid, $cnt, $topRows);
    if (!empty($res['ok'])) {
        mch_audit_log($pdo, 'ADMIN_NOTIFY_NURSES_IMMUN', 'immunizations', null, $res['message'] ?? 'Admin notified nurses for immunization defaulters');
        $_SESSION['flash_immun_notify_success'] = "Notified nurses: sent {$res['sent']} (failed {$res['failed']}).";
    } else {
        $_SESSION['flash_immun_notify_error'] = $res['error'] ?? 'Failed to notify nurses.';
    }

    $q2 = $_GET;
    $q2['clinic_id'] = $cid;
    header("Location: /mch_system/admin_portal/immunization_defaulters.php?" . http_build_query($q2));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'assign_task') {
    mch_csrf_require_valid('admin_immun_defaulters');
    $babyId = (int)($_POST['baby_id'] ?? 0);
    $nurseId = (int)($_POST['assigned_to'] ?? 0);
    $priority = (string)($_POST['priority'] ?? 'MEDIUM');
    $note = trim((string)($_POST['note'] ?? ''));
    $due = trim((string)($_POST['due_date'] ?? ''));

    if (!$tasksEnabled) {
        $_SESSION['flash_immun_task_error'] = "Tasks are not enabled yet. Run sql/follow_up_tasks.sql on the database.";
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }
    if ($taskClinicId <= 0) {
        $_SESSION['flash_immun_task_error'] = "Select a clinic first to assign tasks.";
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }
    if ($babyId <= 0 || $nurseId <= 0) {
        $_SESSION['flash_immun_task_error'] = "Select a nurse and a valid baby.";
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }
    if (!in_array($priority, ['LOW', 'MEDIUM', 'HIGH'], true)) {
        $priority = 'MEDIUM';
    }
    if ($note !== '' && mb_strlen($note) > 255) {
        $note = mb_substr($note, 0, 255);
    }
    if ($due !== '' && !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $due)) {
        $due = '';
    }

    try {
        $bStmt = $pdo->prepare("
            SELECT b.id, u.clinic_id
            FROM babies b
            JOIN mothers m ON b.mother_id = m.id
            LEFT JOIN users u ON m.user_id = u.id
            WHERE b.id = ?
            LIMIT 1
        ");
        $bStmt->execute([$babyId]);
        $bRow = $bStmt->fetch(PDO::FETCH_ASSOC);
        if (!$bRow) {
            throw new Exception("Baby not found.");
        }
        $babyClinicId = (int)($bRow['clinic_id'] ?? 0);
        if ($babyClinicId !== $taskClinicId) {
            throw new Exception("This baby is not in the selected clinic.");
        }

        $nStmt = $pdo->prepare("SELECT id, clinic_id FROM users WHERE id = ? AND role = 'nurse' LIMIT 1");
        $nStmt->execute([$nurseId]);
        $nRow = $nStmt->fetch(PDO::FETCH_ASSOC);
        if (!$nRow) {
            throw new Exception("Nurse not found.");
        }
        if ((int)$nRow['clinic_id'] !== $taskClinicId) {
            throw new Exception("Selected nurse is not in the selected clinic.");
        }

        $ins = $pdo->prepare("
            INSERT INTO follow_up_tasks
                (clinic_id, task_type, subject_type, subject_id, due_date, priority, status, assigned_to, created_by, note, created_at)
            VALUES
                (?, 'IMMUN_FOLLOWUP', 'baby', ?, ?, ?, 'OPEN', ?, ?, ?, NOW())
        ");
        $ins->execute([
            $taskClinicId,
            $babyId,
            ($due !== '' ? $due : null),
            $priority,
            $nurseId,
            (int)($_SESSION['user_id'] ?? 0) ?: null,
            ($note !== '' ? $note : null),
        ]);
        $taskId = (int)$pdo->lastInsertId();
        mch_audit_log($pdo, 'TASK_ASSIGN_IMMUN', 'follow_up_tasks', $taskId, "Assigned immunization follow-up task: baby_id={$babyId}, nurse_id={$nurseId}, due={$due}, priority={$priority}");
        $_SESSION['flash_immun_task_success'] = "Task assigned to nurse successfully.";
    } catch (Throwable $e) {
        $_SESSION['flash_immun_task_error'] = $e->getMessage() ?: 'Failed to assign task.';
    }

    header("Location: " . $_SERVER['REQUEST_URI']);
    exit;
}

$totalStmt = $pdo->prepare("SELECT COUNT(DISTINCT b.id) {$baseImmunSql}");
$totalStmt->execute($clinicParams);
$totalShots = (int)$totalStmt->fetchColumn();

$limitSql = (int)$perPage;
$offsetSql = (int)$offset;

// MariaDB/MySQL native prepared statements don't reliably support binding LIMIT/OFFSET placeholders.
// Interpolate validated integers instead.
$shotsStmt = $pdo->prepare("
  SELECT
    b.id AS baby_id,
    b.baby_name,
    m.fullname AS mother_name,
    COALESCE(c.clinic_name, '') AS clinic_name,
    MIN(i.due_date) AS next_due,
    GROUP_CONCAT(CONCAT(i.vaccine, ' (', DATE_FORMAT(i.due_date, '%d %b'), ')') ORDER BY i.due_date ASC SEPARATOR ', ') AS pending_vaccines,
    COUNT(*) AS pending_count,
    MAX(DATEDIFF(CURDATE(), i.due_date)) AS max_overdue
  {$baseImmunSql}
  GROUP BY b.id, b.baby_name, m.fullname, c.clinic_name
  ORDER BY next_due ASC, max_overdue DESC
  LIMIT {$limitSql} OFFSET {$offsetSql}
");
$shotsStmt->execute($clinicParams);
$shots = $shotsStmt->fetchAll(PDO::FETCH_ASSOC);

$topStmt = $pdo->prepare("
  SELECT
    b.id AS baby_id,
    b.baby_name,
    m.fullname AS mother_name,
    COALESCE(c.clinic_name, '') AS clinic_name,
    MIN(i.due_date) AS next_due,
    GROUP_CONCAT(CONCAT(i.vaccine, ' (', DATE_FORMAT(i.due_date, '%d %b'), ')') ORDER BY i.due_date ASC SEPARATOR ', ') AS pending_vaccines,
    COUNT(*) AS pending_count,
    MAX(DATEDIFF(CURDATE(), i.due_date)) AS max_overdue
  {$baseImmunSql}
  GROUP BY b.id, b.baby_name, m.fullname, c.clinic_name
  ORDER BY max_overdue DESC, next_due ASC
  LIMIT 25
");
$topStmt->execute($clinicParams);
$topShots = $topStmt->fetchAll(PDO::FETCH_ASSOC);
$totalPages = max(1, (int)ceil($totalShots / $perPage));

$isPrint = ((int)($_GET['print'] ?? 0)) === 1;
$printUrl = '/mch_system/admin_portal/immunization_defaulters.php?' . http_build_query(array_merge($_GET, ['print' => 1]));

include __DIR__ . "/../includes/header.php";
if (!$isPrint) {
  include __DIR__ . "/../includes/sidebar_admin.php";
}
?>

<?php if ($isPrint): ?>
  <style>
    .ml-64 { margin-left: 0 !important; }
    @media print {
      .mch-no-print { display: none !important; }
      body { background: #fff !important; }
      a { color: #000 !important; text-decoration: none !important; }
      .mch-actions-col { display: none !important; }
    }
  </style>
<?php endif; ?>

<div class="<?= $isPrint ? 'p-6' : 'ml-64 p-6' ?>">
  <div class="mch-card bg-white/90 p-6 border border-slate-100">
    <div class="flex items-center justify-between mb-4">
      <div>
        <h1 class="text-2xl font-semibold text-slate-900">Immunization Defaulters</h1>
        <?php if ($isPrint): ?>
          <div class="text-xs text-slate-500 mt-1">Printed: <?= date('Y-m-d H:i') ?></div>
        <?php endif; ?>
      </div>
      <div class="mch-no-print flex items-center gap-2">
        <a href="<?= htmlspecialchars($printUrl) ?>"
           class="px-3 py-2 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-sm">
          Print / Save PDF
        </a>
      </div>
      <form method="GET" class="mch-no-print flex items-center gap-2 text-sm">
        <?php if ($adminLevel === 'SUPER'): ?>
          <label class="text-xs text-slate-500 uppercase tracking-wide">Clinic</label>
          <select name="clinic_id" class="border border-slate-200 p-1 rounded-lg bg-white text-sm">
            <option value="0">All clinics</option>
            <?php foreach ($clinics as $cRow): ?>
              <option value="<?= (int)$cRow['id'] ?>" <?= (int)$selectedClinicId === (int)$cRow['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($cRow['clinic_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        <?php endif; ?>

        <label class="text-xs text-slate-500 uppercase tracking-wide">Per page</label>
        <select name="per_page" class="border border-slate-200 p-1 rounded-lg bg-white text-sm">
          <?php foreach ([10, 25, 50, 100] as $pp): ?>
            <option value="<?= $pp ?>" <?= $perPage === $pp ? 'selected' : '' ?>><?= $pp ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-xs font-semibold">
          Apply
        </button>
      </form>
    </div>

    <?php if ($notify_error): ?>
      <div class="bg-rose-50 border border-rose-200 text-rose-700 p-3 rounded-lg mb-4"><?= htmlspecialchars($notify_error) ?></div>
    <?php endif; ?>

    <?php if ($notify_success): ?>
      <div class="bg-sky-50 border border-sky-200 text-sky-700 p-3 rounded-lg mb-4"><?= htmlspecialchars($notify_success) ?></div>
    <?php endif; ?>

    <?php if ($task_error): ?>
      <div class="bg-rose-50 border border-rose-200 text-rose-700 p-3 rounded-lg mb-4"><?= htmlspecialchars($task_error) ?></div>
    <?php endif; ?>

    <?php if ($task_success): ?>
      <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 p-3 rounded-lg mb-4"><?= htmlspecialchars($task_success) ?></div>
    <?php endif; ?>

    <div class="mch-no-print flex items-center gap-2 mb-4">
      <form method="POST" class="inline-flex items-center gap-2">
        <?= mch_csrf_input('admin_immun_defaulters') ?>
        <input type="hidden" name="action" value="notify_nurses">
        <input type="hidden" name="clinic_id" value="<?= (int)$selectedClinicId ?>">
        <button type="submit"
                class="px-4 py-2 rounded-lg border border-indigo-200 bg-indigo-50 hover:bg-indigo-100 text-sm font-semibold text-indigo-800 <?= ($selectedClinicId > 0) ? '' : 'opacity-50 cursor-not-allowed' ?>"
                <?= ($selectedClinicId > 0) ? '' : 'disabled' ?>>
          Notify Nurses (Selected Clinic)
        </button>
      </form>
      <?php if ($adminLevel === 'SUPER' && $selectedClinicId === 0): ?>
        <span class="text-xs text-slate-500">Select a clinic above to enable nurse notification.</span>
      <?php endif; ?>
    </div>

    <?php if (count($shots) === 0 && count($topShots) === 0): ?>
      <div class="bg-emerald-100 text-emerald-700 p-3 rounded-lg">
        No immunization defaulters today
      </div>
    <?php else: ?>
      <div class="space-y-4">
        <div class="mch-no-print flex items-center gap-2 border border-slate-200 bg-slate-50 rounded-full p-1 text-sm font-semibold">
          <button type="button" class="px-4 py-1 rounded-full transition tab-trigger active:bg-white" data-panel="top">Top 25</button>
          <button type="button" class="px-4 py-1 rounded-full transition tab-trigger" data-panel="all">All Defaulters</button>
        </div>

        <div class="tab-panel <?= $isPrint ? 'hidden' : '' ?>" data-panel="top">
          <div class="overflow-x-auto">
            <table class="min-w-full text-sm mch-table">
              <thead>
                <tr>
                  <th class="p-3 text-left">Baby</th>
                  <th class="p-3 text-left">Mother</th>
                  <th class="p-3 text-left">Next due</th>
                  <th class="p-3 text-left">Pending vaccines</th>
                  <th class="p-3 text-left">Status</th>
                  <th class="p-3 text-center mch-actions-col">Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($topShots as $s): ?>
                <?php
                  $nextDue = (string)($s['next_due'] ?? '');
                  $daysOverdue = (int)($s['max_overdue'] ?? 0);
                  $badge = 'bg-rose-100 text-rose-700';
                  $label = 'STRICTLY PENDING';
                  $dotClass = 'bg-rose-600 animate-pulse';
                  if ($nextDue === date('Y-m-d')) {
                    $badge = 'bg-amber-100 text-amber-700';
                    $label = 'DUE TODAY';
                    $dotClass = 'bg-amber-600';
                  }
                  if ($daysOverdue <= 0 && $nextDue !== date('Y-m-d')) {
                    $badge = 'bg-slate-100 text-slate-700';
                    $label = 'COMING UP';
                    $dotClass = 'bg-slate-400';
                  }
                  $pendingList = trim((string)($s['pending_vaccines'] ?? ''));
                  $pendingSnippet = $pendingList;
                  if ($pendingList !== '') {
                    if (function_exists('mb_strlen') && mb_strlen($pendingList) > 80) {
                      $pendingSnippet = mb_substr($pendingList, 0, 80) . '…';
                    } elseif (!function_exists('mb_strlen') && strlen($pendingList) > 80) {
                      $pendingSnippet = substr($pendingList, 0, 80) . '…';
                    }
                  }
                ?>
                <tr class="border-t border-slate-200">
                  <td class="p-3 font-semibold"><?= htmlspecialchars($s['baby_name']) ?></td>
                  <td class="p-3 text-slate-600"><?= htmlspecialchars($s['mother_name'] ?? '') ?></td>
                  <td class="p-3"><?= htmlspecialchars($nextDue ?: '-') ?></td>
                  <td class="p-3">
                    <div class="text-sm font-semibold text-slate-900"><?= (int)($s['pending_count'] ?? 0) ?> vaccine<?= ((int)($s['pending_count'] ?? 0) === 1) ? '' : 's' ?> pending</div>
                    <?php if ($pendingList): ?>
                      <div class="text-xs text-slate-500 mt-1" title="<?= htmlspecialchars($pendingList) ?>">
                        <?= htmlspecialchars($pendingSnippet) ?>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td class="p-3">
                    <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-semibold <?= $badge ?>">
                      <span class="inline-block w-2 h-2 rounded-full <?= $dotClass ?>"></span>
                      <?= $label ?>
                    </span>
                  </td>
                    <td class="p-3 text-center mch-actions-col">
                    <a href="/mch_system/nurse_portal/baby_history.php?baby_id=<?= (int)($s['baby_id'] ?? 0) ?>" class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-xs font-semibold">
                      View vaccines
                    </a>
                    <button type="button"
                            class="ml-2 px-3 py-1.5 rounded-lg border border-teal-200 bg-teal-50 hover:bg-teal-100 text-xs font-semibold text-teal-800 <?= ($tasksEnabled && $taskClinicId > 0 && !empty($taskNurses)) ? '' : 'opacity-50 cursor-not-allowed' ?>"
                            <?= ($tasksEnabled && $taskClinicId > 0 && !empty($taskNurses)) ? '' : 'disabled' ?>
                            data-assign-immun-task
                            data-baby-id="<?= (int)($s['baby_id'] ?? 0) ?>"
                            data-baby-name="<?= htmlspecialchars((string)($s['baby_name'] ?? ''), ENT_QUOTES) ?>"
                            data-due-date="<?= htmlspecialchars((string)($s['next_due'] ?? ''), ENT_QUOTES) ?>"
                            data-pending="<?= htmlspecialchars((string)($s['pending_vaccines'] ?? ''), ENT_QUOTES) ?>">
                      Assign Task
                    </button>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="tab-panel <?= $isPrint ? '' : 'hidden' ?>" data-panel="all">
          <?php if (!count($shots)): ?>
            <div class="bg-emerald-100 text-emerald-700 p-3 rounded-lg">
              No immunization defaulters on this page.
            </div>
          <?php else: ?>
            <div class="flex items-center justify-between mb-3 text-sm text-slate-600">
              <div>
                Page <?= (int)$page ?> of <?= (int)$totalPages ?>
              </div>
              <div class="flex gap-2 items-center">
                <?php
                  $pageQuery = $_GET;
                  $pageQuery['per_page'] = $perPage;
                ?>
                <?php if ($page > 1): ?>
                  <?php $pageQuery['page'] = $page - 1; ?>
                  <a class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50" href="/mch_system/admin_portal/immunization_defaulters.php?<?= http_build_query($pageQuery) ?>">
                    Prev
                  </a>
                <?php endif; ?>
                <?php if ($page < $totalPages): ?>
                  <?php $pageQuery['page'] = $page + 1; ?>
                  <a class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50" href="/mch_system/admin_portal/immunization_defaulters.php?<?= http_build_query($pageQuery) ?>">
                    Next
                  </a>
                <?php endif; ?>
              </div>
            </div>

            <div class="overflow-x-auto">
              <table class="min-w-full text-sm mch-table mch-scroll">
                <thead>
                  <tr>
                    <th class="p-3 text-left">Baby</th>
                    <th class="p-3 text-left">Mother</th>
                    <th class="p-3 text-left">Next due</th>
                    <th class="p-3 text-left">Pending vaccines</th>
                    <th class="p-3 text-left">Status</th>
                    <th class="p-3 text-center mch-actions-col">Actions</th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($shots as $s): ?>
                  <?php
                    $nextDue = (string)($s['next_due'] ?? '');
                    $daysOverdue = (int)($s['max_overdue'] ?? 0);
                    $badge = 'bg-rose-100 text-rose-700';
                    $label = 'STRICTLY PENDING';
                    $dotClass = 'bg-rose-600 animate-pulse';
                    if ($nextDue === date('Y-m-d')) {
                      $badge = 'bg-amber-100 text-amber-700';
                      $label = 'DUE TODAY';
                      $dotClass = 'bg-amber-600';
                    }
                    if ($daysOverdue <= 0 && $nextDue !== date('Y-m-d')) {
                      $badge = 'bg-slate-100 text-slate-700';
                      $label = 'COMING UP';
                      $dotClass = 'bg-slate-400';
                    }
                    $pendingList = trim((string)($s['pending_vaccines'] ?? ''));
                    $pendingSnippet = $pendingList;
                    if ($pendingList !== '') {
                      if (function_exists('mb_strlen') && mb_strlen($pendingList) > 80) {
                        $pendingSnippet = mb_substr($pendingList, 0, 80) . '…';
                      } elseif (!function_exists('mb_strlen') && strlen($pendingList) > 80) {
                        $pendingSnippet = substr($pendingList, 0, 80) . '…';
                      }
                    }
                  ?>
                  <tr class="border-t border-slate-200">
                    <td class="p-3 font-semibold"><?= htmlspecialchars($s['baby_name']) ?></td>
                    <td class="p-3 text-slate-600"><?= htmlspecialchars($s['mother_name'] ?? '') ?></td>
                    <td class="p-3"><?= htmlspecialchars($nextDue ?: '-') ?></td>
                    <td class="p-3">
                      <div class="text-sm font-semibold text-slate-900"><?= (int)($s['pending_count'] ?? 0) ?> vaccine<?= ((int)($s['pending_count'] ?? 0) === 1) ? '' : 's' ?> pending</div>
                      <?php if ($pendingList): ?>
                        <div class="text-xs text-slate-500 mt-1" title="<?= htmlspecialchars($pendingList) ?>">
                          <?= htmlspecialchars($pendingSnippet) ?>
                        </div>
                      <?php endif; ?>
                    </td>
                    <td class="p-3">
                      <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-semibold <?= $badge ?>">
                        <span class="inline-block w-2 h-2 rounded-full <?= $dotClass ?>"></span>
                        <?= $label ?>
                      </span>
                      <?php if ($daysOverdue > 0): ?>
                        <div class="text-[10px] text-rose-600 mt-1"><?= $daysOverdue ?> day<?= $daysOverdue === 1 ? '' : 's' ?> overdue</div>
                      <?php endif; ?>
                    </td>
                    <td class="p-3 text-center mch-actions-col">
                    <a href="/mch_system/nurse_portal/baby_history.php?baby_id=<?= (int)($s['baby_id'] ?? 0) ?>" class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-xs font-semibold">
                      View vaccines
                    </a>
                    <button type="button"
                            class="ml-2 px-3 py-1.5 rounded-lg border border-teal-200 bg-teal-50 hover:bg-teal-100 text-xs font-semibold text-teal-800 <?= ($tasksEnabled && $taskClinicId > 0 && !empty($taskNurses)) ? '' : 'opacity-50 cursor-not-allowed' ?>"
                            <?= ($tasksEnabled && $taskClinicId > 0 && !empty($taskNurses)) ? '' : 'disabled' ?>
                            data-assign-immun-task
                            data-baby-id="<?= (int)($s['baby_id'] ?? 0) ?>"
                            data-baby-name="<?= htmlspecialchars((string)($s['baby_name'] ?? ''), ENT_QUOTES) ?>"
                            data-due-date="<?= htmlspecialchars((string)($s['next_due'] ?? ''), ENT_QUOTES) ?>"
                            data-pending="<?= htmlspecialchars((string)($s['pending_vaccines'] ?? ''), ENT_QUOTES) ?>">
                      Assign Task
                    </button>
                  </td>
                </tr>
              <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<div id="immunTaskModal" class="hidden fixed inset-0 z-50">
  <div class="absolute inset-0 bg-black/50" data-task-close></div>
  <div class="relative mx-auto max-w-lg mt-24 bg-white rounded-2xl shadow-2xl border border-slate-200 overflow-hidden">
    <div class="p-5 border-b border-slate-200 flex items-center justify-between">
      <div>
        <div class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Assign Follow-up Task</div>
        <div class="text-lg font-semibold text-slate-900" id="immunTaskTitle">Immunization follow-up</div>
      </div>
      <button type="button" class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-sm" data-task-close>
        Close
      </button>
    </div>
    <form method="POST" class="p-5 space-y-3">
      <?= mch_csrf_input('admin_immun_defaulters') ?>
      <input type="hidden" name="action" value="assign_task">
      <input type="hidden" name="baby_id" id="immun_task_baby_id" value="">

      <div class="text-sm text-slate-600">
        <div><span class="font-semibold text-slate-900" id="immun_task_baby_name">-</span></div>
        <div class="text-xs text-slate-500">Next due: <span id="immun_task_due_hint">-</span></div>
        <div class="text-xs text-slate-500 mt-1">Pending: <span id="immun_task_pending_hint">-</span></div>
      </div>

      <div>
        <label class="block text-sm font-semibold text-slate-700 mb-1">Assign to nurse</label>
        <select name="assigned_to" class="w-full border border-slate-200 p-2 rounded-lg bg-white" required>
          <option value="">Select nurse</option>
          <?php foreach ($taskNurses as $n): ?>
            <option value="<?= (int)$n['id'] ?>"><?= htmlspecialchars((string)($n['full_name'] ?? ('Nurse #' . (int)$n['id']))) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if (!$tasksEnabled): ?>
          <p class="text-xs text-amber-700 mt-1">Tasks are disabled. Run <span class="font-mono">sql/follow_up_tasks.sql</span>.</p>
        <?php elseif ($taskClinicId <= 0): ?>
          <p class="text-xs text-amber-700 mt-1">Select a clinic first to assign tasks.</p>
        <?php elseif (empty($taskNurses)): ?>
          <p class="text-xs text-amber-700 mt-1">No nurses found for this clinic.</p>
        <?php endif; ?>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">Due date (optional)</label>
          <input type="date" name="due_date" id="immun_task_due_date" class="w-full border border-slate-200 p-2 rounded-lg bg-white">
        </div>
        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">Priority</label>
          <select name="priority" class="w-full border border-slate-200 p-2 rounded-lg bg-white">
            <option value="LOW">LOW</option>
            <option value="MEDIUM" selected>MEDIUM</option>
            <option value="HIGH">HIGH</option>
          </select>
        </div>
      </div>

      <div>
        <label class="block text-sm font-semibold text-slate-700 mb-1">Note (optional)</label>
        <textarea name="note" rows="2" class="w-full border border-slate-200 p-2 rounded-lg bg-white" maxlength="255"
                  placeholder="e.g. Call mother and schedule immunization visit."></textarea>
      </div>

      <button type="submit" class="mch-btn w-full py-2">Assign Task</button>
    </form>
  </div>
</div>

<script>
  (function () {
    const triggers = Array.from(document.querySelectorAll('.tab-trigger'));
    const panels = Array.from(document.querySelectorAll('.tab-panel'));
    if (!triggers.length || !panels.length) return;
    const activate = (target) => {
      triggers.forEach(btn => btn.classList.toggle('active', btn.dataset.panel === target));
      panels.forEach(panel => panel.classList.toggle('hidden', panel.dataset.panel !== target));
    };
    triggers.forEach(btn => {
      btn.addEventListener('click', () => activate(btn.dataset.panel));
    });
    activate('<?= $isPrint ? 'all' : 'top' ?>');
  })();
</script>

<?php if ($isPrint): ?>
  <script>
    window.addEventListener('load', () => {
      try { window.print(); } catch (e) {}
    });
  </script>
<?php endif; ?>

<script>
  (function () {
    const modal = document.getElementById('immunTaskModal');
    const babyId = document.getElementById('immun_task_baby_id');
    const babyName = document.getElementById('immun_task_baby_name');
    const dueDate = document.getElementById('immun_task_due_date');
    const dueHint = document.getElementById('immun_task_due_hint');
    const pendingHint = document.getElementById('immun_task_pending_hint');
    if (!modal || !babyId || !babyName || !dueDate || !dueHint || !pendingHint) return;

    const closeEls = Array.from(modal.querySelectorAll('[data-task-close]'));
    const open = () => {
      modal.classList.remove('hidden');
      document.body.classList.add('overflow-hidden');
    };
    const close = () => {
      modal.classList.add('hidden');
      document.body.classList.remove('overflow-hidden');
    };
    closeEls.forEach(el => el.addEventListener('click', close));
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && !modal.classList.contains('hidden')) close();
    });

    document.querySelectorAll('[data-assign-immun-task]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const bid = btn.getAttribute('data-baby-id') || '';
        const name = btn.getAttribute('data-baby-name') || 'Baby';
        const due = btn.getAttribute('data-due-date') || '';
        const pending = btn.getAttribute('data-pending') || '';
        babyId.value = bid;
        babyName.textContent = name;
        dueHint.textContent = due || '-';
        pendingHint.textContent = pending || '-';
        dueDate.value = due;
        open();
      });
    });
  })();
</script>

<?php include __DIR__ . "/../includes/footer.php"; ?>
