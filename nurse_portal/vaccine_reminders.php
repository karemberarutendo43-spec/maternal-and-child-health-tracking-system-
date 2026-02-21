<?php
require_once __DIR__ . "/../config/session.php";
mch_session_start();
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/sms.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/scope.php";
require_once __DIR__ . "/../config/csrf.php";

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'nurse'])) {
    header("Location: /mch_system/auth/login.php");
    exit;
}

$role = $_SESSION['role'];
$scopedClinicId = mch_scope_scoped_clinic_id();
if ($role === 'nurse' && $scopedClinicId <= 0) {
    die("Your account is not assigned to a clinic. Please contact Admin.");
}

$sms_error = '';
$sms_success = '';
$smsLogsAvailable = false;
try {
    $smsLogsAvailable = (bool)$pdo->query("SHOW TABLES LIKE 'sms_logs'")->fetchColumn();
} catch (Throwable $e) {
    $smsLogsAvailable = false;
}

$clinicName = 'Clinic';
$clinic_id = $scopedClinicId > 0 ? $scopedClinicId : null;
$scalar = function (string $sql, array $params = []) use ($pdo) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
};
if (!empty($clinic_id)) {
    $cstmt = $pdo->prepare("SELECT clinic_name FROM clinics WHERE id = ? LIMIT 1");
    $cstmt->execute([$clinic_id]);
    $crow = $cstmt->fetch(PDO::FETCH_ASSOC);
    if ($crow && !empty($crow['clinic_name'])) {
        $clinicName = $crow['clinic_name'];
    }
}

$immunDueSoonParams = [];
$immunDueSoonSql = $smsLogsAvailable ? "
    SELECT
        i.id,
        i.vaccine,
        i.due_date,
        b.id AS baby_id,
        b.baby_name,
        m.fullname AS mother_name,
        m.phone AS mother_phone,
        u.clinic_id,
        (
            SELECT sl.status
            FROM sms_logs sl
            WHERE sl.baby_id = b.id
              AND sl.vaccine = i.vaccine
              AND sl.due_date = i.due_date
            ORDER BY sl.created_at DESC
            LIMIT 1
        ) AS sms_status,
        (
            SELECT sl.created_at
            FROM sms_logs sl
            WHERE sl.baby_id = b.id
              AND sl.vaccine = i.vaccine
              AND sl.due_date = i.due_date
            ORDER BY sl.created_at DESC
            LIMIT 1
        ) AS sms_sent_at
    FROM immunizations i
    JOIN babies b ON i.baby_id = b.id
    JOIN mothers m ON b.mother_id = m.id
    LEFT JOIN users u ON u.id = m.user_id
    WHERE i.status = 'PENDING'
      AND i.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
      " . ($scopedClinicId > 0 ? " AND u.clinic_id = ? " : "") . "
    ORDER BY i.due_date ASC
    LIMIT 8
" : "
    SELECT
        i.id,
        i.vaccine,
        i.due_date,
        b.id AS baby_id,
        b.baby_name,
        m.fullname AS mother_name,
        m.phone AS mother_phone,
        NULL AS sms_status,
        NULL AS sms_sent_at
    FROM immunizations i
    JOIN babies b ON i.baby_id = b.id
    JOIN mothers m ON b.mother_id = m.id
    LEFT JOIN users u ON u.id = m.user_id
    WHERE i.status = 'PENDING'
      AND i.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
      " . ($scopedClinicId > 0 ? " AND u.clinic_id = ? " : "") . "
    ORDER BY i.due_date ASC
    LIMIT 8
