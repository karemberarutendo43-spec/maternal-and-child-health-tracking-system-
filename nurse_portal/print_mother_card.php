<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/crypto.php";

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'nurse'])) {
    header("Location: /mch_system/auth/login.php");
    exit;
}

$mother_id = (int)($_GET['mother_id'] ?? 0);
if (!$mother_id) {
    die("Mother not found.");
}

$stmt = $pdo->prepare("
    SELECT m.fullname, m.hospital_number, m.dob, m.phone, m.address, m.pregnancy_status,
           u.id AS user_id, u.clinic_id
    FROM mothers m
    LEFT JOIN users u ON m.user_id = u.id
    WHERE m.id = ?
    LIMIT 1
");
$stmt->execute([$mother_id]);
$m = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$m) {
    die("Mother not found.");
}

$m['address'] = mch_crypto_decrypt($m['address'] ?? null);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>ANC Card</title>
  <style>
    body { font-family: "Plus Jakarta Sans", Arial, sans-serif; background: #f8fafc; }
    .card { width: 560px; background: #fff; margin: 40px auto; border: 1px solid #e2e8f0; padding: 24px; border-radius: 14px; box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08); }
    h1 { font-size: 18px; margin: 0 0 10px; color: #0f172a; }
    .sub { font-size: 12px; color: #64748b; margin-bottom: 12px; }
    .row { margin: 8px 0; display: flex; gap: 10px; }
    .label { color: #64748b; width: 140px; flex-shrink: 0; }
    .value { color: #0f172a; font-weight: 600; }
    .footer { margin-top: 16px; font-size: 12px; color: #64748b; }
    .badge { display: inline-block; padding: 2px 8px; border-radius: 999px; background: #e2e8f0; font-size: 11px; }
    @media print {
      body { background: #fff; }
      .card { border: none; margin: 0; width: auto; box-shadow: none; }
    }
  </style>
</head>
<body>
  <div class="card">
    <h1>Maternal & Child Health Card</h1>
    <div class="sub">Clinic record card for ANC tracking</div>
    <div class="row"><span class="label">Full Name</span><span class="value"><?= htmlspecialchars($m['fullname']) ?></span></div>
    <div class="row"><span class="label">Hospital #</span><span class="value"><?= htmlspecialchars($m['hospital_number']) ?></span></div>
    <div class="row"><span class="label">DOB</span><span class="value"><?= htmlspecialchars($m['dob']) ?></span></div>
    <div class="row"><span class="label">Phone</span><span class="value"><?= htmlspecialchars($m['phone']) ?></span></div>
    <div class="row"><span class="label">Address</span><span class="value"><?= htmlspecialchars($m['address']) ?></span></div>
    <div class="row"><span class="label">Status</span><span class="value"><span class="badge"><?= htmlspecialchars($m['pregnancy_status']) ?></span></span></div>
    <div class="row"><span class="label">Clinic ID</span><span class="value"><?= htmlspecialchars($m['clinic_id'] ?? '-') ?></span></div>
    <div class="row"><span class="label">System ID</span><span class="value"><?= htmlspecialchars($m['user_id'] ?? '-') ?></span></div>

    <div class="footer">Print and attach this card to the ANC booklet for quick identification.</div>
  </div>
  <script>
    window.print();
  </script>
</body>
</html>
