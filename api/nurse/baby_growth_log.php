<?php
require_once __DIR__ . "/../_bootstrap.php";

api_require_role('nurse');
api_csrf_require_valid('api_nurse_baby_growth');

$scopedClinicId = mch_scope_scoped_clinic_id();
if ($scopedClinicId <= 0) {
    api_error('Your account is not assigned to a clinic.', 403);
}

$babyId = (int)($_POST['baby_id'] ?? 0);
$weightRaw = trim((string)($_POST['weight_kg'] ?? ''));
$observed = trim((string)($_POST['observed_date'] ?? date('Y-m-d')));
$lengthRaw = trim((string)($_POST['length_cm'] ?? ''));
$headRaw = trim((string)($_POST['head_circumference_cm'] ?? ''));

if ($babyId <= 0) api_error('Missing baby_id', 400);
if ($weightRaw === '' || !is_numeric($weightRaw)) api_error('Weight must be a number (kg).', 400);
$wkg = (float)$weightRaw;
if ($wkg < 0 || $wkg > 30) api_error('Weight must be between 0 and 30 kg.', 400);

$today = date('Y-m-d');
if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $observed) || strtotime($observed) === false) {
    api_error('Observation date must be a valid date (YYYY-MM-DD).', 400);
}
if ($observed > $today) api_error('Observation date cannot be in the future.', 400);
if ($observed !== $today) api_error('For nurses, observation date must be today.', 400);

// Scope check (baby must belong to nurse clinic)
$scopeStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM babies b
    JOIN mothers m ON b.mother_id = m.id
    JOIN users u ON m.user_id = u.id
    WHERE b.id = ?
      AND u.clinic_id = ?
    LIMIT 1
");
$scopeStmt->execute([$babyId, $scopedClinicId]);
if ((int)$scopeStmt->fetchColumn() !== 1) {
    api_error('Forbidden', 403);
}

$hasWeights = false;
try {
    $hasWeights = (bool)$pdo->query("SHOW TABLES LIKE 'baby_weights'")->fetchColumn();
} catch (Throwable $e) {
    $hasWeights = false;
}
if (!$hasWeights) {
    api_error('Growth module is not configured yet (baby_weights table missing).', 400);
}

// Prevent duplicates same day
$dup = $pdo->prepare("
    SELECT COUNT(*)
    FROM baby_weights
    WHERE baby_id = ?
      AND observed_date = ?
      AND deleted_at IS NULL
");
$dup->execute([$babyId, $observed]);
if ((int)$dup->fetchColumn() > 0) {
    api_error('A growth entry already exists for this date.', 409);
}

$supportsLength = db_has_column($pdo, 'baby_weights', 'length_cm');
$supportsHead = db_has_column($pdo, 'baby_weights', 'head_circumference_cm');

$length = null;
if ($supportsLength && $lengthRaw !== '') {
    if (!is_numeric($lengthRaw)) api_error('Length must be a number (cm).', 400);
    $l = (float)$lengthRaw;
    if ($l < 10 || $l > 130) api_error('Length must be between 10 and 130 cm.', 400);
    $length = $l;
}

$head = null;
if ($supportsHead && $headRaw !== '') {
    if (!is_numeric($headRaw)) api_error('Head circumference must be a number (cm).', 400);
    $h = (float)$headRaw;
    if ($h < 10 || $h > 80) api_error('Head circumference must be between 10 and 80 cm.', 400);
    $head = $h;
}

if ($supportsLength || $supportsHead) {
    $cols = "baby_id, weight_kg, observed_date, created_at";
    $qs = "?, ?, ?, NOW()";
    $params = [$babyId, $weightRaw, $observed];
    if ($supportsLength) {
        $cols .= ", length_cm";
        $qs .= ", ?";
        $params[] = $length;
    }
    if ($supportsHead) {
        $cols .= ", head_circumference_cm";
        $qs .= ", ?";
        $params[] = $head;
    }
    $stmt = $pdo->prepare("INSERT INTO baby_weights ({$cols}) VALUES ({$qs})");
    $stmt->execute($params);
} else {
    $stmt = $pdo->prepare("
        INSERT INTO baby_weights (baby_id, weight_kg, observed_date, created_at)
        VALUES (?, ?, ?, NOW())
    ");
    $stmt->execute([$babyId, $weightRaw, $observed]);
}

api_ok(['saved' => true]);