";
if ($scopedClinicId > 0) {
    $immunDueSoonParams[] = $scopedClinicId;
}
$stmtDueSoon = $pdo->prepare($immunDueSoonSql);
$stmtDueSoon->execute($immunDueSoonParams);
$immunDueSoonList = $stmtDueSoon->fetchAll(PDO::FETCH_ASSOC);
$immunDueSoon = (int)$scalar("
    SELECT COUNT(*)
    FROM immunizations i
    JOIN babies b ON i.baby_id = b.id
    JOIN mothers m ON b.mother_id = m.id
    JOIN users u ON u.id = m.user_id
    WHERE i.status = 'PENDING'
      AND i.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
      " . ($scopedClinicId > 0 ? " AND u.clinic_id = ? " : "") . "
", $scopedClinicId > 0 ? [$scopedClinicId] : []);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mch_csrf_require_valid('nurse_dashboard_sms');
    $action = $_POST['action'] ?? '';

    if ($action === 'send_vaccine_sms') {
        $baby_id = (int)($_POST['baby_id'] ?? 0);
        $vaccine = trim($_POST['vaccine'] ?? '');
        $due_date = trim($_POST['due_date'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $mother_name = trim($_POST['mother_name'] ?? '');
        $baby_name = trim($_POST['baby_name'] ?? '');

        if ($baby_id <= 0 || $vaccine === '' || $due_date === '') {
            $sms_error = "Missing vaccine reminder details.";
        } elseif ($phone === '') {
            $sms_error = "Mother phone not found for this baby.";
        } elseif ($scopedClinicId > 0) {
            $scopeStmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM babies b
                JOIN mothers m ON b.mother_id = m.id
                JOIN users u ON m.user_id = u.id
                WHERE b.id = ?
                  AND u.clinic_id = ?
                LIMIT 1
            ");
            $scopeStmt->execute([$baby_id, $scopedClinicId]);
            if ((int)$scopeStmt->fetchColumn() !== 1) {
                $sms_error = "You can only send reminders for babies in your clinic.";
            }
        } else {
            $clinicLabel = $clinicName ?? 'your clinic';
            $msg = "Mhoro Mai {$mother_name}, reminder: {$baby_name} needs {$vaccine} on {$due_date} at {$clinicLabel}.";
            $ok = sendSms($phone, $msg);

            if ($smsLogsAvailable) {
                $logStmt = $pdo->prepare("
                    INSERT INTO sms_logs (baby_id, vaccine, due_date, status, message, created_at)
                    VALUES (?, ?, ?, ?, ?, NOW())
                ");
                $logStmt->execute([
                    $baby_id,
                    $vaccine,
                    $due_date,
                    $ok ? 'SENT' : 'FAILED',
                    $msg
                ]);
            }

            if ($ok) {
                $sms_success = $smsLogsAvailable
                    ? "SMS reminder sent."
                    : "SMS reminder sent. (Install sms_logs table to track delivery status.)";
            } else {
                $sms_error = "Failed to send SMS reminder.";
            }
        }
    }

    if ($action === 'send_all_vaccine_sms') {
        $dueList = $pdo->query("
            SELECT
                i.vaccine,
                i.due_date,
                b.id AS baby_id,
                b.baby_name,
                m.fullname AS mother_name,
                m.phone AS mother_phone
            FROM immunizations i
            JOIN babies b ON i.baby_id = b.id
            JOIN mothers m ON b.mother_id = m.id
            WHERE i.status = 'PENDING'
              AND i.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
            ORDER BY i.due_date ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $sent = 0;
        $failed = 0;
        $clinicLabel = $clinicName ?? 'your clinic';
        $logStmt = null;
        if ($smsLogsAvailable) {
            $logStmt = $pdo->prepare("
                INSERT INTO sms_logs (baby_id, vaccine, due_date, status, message, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
        }

        foreach ($dueList as $row) {
            if (empty($row['mother_phone'])) {
                $failed++;
                continue;
            }
            $msg = "Mhoro Mai {$row['mother_name']}, reminder: {$row['baby_name']} needs {$row['vaccine']} on {$row['due_date']} at {$clinicLabel}.";
            $ok = sendSms($row['mother_phone'], $msg);
            if ($smsLogsAvailable && $logStmt) {
                $logStmt->execute([
                    $row['baby_id'],
                    $row['vaccine'],
                    $row['due_date'],
                    $ok ? 'SENT' : 'FAILED',
                    $msg
                ]);
            }
            if ($ok) {
                $sent++;
            } else {
                $failed++;
            }
        }

        $sms_success = $smsLogsAvailable
            ? "Bulk SMS sent: {$sent} successful, {$failed} failed."
            : "Bulk SMS sent: {$sent} successful, {$failed} failed. (Install sms_logs table to track delivery status.)";
    }
}

$page = 'vaccine_reminders';
include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_nurse.php";
?>

<div class="ml-64 p-6 space-y-6">
    <div class="mch-card bg-white/90 p-6 border border-slate-100">
        <div class="flex items-center justify-between">
            <h2 class="text-2xl font-semibold text-slate-900">Vaccine Reminders</h2>
            <a href="/mch_system/nurse_portal/dashboard.php#quick_links" class="text-xs font-semibold text-slate-600 hover:text-slate-900">
                Back to Quick Links
            </a>
        </div>
        <p class="text-sm text-slate-500 mt-1">Showing vaccines due over the next seven days for <?= htmlspecialchars($clinicName) ?>.</p>
        <div class="flex items-center justify-between mt-4">
            <p class="text-xs uppercase tracking-wide text-slate-400">Pending this week</p>
            <form method="POST" class="inline">
                <?= mch_csrf_input('nurse_dashboard_sms') ?>
                <input type="hidden" name="action" value="send_all_vaccine_sms">
                <button class="inline-flex items-center px-3 py-1 rounded-lg bg-emerald-50 border border-emerald-200 hover:bg-emerald-100 text-sm">
                    Send All SMS
                </button>
            </form>
        </div>
        <?php if ($sms_error): ?>
            <div class="bg-rose-100 text-rose-700 p-3 rounded-lg mb-3 mt-4"><?= htmlspecialchars($sms_error) ?></div>
        <?php endif; ?>
        <?php if ($sms_success): ?>
            <div class="bg-emerald-100 text-emerald-700 p-3 rounded-lg mb-3 mt-4"><?= htmlspecialchars($sms_success) ?></div>
        <?php endif; ?>
        <div class="overflow-x-auto mt-4">
            <table class="w-full text-sm mch-table mch-scroll">
                <thead>
                    <tr>
                        <th class="p-2 border-b text-left">Baby</th>
                        <th class="p-2 border-b text-left">Mother</th>
                        <th class="p-2 border-b text-left">Vaccine</th>
                        <th class="p-2 border-b text-left">Due Date</th>
                        <th class="p-2 border-b text-left">SMS</th>
                        <th class="p-2 border-b text-left">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($immunDueSoonList)): ?>
                        <tr>
                            <td colspan="6" class="p-4 text-sm text-slate-500">No vaccine reminders for the next seven days.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($immunDueSoonList as $r): ?>
                            <tr>
                                <td class="p-2 border-b"><?= htmlspecialchars($r['baby_name']) ?></td>
                                <td class="p-2 border-b"><?= htmlspecialchars($r['mother_name']) ?></td>
                                <td class="p-2 border-b"><?= htmlspecialchars($r['vaccine']) ?></td>
                                <td class="p-2 border-b"><?= htmlspecialchars($r['due_date']) ?></td>
                                <td class="p-2 border-b">
                                    <?php if (!empty($r['sms_status'])): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs <?= $r['sms_status'] === 'SENT' ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' ?>">
                                            <?= htmlspecialchars($r['sms_status']) ?>
                                        </span>
                                        <div class="text-[11px] text-slate-500 mt-1">
                                            <?= htmlspecialchars(date('d M, H:i', strtotime($r['sms_sent_at']))) ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-xs text-slate-400">Not sent</span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-2 border-b">
                                    <form method="POST" class="inline-flex flex-wrap gap-2">
                                        <?= mch_csrf_input('nurse_dashboard_sms') ?>
                                        <input type="hidden" name="action" value="send_vaccine_sms">
                                        <input type="hidden" name="baby_id" value="<?= (int)$r['baby_id'] ?>">
                                        <input type="hidden" name="vaccine" value="<?= htmlspecialchars($r['vaccine'], ENT_QUOTES) ?>">
                                        <input type="hidden" name="due_date" value="<?= htmlspecialchars($r['due_date'], ENT_QUOTES) ?>">
                                        <input type="hidden" name="phone" value="<?= htmlspecialchars($r['mother_phone'], ENT_QUOTES) ?>">
                                        <input type="hidden" name="mother_name" value="<?= htmlspecialchars($r['mother_name'], ENT_QUOTES) ?>">
                                        <input type="hidden" name="baby_name" value="<?= htmlspecialchars($r['baby_name'], ENT_QUOTES) ?>">
                                        <button class="inline-flex items-center px-3 py-1 rounded-lg bg-emerald-50 border border-emerald-200 hover:bg-emerald-100 text-sm">
                                            Send SMS
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="flex flex-wrap items-center gap-2 text-xs text-slate-600 mt-4">
            <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200">
                <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                Up to date
            </span>
            <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-amber-50 text-amber-700 border border-amber-200">
                <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                Upcoming
            </span>
            <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-rose-50 text-rose-700 border border-rose-200">
                <span class="w-2 h-2 rounded-full bg-rose-500"></span>
                Overdue / High risk
            </span>
        </div>
    </div>
</div>

<?php include __DIR__ . "/../includes/footer.php"; ?>
