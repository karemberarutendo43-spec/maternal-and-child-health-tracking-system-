<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/sms.php";
require_once __DIR__ . "/../config/cards.php";
require_once __DIR__ . "/../config/who_growth.php";
require_once __DIR__ . "/../config/scope.php";
require_once __DIR__ . "/../config/csrf.php";
require_once __DIR__ . "/../config/crypto.php";

function mch_baby_weights_has_growth_columns(PDO $pdo): bool
{
    static $has = null;
    if ($has !== null) {
        return $has;
    }

    try {
        $pdo->query("SELECT length_cm, head_circumference_cm FROM baby_weights LIMIT 0");
        $has = true;
    } catch (Exception $e) {
        $has = false;
    }

    return $has;
}

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'nurse'], true)) {
    header("Location: /mch_system/auth/login.php");
    exit;
}

$healthCentreOptions = [];
try {
    $healthCentreOptions = $pdo->query("SELECT clinic_name FROM clinics ORDER BY clinic_name")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $healthCentreOptions = [];
}

$error = '';
$success = '';

$baby_id = (int)($_GET['baby_id'] ?? ($_POST['baby_id'] ?? 0));
$card_uid = normalize_card_uid((string)($_GET['card_uid'] ?? ($_POST['card_uid'] ?? ($_GET['cid'] ?? ($_POST['cid'] ?? '')))));
if ($baby_id <= 0 && $card_uid !== '') {
    $lookup = $pdo->prepare("SELECT id FROM babies WHERE card_uid = ? LIMIT 1");
    $lookup->execute([$card_uid]);
    $found = $lookup->fetch(PDO::FETCH_ASSOC);
    if ($found) {
        $baby_id = (int)$found['id'];
    } else {
        $error = "No baby record found for that Card ID.";
    }
}
if ($baby_id <= 0) {
    $page = 'baby_history';
    include __DIR__ . "/../includes/header.php";
    include __DIR__ . "/../includes/sidebar_nurse.php";
    ?>
    <div class="ml-64 p-6">
      <div class="mch-card bg-white/90 p-6 border border-slate-100 max-w-2xl">
        <h2 class="text-2xl font-semibold text-slate-900 mb-2">Baby Health Records</h2>
        <p class="text-slate-600 mb-4">Could not open the baby record.</p>
        <?php if ($error): ?>
          <div class="bg-rose-100 text-rose-700 p-3 rounded-lg mb-4"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <a href="/mch_system/nurse_portal/scan_baby_card.php" class="mch-btn px-4 py-2 inline-flex">Scan Baby Card</a>
      </div>
    </div>
    <?php
    include __DIR__ . "/../includes/footer.php";
    exit;
}

