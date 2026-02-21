<?php
require_once __DIR__ . "/../config/db.php";

header("Content-Type: application/json");

$phone = trim($_GET['phone'] ?? '');
if (!$phone) {
    echo json_encode(["ok" => false]);
    exit;
}

if (preg_match('/^7\\d{8}$/', $phone)) {
    $phone = '+263' . $phone;
}
$rawPhone = $phone;
if (preg_match('/^\\+2637\\d{8}$/', $phone)) {
    $rawPhone = '7' . substr($phone, 4);
}

$stmt = $pdo->prepare("
    SELECT profile_photo, full_name
    FROM users
    WHERE phone IN (?, ?) AND role = 'mother'
    LIMIT 1
");
$stmt->execute([$phone, $rawPhone]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if ($row && !empty($row['profile_photo'])) {
    echo json_encode(["ok" => true, "profile_photo" => $row['profile_photo'], "full_name" => $row['full_name']]);
    exit;
}

if ($row && !empty($row['full_name'])) {
    echo json_encode(["ok" => true, "full_name" => $row['full_name']]);
    exit;
}

echo json_encode(["ok" => false]);
