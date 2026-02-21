<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/cards.php";
require_once __DIR__ . "/../libs/qr.php";

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'nurse'], true)) {
    header("Location: /mch_system/auth/login.php");
    exit;
}

$baby_id = (int)($_GET['baby_id'] ?? 0);
$card_uid = normalize_card_uid((string)($_GET['card_uid'] ?? ($_GET['cid'] ?? '')));

if ($baby_id <= 0 && $card_uid !== '') {
    $lookup = $pdo->prepare("SELECT id FROM babies WHERE card_uid = ? LIMIT 1");
    $lookup->execute([$card_uid]);
    $row = $lookup->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $baby_id = (int)$row['id'];
    }
}

if ($baby_id <= 0) {
    die("Invalid baby ID.");
}

$stmt = $pdo->prepare("
    SELECT
        b.id,
        b.baby_name,
        b.dob,
        b.gender,
        b.birth_weight,
        b.card_uid,
        m.fullname AS mother_name,
        m.hospital_number,
        c.clinic_name
    FROM babies b
    JOIN mothers m ON b.mother_id = m.id
    LEFT JOIN users u ON m.user_id = u.id
    LEFT JOIN clinics c ON u.clinic_id = c.id
    WHERE b.id = ?
    LIMIT 1
");
$stmt->execute([$baby_id]);
$baby = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$baby) {
    die("Baby not found.");
}

$babyCardUid = normalize_card_uid((string)($baby['card_uid'] ?? ''));
if ($babyCardUid === '') {
    die("This baby does not have a Card ID yet. Open the baby record and assign one first.");
}

$payload = "CID:" . $babyCardUid; // QR payload (fits QR v1-M alphanumeric capacity)
$qrSvg = qr_v1m_alnum_svg($payload, 8, 3);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Print Baby Card Sticker</title>
  <style>
    body { font-family: Arial, sans-serif; background: #fff; margin: 0; }
    .sheet { padding: 18px; }
    .sticker {
      width: 86mm;
      min-height: 44mm;
      border: 1px solid #111827;
      border-radius: 10px;
      padding: 10px;
      display: grid;
      grid-template-columns: 1fr 1.4fr;
      gap: 10px;
      align-items: center;
    }
    .qr { display:flex; align-items:center; justify-content:center; }
    .title { font-size: 12px; font-weight: 700; margin-bottom: 4px; }
    .id { font-size: 14px; font-weight: 800; letter-spacing: 0.08em; margin: 2px 0 6px; }
    .meta { font-size: 10px; color: #111827; line-height: 1.25; }
    .muted { color: #374151; }
    @media print {
      .sheet { padding: 0; }
      .sticker { border-width: 1px; }
    }
  </style>
</head>
<body>
  <div class="sheet">
    <div class="sticker">
      <div class="qr">
        <?= $qrSvg ?>
      </div>
      <div>
        <div class="title">MCHTS — Zimbabwe Child Health Card</div>
        <div class="id"><?= htmlspecialchars(format_card_uid($babyCardUid)) ?></div>
        <div class="meta"><span class="muted">Baby:</span> <?= htmlspecialchars($baby['baby_name'] ?? '-') ?></div>
        <div class="meta"><span class="muted">DOB:</span> <?= htmlspecialchars($baby['dob'] ?? '-') ?> <span class="muted">Sex:</span> <?= htmlspecialchars($baby['gender'] ?? '-') ?></div>
        <div class="meta"><span class="muted">Mother:</span> <?= htmlspecialchars($baby['mother_name'] ?? '-') ?></div>
        <div class="meta"><span class="muted">Hospital #:</span> <?= htmlspecialchars($baby['hospital_number'] ?? '-') ?></div>
        <div class="meta"><span class="muted">Clinic:</span> <?= htmlspecialchars($baby['clinic_name'] ?? 'Unassigned') ?></div>
      </div>
    </div>
  </div>

  <script>
    window.print();
  </script>
</body>
</html>

