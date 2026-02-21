<?php
require_once __DIR__ . "/../config/session.php";
mch_session_start();
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/sms.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/scope.php";
require_once __DIR__ . "/../config/csrf.php";

// Access check
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'nurse'])) {
    header("Location: /mch_system/auth/login.php");
    exit;
}

$role = $_SESSION['role'];
$full_name = $_SESSION['full_name'] ?? 'User';
$sms_error = '';
$sms_success = '';

// Optional feature: SMS logging table (immunization reminders).
$smsLogsAvailable = false;
try {
    $smsLogsAvailable = (bool)$pdo->query("SHOW TABLES LIKE 'sms_logs'")->fetchColumn();
} catch (Throwable $e) {
    $smsLogsAvailable = false;
}

// ====================
// Dashboard Stats
// ====================
$scopedClinicId = mch_scope_scoped_clinic_id();
if ($role === 'nurse' && $scopedClinicId <= 0) {
    die("Your account is not assigned to a clinic. Please contact Admin.");
}

$scalar = function (string $sql, array $params = []) use ($pdo) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
};
$all = function (string $sql, array $params = []) use ($pdo) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
};

$totalMothers = $scopedClinicId > 0
    ? (int)$scalar("SELECT COUNT(*) FROM mothers m JOIN users u ON u.id = m.user_id WHERE u.clinic_id = ?", [$scopedClinicId])
    : (int)$scalar("SELECT COUNT(*) FROM mothers");
