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
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_SLASHES);
    exit;
}

if (empty($_SESSION['user_id']) || empty($_SESSION['role'])) {
    mch_json_fail(401, 'Not authenticated');
}

$role = (string)($_SESSION['role'] ?? '');
if (!in_array($role, ['nurse', 'admin'], true)) {
    mch_json_fail(403, 'Forbidden');
}

$motherId = (int)($_GET['mother_id'] ?? 0);
if ($motherId <= 0) {
    mch_json_fail(400, 'mother_id is required');
}

$scopedClinicId = mch_scope_scoped_clinic_id();
if ($role === 'nurse' && $scopedClinicId <= 0) {
    mch_json_fail(400, 'Clinic not assigned');
}

try {
    // Enforce clinic scope when applicable.
    if ($scopedClinicId > 0) {
        $scopeStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM mothers m
            JOIN users u ON u.id = m.user_id
            WHERE m.id = ?
              AND u.clinic_id = ?
            LIMIT 1
        ");
        $scopeStmt->execute([$motherId, $scopedClinicId]);
        if ((int)$scopeStmt->fetchColumn() !== 1) {
            mch_json_fail(403, 'Mother not in your clinic');
        }
    }

    // Latest baby for this mother.
    $babyStmt = $pdo->prepare("
        SELECT id, baby_name, dob, gender, birth_weight
        FROM babies
        WHERE mother_id = ?
        ORDER BY dob DESC, id DESC
        LIMIT 1
    ");
    $babyStmt->execute([$motherId]);
    $baby = $babyStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    // Latest delivery record (if the optional deliveries table exists).
    $delivery = null;
    try {
        $hasDeliveries = (bool)$pdo->query("SHOW TABLES LIKE 'deliveries'")->fetchColumn();
    } catch (Throwable $e) {
        $hasDeliveries = false;
    }
    if ($hasDeliveries) {
        $hasOutcome = false;
        try {
            $hasOutcome = db_has_column($pdo, 'deliveries', 'outcome');
        } catch (Throwable $e) {
            $hasOutcome = false;
        }

        $sql = $hasOutcome
            ? "SELECT delivery_date, delivery_type, outcome FROM deliveries WHERE mother_id = ? ORDER BY delivery_date DESC, id DESC LIMIT 1"
            : "SELECT delivery_date, delivery_type, 'LIVE' AS outcome FROM deliveries WHERE mother_id = ? ORDER BY delivery_date DESC, id DESC LIMIT 1";
        $dStmt = $pdo->prepare($sql);
        $dStmt->execute([$motherId]);
        $delivery = $dStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    $babySex = null;
    $babyWeightGrams = null;
    $deliveryDatetime = null;
    $deliveryMethod = null;

    if ($baby) {
        $gender = (string)($baby['gender'] ?? '');
        if ($gender === 'Male') $babySex = 'B';
        if ($gender === 'Female') $babySex = 'G';

        $bw = $baby['birth_weight'];
        if ($bw !== null && $bw !== '' && is_numeric((string)$bw)) {
            $babyWeightGrams = (int)round(((float)$bw) * 1000.0);
        }

        $dob = (string)($baby['dob'] ?? '');
        if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $dob)) {
            $deliveryDatetime = $dob . 'T00:00';
        }
    }

    if ($delivery) {
        $dDate = (string)($delivery['delivery_date'] ?? '');
        if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $dDate)) {
            $deliveryDatetime = $dDate . 'T00:00';
        }

        $dt = (string)($delivery['delivery_type'] ?? '');
        $map = [
            'Vaginal' => 'NVD',
            'C-Section' => 'CS',
            'Vacuum' => 'VACUUM',
            'Breech' => 'BREECH',
            'Forceps' => 'FORCEPS',
        ];
        if (isset($map[$dt])) {
            $deliveryMethod = $map[$dt];
        }
    }

    echo json_encode([
        'ok' => true,
        'data' => [
            'baby' => $baby ? [
                'id' => (int)$baby['id'],
                'baby_name' => (string)($baby['baby_name'] ?? ''),
                'dob' => (string)($baby['dob'] ?? ''),
                'gender' => (string)($baby['gender'] ?? ''),
                'birth_weight_kg' => $baby['birth_weight'],
            ] : null,
            'delivery' => $delivery,
            'suggest' => [
                'baby_name' => $baby ? (string)($baby['baby_name'] ?? '') : null,
                'baby_sex' => $babySex,
                'birth_weight_grams' => $babyWeightGrams,
                'delivery_datetime' => $deliveryDatetime,
                'delivery_method' => $deliveryMethod,
            ],
        ],
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    mch_json_fail(500, 'Server error');
}
