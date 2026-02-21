<?php
require_once __DIR__ . "/../_bootstrap.php";

api_require_role('nurse');

$scopedClinicId = mch_scope_scoped_clinic_id();
if ($scopedClinicId <= 0) {
    api_error('Your account is not assigned to a clinic.', 403);
}

$babyId = (int)($_GET['baby_id'] ?? 0);
if ($babyId <= 0) {
    api_error('Missing baby_id', 400);
}

// Scope check + baby details
$babyStmt = $pdo->prepare("
    SELECT
      b.*,
      m.fullname AS mother_name,
      u.clinic_id AS clinic_id
    FROM babies b
    JOIN mothers m ON b.mother_id = m.id
    JOIN users u ON m.user_id = u.id
    WHERE b.id = ?
    LIMIT 1
");
$babyStmt->execute([$babyId]);
$baby = $babyStmt->fetch(PDO::FETCH_ASSOC);
if (!$baby) {
    api_error('Baby not found', 404);
}
if ((int)($baby['clinic_id'] ?? 0) !== $scopedClinicId) {
    api_error('Forbidden', 403);
}

$hasWeights = false;
try {
    $hasWeights = (bool)$pdo->query("SHOW TABLES LIKE 'baby_weights'")->fetchColumn();
} catch (Throwable $e) {
    $hasWeights = false;
}

$supportsLength = $hasWeights ? db_has_column($pdo, 'baby_weights', 'length_cm') : false;
$supportsHead = $hasWeights ? db_has_column($pdo, 'baby_weights', 'head_circumference_cm') : false;

$weights = [];
if ($hasWeights) {
    $cols = "id, baby_id, weight_kg, observed_date, created_at";
    if ($supportsLength) $cols .= ", length_cm";
    if ($supportsHead) $cols .= ", head_circumference_cm";
    $wStmt = $pdo->prepare("
        SELECT {$cols}
        FROM baby_weights
        WHERE baby_id = ?
          AND deleted_at IS NULL
        ORDER BY observed_date DESC, id DESC
        LIMIT 200
    ");
    $wStmt->execute([$babyId]);
    $weights = $wStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$immunizations = [];
try {
    $hasImmun = (bool)$pdo->query("SHOW TABLES LIKE 'immunizations'")->fetchColumn();
    if ($hasImmun) {
        $iStmt = $pdo->prepare("
            SELECT id, baby_id, vaccine, due_date, status, given_date
            FROM immunizations
            WHERE baby_id = ?
            ORDER BY due_date ASC, id ASC
        ");
        $iStmt->execute([$babyId]);
        $immunizations = $iStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    $immunizations = [];
}

api_ok([
    'baby' => [
        'id' => (int)($baby['id'] ?? 0),
        'baby_name' => (string)($baby['baby_name'] ?? ''),
        'dob' => $baby['dob'] ?? null,
        'gender' => $baby['gender'] ?? null,
        'birth_weight' => $baby['birth_weight'] ?? null,
        'mother_id' => (int)($baby['mother_id'] ?? 0),
        'mother_name' => (string)($baby['mother_name'] ?? ''),
    ],
    'supports' => [
        'baby_weights' => $hasWeights,
        'length_cm' => $supportsLength,
        'head_circumference_cm' => $supportsHead,
    ],
    'weights' => $weights,
    'immunizations' => $immunizations,
]);

