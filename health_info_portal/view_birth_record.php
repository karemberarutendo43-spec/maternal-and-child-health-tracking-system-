<?php
require_once __DIR__ . "/../config/session.php";
mch_session_start();
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/audit.php";
require_once __DIR__ . "/../config/cards.php";
require_once __DIR__ . "/../config/crypto.php";

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'health_info') {
    header("Location: /mch_system/auth/login.php");
    exit;
}

$hasRegisterTable = false;
try {
    $hasRegisterTable = (bool)$pdo->query("SHOW TABLES LIKE 'delivery_register'")->fetchColumn();
} catch (Throwable $e) {
    $hasRegisterTable = false;
}
if (!$hasRegisterTable) {
    http_response_code(500);
    echo "Delivery register is not installed.";
    exit;
}

$registerId = (int)($_GET['register_id'] ?? 0);
if ($registerId <= 0) {
    http_response_code(400);
    echo "Invalid register id.";
    exit;
}

$hasNat = db_has_column($pdo, 'mothers', 'national_id_number');
$hasMar = db_has_column($pdo, 'mothers', 'marital_status');
$hasBcn = db_has_column($pdo, 'babies', 'birth_confirmation_number');
$hasCardUid = db_has_column($pdo, 'babies', 'card_uid');

$stmt = $pdo->prepare("
    SELECT
      r.*,
      c.clinic_name,
      m.id AS mother_id,
      " . ($hasNat ? "m.national_id_number" : "NULL") . " AS national_id_number,
      " . ($hasMar ? "m.marital_status" : "NULL") . " AS marital_status,
      " . ($hasCardUid ? "b.card_uid" : "NULL") . " AS baby_card_uid,
      " . ($hasBcn ? "b.birth_confirmation_number" : "NULL") . " AS birth_confirmation_number
    FROM delivery_register r
    JOIN mothers m ON m.id = r.mother_id
    LEFT JOIN babies b ON b.id = r.baby_id
    LEFT JOIN clinics c ON c.id = r.clinic_id
    WHERE r.id = ?
    LIMIT 1
");
$stmt->execute([$registerId]);
$r = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$r) {
    http_response_code(404);
    echo "Record not found.";
    exit;
}

// Child DOB: from delivery date (register standard).
$childDob = substr((string)($r['delivery_datetime'] ?? ''), 0, 10);
$motherAddressPlain = mch_crypto_decrypt($r['mother_address'] ?? null);
$babyCardUidFormatted = '';
if (!empty($r['baby_card_uid'])) {
    $babyCardUidFormatted = format_card_uid((string)$r['baby_card_uid']);
}

mch_audit_log($pdo, 'BIRTH_RECORD_VIEW', 'health_info', $registerId, "Viewed birth record register_id={$registerId}");

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Birth Record</title>
  <style>
    @page { size: A4; margin: 14mm; }
    body { font-family: Arial, sans-serif; color: #111; }
    .wrap { max-width: 800px; margin: 0 auto; }
    .top { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; }
    h1 { font-size: 16px; margin: 0; }
    .meta { font-size: 11px; color: #374151; }
    .card { border: 1px solid #111; padding: 10px; margin-top: 10px; }
    .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .label { font-size: 11px; color: #374151; }
    .value { font-size: 13px; font-weight: 700; }
    .row { margin-top: 6px; }
    .actions { margin-top: 10px; }
    button { padding: 8px 12px; border-radius: 8px; border: 1px solid #111; background: #111; color: #fff; cursor: pointer; }
    @media print { .actions { display: none; } }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="top">
      <div>
        <h1>Birth Record Verification</h1>
        <div class="meta">Generated: <?= htmlspecialchars(date('Y-m-d H:i')) ?> • Register ID: <?= (int)$registerId ?></div>
      </div>
      <div class="actions">
        <button type="button" onclick="window.print()">Print</button>
      </div>
    </div>

    <div class="card">
      <div class="grid">
        <div>
          <div class="label">Mother (Name)</div>
          <div class="value"><?= htmlspecialchars((string)($r['mother_name'] ?? '')) ?></div>
          <div class="row">
            <span class="label">National ID:</span>
            <span class="value"><?= htmlspecialchars((string)($r['national_id_number'] ?? '')) ?></span>
          </div>
          <div class="row">
            <span class="label">Phone:</span>
            <span class="value"><?= htmlspecialchars((string)($r['mother_phone'] ?? '')) ?></span>
          </div>
           <div class="row">
             <span class="label">Address:</span>
             <span class="value"><?= htmlspecialchars((string)($motherAddressPlain ?? '')) ?></span>
           </div>
           <div class="row">
             <span class="label">Marital status:</span>
             <span class="value"><?= htmlspecialchars((string)($r['marital_status'] ?? '')) ?></span>
           </div>
         </div>

         <div>
           <div class="label">Hospital/Maternity Register #</div>
           <div class="value"><?= htmlspecialchars((string)($r['hospital_number'] ?? '')) ?></div>
           <div class="row">
             <span class="label">Delivery Register Serial #:</span>
             <span class="value"><?= htmlspecialchars((string)($r['serial_no'] ?? '')) ?></span>
           </div>
           <div class="row">
             <span class="label">ANC #:</span>
             <span class="value"><?= htmlspecialchars((string)($r['anc_no'] ?? '')) ?></span>
           </div>
          <div class="row">
            <span class="label">Delivery date/time:</span>
            <span class="value"><?= htmlspecialchars((string)($r['delivery_datetime'] ?? '')) ?></span>
          </div>
          <div class="row">
            <span class="label">Place of birth (facility):</span>
            <span class="value"><?= htmlspecialchars((string)($r['clinic_name'] ?? '')) ?></span>
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="grid">
        <div>
          <div class="label">Child Date of Birth</div>
          <div class="value"><?= htmlspecialchars((string)$childDob) ?></div>
        </div>
        <div>
          <div class="label">Baby Serial # (Card ID)</div>
          <div class="value"><?= htmlspecialchars($babyCardUidFormatted !== '' ? $babyCardUidFormatted : '-') ?></div>
        </div>
        <div>
          <div class="label">Birth Confirmation #</div>
          <div class="value"><?= htmlspecialchars((string)($r['birth_confirmation_number'] ?? '') ?: '-') ?></div>
        </div>
        <div>
          <div class="label">Birth Outcome</div>
          <div class="value"><?= htmlspecialchars((string)($r['birth_outcome'] ?? '')) ?></div>
        </div>
        <div>
          <div class="label">Sex</div>
          <div class="value"><?= htmlspecialchars((string)($r['baby_sex'] ?? '')) ?></div>
        </div>
        <div>
          <div class="label">Birth Weight</div>
          <div class="value"><?= htmlspecialchars((string)($r['birth_weight_grams'] ?? '')) ?> g</div>
        </div>
        <div>
          <div class="label">Apgar</div>
          <div class="value"><?= htmlspecialchars((string)($r['apgar_score'] ?? '')) ?></div>
        </div>
        <div>
          <div class="label">Delivery Method</div>
          <div class="value"><?= htmlspecialchars((string)($r['delivery_method'] ?? '')) ?></div>
        </div>
      </div>
    </div>

    <div class="meta" style="margin-top:10px;">
      Note: This page intentionally hides sensitive clinical fields (HIV/PMTCT, complications, etc.). Use nurse/admin portals for clinical review.
    </div>
  </div>
</body>
</html>
