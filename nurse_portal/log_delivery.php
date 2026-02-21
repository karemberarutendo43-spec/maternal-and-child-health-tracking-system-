<?php
require_once '../config/db.php';
require_once '../config/auth.php';
require_once __DIR__ . '/../config/cards.php';
require_once __DIR__ . '/../config/epi.php';
require_once __DIR__ . '/../config/csrf.php';

// Access check
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'nurse'])) {
    header("Location: /mch_system/auth/login.php");
    exit;
}

$page = 'log_delivery';
include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_nurse.php";

$error = '';
$success = '';
$birth_confirmation_number = '';

// Fetch all currently pregnant mothers
$mothers = $pdo->query("
    SELECT id, fullname, hospital_number
    FROM mothers
    WHERE pregnancy_status = 'Pregnant'
    ORDER BY fullname
")->fetchAll(PDO::FETCH_ASSOC);

$supportsBcn = db_has_column($pdo, 'babies', 'birth_confirmation_number');
$supportsDeliveryDate = db_has_column($pdo, 'mothers', 'delivery_date');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mch_csrf_require_valid('nurse_log_delivery');

    $mother_id = $_POST['mother_id'];
    $baby_name = trim($_POST['baby_name']);
    $dob       = $_POST['dob'];
    $gender    = $_POST['gender'];
    $birth_confirmation_number = trim((string)($_POST['birth_confirmation_number'] ?? ''));
    $birth_weight_raw = trim((string)($_POST['birth_weight'] ?? ''));
    if ($birth_weight_raw === '' || !is_numeric($birth_weight_raw)) {
        $error = "Birth weight is required and must be a number.";
    } else {
        $bw = (float)$birth_weight_raw;
        if ($bw < 0 || $bw > 5) {
            $error = "Birth weight must be between 0 and 5 kg.";
        }
    }
    $birth_weight = $birth_weight_raw;

    try {
        if ($error) {
            throw new Exception($error);
        }
        $pdo->beginTransaction();

        // 1️⃣ Update mother status to Postnatal
        if ($supportsDeliveryDate) {
            $stmt = $pdo->prepare("UPDATE mothers SET pregnancy_status = 'Postnatal', delivery_date = ? WHERE id = ?");
            $stmt->execute([$dob, $mother_id]);
        } else {
            $stmt = $pdo->prepare("UPDATE mothers SET pregnancy_status = 'Postnatal' WHERE id = ?");
            $stmt->execute([$mother_id]);
        }

        // 2️⃣ Insert baby record
        if ($supportsBcn && $birth_confirmation_number !== '') {
            $dup = $pdo->prepare("SELECT id FROM babies WHERE birth_confirmation_number = ? LIMIT 1");
            $dup->execute([$birth_confirmation_number]);
            if ($dup->fetch(PDO::FETCH_ASSOC)) {
                throw new Exception("Birth Confirmation Number already exists.");
            }
        }

        $supportsCard = babies_supports_card_uid($pdo);
        $card_uid = $supportsCard ? generate_card_uid() : null;

        if ($supportsCard && $supportsBcn) {
            $stmt = $pdo->prepare("
                INSERT INTO babies (mother_id, baby_name, dob, gender, birth_weight, card_uid, birth_confirmation_number, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$mother_id, $baby_name, $dob, $gender, $birth_weight, $card_uid, ($birth_confirmation_number !== '' ? $birth_confirmation_number : null)]);
        } elseif ($supportsCard && !$supportsBcn) {
            $stmt = $pdo->prepare("
                INSERT INTO babies (mother_id, baby_name, dob, gender, birth_weight, card_uid, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$mother_id, $baby_name, $dob, $gender, $birth_weight, $card_uid]);
        } elseif (!$supportsCard && $supportsBcn) {
            $stmt = $pdo->prepare("
                INSERT INTO babies (mother_id, baby_name, dob, gender, birth_weight, birth_confirmation_number, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$mother_id, $baby_name, $dob, $gender, $birth_weight, ($birth_confirmation_number !== '' ? $birth_confirmation_number : null)]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO babies (mother_id, baby_name, dob, gender, birth_weight, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$mother_id, $baby_name, $dob, $gender, $birth_weight]);
        }
        $baby_id = $pdo->lastInsertId();

        // 3️⃣ Preload immunizations with realistic due dates
        mch_epi_insert_schedule($pdo, (int)$baby_id, (string)$dob);

        $pdo->commit();
        $success = "Delivery logged successfully! Baby and immunizations created.";

    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Error: " . $e->getMessage();
    }
}
?>

<div class="ml-64 p-6 max-w-3xl">
  <div class="mch-card bg-white/90 p-6 border border-slate-100">
    <h2 class="text-2xl font-semibold text-slate-900 mb-4">Log Delivery</h2>

    <?php if($error): ?>
      <div class="bg-rose-100 text-rose-700 p-3 rounded-lg mb-4"><?= $error ?></div>
    <?php endif; ?>
    <?php if($success): ?>
      <div class="bg-emerald-100 text-emerald-700 p-3 rounded-lg mb-4"><?= $success ?></div>
    <?php endif; ?>

    <form method="POST" class="space-y-4">
      <?= mch_csrf_input('nurse_log_delivery') ?>
      <select name="mother_id" required class="w-full border border-slate-200 p-2 rounded-lg bg-white">
        <option value="">Select Mother (Pregnant)</option>
        <?php foreach($mothers as $m): ?>
          <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['fullname']) ?> (<?= $m['hospital_number'] ?>)</option>
        <?php endforeach; ?>
      </select>

      <input type="text" name="baby_name" placeholder="Baby Name" required class="w-full border border-slate-200 p-2 rounded-lg bg-white">
      <input type="date" name="dob" placeholder="Date of Birth" required class="w-full border border-slate-200 p-2 rounded-lg bg-white">
      <select name="gender" required class="w-full border border-slate-200 p-2 rounded-lg bg-white">
        <option value="">Select Gender</option>
        <option value="Male">Male</option>
        <option value="Female">Female</option>
      </select>

      <div>
        <input type="text" name="birth_confirmation_number" placeholder="Birth Confirmation Number (optional)"
               class="w-full border border-slate-200 p-2 rounded-lg bg-white"
               value="<?= htmlspecialchars($birth_confirmation_number ?? '') ?>">
        <?php if (!$supportsBcn): ?>
          <p class="text-xs text-amber-700 mt-1">To enable this, run <span class="font-mono">sql/birth_confirmation_number.sql</span> on the database.</p>
        <?php endif; ?>
      </div>

      <input type="number" step="0.01" min="0" max="5" name="birth_weight" placeholder="Birth Weight (kg)" required class="w-full border border-slate-200 p-2 rounded-lg bg-white">

      <button type="submit" class="mch-btn w-full py-2">Log Delivery</button>
    </form>
  </div>
</div>

<?php include __DIR__ . "/../includes/footer.php"; ?>
