<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . "/../config/session.php";
mch_session_start();

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/scope.php";

function mch_json_fail(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

if (empty($_SESSION['user_id']) || empty($_SESSION['role'])) {
    mch_json_fail(401, 'Not authenticated');
}

$role = (string)($_SESSION['role'] ?? '');
if (!in_array($role, ['nurse', 'admin'], true)) {
    mch_json_fail(403, 'Forbidden');
}

try {
    $scopedClinicId = mch_scope_scoped_clinic_id();
    if ($role === 'nurse' && $scopedClinicId <= 0) {
        mch_json_fail(400, 'Clinic not assigned');
    }

    $scalar = function (string $sql, array $params = []) use ($pdo) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    };

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
    $appointmentsToday = $ancDueToday + $immunDueToday;

    $waitingStmt = $scopedClinicId > 0
        ? $pdo->prepare("
            SELECT
              SUM(CASE WHEN email_verified = 1 THEN 1 ELSE 0 END) AS verified,
              SUM(CASE WHEN email_verified = 0 OR email_verified IS NULL THEN 1 ELSE 0 END) AS unverified
            FROM users
            WHERE role = 'mother'
              AND status IN ('PENDING','APPROVED')
              AND clinic_id = ?
        ")
        : $pdo->prepare("
            SELECT
              SUM(CASE WHEN email_verified = 1 THEN 1 ELSE 0 END) AS verified,
              SUM(CASE WHEN email_verified = 0 OR email_verified IS NULL THEN 1 ELSE 0 END) AS unverified
            FROM users
            WHERE role = 'mother'
              AND status IN ('PENDING','APPROVED')
        ");
    $waitingStmt->execute($scopedClinicId > 0 ? [$scopedClinicId] : []);
    $waitingRow = $waitingStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $waitingVerified = (int)($waitingRow['verified'] ?? 0);
    $waitingUnverified = (int)($waitingRow['unverified'] ?? 0);
    $waitingMothers = $waitingVerified + $waitingUnverified;

    $unlinkedStmt = $scopedClinicId > 0
        ? $pdo->prepare("
            SELECT COUNT(*)
            FROM users u
            LEFT JOIN mothers m ON m.user_id = u.id
            WHERE u.role = 'mother'
              AND u.status = 'ACTIVE'
              AND m.id IS NULL
              AND u.clinic_id = ?
        ")
        : $pdo->prepare("
            SELECT COUNT(*)
            FROM users u
            LEFT JOIN mothers m ON m.user_id = u.id
            WHERE u.role = 'mother'
              AND u.status = 'ACTIVE'
              AND m.id IS NULL
        ");
    $unlinkedStmt->execute($scopedClinicId > 0 ? [$scopedClinicId] : []);
    $unlinkedMothers = (int)$unlinkedStmt->fetchColumn();

    $pncLast7Days = $scopedClinicId > 0
        ? (int)$scalar("
            SELECT COUNT(*)
            FROM pnc_visits p
            JOIN mothers m ON p.mother_id = m.id
            JOIN users u ON u.id = m.user_id
            WHERE p.visit_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND CURDATE()
              AND u.clinic_id = ?
          ", [$scopedClinicId])
        : (int)$scalar("
            SELECT COUNT(*)
            FROM pnc_visits
            WHERE visit_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND CURDATE()
          ");

    $highRiskMothers = $scopedClinicId > 0
        ? (int)$scalar("
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
          ", [$scopedClinicId])
        : (int)$scalar("
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
          ");

    echo json_encode([
        'ok' => true,
        'appointmentsToday' => $appointmentsToday,
        'waitingTotal' => $waitingMothers + $unlinkedMothers,
        'waitingVerified' => $waitingVerified,
        'waitingUnverified' => $waitingUnverified,
        'unlinkedMothers' => $unlinkedMothers,
        'highRiskMothers' => $highRiskMothers,
        'pncLast7Days' => $pncLast7Days,
    ]);
} catch (Throwable $e) {
    mch_json_fail(500, 'Failed to load stats');
}
