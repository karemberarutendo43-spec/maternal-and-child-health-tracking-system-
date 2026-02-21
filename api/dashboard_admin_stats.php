<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . "/../config/session.php";
mch_session_start();

require_once __DIR__ . "/../config/db.php";

function mch_json_fail(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    mch_json_fail(401, 'Not authenticated');
}

$adminLevel = (string)($_SESSION['admin_level'] ?? 'SUPER');
$adminClinicId = (int)($_SESSION['clinic_id'] ?? 0);
$allClinics = ($adminLevel === 'SUPER' || $adminLevel === 'DISTRICT');
if (!$allClinics && $adminClinicId <= 0) {
    mch_json_fail(400, 'Clinic admin not assigned');
}
$selectedClinicId = $allClinics ? 0 : $adminClinicId;

try {
    $scalar = function (string $sql, array $params = []) use ($pdo) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    };

    $totalMothers = $selectedClinicId > 0
        ? (int)$scalar("SELECT COUNT(*) FROM mothers m JOIN users u ON u.id = m.user_id WHERE u.clinic_id = ?", [$selectedClinicId])
        : (int)$scalar("SELECT COUNT(*) FROM mothers");

    $totalBabies = $selectedClinicId > 0
        ? (int)$scalar("
            SELECT COUNT(*)
            FROM babies b
            JOIN mothers m ON b.mother_id = m.id
            JOIN users u ON u.id = m.user_id
            WHERE u.clinic_id = ?
        ", [$selectedClinicId])
        : (int)$scalar("SELECT COUNT(*) FROM babies");

    $totalNurses = $selectedClinicId > 0
        ? (int)$scalar("SELECT COUNT(*) FROM users WHERE role='nurse' AND clinic_id = ?", [$selectedClinicId])
        : (int)$scalar("SELECT COUNT(*) FROM users WHERE role='nurse'");

    $ancDefaulters = $selectedClinicId > 0
        ? (int)$scalar("
            SELECT COUNT(*)
            FROM anc_visits a
            JOIN mothers m ON a.mother_id = m.id
            JOIN users u ON m.user_id = u.id
            WHERE a.next_visit_date < CURDATE()
              AND a.status = 'PENDING'
              AND u.clinic_id = ?
        ", [$selectedClinicId])
        : (int)$scalar("
            SELECT COUNT(*)
            FROM anc_visits
            WHERE next_visit_date < CURDATE()
              AND status = 'PENDING'
        ");

    $immunDefaulters = $selectedClinicId > 0
        ? (int)$scalar("
            SELECT COUNT(*)
            FROM immunizations i
            JOIN babies b ON i.baby_id = b.id
            JOIN mothers m ON b.mother_id = m.id
            JOIN users u ON m.user_id = u.id
            WHERE i.due_date < CURDATE()
              AND i.status = 'PENDING'
              AND u.clinic_id = ?
        ", [$selectedClinicId])
        : (int)$scalar("
            SELECT COUNT(*)
            FROM immunizations
            WHERE due_date < CURDATE()
              AND status = 'PENDING'
        ");

    $totalImmun = $selectedClinicId > 0
        ? (int)$scalar("
            SELECT COUNT(*)
            FROM immunizations i
            JOIN babies b ON i.baby_id = b.id
            JOIN mothers m ON b.mother_id = m.id
            JOIN users u ON m.user_id = u.id
            WHERE u.clinic_id = ?
        ", [$selectedClinicId])
        : (int)$scalar("SELECT COUNT(*) FROM immunizations");

    $givenImmun = $selectedClinicId > 0
        ? (int)$scalar("
            SELECT COUNT(*)
            FROM immunizations i
            JOIN babies b ON i.baby_id = b.id
            JOIN mothers m ON b.mother_id = m.id
            JOIN users u ON m.user_id = u.id
            WHERE i.status = 'GIVEN'
              AND u.clinic_id = ?
        ", [$selectedClinicId])
        : (int)$scalar("SELECT COUNT(*) FROM immunizations WHERE status = 'GIVEN'");

    $coverage = $totalImmun > 0 ? (int)round(($givenImmun / $totalImmun) * 100) : 0;

    $highRiskMothers = $selectedClinicId > 0
        ? (int)$scalar("
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
        ", [$selectedClinicId])
        : (int)$scalar("
            SELECT COUNT(*)
            FROM mothers m
            WHERE NOT EXISTS (SELECT 1 FROM anc_visits a WHERE a.mother_id = m.id)
               OR EXISTS (
                 SELECT 1
                 FROM anc_visits a
                 WHERE a.mother_id = m.id
                   AND a.next_visit_date < CURDATE()
                   AND a.status = 'PENDING'
               )
        ");

    $topClinicName = 'N/A';
    if ($selectedClinicId > 0) {
        $cstmt = $pdo->prepare("SELECT clinic_name FROM clinics WHERE id = ? LIMIT 1");
        $cstmt->execute([$selectedClinicId]);
        $topClinicName = (string)($cstmt->fetchColumn() ?: 'N/A');
    } else {
        $topClinic = $pdo->query("
            SELECT c.clinic_name, COUNT(m.id) AS mothers_count
            FROM clinics c
            LEFT JOIN users u ON u.clinic_id = c.id
            LEFT JOIN mothers m ON m.user_id = u.id
            GROUP BY c.id
            ORDER BY mothers_count DESC
            LIMIT 1
        ")->fetch(PDO::FETCH_ASSOC);
        $topClinicName = (string)($topClinic['clinic_name'] ?? 'N/A');
    }

    // Critical stock alerts (low stock)
    $criticalStock = 0;
    try {
        $hasStock = (bool)$pdo->query("SHOW TABLES LIKE 'commodity_stock_levels'")->fetchColumn();
        if ($hasStock) {
            $critStmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM commodity_stock_levels l
                JOIN commodities cm ON cm.id = l.commodity_id
                WHERE cm.active = 1
                  AND l.reorder_level > 0
                  AND l.on_hand <= l.reorder_level
                  AND (? = 0 OR l.clinic_id = ?)
            ");
            $critStmt->execute([$selectedClinicId, $selectedClinicId]);
            $criticalStock = (int)$critStmt->fetchColumn();
        }
    } catch (Throwable $e3) {
        $criticalStock = 0;
    }

    echo json_encode([
        'ok' => true,
        'selectedClinicId' => $selectedClinicId,
        'totalMothers' => $totalMothers,
        'totalBabies' => $totalBabies,
        'totalNurses' => $totalNurses,
        'ancDefaulters' => $ancDefaulters,
        'immunDefaulters' => $immunDefaulters,
        'highRiskMothers' => $highRiskMothers,
        'coverage' => $coverage,
        'topClinicName' => $topClinicName,
        'criticalStock' => $criticalStock,
    ]);
} catch (Throwable $e) {
    mch_json_fail(500, 'Failed to load stats');
}
