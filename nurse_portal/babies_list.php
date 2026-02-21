<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/scope.php";
require_once __DIR__ . "/../config/csrf.php";
require_once __DIR__ . "/../config/cards.php";

// Access check
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'nurse'])) {
    header("Location: /mch_system/auth/login.php");
    exit;
}

$page = 'babies_list';
include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_nurse.php";

$error = '';
$success = '';
$today = date('Y-m-d');
$isNurse = ((string)($_SESSION['role'] ?? '') === 'nurse');
$scopedClinicId = mch_scope_scoped_clinic_id();
if ((string)($_SESSION['role'] ?? '') === 'nurse' && $scopedClinicId <= 0) {
  $error = "Your account is not assigned to a clinic. Please contact Admin.";
}

$supportsLength = db_has_column($pdo, 'baby_weights', 'length_cm');
$supportsHead = db_has_column($pdo, 'baby_weights', 'head_circumference_cm');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_weight'])) {
  mch_csrf_require_valid('nurse_baby_weights');
  $baby_id = (int)($_POST['baby_id'] ?? 0);
  $weight = trim($_POST['weight_kg'] ?? '');
  $length_cm = $supportsLength ? trim((string)($_POST['length_cm'] ?? '')) : '';
  $head_cm = $supportsHead ? trim((string)($_POST['head_circumference_cm'] ?? '')) : '';
  $observed_date = trim((string)($_POST['observed_date'] ?? $today));

  if (!$baby_id || $weight === '') {
    $error = "Baby and weight are required.";
  } elseif (!preg_match('/^\d+(\.\d{1,2})?$/', $weight)) {
    $error = "Weight must be a number (e.g., 3.2).";
  } else {
    $wkg = (float)$weight;
    if ($wkg < 0 || $wkg > 30) {
      $error = "Weight must be between 0 and 30 kg.";
    }
  }

  if ($error === '') {
    if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $observed_date) || strtotime($observed_date) === false) {
      $error = "Observation date must be a valid date (YYYY-MM-DD).";
    } elseif ($observed_date > $today) {
      $error = "Observation date cannot be in the future.";
    } elseif ($isNurse && $observed_date !== $today) {
      $error = "For nurses, observation date must be today.";
    }
  }

  if ($error === '' && $supportsLength && $length_cm !== '') {
    if (!preg_match('/^\d+(\.\d{1,2})?$/', $length_cm)) {
      $error = "Length must be a number (cm).";
    } else {
      $l = (float)$length_cm;
      if ($l < 10 || $l > 130) $error = "Length must be between 10 and 130 cm.";
    }
  }
  if ($error === '' && $supportsHead && $head_cm !== '') {
    if (!preg_match('/^\d+(\.\d{1,2})?$/', $head_cm)) {
      $error = "Head circumference must be a number (cm).";
    } else {
      $h = (float)$head_cm;
      if ($h < 10 || $h > 80) $error = "Head circumference must be between 10 and 80 cm.";
    }
  }

  if ($error === '') {
    // Clinic scope check (prevents IDOR: editing weights for other clinics).
    if ($scopedClinicId > 0) {
      $scopeStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM babies b
        JOIN mothers m ON b.mother_id = m.id
        JOIN users u ON m.user_id = u.id
        WHERE b.id = ?
          AND u.clinic_id = ?
        LIMIT 1
      ");
      $scopeStmt->execute([$baby_id, $scopedClinicId]);
      if ((int)$scopeStmt->fetchColumn() !== 1) {
        $error = "You can only record weights for babies in your clinic.";
      }
    }
  }

  if ($error === '') {
    $dup = $pdo->prepare("
      SELECT COUNT(*)
      FROM baby_weights
      WHERE baby_id = ?
        AND observed_date = ?
        AND deleted_at IS NULL
    ");
    $dup->execute([$baby_id, $observed_date]);
    if ((int)$dup->fetchColumn() > 0) {
      $error = "A weight entry already exists for this date (for this baby).";
    }
  }

  if ($error === '') {
    if ($supportsLength || $supportsHead) {
      $cols = "baby_id, weight_kg, observed_date, created_at";
      $qs = "?, ?, ?, NOW()";
      $params = [$baby_id, $weight, $observed_date];
      if ($supportsLength) {
        $cols .= ", length_cm";
        $qs .= ", ?";
        $params[] = ($length_cm !== '' ? $length_cm : null);
      }
      if ($supportsHead) {
        $cols .= ", head_circumference_cm";
        $qs .= ", ?";
        $params[] = ($head_cm !== '' ? $head_cm : null);
      }
      $stmt = $pdo->prepare("INSERT INTO baby_weights ({$cols}) VALUES ({$qs})");
      $stmt->execute($params);
    } else {
      $stmt = $pdo->prepare("
              INSERT INTO baby_weights (baby_id, weight_kg, observed_date, created_at)
              VALUES (?, ?, ?, NOW())
          ");
      $stmt->execute([$baby_id, $weight, $observed_date]);
    }
    $success = "Weight recorded.";
  }
}

if ($scopedClinicId > 0) {
  $stmt = $pdo->prepare("
      SELECT b.id, b.baby_name, b.dob, b.gender, b.birth_weight, m.fullname AS mother_name
      FROM babies b
      JOIN mothers m ON b.mother_id = m.id
      JOIN users u ON m.user_id = u.id
      WHERE u.clinic_id = ?
      ORDER BY b.dob DESC
  ");
  $stmt->execute([$scopedClinicId]);
  $babies = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
  $babies = $pdo->query("
      SELECT b.id, b.baby_name, b.dob, b.gender, b.birth_weight, m.fullname AS mother_name
      FROM babies b
      JOIN mothers m ON b.mother_id = m.id
      ORDER BY b.dob DESC
  ")->fetchAll(PDO::FETCH_ASSOC);
}
?>

<div class="ml-64 p-6">
    <div class="mch-card bg-white/90 p-6 border border-slate-100">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-2xl font-semibold text-slate-900">Baby Weights</h2>
            <a href="/mch_system/nurse_portal/dashboard.php#quick_links" class="text-xs font-semibold text-slate-600 hover:text-slate-900">
                Back to Quick Links
            </a>
        </div>

        <?php if ($error): ?>
            <div class="bg-rose-100 text-rose-700 p-3 rounded-lg mb-4"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="bg-emerald-100 text-emerald-700 p-3 rounded-lg mb-4"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <?php if (empty($babies)): ?>
            <p class="text-slate-500">No babies registered.</p>
        <?php else: ?>
            <table class="w-full text-sm mch-table mch-scroll">
                <thead>
                    <tr>
                        <th class="p-2 text-left">Baby</th>
                        <th class="p-2 text-left">Mother</th>
                        <th class="p-2 text-left">DOB</th>
                        <th class="p-2 text-left">Gender</th>
                        <th class="p-2 text-left">Birth Weight</th>
                        <th class="p-2 text-left">Add Weight</th>
                        <th class="p-2 text-left">Growth</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($babies as $b): ?>
                        <tr class="border-t border-slate-200">
                            <td class="p-2"><?= htmlspecialchars($b['baby_name']) ?></td>
                            <td class="p-2"><?= htmlspecialchars($b['mother_name']) ?></td>
                            <td class="p-2"><?= htmlspecialchars($b['dob']) ?></td>
                            <td class="p-2"><?= htmlspecialchars($b['gender']) ?></td>
                            <td class="p-2"><?= htmlspecialchars($b['birth_weight'] ?? '-') ?></td>
                            <td class="p-2">
                                <form method="POST" class="flex flex-wrap items-center gap-2">
                                    <?= mch_csrf_input('nurse_baby_weights') ?>
                                    <input type="hidden" name="baby_id" value="<?= (int)$b['id'] ?>">
                                     <input type="number" step="0.01" min="0" name="weight_kg" placeholder="kg"
                                           max="30" class="border border-slate-200 p-1 rounded-lg w-20 bg-white" required>
                                    <?php if ($supportsLength): ?>
                                        <input type="number" step="0.01" min="10" name="length_cm" placeholder="cm"
                                               max="130" class="border border-slate-200 p-1 rounded-lg w-20 bg-white">
                                    <?php endif; ?>
                                    <?php if ($supportsHead): ?>
                                        <input type="number" step="0.01" min="10" name="head_circumference_cm" placeholder="head cm"
                                               max="80" class="border border-slate-200 p-1 rounded-lg w-24 bg-white">
                                    <?php endif; ?>
                                    <input type="date" name="observed_date"
                                           class="border border-slate-200 p-1 rounded-lg bg-white" value="<?= $today ?>"
                                           <?php if ($isNurse): ?>min="<?= $today ?>"<?php endif; ?> max="<?= $today ?>" required>
                                    <button name="add_weight" class="bg-slate-900 text-white px-3 py-1 rounded-lg text-xs hover:bg-slate-800">
                                        Save
                                    </button>
                                </form>
                            </td>
                            <td class="p-2">
                                <a class="text-xs font-semibold text-teal-700 hover:underline" href="/mch_system/nurse_portal/baby_growth.php?baby_id=<?= (int)$b['id'] ?>">
                                    View chart
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . "/../includes/footer.php"; ?>
