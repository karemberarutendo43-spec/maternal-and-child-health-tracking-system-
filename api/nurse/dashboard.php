<?php
require_once __DIR__ . "/../_bootstrap.php";

api_require_role('nurse');

$uid = (int)($_SESSION['user_id'] ?? 0);
$scopedClinicId = mch_scope_scoped_clinic_id();
if ($scopedClinicId <= 0) {
    api_error('Your account is not assigned to a clinic.', 403);
}

try {
    $scalar = function (string $sql, array $params = []) use ($pdo) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    };
    $all = function (string $sql, array $params = []) use ($pdo) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    };

    $today = date('Y-m-d');

    $totalMothers = (int)$scalar("
        SELECT COUNT(*)
        FROM mothers m
        JOIN users u ON u.id = m.user_id
        WHERE u.clinic_id = ?
    ", [$scopedClinicId]);

    $totalBabies = (int)$scalar("
        SELECT COUNT(*)
        FROM babies b
        JOIN mothers m ON b.mother_id = m.id
        JOIN users u ON u.id = m.user_id
        WHERE u.clinic_id = ?
    ", [$scopedClinicId]);

    $ancDueToday = (int)$scalar("
        SELECT COUNT(*)
        FROM anc_visits a
        JOIN mothers m ON a.mother_id = m.id
        JOIN users u ON u.id = m.user_id
        WHERE a.next_visit_date = CURDATE()
          AND a.status = 'PENDING'
          AND u.clinic_id = ?
    ", [$scopedClinicId]);

    $ancDefaulters = (int)$scalar("
        SELECT COUNT(*)
        FROM anc_visits a
        JOIN mothers m ON a.mother_id = m.id
        JOIN users u ON u.id = m.user_id
        WHERE a.next_visit_date < CURDATE()
          AND a.status = 'PENDING'
          AND u.clinic_id = ?
    ", [$scopedClinicId]);

    $immunDueToday = (int)$scalar("
        SELECT COUNT(*)
        FROM immunizations i
        JOIN babies b ON i.baby_id = b.id
        JOIN mothers m ON b.mother_id = m.id
        JOIN users u ON u.id = m.user_id
        WHERE i.due_date = CURDATE()
          AND i.status = 'PENDING'
          AND u.clinic_id = ?
    ", [$scopedClinicId]);

    $immunDefaulters = (int)$scalar("
        SELECT COUNT(*)
        FROM immunizations i
        JOIN babies b ON i.baby_id = b.id
        JOIN mothers m ON b.mother_id = m.id
        JOIN users u ON u.id = m.user_id
        WHERE i.due_date < CURDATE()
          AND i.status = 'PENDING'
          AND u.clinic_id = ?
    ", [$scopedClinicId]);

    $immunDueSoon = (int)$scalar("
        SELECT COUNT(*)
        FROM immunizations i
        JOIN babies b ON i.baby_id = b.id
        JOIN mothers m ON b.mother_id = m.id
        JOIN users u ON u.id = m.user_id
        WHERE i.status = 'PENDING'
          AND i.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
          AND u.clinic_id = ?
    ", [$scopedClinicId]);

    $tasksEnabled = false;
    try {
        $tasksEnabled = (bool)$pdo->query("SHOW TABLES LIKE 'follow_up_tasks'")->fetchColumn();
    } catch (Throwable $e) {
        $tasksEnabled = false;
    }
    $openTasks = 0;
    $openTasksList = [];
    if ($tasksEnabled && $uid > 0) {
        $openTasks = (int)$scalar("
            SELECT COUNT(*)
            FROM follow_up_tasks
            WHERE status = 'OPEN'
              AND clinic_id = ?
              AND assigned_to = ?
        ", [$scopedClinicId, $uid]);

        $openTasksList = $all("
            SELECT
              t.id AS task_id,
              t.task_type,
              t.subject_type,
              t.subject_id,
              t.due_date,
              t.priority,
              t.note,
              t.created_at,
              COALESCE(m.fullname, b.baby_name, '') AS subject_name
            FROM follow_up_tasks t
            LEFT JOIN mothers m ON (t.subject_type = 'mother' AND t.subject_id = m.id)
            LEFT JOIN babies b ON (t.subject_type = 'baby' AND t.subject_id = b.id)
            WHERE t.status = 'OPEN'
              AND t.clinic_id = ?
              AND t.assigned_to = ?
            ORDER BY (t.due_date IS NULL) ASC, t.due_date ASC, t.priority DESC, t.created_at DESC, t.id DESC
            LIMIT 20
        ", [$scopedClinicId, $uid]);
    }

    $pncLast7Days = 0;
    $pncThisMonth = 0;
    $pncThisMonthList = [];
    $pncLast7DaysList = [];
    try {
        $pncLast7Days = (int)$scalar("
            SELECT COUNT(*)
            FROM pnc_visits p
            JOIN mothers m ON p.mother_id = m.id
            JOIN users u ON u.id = m.user_id
            WHERE p.visit_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND CURDATE()
              AND u.clinic_id = ?
        ", [$scopedClinicId]);

        $pncThisMonth = (int)$scalar("
            SELECT COUNT(*)
            FROM pnc_visits p
            JOIN mothers m ON p.mother_id = m.id
            JOIN users u ON u.id = m.user_id
            WHERE p.visit_date BETWEEN DATE_FORMAT(CURDATE(), '%Y-%m-01') AND LAST_DAY(CURDATE())
              AND u.clinic_id = ?
        ", [$scopedClinicId]);

        $pncThisMonthList = $all("
            SELECT p.visit_date, m.fullname AS mother_name, b.baby_name
            FROM pnc_visits p
            JOIN mothers m ON p.mother_id = m.id
            JOIN users u ON u.id = m.user_id
            LEFT JOIN babies b ON p.baby_id = b.id
            WHERE p.visit_date BETWEEN DATE_FORMAT(CURDATE(), '%Y-%m-01') AND LAST_DAY(CURDATE())
              AND u.clinic_id = ?
            ORDER BY p.visit_date DESC
            LIMIT 10
        ", [$scopedClinicId]);

        $pncLast7DaysList = $all("
            SELECT p.visit_date, m.fullname AS mother_name, b.baby_name
            FROM pnc_visits p
            JOIN mothers m ON p.mother_id = m.id
            JOIN users u ON u.id = m.user_id
            LEFT JOIN babies b ON p.baby_id = b.id
            WHERE p.visit_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND CURDATE()
              AND u.clinic_id = ?
            ORDER BY p.visit_date DESC
            LIMIT 10
        ", [$scopedClinicId]);
    } catch (Throwable $e) {
        $pncLast7Days = 0;
        $pncThisMonth = 0;
        $pncThisMonthList = [];
        $pncLast7DaysList = [];
    }

    $ancDueTodayList = $all("
        SELECT m.id AS mother_id, m.fullname, m.hospital_number, a.next_visit_date
        FROM anc_visits a
        JOIN mothers m ON a.mother_id = m.id
        JOIN users u ON u.id = m.user_id
        WHERE a.next_visit_date = CURDATE()
          AND a.status = 'PENDING'
          AND u.clinic_id = ?
        ORDER BY m.fullname
        LIMIT 10
    ", [$scopedClinicId]);

    $immunDueTodayList = $all("
        SELECT b.id AS baby_id, b.baby_name, m.fullname AS mother_name, i.vaccine, i.due_date
        FROM immunizations i
        JOIN babies b ON i.baby_id = b.id
        JOIN mothers m ON b.mother_id = m.id
        JOIN users u ON u.id = m.user_id
        WHERE i.due_date = CURDATE()
          AND i.status = 'PENDING'
          AND u.clinic_id = ?
        ORDER BY b.baby_name, i.vaccine
        LIMIT 10
    ", [$scopedClinicId]);

    $ancDefaultersList = $all("
        SELECT m.id AS mother_id, m.fullname, m.hospital_number, a.next_visit_date
        FROM anc_visits a
        JOIN mothers m ON a.mother_id = m.id
        JOIN users u ON u.id = m.user_id
        WHERE a.next_visit_date < CURDATE()
          AND a.status = 'PENDING'
          AND u.clinic_id = ?
        ORDER BY a.next_visit_date ASC
        LIMIT 10
    ", [$scopedClinicId]);

    $immunDefaultersList = $all("
        SELECT b.id AS baby_id, b.baby_name, m.fullname AS mother_name, i.vaccine, i.due_date
        FROM immunizations i
        JOIN babies b ON i.baby_id = b.id
        JOIN mothers m ON b.mother_id = m.id
        JOIN users u ON u.id = m.user_id
        WHERE i.due_date < CURDATE()
          AND i.status = 'PENDING'
          AND u.clinic_id = ?
        ORDER BY i.due_date ASC
        LIMIT 10
    ", [$scopedClinicId]);

    $recentMothers = $all("
        SELECT m.id AS mother_id, m.fullname, m.hospital_number, m.pregnancy_status, m.created_at
        FROM mothers m
        JOIN users u ON u.id = m.user_id
        WHERE u.clinic_id = ?
        ORDER BY m.created_at DESC, m.id DESC
        LIMIT 10
    ", [$scopedClinicId]);

    $recentBabies = $all("
        SELECT
          b.id AS baby_id,
          b.baby_name,
          b.dob,
          b.gender,
          m.fullname AS mother_name
        FROM babies b
        JOIN mothers m ON b.mother_id = m.id
        JOIN users u ON u.id = m.user_id
        WHERE u.clinic_id = ?
        ORDER BY b.id DESC
        LIMIT 10
    ", [$scopedClinicId]);

    // Activation waiting + unlinked + high risk (matches dashboard.php definitions)
    $waitingVerified = 0;
    $waitingUnverified = 0;
    $unlinkedMothers = 0;
    $highRiskMothers = 0;
    $activationQueueList = [];
    $unlinkedActiveList = [];
    $highRiskMothersList = [];

    try {
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
        $waitingRow = $waitingStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $waitingVerified = (int)($waitingRow['verified'] ?? 0);
        $waitingUnverified = (int)($waitingRow['unverified'] ?? 0);

        $unlinkedMothers = (int)$scalar("
            SELECT COUNT(*)
            FROM users u
            LEFT JOIN mothers m ON m.user_id = u.id
            WHERE u.role = 'mother'
              AND u.status = 'ACTIVE'
              AND m.id IS NULL
              AND u.clinic_id = ?
        ", [$scopedClinicId]);

        $highRiskMothers = (int)$scalar("
            SELECT COUNT(*)
            FROM mothers m
            JOIN users u ON u.id = m.user_id
            WHERE u.clinic_id = ?
              AND (
                NOT EXISTS (SELECT 1 FROM anc_visits a WHERE a.mother_id = m.id)
                OR EXISTS (
                  SELECT 1
                  FROM anc_visits a
                  WHERE a.mother_id = m.id
                    AND a.next_visit_date < CURDATE()
                    AND a.status = 'PENDING'
                )
              )
        ", [$scopedClinicId]);

        $activationQueueList = $all("
            SELECT id, full_name, phone, email, created_at, email_verified, status
            FROM users
            WHERE role = 'mother'
              AND status IN ('PENDING','APPROVED')
              AND clinic_id = ?
            ORDER BY created_at DESC
            LIMIT 10
        ", [$scopedClinicId]);

        $unlinkedActiveList = $all("
            SELECT u.id, u.full_name, u.phone, u.email, u.created_at, u.email_verified
            FROM users u
            LEFT JOIN mothers m ON m.user_id = u.id
            WHERE u.role = 'mother'
              AND u.status = 'ACTIVE'
              AND m.id IS NULL
              AND u.clinic_id = ?
            ORDER BY u.created_at DESC
            LIMIT 10
        ", [$scopedClinicId]);

        $highRiskMothersList = $all("
            SELECT
              m.id AS mother_id,
              m.fullname,
              m.hospital_number,
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
                NOT EXISTS (SELECT 1 FROM anc_visits a WHERE a.mother_id = m.id)
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
        ", [$scopedClinicId]);
    } catch (Throwable $e) {
        // Keep defaults
    }

    // Immun due soon (next 7 days) + SMS status if sms_logs is installed
    $smsLogsAvailable = false;
    try {
        $smsLogsAvailable = (bool)$pdo->query("SHOW TABLES LIKE 'sms_logs'")->fetchColumn();
    } catch (Throwable $e) {
        $smsLogsAvailable = false;
    }

    $immunDueSoonList = [];
    try {
        $immunDueSoonSql = $smsLogsAvailable ? "
            SELECT
              i.id AS immunization_id,
              i.vaccine,
              i.due_date,
              b.id AS baby_id,
              b.baby_name,
              m.fullname AS mother_name,
              m.phone AS mother_phone,
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
            JOIN users u ON u.id = m.user_id
            WHERE i.status = 'PENDING'
              AND i.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
              AND u.clinic_id = ?
            ORDER BY i.due_date ASC
            LIMIT 8
        " : "
            SELECT
              i.id AS immunization_id,
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
            JOIN users u ON u.id = m.user_id
            WHERE i.status = 'PENDING'
              AND i.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
              AND u.clinic_id = ?
            ORDER BY i.due_date ASC
            LIMIT 8
        ";
        $immunDueSoonList = $all($immunDueSoonSql, [$scopedClinicId]);
    } catch (Throwable $e) {
        $immunDueSoonList = [];
    }

    // Baby weight distribution (birth_weight) + latest baby_weights entries
    $weightBuckets = [
        ['key' => 'lt_2_5', 'label' => '<2.5 kg', 'count' => 0],
        ['key' => '2_5_3_0', 'label' => '2.5–3.0 kg', 'count' => 0],
        ['key' => '3_0_3_5', 'label' => '3.0–3.5 kg', 'count' => 0],
        ['key' => '3_5_4_0', 'label' => '3.5–4.0 kg', 'count' => 0],
        ['key' => 'ge_4_0', 'label' => '4+ kg', 'count' => 0],
    ];
    $weightTotal = 0;
    try {
        $stmt = $pdo->prepare("
            SELECT
              SUM(CASE WHEN b.birth_weight > 0 AND b.birth_weight < 2.5 THEN 1 ELSE 0 END) AS lt_2_5,
              SUM(CASE WHEN b.birth_weight >= 2.5 AND b.birth_weight < 3.0 THEN 1 ELSE 0 END) AS b_2_5_3_0,
              SUM(CASE WHEN b.birth_weight >= 3.0 AND b.birth_weight < 3.5 THEN 1 ELSE 0 END) AS b_3_0_3_5,
              SUM(CASE WHEN b.birth_weight >= 3.5 AND b.birth_weight < 4.0 THEN 1 ELSE 0 END) AS b_3_5_4_0,
              SUM(CASE WHEN b.birth_weight >= 4.0 THEN 1 ELSE 0 END) AS ge_4_0,
              SUM(CASE WHEN b.birth_weight IS NOT NULL AND b.birth_weight > 0 THEN 1 ELSE 0 END) AS total
            FROM babies b
            JOIN mothers m ON b.mother_id = m.id
            JOIN users u ON u.id = m.user_id
            WHERE u.clinic_id = ?
              AND b.birth_weight IS NOT NULL
        ");
        $stmt->execute([$scopedClinicId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $weightTotal = (int)($row['total'] ?? 0);
        $map = [
            'lt_2_5' => (int)($row['lt_2_5'] ?? 0),
            '2_5_3_0' => (int)($row['b_2_5_3_0'] ?? 0),
            '3_0_3_5' => (int)($row['b_3_0_3_5'] ?? 0),
            '3_5_4_0' => (int)($row['b_3_5_4_0'] ?? 0),
            'ge_4_0' => (int)($row['ge_4_0'] ?? 0),
        ];
        foreach ($weightBuckets as &$b) {
            $b['count'] = $map[$b['key']] ?? 0;
        }
        unset($b);
    } catch (Throwable $e) {
        $weightBuckets = $weightBuckets;
        $weightTotal = 0;
    }

    $recentWeights = [];
    try {
        $hasBabyWeights = (bool)$pdo->query("SHOW TABLES LIKE 'baby_weights'")->fetchColumn();
        if ($hasBabyWeights) {
            $recentWeights = $all("
                SELECT bw.weight_kg, bw.observed_date, b.id AS baby_id, b.baby_name
                FROM baby_weights bw
                JOIN babies b ON bw.baby_id = b.id
                JOIN mothers m ON b.mother_id = m.id
                JOIN users u ON m.user_id = u.id
                WHERE u.clinic_id = ?
                  AND bw.deleted_at IS NULL
                ORDER BY bw.observed_date DESC, bw.created_at DESC
                LIMIT 3
            ", [$scopedClinicId]);
        }
    } catch (Throwable $e) {
        $recentWeights = [];
    }

    api_ok([
        'scoped_clinic_id' => $scopedClinicId,
        'counts' => [
            'total_mothers' => $totalMothers,
            'total_babies' => $totalBabies,
            'anc_due_today' => $ancDueToday,
            'anc_defaulters' => $ancDefaulters,
            'immun_due_today' => $immunDueToday,
            'immun_defaulters' => $immunDefaulters,
            'immun_due_soon_7d' => $immunDueSoon,
            'open_tasks' => $openTasks,
            'pnc_last_7_days' => $pncLast7Days,
            'pnc_this_month' => $pncThisMonth,
            'activation_waiting_total' => ($waitingVerified + $waitingUnverified) + $unlinkedMothers,
            'activation_waiting_verified' => $waitingVerified,
            'activation_waiting_unverified' => $waitingUnverified,
            'unlinked_mothers' => $unlinkedMothers,
            'high_risk_mothers' => $highRiskMothers,
        ],
        'lists' => [
            'anc_due_today' => $ancDueTodayList,
            'immun_due_today' => $immunDueTodayList,
            'anc_defaulters' => $ancDefaultersList,
            'immun_defaulters' => $immunDefaultersList,
            'immun_due_soon' => $immunDueSoonList,
            'activation_queue' => $activationQueueList,
            'unlinked_active' => $unlinkedActiveList,
            'high_risk_mothers' => $highRiskMothersList,
            'pnc_this_month' => $pncThisMonthList,
            'pnc_last_7_days' => $pncLast7DaysList,
            'recent_mothers' => $recentMothers,
            'recent_babies' => $recentBabies,
            'open_tasks' => $openTasksList,
            'recent_weights' => $recentWeights,
            'birth_weight_buckets' => $weightBuckets,
        ],
        'meta' => [
            'today' => $today,
            'birth_weight_total' => $weightTotal,
            'sms_logs_available' => $smsLogsAvailable,
        ],
    ]);
} catch (Throwable $e) {
    api_error('Failed to load dashboard', 500);
}