$scopedClinicId = mch_scope_scoped_clinic_id();
if ((string)($_SESSION['role'] ?? '') === 'nurse' && $scopedClinicId <= 0) {
    http_response_code(403);
    $error = "Your account is not assigned to a clinic. Please contact Admin.";
    $page = 'baby_history';
    include __DIR__ . "/../includes/header.php";
    include __DIR__ . "/../includes/sidebar_nurse.php";
    ?>
    <div class="ml-64 p-6">
      <div class="mch-card bg-white/90 p-6 border border-slate-100 max-w-2xl">
        <h2 class="text-2xl font-semibold text-slate-900 mb-2">Baby Health Records</h2>
        <div class="bg-rose-100 text-rose-700 p-3 rounded-lg mb-4"><?= htmlspecialchars($error) ?></div>
        <a href="/mch_system/nurse_portal/dashboard.php" class="mch-btn px-4 py-2 inline-flex">Back</a>
      </div>
    </div>
    <?php
    include __DIR__ . "/../includes/footer.php";
    exit;
}
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
        http_response_code(403);
        $error = "403 Forbidden: You do not have access to this baby record.";
        $page = 'baby_history';
        include __DIR__ . "/../includes/header.php";
        include __DIR__ . "/../includes/sidebar_nurse.php";
        ?>
        <div class="ml-64 p-6">
          <div class="mch-card bg-white/90 p-6 border border-slate-100 max-w-2xl">
            <h2 class="text-2xl font-semibold text-slate-900 mb-2">Baby Health Records</h2>
            <div class="bg-rose-100 text-rose-700 p-3 rounded-lg mb-4"><?= htmlspecialchars($error) ?></div>
            <a href="/mch_system/nurse_portal/find_baby.php" class="mch-btn px-4 py-2 inline-flex">Find Baby</a>
          </div>
        </div>
        <?php
        include __DIR__ . "/../includes/footer.php";
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  mch_csrf_require_valid('nurse_baby_history');
  $action = $_POST['action'] ?? '';
  try {
	        if ($action === 'assign_card_uid') {
	            $cstmt = $pdo->prepare("SELECT card_uid FROM babies WHERE id = ? LIMIT 1");
	            $cstmt->execute([$baby_id]);
	            $crow = $cstmt->fetch(PDO::FETCH_ASSOC);
            if (!$crow) {
                throw new Exception("Baby not found.");
            }
            if (!empty($crow['card_uid'])) {
                throw new Exception("This baby already has a Card ID.");
            }

            $assigned = false;
            for ($i = 0; $i < 5; $i++) {
                $new_uid = generate_card_uid();
                try {
                    $upd = $pdo->prepare("UPDATE babies SET card_uid = ? WHERE id = ? AND (card_uid IS NULL OR card_uid = '')");
                    $upd->execute([$new_uid, $baby_id]);
                    if ($upd->rowCount() > 0) {
                        $success = "Card ID assigned: " . format_card_uid($new_uid);
                        $assigned = true;
                        break;
                    }
                } catch (Exception $e) {
                    // retry on rare duplicate collisions; otherwise surface the error
                    if (strpos($e->getMessage(), 'card_uid') === false) {
                        throw $e;
                    }
                }
            }
	            if (!$assigned) {
	                throw new Exception("Failed to assign Card ID. Please try again.");
	            }
	        }

	        if ($action === 'save_bio') {
	            $surname = trim($_POST['surname'] ?? '');
	            $health_centre = trim($_POST['health_centre'] ?? '');
	            $health_centre_other = trim($_POST['health_centre_other'] ?? '');
	            if ($health_centre === '__OTHER__') {
	                $health_centre = $health_centre_other;
	            }
	            $address = trim($_POST['address'] ?? '');
	            $father_name = trim($_POST['father_name'] ?? '');
	            $mother_name_on_card = trim($_POST['mother_name_on_card'] ?? '');
	            $mother_national_id = trim($_POST['mother_national_id'] ?? '');
	            $birth_entry_number = trim($_POST['birth_entry_number'] ?? '');

                $mother_national_id_enc = $mother_national_id !== '' ? mch_crypto_encrypt($mother_national_id) : null;

	            $stmt = $pdo->prepare("
	                INSERT INTO baby_bio_data
	                  (baby_id, surname, health_centre, address, father_name, mother_name_on_card, mother_national_id, birth_entry_number, created_at, updated_at)
	                VALUES
	                  (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
	                ON DUPLICATE KEY UPDATE
	                  surname = VALUES(surname),
	                  health_centre = VALUES(health_centre),
	                  address = VALUES(address),
	                  father_name = VALUES(father_name),
	                  mother_name_on_card = VALUES(mother_name_on_card),
	                  mother_national_id = VALUES(mother_national_id),
	                  birth_entry_number = VALUES(birth_entry_number),
	                  updated_at = NOW()
	            ");
	            $stmt->execute([
	                $baby_id,
	                $surname !== '' ? $surname : null,
	                $health_centre !== '' ? $health_centre : null,
	                $address !== '' ? $address : null,
	                $father_name !== '' ? $father_name : null,
	                $mother_name_on_card !== '' ? $mother_name_on_card : null,
	                $mother_national_id_enc,
	                $birth_entry_number !== '' ? $birth_entry_number : null,
	            ]);
	            $success = "Bio-data saved.";
	        }

	        if ($action === 'save_birth_history') {
	            $labour_duration_hours = trim($_POST['labour_duration_hours'] ?? '');
	            $mode_of_delivery = trim($_POST['mode_of_delivery'] ?? '');
	            $mode_of_delivery_other = trim($_POST['mode_of_delivery_other'] ?? '');
	            if ($mode_of_delivery === '__OTHER__') {
	                $mode_of_delivery = $mode_of_delivery_other;
	            }
	            $apgar_1min = trim($_POST['apgar_1min'] ?? '');
	            $apgar_5min = trim($_POST['apgar_5min'] ?? '');
	            $head_circumference_cm = trim($_POST['head_circumference_cm'] ?? '');
	            $birth_length_cm = trim($_POST['birth_length_cm'] ?? '');

	            $stmt = $pdo->prepare("
	                INSERT INTO baby_birth_history
	                  (baby_id, labour_duration_hours, mode_of_delivery, apgar_1min, apgar_5min, head_circumference_cm, birth_length_cm, created_at, updated_at)
	                VALUES
	                  (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
	                ON DUPLICATE KEY UPDATE
	                  labour_duration_hours = VALUES(labour_duration_hours),
	                  mode_of_delivery = VALUES(mode_of_delivery),
	                  apgar_1min = VALUES(apgar_1min),
	                  apgar_5min = VALUES(apgar_5min),
	                  head_circumference_cm = VALUES(head_circumference_cm),
	                  birth_length_cm = VALUES(birth_length_cm),
	                  updated_at = NOW()
	            ");
	            $stmt->execute([
	                $baby_id,
	                $labour_duration_hours !== '' ? (float)$labour_duration_hours : null,
	                $mode_of_delivery !== '' ? $mode_of_delivery : null,
	                $apgar_1min !== '' ? (int)$apgar_1min : null,
	                $apgar_5min !== '' ? (int)$apgar_5min : null,
	                $head_circumference_cm !== '' ? (float)$head_circumference_cm : null,
	                $birth_length_cm !== '' ? (float)$birth_length_cm : null,
	            ]);
	            $success = "Birth history saved.";
	        }

	        if ($action === 'save_risk_factors') {
	            $low_birth_weight = isset($_POST['low_birth_weight']) ? 1 : 0;
	            $fifth_child_plus = isset($_POST['fifth_child_plus']) ? 1 : 0;
	            $multiple_births = isset($_POST['multiple_births']) ? 1 : 0;
	            $mtct_pmtct = isset($_POST['mtct_pmtct']) ? 1 : 0;
	            $birth_interval_lt_2yrs = isset($_POST['birth_interval_lt_2yrs']) ? 1 : 0;
	            $single_parent = isset($_POST['single_parent']) ? 1 : 0;
	            $maternal_death = isset($_POST['maternal_death']) ? 1 : 0;
	            $severe_jaundice = isset($_POST['severe_jaundice']) ? 1 : 0;
	            $birth_asphyxia = isset($_POST['birth_asphyxia']) ? 1 : 0;
	            $notes = trim($_POST['risk_notes'] ?? '');

	            $stmt = $pdo->prepare("
	                INSERT INTO baby_risk_factors
	                  (baby_id, low_birth_weight, fifth_child_plus, multiple_births, mtct_pmtct, birth_interval_lt_2yrs, single_parent, maternal_death, severe_jaundice, birth_asphyxia, notes, created_at, updated_at)
	                VALUES
	                  (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
	                ON DUPLICATE KEY UPDATE
	                  low_birth_weight = VALUES(low_birth_weight),
	                  fifth_child_plus = VALUES(fifth_child_plus),
	                  multiple_births = VALUES(multiple_births),
	                  mtct_pmtct = VALUES(mtct_pmtct),
	                  birth_interval_lt_2yrs = VALUES(birth_interval_lt_2yrs),
	                  single_parent = VALUES(single_parent),
	                  maternal_death = VALUES(maternal_death),
	                  severe_jaundice = VALUES(severe_jaundice),
	                  birth_asphyxia = VALUES(birth_asphyxia),
	                  notes = VALUES(notes),
	                  updated_at = NOW()
	            ");
	            $stmt->execute([
	                $baby_id,
	                $low_birth_weight,
	                $fifth_child_plus,
	                $multiple_births,
	                $mtct_pmtct,
	                $birth_interval_lt_2yrs,
	                $single_parent,
	                $maternal_death,
	                $severe_jaundice,
	                $birth_asphyxia,
	                $notes !== '' ? $notes : null,
	            ]);
	            $success = "Risk factors saved.";
	        }

	        if ($action === 'save_milestones') {
	            $solids_introduced_date = trim($_POST['solids_introduced_date'] ?? '');
	            $crawling_date = trim($_POST['crawling_date'] ?? '');
	            $talking_date = trim($_POST['talking_date'] ?? '');
	            $breastfeeding_stopped_date = trim($_POST['breastfeeding_stopped_date'] ?? '');

	            $stmt = $pdo->prepare("
	                INSERT INTO baby_milestones
	                  (baby_id, solids_introduced_date, crawling_date, talking_date, breastfeeding_stopped_date, created_at, updated_at)
	                VALUES
	                  (?, ?, ?, ?, ?, NOW(), NOW())
	                ON DUPLICATE KEY UPDATE
	                  solids_introduced_date = VALUES(solids_introduced_date),
	                  crawling_date = VALUES(crawling_date),
	                  talking_date = VALUES(talking_date),
	                  breastfeeding_stopped_date = VALUES(breastfeeding_stopped_date),
	                  updated_at = NOW()
	            ");
	            $stmt->execute([
	                $baby_id,
	                $solids_introduced_date !== '' ? $solids_introduced_date : null,
	                $crawling_date !== '' ? $crawling_date : null,
	                $talking_date !== '' ? $talking_date : null,
	                $breastfeeding_stopped_date !== '' ? $breastfeeding_stopped_date : null,
	            ]);
	            $success = "Milestones saved.";
	        }

	        if ($action === 'log_weight') {
	            $weight = trim($_POST['weight_kg'] ?? '');
	            $observed_date = trim($_POST['observed_date'] ?? '');
	            $length_cm = trim($_POST['length_cm'] ?? '');
	            $head_circumference_cm = trim($_POST['head_circumference_cm'] ?? '');

            if ($weight === '' || $observed_date === '') {
                throw new Exception("Weight and observation date are required.");
            }
            $today = date('Y-m-d');
            if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $observed_date) || strtotime($observed_date) === false) {
                throw new Exception("Observation date must be a valid date (YYYY-MM-DD).");
            }
            if ($observed_date > $today) {
                throw new Exception("Observation date cannot be in the future.");
            }
            if ((string)($_SESSION['role'] ?? '') === 'nurse' && $observed_date !== $today) {
                throw new Exception("For nurses, observation date must be today.");
            }
            if (!is_numeric($weight)) {
                throw new Exception("Weight must be a number (kg).");
            }
            $wkg = (float)$weight;
            if ($wkg < 0 || $wkg > 30) {
                throw new Exception("Weight must be between 0 and 30 kg.");
            }

            // Prevent duplicates for the same date (keep data clean for WHO zone charts).
            $dupStmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM baby_weights
                WHERE baby_id = ?
                  AND observed_date = ?
                  AND deleted_at IS NULL
            ");
            $dupStmt->execute([$baby_id, $observed_date]);
            if ((int)$dupStmt->fetchColumn() > 0) {
                throw new Exception("A growth entry already exists for this date. Delete the old entry first, then re-add.");
            }

            $length_cm = $length_cm === '' ? null : $length_cm;
            $head_circumference_cm = $head_circumference_cm === '' ? null : $head_circumference_cm;

            if (mch_baby_weights_has_growth_columns($pdo)) {
                $stmt = $pdo->prepare("
                    INSERT INTO baby_weights (baby_id, weight_kg, length_cm, head_circumference_cm, observed_date, created_at)
                    VALUES (?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$baby_id, $weight, $length_cm, $head_circumference_cm, $observed_date]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO baby_weights (baby_id, weight_kg, observed_date, created_at)
                    VALUES (?, ?, ?, NOW())
                ");
                $stmt->execute([$baby_id, $weight, $observed_date]);
            }

            $success = "Growth recorded successfully.";
        }

        if ($action === 'delete_weight') {
            $weight_id = (int)($_POST['weight_id'] ?? 0);
            if ($weight_id <= 0) {
                throw new Exception("Invalid weight record.");
            }
            $stmt = $pdo->prepare("UPDATE baby_weights SET deleted_at = NOW() WHERE id = ? AND baby_id = ?");
            $stmt->execute([$weight_id, $baby_id]);
            $success = "Weight entry deleted (you can undo).";
        }

        if ($action === 'undo_weight') {
            $weight_id = (int)($_POST['weight_id'] ?? 0);
            if ($weight_id <= 0) {
                throw new Exception("Invalid weight record.");
            }
            $stmt = $pdo->prepare("UPDATE baby_weights SET deleted_at = NULL WHERE id = ? AND baby_id = ?");
            $stmt->execute([$weight_id, $baby_id]);
            $success = "Weight entry restored.";
        }

        if ($action === 'resend_sms') {
            $vaccine = trim($_POST['vaccine'] ?? '');
            $due_date = trim($_POST['due_date'] ?? '');
            if ($vaccine === '' || $due_date === '') {
                throw new Exception("Missing SMS reminder details.");
            }

            $pstmt = $pdo->prepare("
                SELECT m.phone, m.fullname, b.baby_name
                FROM babies b
                JOIN mothers m ON b.mother_id = m.id
                WHERE b.id = ?
                LIMIT 1
            ");
            $pstmt->execute([$baby_id]);
            $row = $pstmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || empty($row['phone'])) {
                throw new Exception("Mother phone not found for this baby.");
            }

            $clinicLabel = 'your clinic';
            if (!empty($_SESSION['clinic_id'])) {
                $cstmt = $pdo->prepare("SELECT clinic_name FROM clinics WHERE id = ? LIMIT 1");
                $cstmt->execute([$_SESSION['clinic_id']]);
                $crow = $cstmt->fetch(PDO::FETCH_ASSOC);
                if ($crow && !empty($crow['clinic_name'])) {
                    $clinicLabel = $crow['clinic_name'];
                }
            }

            $msg = "Mhoro Mai {$row['fullname']}, reminder: {$row['baby_name']} needs {$vaccine} on {$due_date} at {$clinicLabel}.";
            $ok = sendSms($row['phone'], $msg);

            $logStmt = $pdo->prepare("
                INSERT INTO sms_logs (baby_id, vaccine, due_date, status, message, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $logStmt->execute([
                $baby_id,
                $vaccine,
                $due_date,
                $ok ? 'SENT' : 'FAILED',
                $msg
            ]);

            $success = $ok ? "SMS reminder resent." : "Failed to resend SMS reminder.";
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$babyStmt = $pdo->prepare("
    SELECT b.*, m.fullname AS mother_name
    FROM babies b
    JOIN mothers m ON b.mother_id = m.id
    WHERE b.id = ?
    LIMIT 1
");
$babyStmt->execute([$baby_id]);
$baby = $babyStmt->fetch(PDO::FETCH_ASSOC);
if (!$baby) {
    die("Baby not found.");
}

$modulesError = '';
$bio = [];
$birthHistory = [];
$risk = [];
$milestones = [];
try {
    $bioStmt = $pdo->prepare("
        SELECT surname, health_centre, address, father_name, mother_name_on_card, mother_national_id, birth_entry_number
        FROM baby_bio_data
        WHERE baby_id = ?
        LIMIT 1
    ");
    $bioStmt->execute([$baby_id]);
    $bio = $bioStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!empty($bio)) {
        $bio['mother_national_id'] = mch_crypto_decrypt($bio['mother_national_id'] ?? null);
    }

    $birthStmt = $pdo->prepare("
        SELECT labour_duration_hours, mode_of_delivery, apgar_1min, apgar_5min, head_circumference_cm, birth_length_cm
        FROM baby_birth_history
        WHERE baby_id = ?
        LIMIT 1
    ");
    $birthStmt->execute([$baby_id]);
    $birthHistory = $birthStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $riskStmt = $pdo->prepare("
        SELECT low_birth_weight, fifth_child_plus, multiple_births, mtct_pmtct, birth_interval_lt_2yrs, single_parent,
               maternal_death, severe_jaundice, birth_asphyxia, notes
        FROM baby_risk_factors
        WHERE baby_id = ?
        LIMIT 1
    ");
    $riskStmt->execute([$baby_id]);
    $risk = $riskStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $milestonesStmt = $pdo->prepare("
        SELECT solids_introduced_date, crawling_date, talking_date, breastfeeding_stopped_date
        FROM baby_milestones
        WHERE baby_id = ?
        LIMIT 1
    ");
    $milestonesStmt->execute([$baby_id]);
    $milestones = $milestonesStmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $modulesError = "Digital Child Health Card tables not found. Run `sql/baby_child_health_card.sql` on the database.";
}

$immunStmt = $pdo->prepare("
    SELECT id, vaccine, due_date, status, given_date
    FROM immunizations
    WHERE baby_id = ?
    ORDER BY due_date ASC
");
$immunStmt->execute([$baby_id]);
$immunizations = $immunStmt->fetchAll(PDO::FETCH_ASSOC);

$weightsStmt = $pdo->prepare("
    SELECT id, weight_kg, observed_date
    FROM baby_weights
    WHERE baby_id = ? AND (deleted_at IS NULL)
      AND id IN (
        SELECT MAX(id)
        FROM baby_weights
        WHERE baby_id = ? AND deleted_at IS NULL
        GROUP BY observed_date
      )
    ORDER BY observed_date DESC
");
$weightsHasGrowthCols = mch_baby_weights_has_growth_columns($pdo);
if ($weightsHasGrowthCols) {
    $weightsStmt = $pdo->prepare("
        SELECT id, weight_kg, length_cm, head_circumference_cm, observed_date
        FROM baby_weights
        WHERE baby_id = ? AND (deleted_at IS NULL)
          AND id IN (
            SELECT MAX(id)
            FROM baby_weights
            WHERE baby_id = ? AND deleted_at IS NULL
            GROUP BY observed_date
          )
        ORDER BY observed_date DESC
    ");
}
$weightsStmt->execute([$baby_id, $baby_id]);
$weights = $weightsStmt->fetchAll(PDO::FETCH_ASSOC);

if ($weightsHasGrowthCols) {
    $weightsChartStmt = $pdo->prepare("
        SELECT weight_kg, length_cm, head_circumference_cm, observed_date
        FROM baby_weights
        WHERE baby_id = ? AND (deleted_at IS NULL)
          AND id IN (
            SELECT MAX(id)
            FROM baby_weights
            WHERE baby_id = ? AND deleted_at IS NULL
            GROUP BY observed_date
          )
        ORDER BY observed_date ASC
    ");
} else {
    $weightsChartStmt = $pdo->prepare("
        SELECT weight_kg, observed_date
        FROM baby_weights
        WHERE baby_id = ? AND (deleted_at IS NULL)
          AND id IN (
            SELECT MAX(id)
            FROM baby_weights
            WHERE baby_id = ? AND deleted_at IS NULL
            GROUP BY observed_date
          )
        ORDER BY observed_date ASC
    ");
}
$weightsChartStmt->execute([$baby_id, $baby_id]);
$weightsChart = $weightsChartStmt->fetchAll(PDO::FETCH_ASSOC);

if ($weightsHasGrowthCols) {
    $deletedWeightsStmt = $pdo->prepare("
        SELECT id, weight_kg, length_cm, head_circumference_cm, observed_date
        FROM baby_weights
        WHERE baby_id = ? AND deleted_at IS NOT NULL
        ORDER BY observed_date DESC
    ");
} else {
    $deletedWeightsStmt = $pdo->prepare("
        SELECT id, weight_kg, observed_date
        FROM baby_weights
        WHERE baby_id = ? AND deleted_at IS NOT NULL
        ORDER BY observed_date DESC
    ");
}
$deletedWeightsStmt->execute([$baby_id]);
$deletedWeights = $deletedWeightsStmt->fetchAll(PDO::FETCH_ASSOC);

$babyDob = (string)($baby['dob'] ?? '');
$babySex = mch_normalize_baby_sex((string)($baby['gender'] ?? ''));
$hasDob = trim($babyDob) !== '';
$canWho = $hasDob && $babySex !== null;

foreach ($weights as &$w) {
    $ageMonths = $hasDob ? mch_growth_age_months($babyDob, (string)($w['observed_date'] ?? '')) : null;
    $w['age_months'] = $ageMonths;

    $wfaRef = ($canWho && $ageMonths !== null) ? mch_who_ref_wfa($babySex, (float)$ageMonths) : null;
    $w['wfa_zone'] = mch_who_zone((float)($w['weight_kg'] ?? 0), $wfaRef);

    if ($weightsHasGrowthCols && isset($w['length_cm']) && $w['length_cm'] !== null && $w['length_cm'] !== '') {
        $lhfaRef = ($canWho && $ageMonths !== null) ? mch_who_ref_lhfa($babySex, (float)$ageMonths) : null;
        $w['lhfa_zone'] = mch_who_zone((float)$w['length_cm'], $lhfaRef);
    } else {
        $w['lhfa_zone'] = 'unknown';
    }
}
unset($w);

$weightsChartPoints = [];
$lengthChartPoints = [];
foreach ($weightsChart as $w) {
    $ageMonths = $hasDob ? mch_growth_age_months($babyDob, (string)($w['observed_date'] ?? '')) : null;
    if ($hasDob && $ageMonths === null) {
        continue;
    }

    if ($hasDob) {
        $wfaRef = ($canWho && $ageMonths !== null) ? mch_who_ref_wfa($babySex, (float)$ageMonths) : null;
        $weightsChartPoints[] = [
            'x' => round((float)$ageMonths, 2),
            'y' => (float)$w['weight_kg'],
            'd' => (string)$w['observed_date'],
            'z' => mch_who_zone((float)$w['weight_kg'], $wfaRef),
        ];

        if ($weightsHasGrowthCols && isset($w['length_cm']) && $w['length_cm'] !== null && $w['length_cm'] !== '') {
            $lhfaRef = ($canWho && $ageMonths !== null) ? mch_who_ref_lhfa($babySex, (float)$ageMonths) : null;
            $lengthChartPoints[] = [
                'x' => round((float)$ageMonths, 2),
                'y' => (float)$w['length_cm'],
                'd' => (string)$w['observed_date'],
                'z' => mch_who_zone((float)$w['length_cm'], $lhfaRef),
            ];
        }
    }
}

$wfaRefLines = ['m3' => [], 'm2' => [], 'med' => []];
$lhfaRefLines = ['m3' => [], 'm2' => [], 'med' => []];
if ($canWho) {
    $wfaTable = mch_who_wfa_table($babySex);
    foreach ($wfaTable as $m => $row) {
        $wfaRefLines['m3'][] = ['x' => (float)$m, 'y' => (float)$row['m3']];
        $wfaRefLines['m2'][] = ['x' => (float)$m, 'y' => (float)$row['m2']];
        $wfaRefLines['med'][] = ['x' => (float)$m, 'y' => (float)$row['med']];
    }

    $lhfaTable = mch_who_lhfa_table($babySex);
    foreach ($lhfaTable as $m => $row) {
        $lhfaRefLines['m3'][] = ['x' => (float)$m, 'y' => (float)$row['m3']];
        $lhfaRefLines['m2'][] = ['x' => (float)$m, 'y' => (float)$row['m2']];
        $lhfaRefLines['med'][] = ['x' => (float)$m, 'y' => (float)$row['med']];
    }
}

$smsFilter = $_GET['sms_status'] ?? 'all';
$smsFilter = in_array($smsFilter, ['all', 'SENT', 'FAILED'], true) ? $smsFilter : 'all';
$smsWhere = "WHERE baby_id = ?";
$smsParams = [$baby_id];
if ($smsFilter !== 'all') {
    $smsWhere .= " AND status = ?";
    $smsParams[] = $smsFilter;
}

$smsLogsStmt = $pdo->prepare("
    SELECT vaccine, due_date, status, message, created_at
    FROM sms_logs
    {$smsWhere}
    ORDER BY created_at DESC
    LIMIT 10
");
$smsLogsStmt->execute($smsParams);
$smsLogs = $smsLogsStmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_nurse.php";
?>

<div class="ml-64 p-6">
	  <div class="flex items-center justify-between mb-6">
	    <div>
	      <h2 class="text-2xl font-semibold text-slate-900">Baby Health Records</h2>
	      <p class="text-sm text-slate-500">Growth tracking and immunizations</p>
	    </div>
	    <a href="/mch_system/nurse_portal/mother_history.php?mother_id=<?= (int)$baby['mother_id'] ?>" class="mch-btn px-4 py-2">Back to Mother</a>
	  </div>

	  <?php if ($error): ?>
	    <div class="bg-rose-100 text-rose-700 p-3 rounded-lg mb-4"><?= htmlspecialchars($error) ?></div>
	  <?php endif; ?>
	  <?php if ($success): ?>
	    <div class="bg-emerald-100 text-emerald-700 p-3 rounded-lg mb-4"><?= htmlspecialchars($success) ?></div>
	  <?php endif; ?>
	
	  <div class="mch-card bg-white/90 p-5 border border-slate-100 mb-6">
	    <div class="text-sm text-slate-500 mb-1">Baby Card</div>
	    <div class="text-lg font-semibold text-slate-900"><?= htmlspecialchars($baby['baby_name']) ?></div>
	    <?php $cardUid = normalize_card_uid((string)($baby['card_uid'] ?? '')); ?>
	    <div class="grid grid-cols-1 md:grid-cols-5 gap-3 text-sm text-slate-700 mt-3">
	      <div><span class="font-semibold">Mother:</span> <?= htmlspecialchars($baby['mother_name']) ?></div>
	      <div><span class="font-semibold">DOB:</span> <?= htmlspecialchars($baby['dob']) ?></div>
	      <div><span class="font-semibold">Gender:</span> <?= htmlspecialchars($baby['gender']) ?></div>
	      <div><span class="font-semibold">Birth Weight:</span> <?= htmlspecialchars($baby['birth_weight'] ?? '-') ?> kg</div>
	      <div>
	        <span class="font-semibold">Card ID:</span>
	        <?php if ($cardUid): ?>
	          <span class="font-mono tracking-wider"><?= htmlspecialchars(format_card_uid($cardUid)) ?></span>
	        <?php else: ?>
	          <span class="text-amber-700 font-semibold">Not assigned</span>
	        <?php endif; ?>
	      </div>
	    </div>

	    <div class="mt-4 flex flex-wrap items-center gap-2">
	      <a href="/mch_system/nurse_portal/scan_baby_card.php"
	         class="px-3 py-2 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-sm">
	        Scan Another Card
	      </a>

	      <?php if ($cardUid): ?>
	        <a href="/mch_system/nurse_portal/print_baby_card.php?baby_id=<?= (int)$baby_id ?>"
	           target="_blank"
	           class="px-3 py-2 rounded-lg bg-slate-900 text-white hover:bg-slate-800 text-sm">
	          Print QR Sticker
	        </a>
	      <?php else: ?>
	        <form method="POST" class="inline">
	          <?= mch_csrf_input('nurse_baby_history') ?>
	          <input type="hidden" name="action" value="assign_card_uid">
	          <input type="hidden" name="baby_id" value="<?= (int)$baby_id ?>">
	          <button type="submit" class="px-3 py-2 rounded-lg bg-slate-900 text-white hover:bg-slate-800 text-sm">
	            Assign Card ID
	          </button>
	        </form>
	      <?php endif; ?>
	    </div>
	  </div>

		  <div class="mch-card bg-white/90 p-5 border border-slate-100 mb-6">
		    <h3 class="text-lg font-semibold text-slate-900 mb-1">Digital Child Health Card</h3>
		    <p class="text-sm text-slate-500 mb-4">Digitize key fields from the physical card.</p>

		    <?php if ($modulesError): ?>
		      <div class="bg-amber-100 text-amber-800 p-3 rounded-lg">
		        <?= htmlspecialchars($modulesError) ?>
		      </div>
		    <?php else: ?>
		    <div class="space-y-3">
		      <script>
		        function mchFillNA(id) {
		          const el = document.getElementById(id);
		          if (!el) return;
		          el.value = 'N/A';
		          try { el.focus({ preventScroll: true }); } catch (e) {}
		        }
		        function mchToggleOther(selectId, wrapId, inputId) {
		          const sel = document.getElementById(selectId);
		          const wrap = document.getElementById(wrapId);
		          const inp = document.getElementById(inputId);
		          if (!sel || !wrap) return;
		          const isOther = sel.value === '__OTHER__';
		          wrap.classList.toggle('hidden', !isOther);
		          if (inp) inp.required = isOther;
		        }
		      </script>
		      <details class="rounded-xl border border-slate-200 bg-white p-4" open>
		        <summary class="cursor-pointer font-semibold text-slate-900">Bio-Data</summary>
		        <form method="POST" class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-3">
		          <?= mch_csrf_input('nurse_baby_history') ?>
	          <input type="hidden" name="action" value="save_bio">
	          <input type="hidden" name="baby_id" value="<?= (int)$baby_id ?>">
	          <div>
	            <label class="block text-sm font-semibold text-slate-700 mb-1">Surname</label>
	            <input type="text" name="surname" value="<?= htmlspecialchars($bio['surname'] ?? '') ?>"
	                   class="w-full border border-slate-200 p-2 rounded-lg bg-white">
	          </div>
	          <div>
	            <label class="block text-sm font-semibold text-slate-700 mb-1">Health Centre</label>
	            <?php
	              $hcVal = (string)($bio['health_centre'] ?? '');
	              $hcKnown = $hcVal !== '' && in_array($hcVal, $healthCentreOptions, true);
	              $hcSelect = $hcKnown ? $hcVal : ($hcVal !== '' ? '__OTHER__' : '');
	            ?>
	            <?php if (!empty($healthCentreOptions)): ?>
	              <select name="health_centre" id="health_centre" class="w-full border border-slate-200 p-2 rounded-lg bg-white"
	                      onchange="mchToggleOther('health_centre','health_centre_other_wrap','health_centre_other')">
	                <option value="">Select health centre</option>
	                <?php foreach ($healthCentreOptions as $opt): ?>
	                  <option value="<?= htmlspecialchars($opt, ENT_QUOTES) ?>" <?= $hcSelect === $opt ? 'selected' : '' ?>>
	                    <?= htmlspecialchars($opt) ?>
	                  </option>
	                <?php endforeach; ?>
	                <option value="__OTHER__" <?= $hcSelect === '__OTHER__' ? 'selected' : '' ?>>Other (type)</option>
	              </select>
	              <div id="health_centre_other_wrap" class="<?= $hcSelect === '__OTHER__' ? '' : 'hidden' ?> mt-2">
	                <input type="text" name="health_centre_other" id="health_centre_other" value="<?= htmlspecialchars($hcKnown ? '' : $hcVal) ?>"
	                       class="w-full border border-slate-200 p-2 rounded-lg bg-white" placeholder="Type health centre">
	              </div>
	            <?php else: ?>
	              <input type="text" name="health_centre" value="<?= htmlspecialchars($hcVal) ?>"
	                     class="w-full border border-slate-200 p-2 rounded-lg bg-white">
	            <?php endif; ?>
	          </div>
	          <div class="md:col-span-2">
	            <label class="block text-sm font-semibold text-slate-700 mb-1">Address</label>
	            <input type="text" name="address" value="<?= htmlspecialchars($bio['address'] ?? '') ?>"
	                   class="w-full border border-slate-200 p-2 rounded-lg bg-white">
	          </div>
	          <div>
	            <label class="block text-sm font-semibold text-slate-700 mb-1">Father's Name</label>
	            <input type="text" name="father_name" value="<?= htmlspecialchars($bio['father_name'] ?? '') ?>"
	                   class="w-full border border-slate-200 p-2 rounded-lg bg-white">
	          </div>
	          <div>
	            <label class="block text-sm font-semibold text-slate-700 mb-1">Mother's Name (as on card)</label>
	            <input type="text" name="mother_name_on_card" value="<?= htmlspecialchars($bio['mother_name_on_card'] ?? '') ?>"
	                   class="w-full border border-slate-200 p-2 rounded-lg bg-white">
	          </div>
	          <div>
	            <label class="block text-sm font-semibold text-slate-700 mb-1">Mother National ID (optional)</label>
	            <input type="text" name="mother_national_id" value="<?= htmlspecialchars($bio['mother_national_id'] ?? '') ?>"
	                   class="w-full border border-slate-200 p-2 rounded-lg bg-white">
	          </div>
	          <div>
	            <label class="block text-sm font-semibold text-slate-700 mb-1">Birth Entry Number (optional)</label>
	            <input type="text" name="birth_entry_number" value="<?= htmlspecialchars($bio['birth_entry_number'] ?? '') ?>"
	                   class="w-full border border-slate-200 p-2 rounded-lg bg-white">
	          </div>
	          <div class="md:col-span-2">
	            <button class="mch-btn px-4 py-2">Save Bio-Data</button>
		          </div>
		        </form>
		      </details>

	      <details class="rounded-xl border border-slate-200 bg-white p-4">
	        <summary class="cursor-pointer font-semibold text-slate-900">Birth History</summary>
	        <form method="POST" class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-3">
	          <?= mch_csrf_input('nurse_baby_history') ?>
	          <input type="hidden" name="action" value="save_birth_history">
	          <input type="hidden" name="baby_id" value="<?= (int)$baby_id ?>">
	          <div>
	            <label class="block text-sm font-semibold text-slate-700 mb-1">Labour Duration (hours)</label>
	            <input type="number" step="0.1" min="0" name="labour_duration_hours" value="<?= htmlspecialchars($birthHistory['labour_duration_hours'] ?? '') ?>"
	                   class="w-full border border-slate-200 p-2 rounded-lg bg-white">
	          </div>
	          <div>
	            <label class="block text-sm font-semibold text-slate-700 mb-1">Mode of Delivery</label>
	            <?php
	              $modVal = (string)($birthHistory['mode_of_delivery'] ?? '');
	              $modOptions = [
	                'Normal Vaginal Delivery (NVD)',
	                'Caesarean Section (C-Section)',
	                'Assisted Deliveries',
	                'Facility-based',
	                'Home-based',
	              ];
	              $modKnown = $modVal !== '' && in_array($modVal, $modOptions, true);
	              $modSelect = $modKnown ? $modVal : ($modVal !== '' ? '__OTHER__' : '');
	            ?>
	            <select name="mode_of_delivery" id="mode_of_delivery" class="w-full border border-slate-200 p-2 rounded-lg bg-white"
	                    onchange="mchToggleOther('mode_of_delivery','mode_of_delivery_other_wrap','mode_of_delivery_other')">
	              <option value="">Select mode</option>
	              <?php foreach ($modOptions as $opt): ?>
	                <option value="<?= htmlspecialchars($opt, ENT_QUOTES) ?>" <?= $modSelect === $opt ? 'selected' : '' ?>>
	                  <?= htmlspecialchars($opt) ?>
	                </option>
	              <?php endforeach; ?>
	              <option value="__OTHER__" <?= $modSelect === '__OTHER__' ? 'selected' : '' ?>>Other (type)</option>
	            </select>
	            <div id="mode_of_delivery_other_wrap" class="<?= $modSelect === '__OTHER__' ? '' : 'hidden' ?> mt-2">
	              <input type="text" name="mode_of_delivery_other" id="mode_of_delivery_other" value="<?= htmlspecialchars($modKnown ? '' : $modVal) ?>"
	                     class="w-full border border-slate-200 p-2 rounded-lg bg-white" placeholder="Type mode of delivery">
	            </div>
	          </div>
	          <div>
	            <label class="block text-sm font-semibold text-slate-700 mb-1">Head Circumference (cm)</label>
	            <input type="number" step="0.1" min="0" name="head_circumference_cm" value="<?= htmlspecialchars($birthHistory['head_circumference_cm'] ?? '') ?>"
	                   class="w-full border border-slate-200 p-2 rounded-lg bg-white">
	          </div>
	          <div>
	            <label class="block text-sm font-semibold text-slate-700 mb-1">Birth Length (cm)</label>
	            <input type="number" step="0.1" min="0" name="birth_length_cm" value="<?= htmlspecialchars($birthHistory['birth_length_cm'] ?? '') ?>"
	                   class="w-full border border-slate-200 p-2 rounded-lg bg-white">
	          </div>
	          <div>
	            <label class="block text-sm font-semibold text-slate-700 mb-1">Apgar (1 min)</label>
	            <input type="number" min="0" max="10" name="apgar_1min" value="<?= htmlspecialchars($birthHistory['apgar_1min'] ?? '') ?>"
	                   class="w-full border border-slate-200 p-2 rounded-lg bg-white">
	          </div>
	          <div>
	            <label class="block text-sm font-semibold text-slate-700 mb-1">Apgar (5 min)</label>
	            <input type="number" min="0" max="10" name="apgar_5min" value="<?= htmlspecialchars($birthHistory['apgar_5min'] ?? '') ?>"
	                   class="w-full border border-slate-200 p-2 rounded-lg bg-white">
	          </div>
	          <div class="md:col-span-3">
	            <button class="mch-btn px-4 py-2">Save Birth History</button>
	          </div>
	        </form>
	      </details>

	      <details class="rounded-xl border border-slate-200 bg-white p-4">
	        <summary class="cursor-pointer font-semibold text-slate-900">Risk Factors (Yes/No)</summary>
	        <form method="POST" class="mt-4 space-y-3">
	          <?= mch_csrf_input('nurse_baby_history') ?>
	          <input type="hidden" name="action" value="save_risk_factors">
	          <input type="hidden" name="baby_id" value="<?= (int)$baby_id ?>">

	          <div class="grid grid-cols-1 md:grid-cols-2 gap-2 text-sm text-slate-700">
	            <?php
	              $rf = fn($k) => !empty($risk[$k]) && (int)$risk[$k] === 1;
	            ?>
	            <label class="flex items-center gap-2">
	              <input type="checkbox" name="low_birth_weight" value="1" <?= $rf('low_birth_weight') ? 'checked' : '' ?>>
	              Birth weight &lt; 2.5kg
	            </label>
	            <label class="flex items-center gap-2">
	              <input type="checkbox" name="fifth_child_plus" value="1" <?= $rf('fifth_child_plus') ? 'checked' : '' ?>>
	              5th child+
	            </label>
	            <label class="flex items-center gap-2">
	              <input type="checkbox" name="multiple_births" value="1" <?= $rf('multiple_births') ? 'checked' : '' ?>>
	              Multiple births
	            </label>
	            <label class="flex items-center gap-2">
	              <input type="checkbox" name="mtct_pmtct" value="1" <?= $rf('mtct_pmtct') ? 'checked' : '' ?>>
	              MTCT (PMTCT)
	            </label>
	            <label class="flex items-center gap-2">
	              <input type="checkbox" name="birth_interval_lt_2yrs" value="1" <?= $rf('birth_interval_lt_2yrs') ? 'checked' : '' ?>>
	              Birth interval &lt; 2 years
	            </label>
	            <label class="flex items-center gap-2">
	              <input type="checkbox" name="single_parent" value="1" <?= $rf('single_parent') ? 'checked' : '' ?>>
	              Single parent
	            </label>
	            <label class="flex items-center gap-2">
	              <input type="checkbox" name="maternal_death" value="1" <?= $rf('maternal_death') ? 'checked' : '' ?>>
	              Maternal death
	            </label>
	            <label class="flex items-center gap-2">
	              <input type="checkbox" name="severe_jaundice" value="1" <?= $rf('severe_jaundice') ? 'checked' : '' ?>>
	              Severe jaundice
	            </label>
	            <label class="flex items-center gap-2">
	              <input type="checkbox" name="birth_asphyxia" value="1" <?= $rf('birth_asphyxia') ? 'checked' : '' ?>>
	              Birth asphyxia
	            </label>
	          </div>

	          <div>
	            <div class="flex items-center justify-between mb-1">
	              <label class="block text-sm font-semibold text-slate-700">Notes (optional)</label>
	              <button type="button" class="text-xs px-2 py-1 rounded-lg border border-slate-200 bg-slate-50 hover:bg-slate-100"
	                      onclick="mchFillNA('risk_notes')">N/A</button>
	            </div>
	            <input type="text" name="risk_notes" value="<?= htmlspecialchars($risk['notes'] ?? '') ?>"
	                   id="risk_notes" class="w-full border border-slate-200 p-2 rounded-lg bg-white">
	          </div>

	          <div>
	            <button class="mch-btn px-4 py-2">Save Risk Factors</button>
	          </div>
	        </form>
	      </details>

	      <details class="rounded-xl border border-slate-200 bg-white p-4">
	        <summary class="cursor-pointer font-semibold text-slate-900">Developmental Milestones</summary>
	        <form method="POST" class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-3">
	          <?= mch_csrf_input('nurse_baby_history') ?>
	          <input type="hidden" name="action" value="save_milestones">
	          <input type="hidden" name="baby_id" value="<?= (int)$baby_id ?>">
	          <div>
	            <label class="block text-sm font-semibold text-slate-700 mb-1">Solids introduced</label>
	            <input type="date" name="solids_introduced_date" value="<?= htmlspecialchars($milestones['solids_introduced_date'] ?? '') ?>"
	                   class="w-full border border-slate-200 p-2 rounded-lg bg-white">
	          </div>
	          <div>
	            <label class="block text-sm font-semibold text-slate-700 mb-1">Crawling</label>
	            <input type="date" name="crawling_date" value="<?= htmlspecialchars($milestones['crawling_date'] ?? '') ?>"
	                   class="w-full border border-slate-200 p-2 rounded-lg bg-white">
	          </div>
	          <div>
	            <label class="block text-sm font-semibold text-slate-700 mb-1">Talking</label>
	            <input type="date" name="talking_date" value="<?= htmlspecialchars($milestones['talking_date'] ?? '') ?>"
	                   class="w-full border border-slate-200 p-2 rounded-lg bg-white">
	          </div>
	          <div>
	            <label class="block text-sm font-semibold text-slate-700 mb-1">Breastfeeding stopped</label>
	            <input type="date" name="breastfeeding_stopped_date" value="<?= htmlspecialchars($milestones['breastfeeding_stopped_date'] ?? '') ?>"
	                   class="w-full border border-slate-200 p-2 rounded-lg bg-white">
	          </div>
	          <div class="md:col-span-2">
	            <button class="mch-btn px-4 py-2">Save Milestones</button>
		          </div>
		        </form>
		      </details>
		    </div>
		    <?php endif; ?>
		  </div>

  <div class="mch-card bg-white/90 p-5 border border-slate-100 mb-6">
    <h3 class="text-lg font-semibold text-slate-900 mb-3">Log Growth</h3>
	    <form method="POST" class="grid grid-cols-1 md:grid-cols-5 gap-3">
	      <?= mch_csrf_input('nurse_baby_history') ?>
	      <input type="hidden" name="action" value="log_weight">
	      <input type="hidden" name="baby_id" value="<?= (int)$baby_id ?>">
          <?php $today = date('Y-m-d'); $isNurse = ((string)($_SESSION['role'] ?? '') === 'nurse'); ?>
	      <input type="date" name="observed_date" class="border border-slate-200 p-2 rounded-lg bg-white"
                 value="<?= $today ?>" <?php if ($isNurse): ?>min="<?= $today ?>"<?php endif; ?> max="<?= $today ?>" required>
          <input type="number" step="0.01" min="0" max="30" name="weight_kg" class="border border-slate-200 p-2 rounded-lg bg-white" placeholder="Weight (kg)" required>
          <input type="number" step="0.1" name="length_cm" class="border border-slate-200 p-2 rounded-lg bg-white" placeholder="Length/Height (cm)">
          <input type="number" step="0.1" name="head_circumference_cm" class="border border-slate-200 p-2 rounded-lg bg-white" placeholder="Head Circumference (cm)">
          <button type="submit" class="mch-btn px-4 py-2">Save</button>
        </form>
        <?php if (!$weightsHasGrowthCols): ?>
          <p class="text-sm text-amber-700 mt-2">
            To enable Length/Height and Head Circumference logging, run <span class="font-mono">sql/baby_growth_columns.sql</span> on the database.
          </p>
        <?php endif; ?>
      </div>

  <div class="mch-card bg-white/90 p-5 border border-slate-100 mb-6">
    <h3 class="text-lg font-semibold text-slate-900 mb-3">Growth History (WHO Zones)</h3>
    <?php if (empty($weights)): ?>
      <p class="text-slate-500">No growth records yet.</p>
    <?php else: ?>
      <?php if (!$hasDob): ?>
        <div class="bg-amber-100 text-amber-800 p-3 rounded-lg mb-4">
          Baby DOB is missing, so the age-based growth chart cannot be drawn.
        </div>
      <?php elseif (!$canWho): ?>
        <div class="bg-amber-100 text-amber-800 p-3 rounded-lg mb-4">
          Baby sex is missing/invalid, so WHO zones cannot be calculated.
        </div>
      <?php endif; ?>

      <?php $latestWfa = $weights[0]['wfa_zone'] ?? 'unknown'; ?>
      <?php if ($latestWfa === 'red'): ?>
        <div class="bg-rose-100 text-rose-700 p-3 rounded-lg mb-4">
          High Priority: Latest weight is in the <span class="font-semibold">Very Dangerous</span> zone for age (&lt; -3 SD).
        </div>
      <?php elseif ($latestWfa === 'orange'): ?>
        <div class="bg-amber-100 text-amber-800 p-3 rounded-lg mb-4">
          Warning: Latest weight is in the <span class="font-semibold">Danger</span> zone for age (-3 to -2 SD).
        </div>
      <?php endif; ?>

      <?php if (isset($weights[0]['length_cm']) && $weights[0]['length_cm'] !== null && $weights[0]['length_cm'] !== ''): ?>
        <?php $latestLhfa = $weights[0]['lhfa_zone'] ?? 'unknown'; ?>
        <?php if ($latestLhfa === 'red'): ?>
          <div class="bg-rose-100 text-rose-700 p-3 rounded-lg mb-4">
            High Priority: Latest length/height is in the <span class="font-semibold">Very Dangerous</span> zone for age (&lt; -3 SD).
          </div>
        <?php elseif ($latestLhfa === 'orange'): ?>
          <div class="bg-amber-100 text-amber-800 p-3 rounded-lg mb-4">
            Warning: Latest length/height is in the <span class="font-semibold">Danger</span> zone for age (-3 to -2 SD).
          </div>
        <?php endif; ?>
      <?php endif; ?>

      <div class="mb-6">
        <canvas id="growthChart" height="120"></canvas>
      </div>
      <?php if (!empty($lengthChartPoints)): ?>
        <div class="mb-6">
          <canvas id="lengthChart" height="120"></canvas>
        </div>
      <?php endif; ?>
      <div class="overflow-x-auto">
        <table class="w-full text-sm mch-table">
          <thead>
            <tr>
              <th class="p-2 text-left">Observed Date</th>
              <th class="p-2 text-left">Age (months)</th>
              <th class="p-2 text-left">Weight (kg)</th>
              <th class="p-2 text-left">Weight Status</th>
              <th class="p-2 text-left">Length/Height (cm)</th>
              <th class="p-2 text-left">Length Status</th>
              <th class="p-2 text-left">Head Circ. (cm)</th>
              <th class="p-2 text-left">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($weights as $w): ?>
              <tr class="border-t border-slate-200">
                <td class="p-2"><?= htmlspecialchars($w['observed_date']) ?></td>
                <td class="p-2">
                  <?= $w['age_months'] !== null ? htmlspecialchars(number_format((float)$w['age_months'], 1)) : '-' ?>
                </td>
                <td class="p-2"><?= htmlspecialchars($w['weight_kg']) ?></td>
                <td class="p-2">
                  <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs <?= mch_who_zone_badge_classes((string)($w['wfa_zone'] ?? 'unknown')) ?>">
                    <?= htmlspecialchars(mch_who_zone_label((string)($w['wfa_zone'] ?? 'unknown'))) ?>
                  </span>
                </td>
                <td class="p-2">
                  <?= isset($w['length_cm']) && $w['length_cm'] !== null && $w['length_cm'] !== '' ? htmlspecialchars($w['length_cm']) : '-' ?>
                </td>
                <td class="p-2">
                  <?php if (isset($w['length_cm']) && $w['length_cm'] !== null && $w['length_cm'] !== ''): ?>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs <?= mch_who_zone_badge_classes((string)($w['lhfa_zone'] ?? 'unknown')) ?>">
                      <?= htmlspecialchars(mch_who_zone_label((string)($w['lhfa_zone'] ?? 'unknown'))) ?>
                    </span>
                  <?php else: ?>
                    -
                  <?php endif; ?>
                </td>
                <td class="p-2">
                  <?= isset($w['head_circumference_cm']) && $w['head_circumference_cm'] !== null && $w['head_circumference_cm'] !== '' ? htmlspecialchars($w['head_circumference_cm']) : '-' ?>
                </td>
                <td class="p-2">
                  <form method="POST" class="inline">
                    <?= mch_csrf_input('nurse_baby_history') ?>
                    <input type="hidden" name="action" value="delete_weight">
                    <input type="hidden" name="baby_id" value="<?= (int)$baby_id ?>">
                    <input type="hidden" name="weight_id" value="<?= (int)$w['id'] ?>">
                    <button class="px-3 py-1 rounded-lg border border-rose-200 bg-rose-50 hover:bg-rose-100 text-sm">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <?php if (!empty($deletedWeights)): ?>
    <div class="mch-card bg-white/90 p-5 border border-slate-100 mb-6">
      <h3 class="text-lg font-semibold text-slate-900 mb-3">Recently Deleted (Undo)</h3>
      <div class="overflow-x-auto">
        <table class="w-full text-sm mch-table">
          <thead>
            <tr>
              <th class="p-2 text-left">Observed Date</th>
              <th class="p-2 text-left">Weight (kg)</th>
              <th class="p-2 text-left">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($deletedWeights as $w): ?>
              <tr class="border-t border-slate-200">
                <td class="p-2"><?= htmlspecialchars($w['observed_date']) ?></td>
                <td class="p-2"><?= htmlspecialchars($w['weight_kg']) ?></td>
                <td class="p-2">
                  <form method="POST" class="inline">
                    <?= mch_csrf_input('nurse_baby_history') ?>
                    <input type="hidden" name="action" value="undo_weight">
                    <input type="hidden" name="baby_id" value="<?= (int)$baby_id ?>">
                    <input type="hidden" name="weight_id" value="<?= (int)$w['id'] ?>">
                    <button class="px-3 py-1 rounded-lg border border-amber-200 bg-amber-50 hover:bg-amber-100 text-sm">Undo</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <div class="mch-card bg-white/90 p-5 border border-slate-100">
    <h3 class="text-lg font-semibold text-slate-900 mb-3">Immunizations</h3>
    <?php if (empty($immunizations)): ?>
      <p class="text-slate-500">No immunization records found.</p>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="w-full text-sm mch-table">
          <thead>
            <tr>
              <th class="p-2 text-left">Vaccine</th>
              <th class="p-2 text-left">Due Date</th>
              <th class="p-2 text-left">Status</th>
              <th class="p-2 text-left">Given Date</th>
              <th class="p-2 text-left">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($immunizations as $i): ?>
              <?php $isGiven = ($i['status'] === 'GIVEN'); ?>
              <tr class="border-t border-slate-200" id="immun-row-<?= (int)$i['id'] ?>">
                <td class="p-2"><?= htmlspecialchars($i['vaccine']) ?></td>
                <td class="p-2"><?= htmlspecialchars($i['due_date']) ?></td>
                <td class="p-2" id="status-<?= (int)$i['id'] ?>">
                  <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs <?= $isGiven ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' ?>">
                    <?= htmlspecialchars($i['status']) ?>
                  </span>
                </td>
                <td class="p-2" id="given-<?= (int)$i['id'] ?>"><?= htmlspecialchars($i['given_date'] ?? '-') ?></td>
                <td class="p-2">
                  <button class="toggle-btn px-3 py-1 rounded-lg border text-sm <?= $isGiven ? 'bg-slate-50 border-slate-200' : 'bg-sky-50 border-sky-200 text-sky-700' ?>"
                          data-id="<?= (int)$i['id'] ?>">
                    <?= $isGiven ? 'Undo' : 'Given' ?>
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="mch-card bg-white/90 p-5 border border-slate-100 mt-6">
    <div class="flex items-center justify-between mb-3">
      <h3 class="text-lg font-semibold text-slate-900">SMS Log (Last 10)</h3>
      <form method="GET" class="flex items-center gap-2">
        <input type="hidden" name="baby_id" value="<?= (int)$baby_id ?>">
        <label class="text-xs text-slate-600">Filter</label>
        <select name="sms_status" class="border border-slate-200 p-1.5 rounded-lg bg-white text-sm">
          <option value="all" <?= $smsFilter === 'all' ? 'selected' : '' ?>>All</option>
          <option value="SENT" <?= $smsFilter === 'SENT' ? 'selected' : '' ?>>SENT</option>
          <option value="FAILED" <?= $smsFilter === 'FAILED' ? 'selected' : '' ?>>FAILED</option>
        </select>
        <button class="inline-flex items-center px-3 py-1 rounded-lg bg-slate-50 border border-slate-200 hover:bg-slate-100 text-sm">
          Apply
        </button>
      </form>
    </div>
    <?php if (empty($smsLogs)): ?>
      <p class="text-slate-500">No SMS reminders sent yet.</p>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="w-full text-sm mch-table">
          <thead>
            <tr>
              <th class="p-2 text-left">Vaccine</th>
              <th class="p-2 text-left">Due Date</th>
              <th class="p-2 text-left">Status</th>
              <th class="p-2 text-left">Sent At</th>
              <th class="p-2 text-left">Message</th>
              <th class="p-2 text-left">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($smsLogs as $s): ?>
              <tr class="border-t border-slate-200">
                <td class="p-2"><?= htmlspecialchars($s['vaccine']) ?></td>
                <td class="p-2"><?= htmlspecialchars($s['due_date']) ?></td>
                <td class="p-2">
                  <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs <?= $s['status'] === 'SENT' ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' ?>">
                    <?= htmlspecialchars($s['status']) ?>
                  </span>
                </td>
                <td class="p-2"><?= htmlspecialchars($s['created_at']) ?></td>
                <td class="p-2 text-slate-600"><?= htmlspecialchars($s['message']) ?></td>
                <td class="p-2">
                  <form method="POST" class="inline">
                    <?= mch_csrf_input('nurse_baby_history') ?>
                    <input type="hidden" name="action" value="resend_sms">
                    <input type="hidden" name="baby_id" value="<?= (int)$baby_id ?>">
                    <input type="hidden" name="vaccine" value="<?= htmlspecialchars($s['vaccine'], ENT_QUOTES) ?>">
                    <input type="hidden" name="due_date" value="<?= htmlspecialchars($s['due_date'], ENT_QUOTES) ?>">
                    <button class="inline-flex items-center px-3 py-1 rounded-lg bg-amber-50 border border-amber-200 hover:bg-amber-100 text-sm">
                      Resend
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
  (function renderGrowthCharts() {
    const hasDob = <?= json_encode($hasDob) ?>;
    const canWho = <?= json_encode($canWho) ?>;

    const weightPoints = <?= json_encode($weightsChartPoints) ?>;
    const lengthPoints = <?= json_encode($lengthChartPoints) ?>;

    const wfaRef = <?= json_encode($wfaRefLines) ?>;
    const lhfaRef = <?= json_encode($lhfaRefLines) ?>;

    const dateLabels = <?= json_encode(array_map(fn($w) => (string)$w['observed_date'], $weightsChart)) ?>;
    const weightByDate = <?= json_encode(array_map(fn($w) => (float)$w['weight_kg'], $weightsChart)) ?>;
    const lengthByDate = <?= json_encode($weightsHasGrowthCols ? array_map(fn($w) => ($w['length_cm'] ?? null) !== null && $w['length_cm'] !== '' ? (float)$w['length_cm'] : null, $weightsChart) : []) ?>;

    function zoneLabel(zone) {
      if (zone === 'red') return 'Very Dangerous';
      if (zone === 'orange') return 'Danger';
      if (zone === 'green') return 'Good';
      return 'Unknown';
    }

    function zoneColor(zone) {
      if (zone === 'red') return '#ef4444';
      if (zone === 'orange') return '#f59e0b';
      if (zone === 'green') return '#10b981';
      return '#0ea5a6';
    }

    const hoverCrosshairPlugin = {
      id: 'mchHoverCrosshair',
      afterDraw: (chart) => {
        if (!chart || typeof chart.getActiveElements !== 'function') return;
        const active = chart.getActiveElements();
        if (!active || !active.length) return;

        const el = active[0].element || null;
        if (!el || typeof el.x !== 'number' || typeof el.y !== 'number') return;

        const area = chart.chartArea;
        if (!area) return;

        const ctx = chart.ctx;
        ctx.save();
        ctx.lineWidth = 1;
        ctx.strokeStyle = 'rgba(15,23,42,0.15)';

        ctx.beginPath();
        ctx.moveTo(el.x, area.top);
        ctx.lineTo(el.x, area.bottom);
        ctx.stroke();

        ctx.beginPath();
        ctx.moveTo(area.left, el.y);
        ctx.lineTo(area.right, el.y);
        ctx.stroke();

        ctx.restore();
      }
    };

    function whoDatasets(ref, unitLabel) {
      return [
        {
          label: `WHO -3 SD (${unitLabel})`,
          data: ref.m3 || [],
          borderColor: 'rgba(239,68,68,0.9)',
          backgroundColor: 'rgba(239,68,68,0.08)',
          borderWidth: 1,
          pointRadius: 0,
          tension: 0.2,
          fill: 'start'
        },
        {
          label: `WHO -2 SD (${unitLabel})`,
          data: ref.m2 || [],
          borderColor: 'rgba(245,158,11,0.95)',
          backgroundColor: 'rgba(245,158,11,0.10)',
          borderWidth: 1,
          pointRadius: 0,
          tension: 0.2,
          fill: '-1'
        },
        {
          label: `WHO Median (${unitLabel})`,
          data: ref.med || [],
          borderColor: 'rgba(100,116,139,0.85)',
          borderDash: [6, 6],
          borderWidth: 1,
          pointRadius: 0,
          tension: 0.2,
          fill: false
        }
      ];
    }

    function buildWhoChart(canvasId, points, unitLabel, ref, yTitle) {
      const canvas = document.getElementById(canvasId);
      if (!canvas) return;

      if (!hasDob) {
        const labels = dateLabels;
        const data = canvasId === 'growthChart' ? weightByDate : lengthByDate.filter(v => v !== null);
        const chartLabel = canvasId === 'growthChart' ? `Weight (${unitLabel})` : `Length/Height (${unitLabel})`;
        new Chart(canvas, {
          type: 'line',
          data: {
            labels,
            datasets: [
              {
                label: chartLabel,
                data,
                borderColor: '#0ea5a6',
                backgroundColor: 'rgba(14,165,166,0.15)',
                borderWidth: 2,
                pointRadius: 3,
                tension: 0.3,
                fill: false
              }
            ]
          },
          options: {
            responsive: true,
            plugins: { legend: { position: 'top' } },
            scales: {
              x: { grid: { color: 'rgba(148,163,184,0.2)' } },
              y: { grid: { color: 'rgba(148,163,184,0.2)' }, title: { display: true, text: yTitle } }
            }
          },
          plugins: [hoverCrosshairPlugin]
        });
        return;
      }

      const datasets = [];
      if (canWho) {
        datasets.push(...whoDatasets(ref, unitLabel));
      }
      datasets.push({
        label: canvasId === 'growthChart' ? `Weight (${unitLabel})` : `Length/Height (${unitLabel})`,
        data: points,
        borderColor: '#0ea5a6',
        backgroundColor: 'rgba(14,165,166,0.12)',
        borderWidth: 2,
        pointRadius: 3,
        pointBackgroundColor: (ctx) => zoneColor(ctx.raw && ctx.raw.z ? ctx.raw.z : 'unknown'),
        pointBorderColor: (ctx) => zoneColor(ctx.raw && ctx.raw.z ? ctx.raw.z : 'unknown'),
        tension: 0.3,
        fill: false
      });

      new Chart(canvas, {
        type: 'line',
        data: { datasets },
        options: {
          responsive: true,
          parsing: false,
          plugins: {
            legend: { position: 'top' },
            tooltip: {
              callbacks: {
                label: (ctx) => {
                  const r = ctx.raw || {};
                  if (typeof r === 'object' && r !== null && typeof r.x === 'number' && typeof r.y === 'number') {
                    const extra = r.d ? ` (${r.d})` : '';
                    const z = r.z ? ` - ${zoneLabel(r.z)}` : '';
                    return `${ctx.dataset.label}: ${r.y} @ ${r.x}m${z}${extra}`;
                  }
                  return `${ctx.dataset.label}: ${ctx.parsed.y}`;
                }
              }
            }
          },
          interaction: { mode: 'nearest', intersect: false },
          scales: {
            x: {
              type: 'linear',
              title: { display: true, text: 'Age (months)' },
              grid: { color: 'rgba(148,163,184,0.2)' }
            },
            y: {
              grid: { color: 'rgba(148,163,184,0.2)' },
              title: { display: true, text: yTitle },
              beginAtZero: canvasId === 'growthChart'
            }
          }
        },
        plugins: [hoverCrosshairPlugin]
      });
    }

    buildWhoChart('growthChart', weightPoints, 'kg', wfaRef, 'kg');
    if (lengthPoints && lengthPoints.length) {
      buildWhoChart('lengthChart', lengthPoints, 'cm', lhfaRef, 'cm');
    }
  })();

  document.querySelectorAll('.toggle-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
      const immunizationId = btn.getAttribute('data-id');
      const statusCell = document.getElementById('status-' + immunizationId);
      const givenCell = document.getElementById('given-' + immunizationId);
      const isGiven = btn.textContent.trim() === 'Undo';
      const action = isGiven ? 'undo' : 'complete';

      try {
        const postToggle = async (payload) => {
          const res = await fetch('/mch_system/immunizations/toggle_immunization.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
          });
          const data = await res.json().catch(() => ({}));
          return { res, data };
        };

        let { data } = await postToggle({ id: immunizationId, action });

        if (!data.success && data.code === 'EARLY' && action === 'complete') {
          const reason = prompt((data.error || 'This immunization is not due yet.') + "\n\nEnter override reason (min 10 characters) to record it today:");
          if (!reason) return;
          ({ data } = await postToggle({ id: immunizationId, action, override: true, override_reason: reason }));
        }

        if (!data.success) {
          alert(data.error || 'Failed to update immunization.');
          return;
        }
        statusCell.innerHTML = `<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs ${data.status === 'GIVEN' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'}">${data.status}</span>`;
        givenCell.textContent = data.given_date || '-';
        btn.textContent = data.status === 'GIVEN' ? 'Undo' : 'Given';
        btn.className = 'toggle-btn px-3 py-1 rounded-lg border text-sm ' + (data.status === 'GIVEN' ? 'bg-slate-50 border-slate-200' : 'bg-sky-50 border-sky-200 text-sky-700');
      } catch (e) {
        alert('Network error while updating immunization.');
      }
    });
  });
</script>

<?php include __DIR__ . "/../includes/footer.php"; ?>
