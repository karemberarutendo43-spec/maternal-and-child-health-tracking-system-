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

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'mother') {
    mch_json_fail(401, 'Not authenticated');
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    mch_json_fail(401, 'Not authenticated');
}

try {
    $motherStmt = $pdo->prepare("SELECT id FROM mothers WHERE user_id = ? LIMIT 1");
    $motherStmt->execute([$userId]);
    $motherId = (int)$motherStmt->fetchColumn();
    if ($motherId <= 0) {
        echo json_encode(['ok' => true, 'linked' => false]);
        exit;
    }

    $nextStmt = $pdo->prepare("
        SELECT next_visit_date
        FROM anc_visits
        WHERE mother_id = ? AND next_visit_date >= CURDATE()
        ORDER BY next_visit_date ASC
        LIMIT 1
    ");
    $nextStmt->execute([$motherId]);
    $nextVisitDate = $nextStmt->fetchColumn();

    $daysToNext = null;
    if (!empty($nextVisitDate)) {
        $daysToNext = (int)floor((strtotime((string)$nextVisitDate) - strtotime(date('Y-m-d'))) / 86400);
    }

    $babiesStmt = $pdo->prepare("SELECT id FROM babies WHERE mother_id = ? ORDER BY id ASC");
    $babiesStmt->execute([$motherId]);
    $babyId = (int)$babiesStmt->fetchColumn();

    $givenCount = 0;
    $pendingCount = 0;
    if ($babyId > 0) {
        $shotsStmt = $pdo->prepare("SELECT status FROM immunizations WHERE baby_id = ? ORDER BY due_date ASC");
        $shotsStmt->execute([$babyId]);
        $shots = $shotsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($shots as $s) {
            if (($s['status'] ?? '') === 'GIVEN') {
                $givenCount++;
            } else {
                $pendingCount++;
            }
        }
    }

    echo json_encode([
        'ok' => true,
        'linked' => true,
        'nextVisitDate' => $nextVisitDate ?: null,
        'daysToNext' => $daysToNext,
        'givenCount' => $givenCount,
        'pendingCount' => $pendingCount,
    ]);
} catch (Throwable $e) {
    mch_json_fail(500, 'Failed to load stats');
}
