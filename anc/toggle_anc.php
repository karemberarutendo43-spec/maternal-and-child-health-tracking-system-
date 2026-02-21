<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/cards.php";
require_once __DIR__ . "/../config/scope.php";
require_once __DIR__ . "/../config/audit.php";

header('Content-Type: application/json');

// Access check
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'nurse'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
$visit_id = $input['id'] ?? null;
$action = $input['action'] ?? null;

if (!$visit_id || !in_array($action, ['complete', 'undo'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit;
}

try {
    $visitId = (int)$visit_id;
    if ($visitId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
        exit;
    }

    $scopedClinicId = mch_scope_scoped_clinic_id();
    $metaStmt = $pdo->prepare("
        SELECT a.id, a.mother_id, a.visit_number, a.visit_date, a.status,
               u.clinic_id AS mother_clinic_id
        FROM anc_visits a
        JOIN mothers m ON a.mother_id = m.id
        JOIN users u ON m.user_id = u.id
        WHERE a.id = ?
        LIMIT 1
    ");
    $metaStmt->execute([$visitId]);
    $meta = $metaStmt->fetch(PDO::FETCH_ASSOC);
    if (!$meta) {
        echo json_encode(['success' => false, 'error' => 'Visit not found']);
        exit;
    }
    if ($scopedClinicId > 0 && (int)($meta['mother_clinic_id'] ?? 0) !== $scopedClinicId) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Forbidden']);
        exit;
    }

    $supportsClinical = db_has_column($pdo, 'anc_visits', 'clinical_status');
    if ($action === 'complete') {
        $visitDateRaw = trim((string)($meta['visit_date'] ?? ''));
        $today = new DateTimeImmutable('today');
        $visitDate = null;
        try {
            if ($visitDateRaw !== '' && $visitDateRaw !== '0000-00-00') {
                $visitDate = new DateTimeImmutable($visitDateRaw);
            }
        } catch (Throwable $e) {
            $visitDate = null;
        }

        $isEarly = ($visitDate instanceof DateTimeImmutable) && ($today < $visitDate);
        if ($isEarly) {
            $override = !empty($input['override']);
            $overrideReason = trim((string)($input['override_reason'] ?? ''));
            if (!$override) {
                http_response_code(409);
                echo json_encode([
                    'success' => false,
                    'code' => 'EARLY',
                    'error' => "This ANC visit is scheduled for {$visitDateRaw}.",
                    'visit_date' => $visitDateRaw,
                ]);
                exit;
            }
            if (mb_strlen($overrideReason) < 10) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Override reason must be at least 10 characters.',
                ]);
                exit;
            }

            mch_audit_log(
                $pdo,
                'ANC_OVERRIDE_EARLY',
                'anc_visits',
                $visitId,
                "Early ANC completion override: mother_id={$meta['mother_id']}; visit_number={$meta['visit_number']}; visit_date={$visitDateRaw}; reason={$overrideReason}"
            );
        }

        $stmt = $pdo->prepare($supportsClinical
            ? "UPDATE anc_visits SET status = 'COMPLETED', clinical_status=1 WHERE id = ?"
            : "UPDATE anc_visits SET status = 'COMPLETED' WHERE id = ?"
        );
        $stmt->execute([$visitId]);

        mch_audit_log(
            $pdo,
            'ANC_COMPLETED',
            'anc_visits',
            $visitId,
            "ANC visit marked COMPLETED: mother_id={$meta['mother_id']}; visit_number={$meta['visit_number']}; visit_date={$visitDateRaw}"
        );

        echo json_encode([
            'success' => true,
            'completed_date' => date('Y-m-d')
        ]);
    } else { // undo
        $stmt = $pdo->prepare($supportsClinical
            ? "UPDATE anc_visits SET status = 'PENDING', clinical_status=0 WHERE id = ?"
            : "UPDATE anc_visits SET status = 'PENDING' WHERE id = ?"
        );
        $stmt->execute([$visitId]);

        mch_audit_log(
            $pdo,
            'ANC_UNDO',
            'anc_visits',
            $visitId,
            "ANC visit marked PENDING (undo): mother_id={$meta['mother_id']}; visit_number={$meta['visit_number']}"
        );
        echo json_encode(['success' => true]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
