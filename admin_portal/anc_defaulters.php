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

$q = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 25);
if ($perPage < 10) $perPage = 10;
if ($perPage > 200) $perPage = 200;
$offset = ($page - 1) * $perPage;

$selectedClinicId = 0;
if ($adminLevel === 'SUPER') {
    $selectedClinicId = max(0, (int)($_GET['clinic_id'] ?? 0));
} else {
    $selectedClinicId = $adminClinicId;
}

$where = [];
$params = [];

$where[] = "a.next_visit_date IS NOT NULL";
$where[] = "a.next_visit_date < CURDATE()";
$where[] = "UPPER(COALESCE(a.status, '')) = 'PENDING'";

// Optional: keep clinical_status (0/1/2) in sync for CDSS-style reporting.
if (db_has_column($pdo, 'anc_visits', 'clinical_status')) {
    try {
        $pdo->query("UPDATE anc_visits SET clinical_status = 2 WHERE status = 'PENDING' AND next_visit_date < CURDATE()");
    } catch (Throwable $e) {
        // ignore
    }
}

if ($selectedClinicId > 0) {
    $where[] = "u.clinic_id = ?";
    $params[] = $selectedClinicId;
}

if ($q !== '') {
    $where[] = "(m.fullname LIKE ? OR m.hospital_number LIKE ? OR m.phone LIKE ?)";
    $like = "%" . $q . "%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

$sms_error = '';
$sms_success = '';
if (!empty($_SESSION['flash_anc_sms_error'])) {
    $sms_error = (string)$_SESSION['flash_anc_sms_error'];
    unset($_SESSION['flash_anc_sms_error']);
}
if (!empty($_SESSION['flash_anc_sms_success'])) {
    $sms_success = (string)$_SESSION['flash_anc_sms_success'];
    unset($_SESSION['flash_anc_sms_success']);
}

$notify_error = '';
$notify_success = '';
if (!empty($_SESSION['flash_anc_notify_error'])) {
    $notify_error = (string)$_SESSION['flash_anc_notify_error'];
    unset($_SESSION['flash_anc_notify_error']);
}
if (!empty($_SESSION['flash_anc_notify_success'])) {
    $notify_success = (string)$_SESSION['flash_anc_notify_success'];
    unset($_SESSION['flash_anc_notify_success']);
}

$task_error = '';
$task_success = '';
if (!empty($_SESSION['flash_anc_task_error'])) {
    $task_error = (string)$_SESSION['flash_anc_task_error'];
    unset($_SESSION['flash_anc_task_error']);
}
if (!empty($_SESSION['flash_anc_task_success'])) {
    $task_success = (string)$_SESSION['flash_anc_task_success'];
    unset($_SESSION['flash_anc_task_success']);
}

$canLogSms = null;
$canRateLimit = null;
function mch_can_use_anc_sms_logs(PDO $pdo): bool
{
    try {
        $pdo->query("SELECT 1 FROM anc_sms_logs LIMIT 0");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

$sendReminder = function (int $motherId, ?string $nextVisitDate, ?string $clinicName = null) use ($pdo, $adminLevel, $adminClinicId, &$canLogSms, &$canRateLimit): array {
    // Fetch mother + clinic check
    $stmt = $pdo->prepare("
        SELECT m.id, m.fullname, m.hospital_number, m.phone, u.clinic_id, c.clinic_name
        FROM mothers m
        LEFT JOIN users u ON m.user_id = u.id
        LEFT JOIN clinics c ON u.clinic_id = c.id
        WHERE m.id = ?
        LIMIT 1
    ");
    $stmt->execute([$motherId]);
    $m = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$m) {
        return ['ok' => false, 'error' => 'Mother not found.'];
    }

    if ($adminLevel !== 'SUPER') {
        $motherClinicId = (int)($m['clinic_id'] ?? 0);
        if ($motherClinicId <= 0 || $motherClinicId !== $adminClinicId) {
            return ['ok' => false, 'error' => 'Forbidden.'];
        }
    }

    $phone = trim((string)($m['phone'] ?? ''));
    if ($phone === '') {
        return ['ok' => false, 'error' => 'Mother phone not available.'];
    }

    $clinic = $clinicName ?: (string)($m['clinic_name'] ?? '');
    $next = trim((string)($nextVisitDate ?? ''));
    $nextLabel = $next !== '' ? date('d M Y', strtotime($next)) : '';
    $clinicPart = $clinic !== '' ? " at {$clinic}" : '';
    $hn = trim((string)($m['hospital_number'] ?? ''));
    $hnPart = $hn !== '' ? " (Hospital #: {$hn})" : '';

    if ($canLogSms === null) {
        $canLogSms = mch_can_use_anc_sms_logs($pdo);
        $canRateLimit = $canLogSms;
    }

    if ($canRateLimit) {
        try {
            $chk = $pdo->prepare("
                SELECT created_at
                FROM anc_sms_logs
                WHERE mother_id = ?
                ORDER BY created_at DESC
                LIMIT 1
            ");
            $chk->execute([$motherId]);
            $last = $chk->fetchColumn();
            if ($last && strtotime((string)$last) > (time() - 24 * 3600)) {
                return ['ok' => false, 'error' => 'SMS already sent recently (last 24 hours).'];
            }
        } catch (Throwable $e) {
            // ignore rate limit if table exists check was wrong or query fails
        }
    }

    $msg = "Mhoro Mai {$m['fullname']}{$hnPart}, you missed your ANC visit" . ($nextLabel ? " scheduled for {$nextLabel}" : "") . ". Please visit the clinic{$clinicPart} as soon as possible.";

    $ok = false;
    try {
        $ok = function_exists('sendSms') ? (bool)sendSms($phone, $msg) : false;
    } catch (Throwable $e) {
        $ok = false;
    }

    if ($canLogSms) {
        try {
            $ins = $pdo->prepare("
                INSERT INTO anc_sms_logs (mother_id, next_visit_date, status, message, created_by_user_id, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $ins->execute([
                $motherId,
                $next !== '' ? $next : null,
                $ok ? 'SENT' : 'FAILED',
                $msg,
                (int)($_SESSION['user_id'] ?? 0) ?: null
            ]);
        } catch (Throwable $e) {
            // ignore if table doesn't exist or insert fails
        }
    }

    return $ok ? ['ok' => true] : ['ok' => false, 'error' => 'Failed to send SMS.'];
};

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
        $n = trim((string)($r['fullname'] ?? ''));
        $d = trim((string)($r['next_visit_date'] ?? ''));
        if ($n === '') continue;
        $snippetParts[] = $d !== '' ? "{$n} ({$d})" : $n;
    }
    $snippet = $snippetParts ? (' Top: ' . implode('; ', $snippetParts) . '.') : '';

    $msg = "Admin alert ({$clinicName}): ANC defaulters: {$defaulterCount}. Please follow up today." . $snippet;

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

$baseSql = "
    FROM anc_visits a
    JOIN mothers m ON a.mother_id = m.id
    LEFT JOIN users u ON m.user_id = u.id
    LEFT JOIN clinics c ON u.clinic_id = c.id
    {$whereSql}
    GROUP BY m.id, m.fullname, m.hospital_number, m.phone, c.clinic_name
";

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM (SELECT m.id {$baseSql}) t");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$stmt = $pdo->prepare("
    SELECT
        m.id AS mother_id,
        m.fullname,
        m.hospital_number,
        m.phone,
        c.clinic_name,
        MIN(a.next_visit_date) AS next_visit_date,
        DATEDIFF(CURDATE(), MIN(a.next_visit_date)) AS days_overdue,
        COUNT(*) AS overdue_visits
    {$baseSql}
    ORDER BY next_visit_date ASC
    LIMIT {$perPage} OFFSET {$offset}
");
$stmt->execute($params);
$defaulters = $stmt->fetchAll(PDO::FETCH_ASSOC);

$topDefaultersStmt = $pdo->prepare("
    SELECT
        m.id AS mother_id,
        m.fullname,
        m.hospital_number,
        m.phone,
        c.clinic_name,
        MIN(a.next_visit_date) AS next_visit_date,
        DATEDIFF(CURDATE(), MIN(a.next_visit_date)) AS days_overdue,
        COUNT(*) AS overdue_visits
    {$baseSql}
    ORDER BY days_overdue DESC, next_visit_date ASC
    LIMIT 25
");
$topDefaultersStmt->execute($params);
$topDefaulters = $topDefaultersStmt->fetchAll(PDO::FETCH_ASSOC);
$defaulterPreview = array_slice($defaulters, 0, 25);

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

// Handle "notify nurses" action.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'notify_nurses') {
    mch_csrf_require_valid('admin_anc_defaulters');
    $cid = (int)($_POST['clinic_id'] ?? 0);
    if ($adminLevel !== 'SUPER' && $cid !== $adminClinicId) {
        $_SESSION['flash_anc_notify_error'] = 'Forbidden.';
        header("Location: /mch_system/admin_portal/anc_defaulters.php");
        exit;
    }

    // Build a fresh query with clinic filter for notify
    $whereNotify = $where;
    $paramsNotify = $params;
    if ($adminLevel === 'SUPER') {
        // remove any previous clinic filter and apply selected
        $whereNotify = array_values(array_filter($whereNotify, fn($w) => strpos($w, 'u.clinic_id = ?') === false));
        $paramsNotify = $params;
        if ($selectedClinicId > 0) {
            // already filtered in page state
            $cid = $selectedClinicId;
        }
        if ($cid > 0) {
            $whereNotify[] = "u.clinic_id = ?";
            $paramsNotify[] = $cid;
        }
    } else {
        $cid = $adminClinicId;
    }
    $whereNotifySql = $whereNotify ? ("WHERE " . implode(" AND ", $whereNotify)) : "";
    $baseSqlNotify = "
        FROM anc_visits a
        JOIN mothers m ON a.mother_id = m.id
        LEFT JOIN users u ON m.user_id = u.id
        LEFT JOIN clinics c ON u.clinic_id = c.id
        {$whereNotifySql}
        GROUP BY m.id, m.fullname, m.hospital_number, m.phone, c.clinic_name
    ";
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM (SELECT m.id {$baseSqlNotify}) t");
    $countStmt->execute($paramsNotify);
    $cnt = (int)$countStmt->fetchColumn();

    $topStmt2 = $pdo->prepare("
        SELECT
            m.fullname,
            MIN(a.next_visit_date) AS next_visit_date,
            DATEDIFF(CURDATE(), MIN(a.next_visit_date)) AS days_overdue
        {$baseSqlNotify}
        ORDER BY days_overdue DESC, next_visit_date ASC
        LIMIT 10
    ");
    $topStmt2->execute($paramsNotify);
    $topRows = $topStmt2->fetchAll(PDO::FETCH_ASSOC);

    $res = $notifyNurses($cid, $cnt, $topRows);
    if (!empty($res['ok'])) {
        mch_audit_log($pdo, 'ADMIN_NOTIFY_NURSES_ANC', 'anc_visits', null, $res['message'] ?? 'Admin notified nurses for ANC defaulters');
        $_SESSION['flash_anc_notify_success'] = "Notified nurses: sent {$res['sent']} (failed {$res['failed']}).";
    } else {
        $_SESSION['flash_anc_notify_error'] = $res['error'] ?? 'Failed to notify nurses.';
    }

    $q2 = $_GET;
    $q2['clinic_id'] = $cid;
    header("Location: /mch_system/admin_portal/anc_defaulters.php?" . http_build_query($q2));
    exit;
}

$clinicStatsStmt = $pdo->prepare("
    SELECT COALESCE(c.clinic_name, 'Unassigned') AS clinic_name,
           COUNT(DISTINCT m.id) AS defaulter_count
    FROM anc_visits a
    JOIN mothers m ON a.mother_id = m.id
    LEFT JOIN users u ON m.user_id = u.id
    LEFT JOIN clinics c ON u.clinic_id = c.id
    {$whereSql}
    GROUP BY c.clinic_name
    ORDER BY defaulter_count DESC
    LIMIT 3
");
$clinicStatsStmt->execute($params);
$clinicStats = $clinicStatsStmt->fetchAll(PDO::FETCH_ASSOC);

$longOverdueSql = $whereSql . " AND DATEDIFF(CURDATE(), a.next_visit_date) >= 7";
$longOverdueStmt = $pdo->prepare("
    SELECT COUNT(DISTINCT m.id)
    FROM anc_visits a
    JOIN mothers m ON a.mother_id = m.id
    LEFT JOIN users u ON m.user_id = u.id
    LEFT JOIN clinics c ON u.clinic_id = c.id
    {$longOverdueSql}
");
$longOverdueStmt->execute($params);
$longOverdueCount = (int)$longOverdueStmt->fetchColumn();

// Handle SMS actions (after we know filters + current page list).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mch_csrf_require_valid('admin_anc_defaulters');
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'assign_task') {
        $motherId = (int)($_POST['mother_id'] ?? 0);
        $nurseId = (int)($_POST['assigned_to'] ?? 0);
        $priority = (string)($_POST['priority'] ?? 'MEDIUM');
        $note = trim((string)($_POST['note'] ?? ''));
        $due = trim((string)($_POST['due_date'] ?? ''));

        if (!$tasksEnabled) {
            $_SESSION['flash_anc_task_error'] = "Tasks are not enabled yet. Run sql/follow_up_tasks.sql on the database.";
            header("Location: " . $_SERVER['REQUEST_URI']);
            exit;
        }
        if ($taskClinicId <= 0) {
            $_SESSION['flash_anc_task_error'] = "Select a clinic first to assign tasks.";
            header("Location: " . $_SERVER['REQUEST_URI']);
            exit;
        }
        if ($motherId <= 0 || $nurseId <= 0) {
            $_SESSION['flash_anc_task_error'] = "Select a nurse and a valid mother.";
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
            $mStmt = $pdo->prepare("
                SELECT m.id, u.clinic_id
                FROM mothers m
                LEFT JOIN users u ON m.user_id = u.id
                WHERE m.id = ?
                LIMIT 1
            ");
            $mStmt->execute([$motherId]);
            $mRow = $mStmt->fetch(PDO::FETCH_ASSOC);
            if (!$mRow) {
                throw new Exception("Mother not found.");
            }
            $motherClinicId = (int)($mRow['clinic_id'] ?? 0);
            if ($motherClinicId !== $taskClinicId) {
                throw new Exception("This mother is not in the selected clinic.");
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
                    (?, 'ANC_FOLLOWUP', 'mother', ?, ?, ?, 'OPEN', ?, ?, ?, NOW())
            ");
            $ins->execute([
                $taskClinicId,
                $motherId,
                ($due !== '' ? $due : null),
                $priority,
                $nurseId,
                (int)($_SESSION['user_id'] ?? 0) ?: null,
                ($note !== '' ? $note : null),
            ]);
            $taskId = (int)$pdo->lastInsertId();
            mch_audit_log($pdo, 'TASK_ASSIGN_ANC', 'follow_up_tasks', $taskId, "Assigned ANC follow-up task: mother_id={$motherId}, nurse_id={$nurseId}, due={$due}, priority={$priority}");
            $_SESSION['flash_anc_task_success'] = "Task assigned to nurse successfully.";
        } catch (Throwable $e) {
            $_SESSION['flash_anc_task_error'] = $e->getMessage() ?: 'Failed to assign task.';
        }

        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }

    if ($action === 'send_sms') {
        $motherId = (int)($_POST['mother_id'] ?? 0);
        $nextVisitDate = trim((string)($_POST['next_visit_date'] ?? ''));
        if ($motherId <= 0) {
            $_SESSION['flash_anc_sms_error'] = "Invalid mother selection.";
        } else {
            $r = $sendReminder($motherId, $nextVisitDate !== '' ? $nextVisitDate : null, null);
            $_SESSION['flash_anc_sms_success'] = !empty($r['ok']) ? "SMS reminder sent." : '';
            $_SESSION['flash_anc_sms_error'] = empty($r['ok']) ? (string)($r['error'] ?? 'Failed to send SMS.') : '';
        }

        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }

    if ($action === 'send_sms_bulk') {
        $sent = 0;
        $failed = 0;
        foreach ($defaulters as $d) {
            $motherId = (int)($d['mother_id'] ?? 0);
            $nextVisitDate = (string)($d['next_visit_date'] ?? '');
            if ($motherId <= 0) {
                $failed++;
                continue;
            }
            $r = $sendReminder($motherId, $nextVisitDate !== '' ? $nextVisitDate : null, (string)($d['clinic_name'] ?? ''));
            if (!empty($r['ok'])) {
                $sent++;
            } else {
                $failed++;
            }
        }

        if ($sent > 0) {
            $_SESSION['flash_anc_sms_success'] = "Bulk SMS sent (this page): {$sent} successful, {$failed} failed.";
        }
        if ($sent === 0) {
            $_SESSION['flash_anc_sms_error'] = "No SMS sent. ({$failed} failed)";
        }

        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }
}

$isPrint = ((int)($_GET['print'] ?? 0)) === 1;
$printUrl = '/mch_system/admin_portal/anc_defaulters.php?' . http_build_query(array_merge($_GET, ['print' => 1]));

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
    <div class="flex items-start justify-between gap-3 mb-4">
      <div>
        <h2 class="text-2xl font-semibold text-slate-900">ANC Defaulters</h2>
        <?php if ($isPrint): ?>
          <div class="text-xs text-slate-500 mt-1">
            Printed: <?= date('Y-m-d H:i') ?><?= $q !== '' ? ' • Search: ' . htmlspecialchars($q) : '' ?>
          </div>
        <?php endif; ?>
      </div>
      <div class="mch-no-print">
        <a href="<?= htmlspecialchars($printUrl) ?>"
           class="px-3 py-2 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-sm">
          Print / Save PDF
        </a>
      </div>
    </div>

    <?php if ($sms_error): ?>
      <div class="bg-rose-100 text-rose-700 p-3 rounded-lg mb-4"><?= htmlspecialchars($sms_error) ?></div>
    <?php endif; ?>

    <?php if ($sms_success): ?>
      <div class="bg-emerald-100 text-emerald-700 p-3 rounded-lg mb-4"><?= htmlspecialchars($sms_success) ?></div>
    <?php endif; ?>

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

    <form method="GET" class="mch-no-print grid grid-cols-1 md:grid-cols-12 gap-3 mb-5">
      <div class="md:col-span-6">
        <label class="block text-xs font-semibold text-slate-600 mb-1">Search</label>
        <input name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Mother name, hospital #, phone"
               class="w-full border border-slate-200 p-2 rounded-lg bg-white">
      </div>

      <?php if ($adminLevel === 'SUPER'): ?>
        <div class="md:col-span-4">
          <label class="block text-xs font-semibold text-slate-600 mb-1">Clinic</label>
          <select name="clinic_id" class="w-full border border-slate-200 p-2 rounded-lg bg-white">
            <option value="0">All clinics</option>
            <?php foreach ($clinics as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= (int)$selectedClinicId === (int)$c['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($c['clinic_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>

      <div class="md:col-span-2">
        <label class="block text-xs font-semibold text-slate-600 mb-1">Per page</label>
        <select name="per_page" class="w-full border border-slate-200 p-2 rounded-lg bg-white">
          <?php foreach ([10, 25, 50, 100, 200] as $pp): ?>
            <option value="<?= $pp ?>" <?= $perPage === $pp ? 'selected' : '' ?>><?= $pp ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="md:col-span-12 flex items-center gap-2">
        <button class="mch-btn px-4 py-2 text-sm font-semibold">Apply</button>
        <a href="/mch_system/admin_portal/anc_defaulters.php" class="px-4 py-2 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-sm">
          Clear
        </a>
        <div class="text-sm text-slate-500 ml-auto">
          <?= number_format($totalRows) ?> record<?= $totalRows === 1 ? '' : 's' ?>
        </div>
      </div>
    </form>

    <div class="mch-no-print flex items-center gap-2 mb-5">
      <form method="POST" class="inline-flex items-center gap-2">
        <?= mch_csrf_input('admin_anc_defaulters') ?>
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

    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
      <button type="button" data-card-title="All defaulters" data-modal-target="modal-total-defaulters" class="group p-4 rounded-xl bg-slate-50 border border-slate-200 text-left text-slate-900 transition hover:ring-2 hover:ring-slate-300">
        <div class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Total defaulters</div>
        <div class="text-2xl font-bold text-slate-900"><?= number_format($totalRows) ?></div>
        <div class="text-sm text-slate-500">Across all clinics in scope</div>
        <div class="mt-2 text-xs font-semibold text-teal-700 hidden group-hover:inline-flex items-center gap-1">
          <span>View list</span>
          <span aria-hidden="true">↗</span>
        </div>
      </button>
      <button type="button" data-card-title="Overdue ≥ 7 days" data-modal-target="modal-overdue-defaulters" class="group p-4 rounded-xl bg-slate-50 border border-slate-200 text-left text-slate-900 transition hover:ring-2 hover:ring-slate-300">
        <div class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Overdue ≥ 7 days</div>
        <div class="text-2xl font-bold text-rose-700"><?= number_format($longOverdueCount) ?></div>
        <div class="text-sm text-slate-500">Needs urgent outreach</div>
        <div class="mt-2 text-xs font-semibold text-rose-700 hidden group-hover:inline-flex items-center gap-1">
          <span>View leaderboard</span>
          <span aria-hidden="true">↗</span>
        </div>
      </button>
      <div class="p-4 rounded-xl bg-slate-50 border border-slate-200">
        <div class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2">Top clinics</div>
        <?php if ($clinicStats): ?>
          <ul class="text-sm space-y-1">
            <?php foreach ($clinicStats as $stat): ?>
              <li class="flex justify-between">
                <span><?= htmlspecialchars($stat['clinic_name']) ?></span>
                <span class="font-semibold text-slate-900"><?= (int)$stat['defaulter_count'] ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <div class="text-sm text-slate-500">No clinics with defaulters yet.</div>
      </div>
    </div>

    <?php if (empty($topDefaulters)): ?>
      <div class="bg-amber-100 text-amber-700 p-3 rounded-lg">No overdue mothers to highlight.</div>
    <?php endif; ?>

    <div class="space-y-4 mb-4">
      <div class="mch-no-print flex items-center gap-2 border border-slate-200 bg-slate-50 rounded-full p-1 text-sm font-semibold">
        <button type="button" class="px-4 py-1 rounded-full transition tab-trigger active:bg-white" data-panel="top">Top 25 Overdue</button>
        <button type="button" class="px-4 py-1 rounded-full transition tab-trigger" data-panel="all">All Defaulters</button>
      </div>
      <div class="tab-panel <?= $isPrint ? 'hidden' : '' ?>" data-panel="top">
        <div class="flex items-center justify-between text-xs text-slate-600 mb-2">
          <span>Most overdue mothers, sorted by days overdue.</span>
          <a href="/mch_system/admin_portal/mother_anc_visits.php" class="text-xs font-semibold text-slate-600 hover:text-slate-900">View all visits</a>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm mch-table">
            <thead>
              <tr>
                <th class="p-2 text-left">Mother</th>
                <th class="p-2 text-left">Clinic</th>
                <th class="p-2 text-left">Next Visit</th>
                <th class="p-2 text-left">Days Overdue</th>
                <th class="p-2 text-left mch-actions-col">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($topDefaulters as $d): ?>
                <tr class="border-t border-slate-200">
                  <td class="p-2 font-semibold"><?= htmlspecialchars($d['fullname']) ?></td>
                  <td class="p-2 text-slate-600"><?= htmlspecialchars($d['clinic_name'] ?? '—') ?></td>
                  <td class="p-2 text-rose-700"><?= htmlspecialchars($d['next_visit_date'] ?? '-') ?></td>
                  <td class="p-2 font-semibold text-rose-700"><?= (int)($d['days_overdue'] ?? 0) ?></td>
                  <td class="p-2 flex flex-wrap gap-2 mch-actions-col">
                    <a class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-xs font-semibold"
                       href="/mch_system/admin_portal/mother_anc_visits.php?mother_id=<?= (int)($d['mother_id'] ?? 0) ?>">
                      View (<?= (int)($d['overdue_visits'] ?? 0) ?>)
                    </a>
                    <form method="POST" class="inline">
                      <?= mch_csrf_input('admin_anc_defaulters') ?>
                      <input type="hidden" name="action" value="send_sms">
                      <input type="hidden" name="mother_id" value="<?= (int)($d['mother_id'] ?? 0) ?>">
                      <input type="hidden" name="next_visit_date" value="<?= htmlspecialchars((string)($d['next_visit_date'] ?? '')) ?>">
                      <button type="submit" class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-xs font-semibold">
                        Send SMS
                      </button>
                    </form>
                    <button type="button"
                            class="px-3 py-1.5 rounded-lg border border-teal-200 bg-teal-50 hover:bg-teal-100 text-xs font-semibold text-teal-800 <?= ($tasksEnabled && $taskClinicId > 0 && !empty($taskNurses)) ? '' : 'opacity-50 cursor-not-allowed' ?>"
                            <?= ($tasksEnabled && $taskClinicId > 0 && !empty($taskNurses)) ? '' : 'disabled' ?>
                            data-assign-task
                            data-mother-id="<?= (int)($d['mother_id'] ?? 0) ?>"
                            data-mother-name="<?= htmlspecialchars((string)($d['fullname'] ?? ''), ENT_QUOTES) ?>"
                            data-due-date="<?= htmlspecialchars((string)($d['next_visit_date'] ?? ''), ENT_QUOTES) ?>">
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
        <?php if (count($defaulters) === 0): ?>
          <div class="bg-emerald-100 text-emerald-700 p-3 rounded-lg">No ANC defaulters</div>
        <?php else: ?>
          <div class="flex items-center justify-between mb-3 text-sm text-slate-600">
            <div>
              Page <?= (int)$page ?> of <?= (int)$totalPages ?>
            </div>
            <div class="flex gap-2 items-center">
              <?php
                $baseQuery = $_GET;
                $baseQuery['per_page'] = $perPage;
              ?>
              <form method="POST" class="inline">
                <?= mch_csrf_input('admin_anc_defaulters') ?>
                <input type="hidden" name="action" value="send_sms_bulk">
                <button type="submit" class="px-3 py-1.5 rounded-lg border border-emerald-200 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 font-semibold">
                  Send SMS (This Page)
                </button>
              </form>
              <a href="/mch_system/nurse_portal/register_mother.php" class="px-3 py-1.5 rounded-lg border border-slate-200 bg-slate-50 hover:bg-slate-100 text-sm font-semibold">
                Book Pregnancy
              </a>
              <?php if ($page > 1): ?>
                <?php $baseQuery['page'] = $page - 1; ?>
                <a class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50" href="/mch_system/admin_portal/anc_defaulters.php?<?= http_build_query($baseQuery) ?>">
                  Prev
                </a>
              <?php endif; ?>
              <?php if ($page < $totalPages): ?>
                <?php $baseQuery['page'] = $page + 1; ?>
                <a class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50" href="/mch_system/admin_portal/anc_defaulters.php?<?= http_build_query($baseQuery) ?>">
                  Next
                </a>
              <?php endif; ?>
            </div>
          </div>

          <table class="w-full text-sm mch-table mch-scroll">
            <thead>
              <tr>
                <th class="p-2 text-left">Mother</th>
                <th class="p-2 text-left">Hospital #</th>
                <th class="p-2 text-left">Phone</th>
                <th class="p-2 text-left">Clinic</th>
                <th class="p-2 text-left">Next Visit</th>
                <th class="p-2 text-left">Days Overdue</th>
                <th class="p-2 text-left">ANC Visits</th>
                <th class="p-2 text-left">Reminder</th>
                <th class="p-2 text-left">Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($defaulters as $d): ?>
                <tr class="border-t border-slate-200">
                  <td class="p-2"><?= htmlspecialchars($d['fullname']) ?></td>
                  <td class="p-2"><?= $d['hospital_number'] ?></td>
                  <td class="p-2"><?= $d['phone'] ?></td>
                  <td class="p-2"><?= htmlspecialchars($d['clinic_name'] ?? '—') ?></td>
                  <td class="p-2 text-rose-700 font-semibold"><?= htmlspecialchars($d['next_visit_date'] ?? '-') ?></td>
                  <td class="p-2 font-semibold text-rose-700"><?= (int)($d['days_overdue'] ?? 0) ?></td>
                  <td class="p-2">
                    <a class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-xs font-semibold"
                       href="/mch_system/admin_portal/mother_anc_visits.php?mother_id=<?= (int)($d['mother_id'] ?? 0) ?>">
                      View (<?= (int)($d['overdue_visits'] ?? 0) ?>)
                    </a>
                  </td>
                  <td class="p-2">
                    <form method="POST" class="inline">
                      <?= mch_csrf_input('admin_anc_defaulters') ?>
                      <input type="hidden" name="action" value="send_sms">
                      <input type="hidden" name="mother_id" value="<?= (int)($d['mother_id'] ?? 0) ?>">
                      <input type="hidden" name="next_visit_date" value="<?= htmlspecialchars((string)($d['next_visit_date'] ?? '')) ?>">
                      <button type="submit" class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-xs font-semibold">
                        Send SMS
                      </button>
                    </form>
                    <button type="button"
                            class="mt-2 px-3 py-1.5 rounded-lg border border-teal-200 bg-teal-50 hover:bg-teal-100 text-xs font-semibold text-teal-800 <?= ($tasksEnabled && $taskClinicId > 0 && !empty($taskNurses)) ? '' : 'opacity-50 cursor-not-allowed' ?>"
                            <?= ($tasksEnabled && $taskClinicId > 0 && !empty($taskNurses)) ? '' : 'disabled' ?>
                            data-assign-task
                            data-mother-id="<?= (int)($d['mother_id'] ?? 0) ?>"
                            data-mother-name="<?= htmlspecialchars((string)($d['fullname'] ?? ''), ENT_QUOTES) ?>"
                            data-due-date="<?= htmlspecialchars((string)($d['next_visit_date'] ?? ''), ENT_QUOTES) ?>">
                      Assign Task
                    </button>
                  </td>
                  <td class="p-2">
                    <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-semibold bg-rose-100 text-rose-700">
                      <span class="inline-block w-2 h-2 rounded-full bg-rose-600 animate-pulse"></span>
                      STRICTLY PENDING
                    </span>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
      <div class="flex items-center justify-between mb-3 text-sm text-slate-600">
        <div>
          Page <?= (int)$page ?> of <?= (int)$totalPages ?>
        </div>
        <div class="flex gap-2 items-center">
          <?php
            $baseQuery = $_GET;
            $baseQuery['per_page'] = $perPage;
          ?>
          <form method="POST" class="inline">
            <?= mch_csrf_input('admin_anc_defaulters') ?>
            <input type="hidden" name="action" value="send_sms_bulk">
            <button type="submit" class="px-3 py-1.5 rounded-lg border border-emerald-200 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 font-semibold">
              Send SMS (This Page)
            </button>
          </form>
          <a href="/mch_system/nurse_portal/register_mother.php" class="px-3 py-1.5 rounded-lg border border-slate-200 bg-slate-50 hover:bg-slate-100 text-sm font-semibold">
            Book Pregnancy
          </a>
          <?php if ($page > 1): ?>
            <?php $baseQuery['page'] = $page - 1; ?>
            <a class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50" href="/mch_system/admin_portal/anc_defaulters.php?<?= http_build_query($baseQuery) ?>">
              Prev
            </a>
          <?php endif; ?>
          <?php if ($page < $totalPages): ?>
            <?php $baseQuery['page'] = $page + 1; ?>
            <a class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50" href="/mch_system/admin_portal/anc_defaulters.php?<?= http_build_query($baseQuery) ?>">
              Next
            </a>
          <?php endif; ?>
        </div>
      </div>

      <table class="w-full text-sm mch-table mch-scroll">
        <thead>
          <tr>
            <th class="p-2 text-left">Mother</th>
            <th class="p-2 text-left">Hospital #</th>
            <th class="p-2 text-left">Phone</th>
            <th class="p-2 text-left">Clinic</th>
            <th class="p-2 text-left">Next Visit</th>
            <th class="p-2 text-left">Days Overdue</th>
            <th class="p-2 text-left">ANC Visits</th>
            <th class="p-2 text-left">Reminder</th>
            <th class="p-2 text-left">Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($defaulters as $d): ?>
            <tr class="border-t border-slate-200">
              <td class="p-2"><?= htmlspecialchars($d['fullname']) ?></td>
              <td class="p-2"><?= $d['hospital_number'] ?></td>
              <td class="p-2"><?= $d['phone'] ?></td>
              <td class="p-2"><?= htmlspecialchars($d['clinic_name'] ?? '—') ?></td>
              <td class="p-2 text-rose-700 font-semibold"><?= htmlspecialchars($d['next_visit_date'] ?? '-') ?></td>
              <td class="p-2 font-semibold text-rose-700"><?= (int)($d['days_overdue'] ?? 0) ?></td>
              <td class="p-2">
                <a class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-xs font-semibold"
                   href="/mch_system/admin_portal/mother_anc_visits.php?mother_id=<?= (int)($d['mother_id'] ?? 0) ?>">
                  View (<?= (int)($d['overdue_visits'] ?? 0) ?>)
                </a>
              </td>
              <td class="p-2">
                <form method="POST" class="inline">
                  <?= mch_csrf_input('admin_anc_defaulters') ?>
                  <input type="hidden" name="action" value="send_sms">
                  <input type="hidden" name="mother_id" value="<?= (int)($d['mother_id'] ?? 0) ?>">
                  <input type="hidden" name="next_visit_date" value="<?= htmlspecialchars((string)($d['next_visit_date'] ?? '')) ?>">
                  <button type="submit" class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-xs font-semibold">
                    Send SMS
                  </button>
                </form>
              </td>
              <td class="p-2">
                <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-semibold bg-rose-100 text-rose-700">
                  <span class="inline-block w-2 h-2 rounded-full bg-rose-600 animate-pulse"></span>
                  STRICTLY PENDING
                </span>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<div id="taskModal" class="hidden fixed inset-0 z-50">
  <div class="absolute inset-0 bg-black/50" data-task-close></div>
  <div class="relative mx-auto max-w-lg mt-24 bg-white rounded-2xl shadow-2xl border border-slate-200 overflow-hidden">
    <div class="p-5 border-b border-slate-200 flex items-center justify-between">
      <div>
        <div class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Assign Follow-up Task</div>
        <div class="text-lg font-semibold text-slate-900" id="taskModalTitle">ANC follow-up</div>
      </div>
      <button type="button" class="px-3 py-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-sm" data-task-close>
        Close
      </button>
    </div>
    <form method="POST" class="p-5 space-y-3">
      <?= mch_csrf_input('admin_anc_defaulters') ?>
      <input type="hidden" name="action" value="assign_task">
      <input type="hidden" name="mother_id" id="task_mother_id" value="">

      <div class="text-sm text-slate-600">
        <div><span class="font-semibold text-slate-900" id="task_mother_name">-</span></div>
        <div class="text-xs text-slate-500">Due date suggestion: <span id="task_due_hint">-</span></div>
      </div>

      <div>
        <label class="block text-sm font-semibold text-slate-700 mb-1">Assign to nurse</label>
        <select name="assigned_to" id="task_assigned_to" class="w-full border border-slate-200 p-2 rounded-lg bg-white" required>
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
          <input type="date" name="due_date" id="task_due_date" class="w-full border border-slate-200 p-2 rounded-lg bg-white">
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
                  placeholder="e.g. Call mother and confirm appointment date."></textarea>
      </div>

      <button type="submit" class="mch-btn w-full py-2">Assign Task</button>
    </form>
  </div>
</div>

<script>
  (function () {
    const modal = document.getElementById('taskModal');
    const motherId = document.getElementById('task_mother_id');
    const motherName = document.getElementById('task_mother_name');
    const dueDate = document.getElementById('task_due_date');
    const dueHint = document.getElementById('task_due_hint');
    if (!modal || !motherId || !motherName || !dueDate || !dueHint) return;

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

    document.querySelectorAll('[data-assign-task]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const mid = btn.getAttribute('data-mother-id') || '';
        const name = btn.getAttribute('data-mother-name') || 'Mother';
        const due = btn.getAttribute('data-due-date') || '';
        motherId.value = mid;
        motherName.textContent = name;
        dueHint.textContent = due || '-';
        dueDate.value = due;
        open();
      });
    });
  })();
</script>

<script>
  (function () {
    const triggers = Array.from(document.querySelectorAll('.tab-trigger'));
    const panels = Array.from(document.querySelectorAll('.tab-panel'));
    if (!triggers.length || !panels.length) return;
    function activate(target) {
      triggers.forEach(btn => btn.classList.toggle('active', btn.dataset.panel === target));
      panels.forEach(panel => panel.classList.toggle('hidden', panel.dataset.panel !== target));
    }
    triggers.forEach(btn => {
      btn.addEventListener('click', () => activate(btn.dataset.panel));
    });
    activate('<?= $isPrint ? 'all' : 'top' ?>');
  })();
</script>

<?php include __DIR__ . "/../includes/footer.php"; ?>

<div id="ancCardModal" class="fixed inset-0 z-50 hidden">
  <div class="absolute inset-0 bg-slate-900/40" data-card-modal-close></div>
  <div class="relative mx-auto my-8 w-[min(960px,94vw)] bg-white rounded-2xl shadow-xl border border-slate-200 p-6">
    <div class="flex items-center justify-between gap-4 mb-4">
      <h3 id="ancCardModalTitle" class="text-lg font-semibold text-slate-900">Details</h3>
      <button type="button" class="px-3 py-1 rounded-lg border border-slate-200 hover:bg-slate-100 text-sm" data-card-modal-close>Close</button>
    </div>
    <div id="ancCardModalBody" class="max-h-[70vh] overflow-y-auto"></div>
  </div>
</div>

<div id="modal-total-defaulters" class="hidden">
  <?php if (empty($defaulterPreview)): ?>
    <p class="text-slate-500">No defaulters at the moment.</p>
  <?php else: ?>
    <div class="space-y-3">
      <div class="text-xs uppercase tracking-wide text-slate-500">
        Showing <?= min(count($defaulterPreview), count($defaulters)) ?> of <?= number_format($totalRows) ?> total defaulters
      </div>
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm mch-table">
          <thead>
            <tr>
              <th class="p-2 text-left">Mother</th>
              <th class="p-2 text-left">Clinic</th>
              <th class="p-2 text-left">Next Visit</th>
              <th class="p-2 text-left">Days Overdue</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($defaulterPreview as $d): ?>
              <tr class="border-t border-slate-200">
                <td class="p-2"><?= htmlspecialchars($d['fullname']) ?></td>
                <td class="p-2 text-slate-600"><?= htmlspecialchars($d['clinic_name'] ?? '—') ?></td>
                <td class="p-2 text-rose-700"><?= htmlspecialchars($d['next_visit_date'] ?? '-') ?></td>
                <td class="p-2 font-semibold text-rose-700"><?= (int)($d['days_overdue'] ?? 0) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
</div>

<div id="modal-overdue-defaulters" class="hidden">
  <?php if (empty($topDefaulters)): ?>
    <p class="text-slate-500">No overdue defaulters flagged.</p>
  <?php else: ?>
    <div class="space-y-3">
      <div class="text-xs uppercase tracking-wide text-slate-500">
        Showing <?= min(10, count($topDefaulters)) ?> most overdue mothers
      </div>
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm mch-table">
          <thead>
            <tr>
              <th class="p-2 text-left">Mother</th>
              <th class="p-2 text-left">Clinic</th>
              <th class="p-2 text-left">Next Visit</th>
              <th class="p-2 text-left">Days Overdue</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach (array_slice($topDefaulters, 0, 10) as $d): ?>
              <tr class="border-t border-slate-200">
                <td class="p-2"><?= htmlspecialchars($d['fullname']) ?></td>
                <td class="p-2 text-slate-600"><?= htmlspecialchars($d['clinic_name'] ?? '—') ?></td>
                <td class="p-2 text-rose-700"><?= htmlspecialchars($d['next_visit_date'] ?? '-') ?></td>
                <td class="p-2 font-semibold text-rose-700"><?= (int)($d['days_overdue'] ?? 0) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
</div>

<script>
  (function () {
    const modal = document.getElementById('ancCardModal');
    const modalTitle = document.getElementById('ancCardModalTitle');
    const modalBody = document.getElementById('ancCardModalBody');
    if (!modal || !modalTitle || !modalBody) return;

    const closeModal = () => {
      modal.classList.add('hidden');
      modalBody.innerHTML = '';
      document.body.classList.remove('overflow-hidden');
    };

    modal.querySelectorAll('[data-card-modal-close]').forEach(btn => {
      btn.addEventListener('click', closeModal);
    });

    const cardButtons = Array.from(document.querySelectorAll('[data-modal-target]'));
    cardButtons.forEach(btn => {
      btn.addEventListener('click', () => {
        const targetId = btn.dataset.modalTarget;
        const title = btn.dataset.cardTitle || '';
        const template = document.getElementById(targetId);
        if (!template) return;
        modalTitle.textContent = title;
        modalBody.innerHTML = template.innerHTML;
        modal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
      });
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && !modal.classList.contains('hidden')) {
        closeModal();
      }
    });

    modal.addEventListener('click', (event) => {
      if (event.target === modal) {
        closeModal();
      }
    });
  })();
</script>

<?php if ($isPrint): ?>
  <script>
    window.addEventListener('load', () => {
      try { window.print(); } catch (e) {}
    });
  </script>
<?php endif; ?>