$totalBabies = $scopedClinicId > 0
    ? (int)$scalar("
        SELECT COUNT(*)
        FROM babies b
        JOIN mothers m ON b.mother_id = m.id
        JOIN users u ON u.id = m.user_id
        WHERE u.clinic_id = ?
      ", [$scopedClinicId])
    : (int)$scalar("SELECT COUNT(*) FROM babies");

$ancDueToday = $scopedClinicId > 0
    ? (int)$scalar("
        SELECT COUNT(*)
        FROM anc_visits a
        JOIN mothers m ON a.mother_id = m.id
        JOIN users u ON u.id = m.user_id
        WHERE a.next_visit_date = CURDATE()
          AND u.clinic_id = ?
      ", [$scopedClinicId])
    : (int)$scalar("SELECT COUNT(*) FROM anc_visits WHERE next_visit_date = CURDATE()");
$ancDefaulters = $scopedClinicId > 0
    ? (int)$scalar("
        SELECT COUNT(*)
        FROM anc_visits a
        JOIN mothers m ON a.mother_id = m.id
        JOIN users u ON u.id = m.user_id
        WHERE a.next_visit_date < CURDATE()
          AND a.status = 'PENDING'
          AND u.clinic_id = ?
      ", [$scopedClinicId])
    : (int)$scalar("SELECT COUNT(*) FROM anc_visits WHERE next_visit_date < CURDATE() AND status = 'PENDING'");

$immunDueToday = $scopedClinicId > 0
    ? (int)$scalar("
        SELECT COUNT(*)
        FROM immunizations i
        JOIN babies b ON i.baby_id = b.id
        JOIN mothers m ON b.mother_id = m.id
        JOIN users u ON u.id = m.user_id
        WHERE i.due_date = CURDATE()
          AND i.status = 'PENDING'
          AND u.clinic_id = ?
      ", [$scopedClinicId])
    : (int)$scalar("SELECT COUNT(*) FROM immunizations WHERE due_date = CURDATE() AND status = 'PENDING'");
$immunDefaulters = $scopedClinicId > 0
    ? (int)$scalar("
        SELECT COUNT(*)
        FROM immunizations i
        JOIN babies b ON i.baby_id = b.id
        JOIN mothers m ON b.mother_id = m.id
        JOIN users u ON u.id = m.user_id
        WHERE i.due_date < CURDATE()
          AND i.status = 'PENDING'
          AND u.clinic_id = ?
      ", [$scopedClinicId])
    : (int)$scalar("SELECT COUNT(*) FROM immunizations WHERE due_date < CURDATE() AND status = 'PENDING'");
$immunDueSoon = $scopedClinicId > 0
    ? (int)$scalar("
        SELECT COUNT(*)
        FROM immunizations i
        JOIN babies b ON i.baby_id = b.id
        JOIN mothers m ON b.mother_id = m.id
        JOIN users u ON u.id = m.user_id
        WHERE i.status = 'PENDING'
          AND i.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
          AND u.clinic_id = ?
      ", [$scopedClinicId])
    : (int)$scalar("SELECT COUNT(*) FROM immunizations WHERE due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND status = 'PENDING'");

$tasksEnabled = false;
try {
    $tasksEnabled = (bool)$pdo->query("SHOW TABLES LIKE 'follow_up_tasks'")->fetchColumn();
} catch (Throwable $e) {
    $tasksEnabled = false;
}
$openTasks = 0;
if ($tasksEnabled && (int)($_SESSION['user_id'] ?? 0) > 0) {
    $uid = (int)$_SESSION['user_id'];
    $openTasks = $scopedClinicId > 0
        ? (int)$scalar("SELECT COUNT(*) FROM follow_up_tasks WHERE status = 'OPEN' AND clinic_id = ? AND assigned_to = ?", [$scopedClinicId, $uid])
        : (int)$scalar("SELECT COUNT(*) FROM follow_up_tasks WHERE status = 'OPEN' AND assigned_to = ?", [$uid]);
}

$pncTotal = $scopedClinicId > 0
    ? (int)$scalar("
        SELECT COUNT(*)
        FROM pnc_visits p
        JOIN mothers m ON p.mother_id = m.id
        JOIN users u ON u.id = m.user_id
        WHERE u.clinic_id = ?
      ", [$scopedClinicId])
    : (int)$scalar("SELECT COUNT(*) FROM pnc_visits");
$pncThisMonth = $scopedClinicId > 0
    ? (int)$scalar("
        SELECT COUNT(*)
        FROM pnc_visits p
        JOIN mothers m ON p.mother_id = m.id
        JOIN users u ON u.id = m.user_id
        WHERE p.visit_date BETWEEN DATE_FORMAT(CURDATE(), '%Y-%m-01') AND LAST_DAY(CURDATE())
          AND u.clinic_id = ?
      ", [$scopedClinicId])
    : (int)$scalar("SELECT COUNT(*) FROM pnc_visits WHERE visit_date BETWEEN DATE_FORMAT(CURDATE(), '%Y-%m-01') AND LAST_DAY(CURDATE())");
$pncLast7Days = $scopedClinicId > 0
    ? (int)$scalar("
        SELECT COUNT(*)
        FROM pnc_visits p
        JOIN mothers m ON p.mother_id = m.id
        JOIN users u ON u.id = m.user_id
        WHERE p.visit_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND CURDATE()
          AND u.clinic_id = ?
      ", [$scopedClinicId])
    : (int)$scalar("SELECT COUNT(*) FROM pnc_visits WHERE visit_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND CURDATE()");

$recentMothers = $scopedClinicId > 0
    ? $all("
        SELECT m.id, m.fullname, m.hospital_number, m.pregnancy_status, m.created_at
        FROM mothers m
        JOIN users u ON u.id = m.user_id
        WHERE u.clinic_id = ?
        ORDER BY m.created_at DESC
        LIMIT 10
      ", [$scopedClinicId])
    : $all("
        SELECT id, fullname, hospital_number, pregnancy_status, created_at
        FROM mothers
        ORDER BY created_at DESC
        LIMIT 10
      ");

$recentBabies = $scopedClinicId > 0
    ? $all("
        SELECT b.id, b.baby_name, b.dob, b.gender, m.fullname AS mother_name
        FROM babies b
        JOIN mothers m ON b.mother_id = m.id
        JOIN users u ON u.id = m.user_id
        WHERE u.clinic_id = ?
        ORDER BY b.id DESC
        LIMIT 10
      ", [$scopedClinicId])
    : $all("
        SELECT b.id, b.baby_name, b.dob, b.gender, m.fullname AS mother_name
        FROM babies b
        JOIN mothers m ON b.mother_id = m.id
        ORDER BY b.id DESC
        LIMIT 10
      ");

$ancDueTodayList = $scopedClinicId > 0
    ? $all("
        SELECT m.id, m.fullname, m.hospital_number, a.next_visit_date
        FROM anc_visits a
        JOIN mothers m ON a.mother_id = m.id
        JOIN users u ON u.id = m.user_id
        WHERE a.next_visit_date = CURDATE()
          AND a.status = 'PENDING'
          AND u.clinic_id = ?
        ORDER BY m.fullname
        LIMIT 10
      ", [$scopedClinicId])
    : $all("
        SELECT m.id, m.fullname, m.hospital_number, a.next_visit_date
        FROM anc_visits a
        JOIN mothers m ON a.mother_id = m.id
        WHERE a.next_visit_date = CURDATE() AND a.status = 'PENDING'
        ORDER BY m.fullname
        LIMIT 10
      ");

$ancDefaultersList = $scopedClinicId > 0
    ? $all("
        SELECT m.id, m.fullname, m.hospital_number, a.next_visit_date
        FROM anc_visits a
        JOIN mothers m ON a.mother_id = m.id
        JOIN users u ON u.id = m.user_id
        WHERE a.next_visit_date < CURDATE()
          AND a.status = 'PENDING'
          AND u.clinic_id = ?
        ORDER BY a.next_visit_date ASC
        LIMIT 10
      ", [$scopedClinicId])
    : $all("
        SELECT m.id, m.fullname, m.hospital_number, a.next_visit_date
        FROM anc_visits a
        JOIN mothers m ON a.mother_id = m.id
        WHERE a.next_visit_date < CURDATE() AND a.status = 'PENDING'
        ORDER BY a.next_visit_date ASC
        LIMIT 10
      ");

$immunDueTodayList = $scopedClinicId > 0
    ? $all("
        SELECT b.id, b.baby_name, m.fullname AS mother_name, i.vaccine, i.due_date
        FROM immunizations i
        JOIN babies b ON i.baby_id = b.id
        JOIN mothers m ON b.mother_id = m.id
        JOIN users u ON u.id = m.user_id
        WHERE i.due_date = CURDATE()
          AND i.status = 'PENDING'
          AND u.clinic_id = ?
        ORDER BY i.due_date, b.baby_name
        LIMIT 10
      ", [$scopedClinicId])
    : $all("
        SELECT b.id, b.baby_name, m.fullname AS mother_name, i.vaccine, i.due_date
        FROM immunizations i
        JOIN babies b ON i.baby_id = b.id
        JOIN mothers m ON b.mother_id = m.id
        WHERE i.due_date = CURDATE() AND i.status = 'PENDING'
        ORDER BY i.due_date, b.baby_name
        LIMIT 10
      ");

$immunDefaultersList = $scopedClinicId > 0
    ? $all("
        SELECT b.id, b.baby_name, m.fullname AS mother_name, i.vaccine, i.due_date
        FROM immunizations i
        JOIN babies b ON i.baby_id = b.id
        JOIN mothers m ON b.mother_id = m.id
        JOIN users u ON u.id = m.user_id
        WHERE i.due_date < CURDATE()
          AND i.status = 'PENDING'
          AND u.clinic_id = ?
        ORDER BY i.due_date ASC
        LIMIT 10
      ", [$scopedClinicId])
    : $all("
        SELECT b.id, b.baby_name, m.fullname AS mother_name, i.vaccine, i.due_date
        FROM immunizations i
        JOIN babies b ON i.baby_id = b.id
        JOIN mothers m ON b.mother_id = m.id
        WHERE i.due_date < CURDATE() AND i.status = 'PENDING'
        ORDER BY i.due_date ASC
        LIMIT 10
      ");

$pncThisMonthList = $scopedClinicId > 0
    ? $all("
        SELECT p.visit_date, m.fullname AS mother_name, b.baby_name
        FROM pnc_visits p
        JOIN mothers m ON p.mother_id = m.id
        JOIN users u ON u.id = m.user_id
        LEFT JOIN babies b ON p.baby_id = b.id
        WHERE p.visit_date BETWEEN DATE_FORMAT(CURDATE(), '%Y-%m-01') AND LAST_DAY(CURDATE())
          AND u.clinic_id = ?
        ORDER BY p.visit_date DESC
        LIMIT 10
      ", [$scopedClinicId])
    : $all("
        SELECT p.visit_date, m.fullname AS mother_name, b.baby_name
        FROM pnc_visits p
        JOIN mothers m ON p.mother_id = m.id
        LEFT JOIN babies b ON p.baby_id = b.id
        WHERE p.visit_date BETWEEN DATE_FORMAT(CURDATE(), '%Y-%m-01') AND LAST_DAY(CURDATE())
        ORDER BY p.visit_date DESC
        LIMIT 10
      ");

$pncLast7DaysList = $scopedClinicId > 0
    ? $all("
        SELECT p.visit_date, m.fullname AS mother_name, b.baby_name
        FROM pnc_visits p
        JOIN mothers m ON p.mother_id = m.id
        JOIN users u ON u.id = m.user_id
        LEFT JOIN babies b ON p.baby_id = b.id
        WHERE p.visit_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND CURDATE()
          AND u.clinic_id = ?
        ORDER BY p.visit_date DESC
        LIMIT 10
      ", [$scopedClinicId])
    : $all("
        SELECT p.visit_date, m.fullname AS mother_name, b.baby_name
        FROM pnc_visits p
        JOIN mothers m ON p.mother_id = m.id
        LEFT JOIN babies b ON p.baby_id = b.id
        WHERE p.visit_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND CURDATE()
        ORDER BY p.visit_date DESC
        LIMIT 10
      ");

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

// Phase 2: Daily stats
$appointmentsToday = $ancDueToday + $immunDueToday;
$clinic_id = $scopedClinicId > 0 ? $scopedClinicId : null;
if ($scopedClinicId > 0) {
    $waitingStmt = $pdo->prepare("
        SELECT
          SUM(CASE WHEN email_verified = 1 THEN 1 ELSE 0 END) AS verified,
          SUM(CASE WHEN email_verified = 0 OR email_verified IS NULL THEN 1 ELSE 0 END) AS unverified
        FROM users
        WHERE role = 'mother'
          AND status IN ('PENDING','APPROVED')
          AND clinic_id = ?
    ");
    $waitingStmt->execute([$scopedClinicId]);
} else {
    $waitingStmt = $pdo->prepare("
        SELECT
          SUM(CASE WHEN email_verified = 1 THEN 1 ELSE 0 END) AS verified,
          SUM(CASE WHEN email_verified = 0 OR email_verified IS NULL THEN 1 ELSE 0 END) AS unverified
        FROM users
        WHERE role = 'mother'
          AND status IN ('PENDING','APPROVED')
    ");
    $waitingStmt->execute();
}
$waitingRow = $waitingStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$waitingVerified = (int)($waitingRow['verified'] ?? 0);
$waitingUnverified = (int)($waitingRow['unverified'] ?? 0);
$waitingMothers = $waitingVerified + $waitingUnverified;
if ($scopedClinicId > 0) {
    $unlinkedStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM users u
        LEFT JOIN mothers m ON m.user_id = u.id
        WHERE u.role = 'mother'
          AND u.status = 'ACTIVE'
          AND m.id IS NULL
          AND u.clinic_id = ?
    ");
    $unlinkedStmt->execute([$scopedClinicId]);
} else {
    $unlinkedStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM users u
        LEFT JOIN mothers m ON m.user_id = u.id
        WHERE u.role = 'mother'
          AND u.status = 'ACTIVE'
          AND m.id IS NULL
    ");
    $unlinkedStmt->execute();
}
$unlinkedMothers = (int)$unlinkedStmt->fetchColumn();

// High-risk definition: no ANC schedule OR overdue ANC visit (PENDING)
if ($scopedClinicId > 0) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM mothers m
        JOIN users u ON u.id = m.user_id
        WHERE u.clinic_id = ?
          AND (
            NOT EXISTS (
                SELECT 1 FROM anc_visits a WHERE a.mother_id = m.id
            )
            OR EXISTS (
                SELECT 1
                FROM anc_visits a
                WHERE a.mother_id = m.id
                  AND a.next_visit_date < CURDATE()
                  AND a.status = 'PENDING'
            )
          )
    ");
    $stmt->execute([$scopedClinicId]);
    $highRiskMothers = (int)$stmt->fetchColumn();
} else {
    $highRiskMothers = (int)$pdo->query("
        SELECT COUNT(*)
        FROM mothers m
        WHERE NOT EXISTS (
            SELECT 1 FROM anc_visits a WHERE a.mother_id = m.id
        )
        OR EXISTS (
            SELECT 1
            FROM anc_visits a
            WHERE a.mother_id = m.id
              AND a.next_visit_date < CURDATE()
              AND a.status = 'PENDING'
        )
    ")->fetchColumn();
}

// Daily booking detail lists (for dashboard modals)
$activationQueueListStmt = $scopedClinicId > 0
    ? $pdo->prepare("
        SELECT id, full_name, phone, email, created_at, email_verified, status
        FROM users
        WHERE role = 'mother'
          AND status IN ('PENDING','APPROVED')
          AND clinic_id = ?
        ORDER BY created_at DESC
        LIMIT 10
    ")
    : $pdo->prepare("
        SELECT id, full_name, phone, email, created_at, email_verified, status
        FROM users
        WHERE role = 'mother'
          AND status IN ('PENDING','APPROVED')
        ORDER BY created_at DESC
        LIMIT 10
    ");
$activationQueueListStmt->execute($scopedClinicId > 0 ? [$scopedClinicId] : []);
$activationQueueList = $activationQueueListStmt->fetchAll(PDO::FETCH_ASSOC);

$unlinkedActiveListStmt = $scopedClinicId > 0
    ? $pdo->prepare("
        SELECT u.id, u.full_name, u.phone, u.email, u.created_at, u.email_verified
        FROM users u
        LEFT JOIN mothers m ON m.user_id = u.id
        WHERE u.role = 'mother'
          AND u.status = 'ACTIVE'
          AND m.id IS NULL
          AND u.clinic_id = ?
        ORDER BY u.created_at DESC
        LIMIT 10
    ")
    : $pdo->prepare("
        SELECT u.id, u.full_name, u.phone, u.email, u.created_at, u.email_verified
        FROM users u
        LEFT JOIN mothers m ON m.user_id = u.id
        WHERE u.role = 'mother'
          AND u.status = 'ACTIVE'
          AND m.id IS NULL
        ORDER BY u.created_at DESC
        LIMIT 10
    ");
$unlinkedActiveListStmt->execute($scopedClinicId > 0 ? [$scopedClinicId] : []);
$unlinkedActiveList = $unlinkedActiveListStmt->fetchAll(PDO::FETCH_ASSOC);

$highRiskMothersListStmt = $scopedClinicId > 0
    ? $pdo->prepare("
        SELECT m.id, m.fullname, m.hospital_number,
               (
                   SELECT MIN(a2.next_visit_date)
                   FROM anc_visits a2
                   WHERE a2.mother_id = m.id
               ) AS next_visit_date,
               (
                   SELECT MIN(a3.next_visit_date)
                   FROM anc_visits a3
                   WHERE a3.mother_id = m.id
                     AND a3.next_visit_date < CURDATE()
                     AND a3.status = 'PENDING'
               ) AS overdue_since
        FROM mothers m
        JOIN users u ON u.id = m.user_id
        WHERE u.clinic_id = ?
          AND (
            NOT EXISTS (
                SELECT 1 FROM anc_visits a WHERE a.mother_id = m.id
            )
            OR EXISTS (
                SELECT 1
                FROM anc_visits a
                WHERE a.mother_id = m.id
                  AND a.next_visit_date < CURDATE()
                  AND a.status = 'PENDING'
            )
          )
        ORDER BY (overdue_since IS NULL) ASC, overdue_since ASC, m.fullname ASC
        LIMIT 10
    ")
    : $pdo->prepare("
        SELECT m.id, m.fullname, m.hospital_number,
               (
                   SELECT MIN(a2.next_visit_date)
                   FROM anc_visits a2
                   WHERE a2.mother_id = m.id
               ) AS next_visit_date,
               (
                   SELECT MIN(a3.next_visit_date)
                   FROM anc_visits a3
                   WHERE a3.mother_id = m.id
                     AND a3.next_visit_date < CURDATE()
                     AND a3.status = 'PENDING'
               ) AS overdue_since
        FROM mothers m
        WHERE NOT EXISTS (
            SELECT 1 FROM anc_visits a WHERE a.mother_id = m.id
        )
        OR EXISTS (
            SELECT 1
            FROM anc_visits a
            WHERE a.mother_id = m.id
              AND a.next_visit_date < CURDATE()
              AND a.status = 'PENDING'
        )
        ORDER BY (overdue_since IS NULL) ASC, overdue_since ASC, m.fullname ASC
        LIMIT 10
    ");
$highRiskMothersListStmt->execute($scopedClinicId > 0 ? [$scopedClinicId] : []);
$highRiskMothersList = $highRiskMothersListStmt->fetchAll(PDO::FETCH_ASSOC);

// Clinic name (if assigned)
$clinicName = 'Clinic';
if (!empty($clinic_id)) {
    $cstmt = $pdo->prepare("SELECT clinic_name FROM clinics WHERE id = ? LIMIT 1");
    $cstmt->execute([$clinic_id]);
    $crow = $cstmt->fetch(PDO::FETCH_ASSOC);
    if ($crow && !empty($crow['clinic_name'])) {
        $clinicName = $crow['clinic_name'];
    }
}

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
            // Prevent sending reminders for babies outside the current clinic (privacy).
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

// ====================
// Mother List
// ====================
$mothers = $pdo->query("SELECT id, fullname, hospital_number, pregnancy_status FROM mothers ORDER BY fullname")->fetchAll(PDO::FETCH_ASSOC);

// ====================
// Baby List
// ====================
$babies = $pdo->query("
    SELECT b.id, b.baby_name, b.dob, b.gender, m.fullname AS mother_name
    FROM babies b
    JOIN mothers m ON b.mother_id = m.id
    ORDER BY b.dob DESC
")->fetchAll(PDO::FETCH_ASSOC);

$weightStmt = $scopedClinicId > 0
    ? $pdo->prepare("
        SELECT b.birth_weight
        FROM babies b
        JOIN mothers m ON b.mother_id = m.id
        JOIN users u ON m.user_id = u.id
        WHERE u.clinic_id = ?
          AND b.birth_weight IS NOT NULL
    ")
    : $pdo->prepare("
        SELECT birth_weight
        FROM babies
        WHERE birth_weight IS NOT NULL
    ");
$weightStmt->execute($scopedClinicId > 0 ? [$scopedClinicId] : []);
$weightRows = $weightStmt->fetchAll(PDO::FETCH_COLUMN);

$weightLimitStmt = $scopedClinicId > 0
    ? $pdo->prepare("
        SELECT bw.weight_kg, bw.observed_date, b.baby_name
        FROM baby_weights bw
        JOIN babies b ON bw.baby_id = b.id
        JOIN mothers m ON b.mother_id = m.id
        JOIN users u ON m.user_id = u.id
        WHERE u.clinic_id = ?
        ORDER BY bw.observed_date DESC, bw.created_at DESC
        LIMIT 3
    ")
    : $pdo->prepare("
        SELECT bw.weight_kg, bw.observed_date, b.baby_name
        FROM baby_weights bw
        JOIN babies b ON bw.baby_id = b.id
        ORDER BY bw.observed_date DESC, bw.created_at DESC
        LIMIT 3
    ");
$weightLimitStmt->execute($scopedClinicId > 0 ? [$scopedClinicId] : []);
$recentWeights = $weightLimitStmt->fetchAll(PDO::FETCH_ASSOC);

$bucketDefs = [
    ['label' => '<2.5 kg',   'min' => 0,   'max' => 2.5, 'count' => 0],
    ['label' => '2.5–3.0 kg','min' => 2.5, 'max' => 3.0, 'count' => 0],
    ['label' => '3.0–3.5 kg','min' => 3.0, 'max' => 3.5, 'count' => 0],
    ['label' => '3.5–4.0 kg','min' => 3.5, 'max' => 4.0, 'count' => 0],
    ['label' => '4+ kg',     'min' => 4.0, 'max' => 999, 'count' => 0],
];
$weightTotal = 0;
foreach ($weightRows as $row) {
    $w = is_numeric($row) ? (float)$row : 0.0;
    if ($w <= 0) {
        continue;
    }
    foreach ($bucketDefs as &$bucket) {
        if ($w >= $bucket['min'] && $w < $bucket['max']) {
            $bucket['count']++;
            $weightTotal++;
            break;
        }
    }
    unset($bucket);
}


include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_nurse.php";
?>

<div id="dashboard_top" class="ml-64 p-6 space-y-8">

    <div class="mch-card bg-white/90 p-4 border border-slate-100 flex flex-col md:flex-row md:items-center md:justify-between gap-3">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-teal-700 text-white flex items-center justify-center font-semibold">
                NC
            </div>
            <div>
                <div class="text-sm text-slate-500"><?= htmlspecialchars($clinicName) ?></div>
                <div class="text-lg font-semibold text-slate-900">Nurse Dashboard</div>
            </div>
        </div>
        <div class="flex items-center gap-3 text-sm">
            <span class="text-slate-600"><?= date("l, d M Y") ?></span>
            <a href="/mch_system/nurse_portal/tasks.php"
               class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-sky-50 text-sky-700 border border-sky-200 hover:bg-sky-100"
               title="Open follow-up tasks">
                <span class="w-2 h-2 rounded-full <?= $openTasks > 0 ? 'bg-sky-500' : 'bg-slate-400' ?>"></span>
                Tasks: <?= (int)$openTasks ?>
            </a>
            <span id="mch_conn_badge" class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200" title="Connection status">
                <span id="mch_conn_dot" class="w-2 h-2 rounded-full bg-emerald-500"></span>
                <span id="mch_conn_label">Online</span>
            </span>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-5">
        <button type="button" data-modal-target="modal-registered-mothers" data-modal-title="Registered Mothers" class="mch-card bg-white/90 p-5 border border-slate-100 text-left hover:ring-2 hover:ring-emerald-200 transition">
            <p class="text-xs uppercase tracking-wide text-slate-500">Registered Mothers</p>
            <p class="text-3xl font-semibold text-slate-900 mt-2"><?= $totalMothers ?></p>
            <p class="text-xs text-slate-400 mt-2">Click to view details</p>
        </button>
        <button type="button" data-modal-target="modal-registered-babies" data-modal-title="Registered Babies" class="mch-card bg-white/90 p-5 border border-slate-100 text-left hover:ring-2 hover:ring-emerald-200 transition">
            <p class="text-xs uppercase tracking-wide text-slate-500">Registered Babies</p>
            <p class="text-3xl font-semibold text-slate-900 mt-2"><?= $totalBabies ?></p>
            <p class="text-xs text-slate-400 mt-2">Click to view details</p>
        </button>
        <button type="button" data-modal-target="modal-appointments-today" data-modal-title="Total Appointments Today" class="mch-card bg-white/90 p-5 border border-slate-100 text-left hover:ring-2 hover:ring-emerald-200 transition">
            <p class="text-xs uppercase tracking-wide text-slate-500">Total Appointments Today</p>
            <p id="mch_nurse_stat_appointments" class="text-3xl font-semibold text-slate-900 mt-2"><?= (int)$appointmentsToday ?></p>
            <p class="text-xs text-slate-400 mt-2">Click to view details</p>
        </button>
        <button type="button" data-modal-target="modal-activation-waiting" data-modal-title="Activation Waiting" class="mch-card bg-white/90 p-5 border border-amber-100 text-left hover:ring-2 hover:ring-amber-200 transition">
            <p class="text-xs uppercase tracking-wide text-amber-500">Activation Waiting</p>
            <p id="mch_nurse_stat_waiting" class="text-3xl font-semibold text-amber-700 mt-2"><?= (int)($waitingMothers + $unlinkedMothers) ?></p>
            <p class="text-xs text-amber-400 mt-2">Click to view details</p>
        </button>
        <button type="button" data-modal-target="modal-high-risk-mothers" data-modal-title="High-Risk Mothers" class="mch-card bg-white/90 p-5 border border-rose-100 text-left hover:ring-2 hover:ring-rose-200 transition">
            <p class="text-xs uppercase tracking-wide text-rose-500">High-Risk Mothers</p>
            <p id="mch_nurse_stat_high_risk" class="text-3xl font-semibold text-rose-700 mt-2"><?= (int)$highRiskMothers ?></p>
            <p class="text-xs text-rose-400 mt-2">Click to view details</p>
        </button>
        <button type="button" data-modal-target="modal-pnc-last7days" data-modal-title="PNC Visits (Last 7 Days)" class="mch-card bg-white/90 p-5 border border-purple-100 text-left hover:ring-2 hover:ring-purple-200 transition">
            <p class="text-xs uppercase tracking-wide text-purple-500">PNC Visits (Last 7 Days)</p>
            <p id="mch_nurse_stat_pnc7" class="text-3xl font-semibold text-purple-700 mt-2"><?= (int)$pncLast7Days ?></p>
            <p class="text-xs text-purple-400 mt-2">Click to view details</p>
        </button>
        <button type="button" data-modal-target="modal-anc-due-today" data-modal-title="ANC Due Today" class="mch-card bg-white/90 p-5 border border-amber-100 text-left hover:ring-2 hover:ring-amber-200 transition">
            <p class="text-xs uppercase tracking-wide text-amber-500">ANC Due Today</p>
            <p class="text-3xl font-semibold text-amber-700 mt-2"><?= $ancDueToday ?></p>
            <p class="text-xs text-amber-400 mt-2">Click to view details</p>
        </button>
        <button type="button" data-modal-target="modal-anc-defaulters" data-modal-title="ANC Defaulters" class="mch-card bg-white/90 p-5 border border-rose-100 text-left hover:ring-2 hover:ring-rose-200 transition">
            <p class="text-xs uppercase tracking-wide text-rose-500">ANC Defaulters</p>
            <p class="text-3xl font-semibold text-rose-700 mt-2"><?= $ancDefaulters ?></p>
            <p class="text-xs text-rose-400 mt-2">Click to view details</p>
        </button>
        <button type="button" data-modal-target="modal-immun-due-today" data-modal-title="Immunizations Due Today" class="mch-card bg-white/90 p-5 border border-sky-100 text-left hover:ring-2 hover:ring-sky-200 transition">
            <p class="text-xs uppercase tracking-wide text-sky-500">Immunizations Due Today</p>
            <p class="text-3xl font-semibold text-sky-700 mt-2"><?= $immunDueToday ?></p>
            <p class="text-xs text-sky-400 mt-2">Click to view details</p>
        </button>
        <button type="button" data-modal-target="modal-immun-defaulters" data-modal-title="Immunization Defaulters" class="mch-card bg-white/90 p-5 border border-rose-100 text-left hover:ring-2 hover:ring-rose-200 transition">
            <p class="text-xs uppercase tracking-wide text-rose-500">Immunization Defaulters</p>
            <p class="text-3xl font-semibold text-rose-700 mt-2"><?= $immunDefaulters ?></p>
            <p class="text-xs text-rose-400 mt-2">Click to view details</p>
        </button>
        <button type="button" data-modal-target="modal-pnc-this-month" data-modal-title="PNC Visits (This Month)" class="mch-card bg-white/90 p-5 border border-purple-100 text-left hover:ring-2 hover:ring-purple-200 transition">
            <p class="text-xs uppercase tracking-wide text-purple-500">PNC Visits (This Month)</p>
            <p class="text-3xl font-semibold text-purple-700 mt-2"><?= $pncThisMonth ?></p>
            <p class="text-xs text-purple-400 mt-2">Click to view details</p>
        </button>
    </div>

    <div class="flex flex-wrap items-center gap-2 text-xs text-slate-600">
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

    <div class="mch-card bg-white/90 p-6 border border-slate-100 hidden">
        <h2 class="text-lg font-semibold text-slate-800 mb-4">Daily Booking Stats</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                <p class="text-xs uppercase tracking-wide text-slate-500">Total Appointments Today</p>
                <p class="text-3xl font-semibold text-slate-900 mt-2"><?= $appointmentsToday ?></p>
            </div>
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-4">
                <p class="text-xs uppercase tracking-wide text-amber-600">Activation Waiting</p>
                <p class="text-3xl font-semibold text-amber-700 mt-2"><?= (int)($waitingMothers + $unlinkedMothers) ?></p>
                <p class="text-xs text-amber-700/80 mt-1">
                    Booking queue: <?= (int)$waitingMothers ?> (V <?= (int)$waitingVerified ?> &middot; U <?= (int)$waitingUnverified ?>) &middot; Unlinked: <?= (int)$unlinkedMothers ?>
                    <!--
                    Verified <?= (int)$waitingVerified ?> · Unverified <?= (int)$waitingUnverified ?>
                    --></p>
            </div>
            <div class="rounded-xl border border-rose-200 bg-rose-50 p-4">
                <p class="text-xs uppercase tracking-wide text-rose-600">High-Risk Mothers</p>
                <p class="text-3xl font-semibold text-rose-700 mt-2"><?= $highRiskMothers ?></p>
            </div>
            <div class="rounded-xl border border-purple-200 bg-purple-50 p-4">
                <p class="text-xs uppercase tracking-wide text-purple-600">PNC Visits (Last 7 Days)</p>
                <p class="text-3xl font-semibold text-purple-700 mt-2"><?= $pncLast7Days ?></p>
            </div>
        </div>
    </div>

    <!-- Quick Links -->
    <div id="quick_links" class="mch-card bg-white/90 p-6 border border-slate-100">
        <h2 class="text-lg font-semibold text-slate-800 mb-4">Quick Links</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
            <a href="/mch_system/nurse_portal/mother_list.php" class="p-4 rounded-xl border border-slate-200 bg-slate-50 hover:bg-slate-100 text-center font-semibold">
                Mother List
            </a>
            <a href="/mch_system/nurse_portal/babies_list.php" class="p-4 rounded-xl border border-slate-200 bg-slate-50 hover:bg-slate-100 text-center font-semibold">
                Baby List
            </a>
            <a href="/mch_system/nurse_portal/vaccine_reminders.php" class="p-4 rounded-xl border border-slate-200 bg-slate-50 hover:bg-slate-100 text-center font-semibold">
                Vaccine Reminders
            </a>
    </div>
  </div>

    <div class="mch-card bg-white/90 p-6 border border-slate-100">
        <div class="flex items-center justify-between">
            <h2 class="text-lg font-semibold text-slate-800">Baby Weight Distribution</h2>
            <span class="text-xs text-slate-500">Based on latest <?= number_format($weightTotal ?: 0) ?> records</span>
        </div>
    <?php if ($weightTotal === 0): ?>
        <div class="text-sm text-slate-500 mt-4">No birth weight data available yet.</div>
    <?php else: ?>
        <div class="space-y-3 mt-4">
                <?php foreach ($bucketDefs as $bucket): ?>
                    <?php
                    $count = (int)$bucket['count'];
                    $pct = $weightTotal ? round(($count / $weightTotal) * 100) : 0;
                    ?>
                    <div class="flex items-center gap-3">
                        <span class="text-xs text-slate-500 w-24"><?= htmlspecialchars($bucket['label']) ?></span>
                        <div class="flex-1 h-2 bg-slate-200 rounded-full overflow-hidden">
                            <div class="h-full bg-teal-500" style="width: <?= $pct ?>%;"></div>
                        </div>
                        <span class="text-xs text-slate-600"><?= $count ?> (<?= $pct ?>%)</span>
                    </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
  </div>

    <div class="mch-card bg-white/90 p-6 border border-slate-100">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-lg font-semibold text-slate-800">Baby's Growth</h2>
                <p class="text-xs text-slate-500">Weights are updated during clinic visits.</p>
            </div>
            <span class="text-xs text-slate-400"><?= count($recentWeights) ?> latest entries</span>
        </div>
        <?php if (empty($recentWeights)): ?>
            <div class="text-sm text-slate-500 mt-4">No weight records captured yet.</div>
        <?php else: ?>
            <ul class="mt-4 space-y-3">
                <?php foreach ($recentWeights as $w): ?>
                    <?php
                    $dateLabel = isset($w['observed_date']) && $w['observed_date'] ? date('d M', strtotime($w['observed_date'])) : '—';
                    $weightValue = is_numeric($w['weight_kg']) ? round((float)$w['weight_kg'], 2) : '-';
                    ?>
                    <li class="flex items-center justify-between">
                        <div>
                            <div class="text-sm font-semibold"><?= htmlspecialchars($w['baby_name'] ?? 'Baby') ?></div>
                            <div class="text-xs text-slate-500"><?= htmlspecialchars($dateLabel) ?> · <?= is_numeric($weightValue) ? htmlspecialchars($weightValue . ' kg') : '—' ?></div>
                        </div>
                        <span class="text-xs text-slate-600"><?= is_numeric($weightValue) ? htmlspecialchars($weightValue . ' kg') : '—' ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <!-- Vaccine reminders, mother/baby tables moved to standalone pages -->
    <!-- Vaccine reminders, mother/baby tables moved to standalone pages -->
</div>

<div id="dashModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-slate-900/40" data-modal-close></div>
    <div class="relative mx-auto my-8 w-[min(900px,94vw)] bg-white rounded-2xl shadow-xl border border-slate-200 p-6">
        <div class="flex items-center justify-between gap-4 mb-4">
            <h3 id="dashModalTitle" class="text-lg font-semibold text-slate-900">Details</h3>
            <button type="button" class="px-3 py-1 rounded-lg border border-slate-200 hover:bg-slate-100 text-sm" data-modal-close>Close</button>
        </div>
        <div id="dashModalBody" class="max-h-[70vh] overflow-y-auto"></div>
    </div>
</div>

<div id="modal-registered-mothers" class="hidden">
    <?php if (empty($recentMothers)): ?>
        <p class="text-slate-500">No mothers registered yet.</p>
    <?php else: ?>
        <table class="w-full text-sm mch-table">
            <thead>
                <tr>
                    <th class="p-2 border-b text-left">Hospital #</th>
                    <th class="p-2 border-b text-left">Full Name</th>
                    <th class="p-2 border-b text-left">Status</th>
                    <th class="p-2 border-b text-left">Registered</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentMothers as $m): ?>
                    <tr>
                        <td class="p-2 border-b"><?= htmlspecialchars($m['hospital_number']) ?></td>
                        <td class="p-2 border-b"><?= htmlspecialchars($m['fullname']) ?></td>
                        <td class="p-2 border-b"><?= htmlspecialchars($m['pregnancy_status']) ?></td>
                        <td class="p-2 border-b text-slate-500"><?= htmlspecialchars(date('d M Y', strtotime($m['created_at']))) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="text-xs text-slate-400 mt-3">Showing latest 10 mothers.</p>
    <?php endif; ?>
</div>

<div id="modal-appointments-today" class="hidden">
    <div class="space-y-6">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-4">
                <div class="text-xs uppercase tracking-wide text-amber-600">ANC Due Today</div>
                <div class="text-2xl font-semibold text-amber-700 mt-1"><?= (int)$ancDueToday ?></div>
            </div>
            <div class="rounded-xl border border-sky-200 bg-sky-50 p-4">
                <div class="text-xs uppercase tracking-wide text-sky-600">Immunizations Due Today</div>
                <div class="text-2xl font-semibold text-sky-700 mt-1"><?= (int)$immunDueToday ?></div>
            </div>
        </div>

        <div>
            <h4 class="text-sm font-semibold text-slate-900 mb-2">ANC Due Today (Top 10)</h4>
            <?php if (empty($ancDueTodayList)): ?>
                <p class="text-slate-500 text-sm">No ANC due today.</p>
            <?php else: ?>
                <table class="w-full text-sm mch-table">
                    <thead>
                        <tr>
                            <th class="p-2 border-b text-left">Hospital #</th>
                            <th class="p-2 border-b text-left">Mother</th>
                            <th class="p-2 border-b text-left">Next Visit</th>
                            <th class="p-2 border-b text-left">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ancDueTodayList as $a): ?>
                            <tr>
                                <td class="p-2 border-b"><?= htmlspecialchars($a['hospital_number']) ?></td>
                                <td class="p-2 border-b"><?= htmlspecialchars($a['fullname']) ?></td>
                                <td class="p-2 border-b"><?= htmlspecialchars($a['next_visit_date']) ?></td>
                                <td class="p-2 border-b">
                                    <a class="inline-flex items-center px-3 py-1 rounded-lg bg-slate-50 border border-slate-200 hover:bg-slate-100 text-sm"
                                       href="/mch_system/nurse_portal/mother_history.php?mother_id=<?= (int)$a['id'] ?>">Open</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div>
            <h4 class="text-sm font-semibold text-slate-900 mb-2">Immunizations Due Today (Top 10)</h4>
            <?php if (empty($immunDueTodayList)): ?>
                <p class="text-slate-500 text-sm">No immunizations due today.</p>
            <?php else: ?>
                <table class="w-full text-sm mch-table">
                    <thead>
                        <tr>
                            <th class="p-2 border-b text-left">Baby</th>
                            <th class="p-2 border-b text-left">Mother</th>
                            <th class="p-2 border-b text-left">Vaccine</th>
                            <th class="p-2 border-b text-left">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($immunDueTodayList as $i): ?>
                            <tr>
                                <td class="p-2 border-b"><?= htmlspecialchars($i['baby_name']) ?></td>
                                <td class="p-2 border-b"><?= htmlspecialchars($i['mother_name']) ?></td>
                                <td class="p-2 border-b"><?= htmlspecialchars($i['vaccine']) ?></td>
                                <td class="p-2 border-b">
                                    <a class="inline-flex items-center px-3 py-1 rounded-lg bg-sky-50 border border-sky-200 hover:bg-sky-100 text-sm"
                                       href="/mch_system/nurse_portal/baby_history.php?baby_id=<?= (int)$i['id'] ?>">Open</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<div id="modal-activation-waiting" class="hidden">
    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 mb-4">
        <p class="text-sm text-amber-800">
            Booking queue: <?= (int)$waitingMothers ?> (V <?= (int)$waitingVerified ?> · U <?= (int)$waitingUnverified ?>) · Unlinked: <?= (int)$unlinkedMothers ?>
        </p>
        <div class="mt-2">
            <a href="/mch_system/nurse_portal/activation_queue.php"
               class="inline-flex items-center px-3 py-1 rounded-lg bg-white border border-amber-200 hover:bg-amber-100 text-sm text-amber-800">
                Open Activation Queue
            </a>
        </div>
    </div>

    <div class="space-y-6">
        <div>
            <h4 class="text-sm font-semibold text-slate-900 mb-2">Booking Queue (Top 10)</h4>
            <?php if (empty($activationQueueList)): ?>
                <p class="text-slate-500 text-sm">No mothers currently in the booking queue.</p>
            <?php else: ?>
                <table class="w-full text-sm mch-table">
                    <thead>
                        <tr>
                            <th class="p-2 border-b text-left">Mother</th>
                            <th class="p-2 border-b text-left">Phone</th>
                            <th class="p-2 border-b text-left">Email</th>
                            <th class="p-2 border-b text-left">Verified</th>
                            <th class="p-2 border-b text-left">Status</th>
                            <th class="p-2 border-b text-left">Registered</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($activationQueueList as $u): ?>
                            <tr>
                                <td class="p-2 border-b"><?= htmlspecialchars($u['full_name']) ?></td>
                                <td class="p-2 border-b"><?= htmlspecialchars($u['phone'] ?? '-') ?></td>
                                <td class="p-2 border-b"><?= htmlspecialchars($u['email'] ?? '-') ?></td>
                                <td class="p-2 border-b">
                                    <?php if ((int)($u['email_verified'] ?? 0) === 1): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-emerald-100 text-emerald-700">Yes</span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-amber-100 text-amber-700">No</span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-2 border-b"><?= htmlspecialchars($u['status']) ?></td>
                                <td class="p-2 border-b text-slate-500"><?= htmlspecialchars(date('d M Y', strtotime($u['created_at']))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div>
            <h4 class="text-sm font-semibold text-slate-900 mb-2">Unlinked ACTIVE Mothers (Top 10)</h4>
            <?php if (empty($unlinkedActiveList)): ?>
                <p class="text-slate-500 text-sm">No unlinked ACTIVE mothers found.</p>
            <?php else: ?>
                <table class="w-full text-sm mch-table">
                    <thead>
                        <tr>
                            <th class="p-2 border-b text-left">Mother</th>
                            <th class="p-2 border-b text-left">Phone</th>
                            <th class="p-2 border-b text-left">Email</th>
                            <th class="p-2 border-b text-left">Verified</th>
                            <th class="p-2 border-b text-left">Registered</th>
                            <th class="p-2 border-b text-left">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($unlinkedActiveList as $u): ?>
                            <tr>
                                <td class="p-2 border-b"><?= htmlspecialchars($u['full_name']) ?></td>
                                <td class="p-2 border-b"><?= htmlspecialchars($u['phone'] ?? '-') ?></td>
                                <td class="p-2 border-b"><?= htmlspecialchars($u['email'] ?? '-') ?></td>
                                <td class="p-2 border-b">
                                    <?php if ((int)($u['email_verified'] ?? 0) === 1): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-emerald-100 text-emerald-700">Yes</span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-amber-100 text-amber-700">No</span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-2 border-b text-slate-500"><?= htmlspecialchars(date('d M Y', strtotime($u['created_at']))) ?></td>
                                <td class="p-2 border-b">
                                    <a class="inline-flex items-center px-3 py-1 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-700 hover:bg-emerald-100 text-sm"
                                       href="/mch_system/nurse_portal/register_mother.php?existing_user_id=<?= (int)$u['id'] ?>">
                                        Activate / Link
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<div id="modal-high-risk-mothers" class="hidden">
    <?php if (empty($highRiskMothersList)): ?>
        <p class="text-slate-500">No high-risk mothers found.</p>
    <?php else: ?>
        <table class="w-full text-sm mch-table">
            <thead>
                <tr>
                    <th class="p-2 border-b text-left">Hospital #</th>
                    <th class="p-2 border-b text-left">Mother</th>
                    <th class="p-2 border-b text-left">Overdue Since</th>
                    <th class="p-2 border-b text-left">Next Visit</th>
                    <th class="p-2 border-b text-left">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($highRiskMothersList as $m): ?>
                    <tr>
                        <td class="p-2 border-b"><?= htmlspecialchars($m['hospital_number'] ?? '-') ?></td>
                        <td class="p-2 border-b"><?= htmlspecialchars($m['fullname']) ?></td>
                        <td class="p-2 border-b"><?= htmlspecialchars($m['overdue_since'] ?? '-') ?></td>
                        <td class="p-2 border-b"><?= htmlspecialchars($m['next_visit_date'] ?? '-') ?></td>
                        <td class="p-2 border-b">
                            <a class="inline-flex items-center px-3 py-1 rounded-lg bg-slate-50 border border-slate-200 hover:bg-slate-100 text-sm"
                               href="/mch_system/nurse_portal/mother_history.php?mother_id=<?= (int)$m['id'] ?>">Open</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="text-xs text-slate-400 mt-3">High risk = no ANC schedule or overdue ANC visit.</p>
    <?php endif; ?>
</div>

<div id="modal-pnc-last7days" class="hidden">
    <?php if (empty($pncLast7DaysList)): ?>
        <p class="text-slate-500">No PNC visits logged in the last 7 days.</p>
    <?php else: ?>
        <table class="w-full text-sm mch-table">
            <thead>
                <tr>
                    <th class="p-2 border-b text-left">Visit Date</th>
                    <th class="p-2 border-b text-left">Mother</th>
                    <th class="p-2 border-b text-left">Baby</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pncLast7DaysList as $p): ?>
                    <tr>
                        <td class="p-2 border-b"><?= htmlspecialchars($p['visit_date']) ?></td>
                        <td class="p-2 border-b"><?= htmlspecialchars($p['mother_name']) ?></td>
                        <td class="p-2 border-b"><?= htmlspecialchars($p['baby_name'] ?? '-') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="text-xs text-slate-400 mt-3">Showing latest 10 PNC visits in the last 7 days.</p>
    <?php endif; ?>
</div>

<div id="modal-registered-babies" class="hidden">
    <?php if (empty($recentBabies)): ?>
        <p class="text-slate-500">No babies registered yet.</p>
    <?php else: ?>
        <table class="w-full text-sm mch-table">
            <thead>
                <tr>
                    <th class="p-2 border-b text-left">Baby</th>
                    <th class="p-2 border-b text-left">DOB</th>
                    <th class="p-2 border-b text-left">Gender</th>
                    <th class="p-2 border-b text-left">Mother</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentBabies as $b): ?>
                    <tr>
                        <td class="p-2 border-b"><?= htmlspecialchars($b['baby_name']) ?></td>
                        <td class="p-2 border-b"><?= htmlspecialchars($b['dob']) ?></td>
                        <td class="p-2 border-b"><?= htmlspecialchars($b['gender']) ?></td>
                        <td class="p-2 border-b"><?= htmlspecialchars($b['mother_name']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="text-xs text-slate-400 mt-3">Showing latest 10 babies.</p>
    <?php endif; ?>
</div>

<div id="modal-anc-due-today" class="hidden">
    <?php if (empty($ancDueTodayList)): ?>
        <p class="text-slate-500">No ANC visits due today.</p>
    <?php else: ?>
        <table class="w-full text-sm mch-table">
            <thead>
                <tr>
                    <th class="p-2 border-b text-left">Hospital #</th>
                    <th class="p-2 border-b text-left">Mother</th>
                    <th class="p-2 border-b text-left">Next Visit</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($ancDueTodayList as $a): ?>
                    <tr>
                        <td class="p-2 border-b"><?= htmlspecialchars($a['hospital_number']) ?></td>
                        <td class="p-2 border-b"><?= htmlspecialchars($a['fullname']) ?></td>
                        <td class="p-2 border-b"><?= htmlspecialchars($a['next_visit_date']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div id="modal-anc-defaulters" class="hidden">
    <?php if (empty($ancDefaultersList)): ?>
        <p class="text-slate-500">No ANC defaulters.</p>
    <?php else: ?>
        <table class="w-full text-sm mch-table">
            <thead>
                <tr>
                    <th class="p-2 border-b text-left">Hospital #</th>
                    <th class="p-2 border-b text-left">Mother</th>
                    <th class="p-2 border-b text-left">Overdue Since</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($ancDefaultersList as $a): ?>
                    <tr>
                        <td class="p-2 border-b"><?= htmlspecialchars($a['hospital_number']) ?></td>
                        <td class="p-2 border-b"><?= htmlspecialchars($a['fullname']) ?></td>
                        <td class="p-2 border-b"><?= htmlspecialchars($a['next_visit_date']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="text-xs text-slate-400 mt-3">Showing top 10 by earliest overdue.</p>
    <?php endif; ?>
</div>

<div id="modal-immun-due-today" class="hidden">
    <?php if (empty($immunDueTodayList)): ?>
        <p class="text-slate-500">No immunizations due today.</p>
    <?php else: ?>
        <table class="w-full text-sm mch-table">
            <thead>
                <tr>
                    <th class="p-2 border-b text-left">Baby</th>
                    <th class="p-2 border-b text-left">Mother</th>
                    <th class="p-2 border-b text-left">Vaccine</th>
                    <th class="p-2 border-b text-left">Due Date</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($immunDueTodayList as $i): ?>
                    <tr>
                        <td class="p-2 border-b"><?= htmlspecialchars($i['baby_name']) ?></td>
                        <td class="p-2 border-b"><?= htmlspecialchars($i['mother_name']) ?></td>
                        <td class="p-2 border-b"><?= htmlspecialchars($i['vaccine']) ?></td>
                        <td class="p-2 border-b"><?= htmlspecialchars($i['due_date']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div id="modal-immun-defaulters" class="hidden">
    <?php if (empty($immunDefaultersList)): ?>
        <p class="text-slate-500">No immunization defaulters.</p>
    <?php else: ?>
        <table class="w-full text-sm mch-table">
            <thead>
                <tr>
                    <th class="p-2 border-b text-left">Baby</th>
                    <th class="p-2 border-b text-left">Mother</th>
                    <th class="p-2 border-b text-left">Vaccine</th>
                    <th class="p-2 border-b text-left">Overdue Since</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($immunDefaultersList as $i): ?>
                    <tr>
                        <td class="p-2 border-b"><?= htmlspecialchars($i['baby_name']) ?></td>
                        <td class="p-2 border-b"><?= htmlspecialchars($i['mother_name']) ?></td>
                        <td class="p-2 border-b"><?= htmlspecialchars($i['vaccine']) ?></td>
                        <td class="p-2 border-b"><?= htmlspecialchars($i['due_date']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="text-xs text-slate-400 mt-3">Showing top 10 by earliest overdue.</p>
    <?php endif; ?>
</div>

<div id="modal-pnc-this-month" class="hidden">
    <?php if (empty($pncThisMonthList)): ?>
        <p class="text-slate-500">No PNC visits logged this month.</p>
    <?php else: ?>
        <table class="w-full text-sm mch-table">
            <thead>
                <tr>
                    <th class="p-2 border-b text-left">Visit Date</th>
                    <th class="p-2 border-b text-left">Mother</th>
                    <th class="p-2 border-b text-left">Baby</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pncThisMonthList as $p): ?>
                    <tr>
                        <td class="p-2 border-b"><?= htmlspecialchars($p['visit_date']) ?></td>
                        <td class="p-2 border-b"><?= htmlspecialchars($p['mother_name']) ?></td>
                        <td class="p-2 border-b"><?= htmlspecialchars($p['baby_name'] ?? '-') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="text-xs text-slate-400 mt-3">Showing latest 10 PNC visits.</p>
    <?php endif; ?>
</div>

<script>
  (function () {
    const modal = document.getElementById('dashModal');
    const modalTitle = document.getElementById('dashModalTitle');
    const modalBody = document.getElementById('dashModalBody');
    if (!modal || !modalTitle || !modalBody) return;

    const openModal = (title, templateId) => {
      const template = document.getElementById(templateId);
      if (!template) return;
      modalTitle.textContent = title || 'Details';
      modalBody.innerHTML = template.innerHTML;
      modal.classList.remove('hidden');
      document.body.classList.add('overflow-hidden');
    };

    const closeModal = () => {
      modal.classList.add('hidden');
      modalBody.innerHTML = '';
      document.body.classList.remove('overflow-hidden');
    };

    document.querySelectorAll('[data-modal-target]').forEach((btn) => {
      btn.addEventListener('click', () => {
        openModal(btn.getAttribute('data-modal-title'), btn.getAttribute('data-modal-target'));
      });
    });

    modal.querySelectorAll('[data-modal-close]').forEach((el) => {
      el.addEventListener('click', closeModal);
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && !modal.classList.contains('hidden')) {
        closeModal();
      }
    });
  })();
</script>

<script>
  (function () {
    const elAppointments = document.getElementById('mch_nurse_stat_appointments');
    const elWaiting = document.getElementById('mch_nurse_stat_waiting');
    const elHighRisk = document.getElementById('mch_nurse_stat_high_risk');
    const elPnc7 = document.getElementById('mch_nurse_stat_pnc7');
    if (!elAppointments || !elWaiting || !elHighRisk || !elPnc7) return;

    let inFlight = false;
    async function refreshStats() {
      if (inFlight) return;
      inFlight = true;
      try {
        const res = await fetch('/mch_system/api/dashboard_nurse_stats.php', {
          method: 'GET',
          headers: { 'Accept': 'application/json' },
          cache: 'no-store'
        });
        if (!res.ok) return;
        const data = await res.json();
        if (!data || !data.ok) return;

        elAppointments.textContent = String(data.appointmentsToday ?? '');
        elWaiting.textContent = String(data.waitingTotal ?? '');
        elHighRisk.textContent = String(data.highRiskMothers ?? '');
        elPnc7.textContent = String(data.pncLast7Days ?? '');
      } catch (e) {
        // ignore
      } finally {
        inFlight = false;
      }
    }

    refreshStats();
    const interval = window.setInterval(refreshStats, 45000);
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) refreshStats();
    });
    window.addEventListener('pagehide', () => {
      try { window.clearInterval(interval); } catch (e) {}
    });
  })();
</script>

<script>
  (function () {
    const badge = document.getElementById('mch_conn_badge');
    const dot = document.getElementById('mch_conn_dot');
    const label = document.getElementById('mch_conn_label');
    if (!badge || !dot || !label) return;

    const setState = (online) => {
      const isOnline = !!online;
      label.textContent = isOnline ? 'Online' : 'Offline';
      badge.classList.toggle('bg-emerald-50', isOnline);
      badge.classList.toggle('text-emerald-700', isOnline);
      badge.classList.toggle('border-emerald-200', isOnline);
      dot.classList.toggle('bg-emerald-500', isOnline);

      badge.classList.toggle('bg-rose-50', !isOnline);
      badge.classList.toggle('text-rose-700', !isOnline);
      badge.classList.toggle('border-rose-200', !isOnline);
      dot.classList.toggle('bg-rose-500', !isOnline);

      badge.title = isOnline ? 'Online' : 'Offline';
    };

    async function probe() {
      if (!navigator.onLine) {
        setState(false);
        return;
      }

      const controller = window.AbortController ? new AbortController() : null;
      const timeout = window.setTimeout(() => {
        try { controller?.abort(); } catch (e) {}
      }, 2500);

      try {
        const res = await fetch('/mch_system/api/ping.php', {
          method: 'GET',
          cache: 'no-store',
          headers: { 'Accept': 'application/json' },
          signal: controller ? controller.signal : undefined
        });
        setState(!!res && res.ok);
      } catch (e) {
        setState(false);
      } finally {
        try { window.clearTimeout(timeout); } catch (e) {}
      }
    }

    window.addEventListener('online', probe);
    window.addEventListener('offline', () => setState(false));
    probe();
    window.setInterval(probe, 15000);
  })();
</script>

<?php include __DIR__ . "/../includes/footer.php"; ?>
