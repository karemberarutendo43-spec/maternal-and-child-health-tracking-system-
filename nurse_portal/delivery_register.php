<?php
require_once __DIR__ . "/../config/session.php";
mch_session_start();
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/scope.php";
require_once __DIR__ . "/../config/csrf.php";
require_once __DIR__ . "/../config/audit.php";
require_once __DIR__ . "/../config/cards.php";
require_once __DIR__ . "/../config/epi.php";

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'nurse') {
    header("Location: /mch_system/auth/login.php");
    exit;
}

$page = 'delivery_register';

$error = '';

$scopedClinicId = mch_scope_scoped_clinic_id();
if ($scopedClinicId <= 0) {
    $error = "Your account is not assigned to a clinic. Please contact Admin.";
}

$hasRegisterTable = false;
try {
    $hasRegisterTable = (bool)$pdo->query("SHOW TABLES LIKE 'delivery_register'")->fetchColumn();
} catch (Throwable $e) {
    $hasRegisterTable = false;
}

$selectedDate = (string)($_GET['date'] ?? date('Y-m-d'));
if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $selectedDate)) {
    $selectedDate = date('Y-m-d');
}

$success = (($_GET['saved'] ?? '') === '1') ? 'Delivery register entry saved.' : '';

$nextSerial = 1;
if ($hasRegisterTable && $scopedClinicId > 0) {
    try {
        $from = $selectedDate . " 00:00:00";
        $to = $selectedDate . " 23:59:59";
        $s = $pdo->prepare("
            SELECT COALESCE(MAX(serial_no), 0)
            FROM delivery_register
            WHERE clinic_id = ?
              AND delivery_datetime BETWEEN ? AND ?
        ");
        $s->execute([$scopedClinicId, $from, $to]);
        $nextSerial = ((int)$s->fetchColumn()) + 1;
        if ($nextSerial <= 0) $nextSerial = 1;
    } catch (Throwable $e) {
        $nextSerial = 1;
    }
}

function mch_age_band(int $ageYears): string
{
    if ($ageYears <= 14) return '10-14';
    if ($ageYears <= 19) return '15-19';
    if ($ageYears <= 24) return '20-24';
    if ($ageYears <= 29) return '25-29';
    if ($ageYears <= 49) return '30-49';
    return '>=50';
}

function mch_opt(string $v, string $label): string
{
    return '<option value="' . htmlspecialchars($v, ENT_QUOTES) . '">' . htmlspecialchars($label) . '</option>';
}

function mch_val(string $key, string $fallback = ''): string
{
    return htmlspecialchars((string)($_POST[$key] ?? $fallback), ENT_QUOTES, 'UTF-8');
}

function mch_parse_int(string $raw, int $min, int $max, string $label): int
{
    $raw = trim($raw);
    if ($raw === '' || !preg_match('/^-?\\d+$/', $raw)) {
        throw new Exception("{$label} is required and must be a whole number.");
    }
    $v = (int)$raw;
    if ($v < $min || $v > $max) {
        throw new Exception("{$label} must be between {$min} and {$max}.");
    }
    return $v;
}

function mch_normalize_anc_no(string $raw): ?string
{
    $raw = strtoupper(trim($raw));
    $raw = preg_replace('/\\s+/', '', $raw);
    if ($raw === null) $raw = '';

    if (preg_match('/^(\\d{4})P(\\d{1,5})$/', $raw, $m)) {
        $year = $m[1];
        $digits = str_pad($m[2], 5, '0', STR_PAD_LEFT);
        return $year . 'P' . $digits;
    }

    if (preg_match('/^\\d{4}P\\d{5}$/', $raw)) {
        return $raw;
    }

    return null;
}

function mch_normalize_marital_status(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') return null;

    $canon = [
        'Married (includes those in a union)',
        'Living with Partner',
        'Separated',
        'Divorced',
        'Widowed',
    ];
    foreach ($canon as $c) {
        if (strcasecmp($raw, $c) === 0) return $c;
    }

    $lc = strtolower($raw);
    $map = [
        'married' => 'Married (includes those in a union)',
        'union' => 'Married (includes those in a union)',
        'in a union' => 'Married (includes those in a union)',
        'married/union' => 'Married (includes those in a union)',
        'living with partner' => 'Living with Partner',
        'living with a partner' => 'Living with Partner',
        'cohabiting' => 'Living with Partner',
        'separated' => 'Separated',
        'divorced' => 'Divorced',
        'widowed' => 'Widowed',
        'widow' => 'Widowed',
        'widower' => 'Widowed',
    ];
    return $map[$lc] ?? null;
}

// Mothers in clinic scope (for selection + autofill)
$mothers = [];
if ($scopedClinicId > 0) {
    $hasNat = db_has_column($pdo, 'mothers', 'national_id_number');
    $hasMar = db_has_column($pdo, 'mothers', 'marital_status');
    $hasPob = db_has_column($pdo, 'mothers', 'place_of_birth');

    $stmt = $pdo->prepare("
        SELECT m.id, m.fullname, m.hospital_number, m.dob, m.phone, m.address, m.gravida, m.parity,
               " . ($hasNat ? "m.national_id_number" : "NULL") . " AS national_id_number,
               " . ($hasMar ? "m.marital_status" : "NULL") . " AS marital_status,
               " . ($hasPob ? "m.place_of_birth" : "NULL") . " AS place_of_birth
        FROM mothers m
        JOIN users u ON u.id = m.user_id
        WHERE u.clinic_id = ?
        ORDER BY m.fullname
    ");
    $stmt->execute([$scopedClinicId]);
    $mothers = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Handle create entry (all required per ward register).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '' && $hasRegisterTable) {
    mch_csrf_require_valid('nurse_delivery_register');

    $mother_id = (int)($_POST['mother_id'] ?? 0);
    $birth_outcome = (string)($_POST['birth_outcome'] ?? '');
    $baby_name = trim((string)($_POST['baby_name'] ?? ''));

    $requiredKeys = [
        'serial_no', 'anc_no',
        'ga_admission_weeks', 'booked_status', 'gravida', 'parity', 'children_alive',
        'past_obstetric_complications',
        'admission_datetime', 'presentation_position', 'abnormalities_on_admission',
        'onset_of_labour_datetime', 'drugs_given_in_labour',
        'delivery_datetime', 'delivery_method', 'estimated_blood_loss_ml', 'perineum', 'placenta_membranes',
        'delivered_by_qualification', 'assisted_by',
        'birth_outcome', 'baby_sex', 'birth_weight_grams', 'apgar_score',
        'tetanus_protected', 'hiv_status_on_admission', 'tc_tested', 'tc_result',
        'maternal_pmtct_arvs', 'maternal_vitamin_a',
        'infant_feeding_method', 'infant_breastfed_first_hour', 'infant_teo_given',
        'national_id_number', 'marital_status', 'place_of_birth',

        // Labour stages (hours/minutes) for auto total calculation.
        'stage1_hours', 'stage1_minutes',
        'stage2_hours', 'stage2_minutes',
        'stage3_hours', 'stage3_minutes',
    ];

    try {
        if ($mother_id <= 0) throw new Exception("Select a mother.");

        // Auto-assign serial number (daily counter per clinic/day of delivery).
        $deliveryDtRaw = (string)($_POST['delivery_datetime'] ?? '');
        $serialDate = $selectedDate;
        if (preg_match('/^(\\d{4}-\\d{2}-\\d{2})T\\d{2}:\\d{2}$/', $deliveryDtRaw, $m)) {
            $serialDate = (string)$m[1];
        }
        if (trim((string)($_POST['serial_no'] ?? '')) === '') {
            $from = $serialDate . " 00:00:00";
            $to = $serialDate . " 23:59:59";
            $s = $pdo->prepare("
                SELECT COALESCE(MAX(serial_no), 0)
                FROM delivery_register
                WHERE clinic_id = ?
                  AND delivery_datetime BETWEEN ? AND ?
            ");
            $s->execute([$scopedClinicId, $from, $to]);
            $auto = ((int)$s->fetchColumn()) + 1;
            $_POST['serial_no'] = (string)max(1, $auto);
        }

        $ancNoNorm = mch_normalize_anc_no((string)($_POST['anc_no'] ?? ''));
        if ($ancNoNorm === null) {
            throw new Exception("ANC No must be in the format YYYYP00000 (5 digits after P), e.g. 2024P00807.");
        }
        // Update POST so the normalized value is shown back in the form after validation errors.
        $_POST['anc_no'] = $ancNoNorm;

        $maritalNorm = mch_normalize_marital_status((string)($_POST['marital_status'] ?? ''));
        if ($maritalNorm === null) {
            throw new Exception("Marital status must be one of: Married (includes those in a union), Living with Partner, Separated, Divorced, Widowed.");
        }
        $_POST['marital_status'] = $maritalNorm;

        foreach ($requiredKeys as $k) {
            $v = (string)($_POST[$k] ?? '');
            if (trim($v) === '') throw new Exception("Missing required field: {$k}");
        }

        // Labour stage duration calculation (auto total).
        $s1h = mch_parse_int((string)$_POST['stage1_hours'], 0, 99, '1st stage hours');
        $s1m = mch_parse_int((string)$_POST['stage1_minutes'], 0, 59, '1st stage minutes');
        $s2h = mch_parse_int((string)$_POST['stage2_hours'], 0, 99, '2nd stage hours');
        $s2m = mch_parse_int((string)$_POST['stage2_minutes'], 0, 59, '2nd stage minutes');
        $s3h = mch_parse_int((string)$_POST['stage3_hours'], 0, 99, '3rd stage hours');
        $s3m = mch_parse_int((string)$_POST['stage3_minutes'], 0, 59, '3rd stage minutes');

        $totalMinutes = ($s1h * 60 + $s1m) + ($s2h * 60 + $s2m) + ($s3h * 60 + $s3m);
        $totH = intdiv($totalMinutes, 60);
        $totM = $totalMinutes % 60;
        $durationStages = sprintf(
            '1st:%dh%02dm; 2nd:%dh%02dm; 3rd:%dh%02dm; Total:%dh%02dm',
            $s1h,
            $s1m,
            $s2h,
            $s2m,
            $s3h,
            $s3m,
            $totH,
            $totM
        );
        $_POST['duration_of_stages'] = $durationStages;

        if (!in_array((string)$_POST['booked_status'], ['B', 'U'], true)) throw new Exception("Booked status must be B or U.");
        if (!in_array((string)$_POST['delivery_method'], ['NVD','BREECH','CS','FORCEPS','VACUUM'], true)) throw new Exception("Invalid delivery method.");
        if (!in_array($birth_outcome, ['LIVE','FSB','MSB'], true)) throw new Exception("Invalid birth outcome.");
        if (!in_array((string)$_POST['baby_sex'], ['B','G'], true)) throw new Exception("Baby sex must be B or G.");
        if (!in_array((string)$_POST['placenta_membranes'], ['COMPLETE','INCOMPLETE','RAGGED'], true)) throw new Exception("Invalid placenta/membranes value.");
        if (!in_array((string)$_POST['tetanus_protected'], ['YES','NO'], true)) throw new Exception("Tetanus protection must be YES or NO.");
        if (!in_array((string)$_POST['hiv_status_on_admission'], ['1','0_NOT_DUE','0_DUE_RETEST','UNKNOWN'], true)) throw new Exception("Invalid HIV status on admission.");
        if (!in_array((string)$_POST['tc_tested'], ['YES','NO'], true)) throw new Exception("T&C tested must be YES or NO.");
        if (!in_array((string)$_POST['tc_result'], ['1','0'], true)) throw new Exception("T&C result must be 1 or 0.");
        if (!in_array((string)$_POST['maternal_pmtct_arvs'], ['TDF_3TC_EFV','TDF_3TC_NVP','SDNVP_AZT','OTHER','NONE'], true)) throw new Exception("Invalid PMTCT ARVs selection.");
        if (!in_array((string)$_POST['maternal_vitamin_a'], ['YES','NO'], true)) throw new Exception("Vitamin A must be YES or NO.");
        if (!in_array((string)$_POST['infant_feeding_method'], ['EBF','ECF'], true)) throw new Exception("Infant feeding method must be EBF or ECF.");
        if (!in_array((string)$_POST['infant_breastfed_first_hour'], ['YES','NO'], true)) throw new Exception("Breastfed 1st hour must be YES or NO.");
        if (!in_array((string)$_POST['infant_teo_given'], ['YES','NO'], true)) throw new Exception("TEO given must be YES or NO.");

        foreach (['serial_no','ga_admission_weeks','gravida','parity','children_alive','estimated_blood_loss_ml','birth_weight_grams'] as $k) {
            if (!is_numeric((string)$_POST[$k])) throw new Exception("{$k} must be numeric.");
        }
        foreach (['admission_datetime','onset_of_labour_datetime','delivery_datetime'] as $k) {
            if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}$/', (string)$_POST[$k])) throw new Exception("{$k} must be a date/time.");
        }

        // If a baby was already registered (Mother History / Register Baby), reuse its details to avoid retyping.
        if ($birth_outcome === 'LIVE' && $baby_name === '') {
            $babyDob = substr((string)$_POST['delivery_datetime'], 0, 10);
            $lookup = $pdo->prepare("
                SELECT baby_name
                FROM babies
                WHERE mother_id = ?
                  AND dob = ?
                ORDER BY id DESC
                LIMIT 1
            ");
            $lookup->execute([$mother_id, $babyDob]);
            $existingName = (string)($lookup->fetchColumn() ?: '');
            if ($existingName !== '') {
                $baby_name = $existingName;
                $_POST['baby_name'] = $existingName;
            }
        }
        if ($birth_outcome === 'LIVE' && trim($baby_name) === '') throw new Exception("Baby name is required for live births.");

        // Mother snapshot + age band.
        $mStmt = $pdo->prepare("
            SELECT m.fullname, m.address, m.phone, m.dob, m.hospital_number
            FROM mothers m
            JOIN users u ON u.id = m.user_id
            WHERE m.id = ? AND u.clinic_id = ?
            LIMIT 1
        ");
        $mStmt->execute([$mother_id, $scopedClinicId]);
        $m = $mStmt->fetch(PDO::FETCH_ASSOC);
        if (!$m) throw new Exception("Mother not found (or not in your clinic).");

        $dob = (string)($m['dob'] ?? '');
        $dobDt = DateTimeImmutable::createFromFormat('Y-m-d', $dob);
        if (!$dobDt || $dobDt->format('Y-m-d') !== $dob) throw new Exception("Mother DOB invalid. Fix mother record first.");
        $ageYears = (int)$dobDt->diff(new DateTimeImmutable('today'))->y;
        $ageBand = mch_age_band($ageYears);

        $mother_name = (string)($m['fullname'] ?? '');
        $mother_address = (string)($m['address'] ?? '');
        $mother_phone = (string)($m['phone'] ?? '');
        $hospital_number = (string)($m['hospital_number'] ?? '');
        if ($mother_name === '' || $mother_address === '' || $mother_phone === '' || $hospital_number === '') {
            throw new Exception("Mother must have name, address, phone, and hospital number.");
        }

        $pdo->beginTransaction();

        // Prevent duplicate serial numbers for the same clinic/day.
        $serialNo = (int)$_POST['serial_no'];
        if ($serialNo <= 0) {
            throw new Exception("Serial # must be 1 or greater.");
        }
        $from = $serialDate . " 00:00:00";
        $to = $serialDate . " 23:59:59";
        $dupSerial = $pdo->prepare("
            SELECT 1
            FROM delivery_register
            WHERE clinic_id = ?
              AND delivery_datetime BETWEEN ? AND ?
              AND serial_no = ?
            LIMIT 1
        ");
        $dupSerial->execute([$scopedClinicId, $from, $to, $serialNo]);
        if ($dupSerial->fetchColumn()) {
            throw new Exception("Serial number {$serialNo} is already used for {$serialDate}. Refresh the page to get the next serial.");
        }

        // Create baby + EPI schedule only for live births.
        $baby_id = null;
        if ($birth_outcome === 'LIVE') {
            $babyDob = substr((string)$_POST['delivery_datetime'], 0, 10);
            $babyGender = ((string)$_POST['baby_sex'] === 'B') ? 'Male' : 'Female';
            $bwKg = ((float)$_POST['birth_weight_grams']) / 1000.0;
            if ($bwKg < 0 || $bwKg > 5) throw new Exception("Birth weight grams must convert to 0-5 kg range.");

            // Prefer reusing an existing baby record for the same mother, DOB and name (prevents duplicates).
            $findBaby = $pdo->prepare("
                SELECT id
                FROM babies
                WHERE mother_id = ?
                  AND dob = ?
                  AND baby_name = ?
                ORDER BY id DESC
                LIMIT 1
            ");
            $findBaby->execute([$mother_id, $babyDob, $baby_name]);
            $existingBabyId = (int)($findBaby->fetchColumn() ?: 0);
            if ($existingBabyId > 0) {
                $baby_id = $existingBabyId;
            } else {
                $stmtB = $pdo->prepare("INSERT INTO babies (mother_id, baby_name, dob, gender, birth_weight, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                $stmtB->execute([$mother_id, $baby_name, $babyDob, $babyGender, (string)$bwKg]);
                $baby_id = (int)$pdo->lastInsertId();
                mch_epi_insert_schedule($pdo, (int)$baby_id, (string)$babyDob);
            }
        }

        // Prevent duplicate register entries for the same mother/day (unless it's a different baby).
        if ($birth_outcome === 'LIVE' && $baby_id !== null) {
            $dup = $pdo->prepare("
                SELECT id, serial_no, delivery_datetime
                FROM delivery_register
                WHERE clinic_id = ?
                  AND mother_id = ?
                  AND birth_outcome = 'LIVE'
                  AND baby_id = ?
                  AND delivery_datetime BETWEEN ? AND ?
                LIMIT 1
            ");
            $dup->execute([$scopedClinicId, $mother_id, (int)$baby_id, $from, $to]);
            $row = $dup->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $sn = (string)($row['serial_no'] ?? '');
                $dt = (string)($row['delivery_datetime'] ?? $serialDate);
                throw new Exception("Duplicate entry: this mother/baby is already recorded for {$serialDate}" . ($sn !== '' ? " (Serial #{$sn}, time {$dt})." : "."));
            }
        }
        if ($birth_outcome !== 'LIVE') {
            $dup = $pdo->prepare("
                SELECT id, serial_no, delivery_datetime
                FROM delivery_register
                WHERE clinic_id = ?
                  AND mother_id = ?
                  AND birth_outcome = ?
                  AND baby_id IS NULL
                  AND delivery_datetime BETWEEN ? AND ?
                LIMIT 1
            ");
            $dup->execute([$scopedClinicId, $mother_id, $birth_outcome, $from, $to]);
            $row = $dup->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $sn = (string)($row['serial_no'] ?? '');
                $dt = (string)($row['delivery_datetime'] ?? $serialDate);
                throw new Exception("Duplicate entry: this mother is already recorded for {$serialDate} ({$birth_outcome})" . ($sn !== '' ? " (Serial #{$sn}, time {$dt})." : "."));
            }
        }

        // Update mother birth record fields if schema supports it.
        if (db_has_column($pdo, 'mothers', 'national_id_number')) {
            $upd = $pdo->prepare("UPDATE mothers SET national_id_number = ?, marital_status = ?, place_of_birth = ? WHERE id = ? LIMIT 1");
            $upd->execute([trim((string)$_POST['national_id_number']), trim((string)$_POST['marital_status']), trim((string)$_POST['place_of_birth']), $mother_id]);
        }

        $stmt = $pdo->prepare("
            INSERT INTO delivery_register (
                mother_id, baby_id, clinic_id,
                serial_no, hospital_number, anc_no,
                mother_name, mother_address, mother_phone,
                age_years, age_band,
                ga_admission_weeks, booked_status, gravida, parity, children_alive, past_obstetric_complications,
                admission_datetime, presentation_position, abnormalities_on_admission,
                onset_of_labour_datetime, duration_of_stages, drugs_given_in_labour,
                delivery_datetime, delivery_method, estimated_blood_loss_ml, perineum, placenta_membranes,
                delivered_by_qualification, assisted_by,
                birth_outcome, baby_sex, birth_weight_grams, apgar_score, tetanus_protected,
                hiv_status_on_admission, tc_tested, tc_result,
                maternal_pmtct_arvs, maternal_pmtct_other, maternal_vitamin_a,
                infant_feeding_method, infant_breastfed_first_hour, infant_teo_given,
                comments,
                created_by, created_at
            ) VALUES (
                ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?,
                ?, ?,
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?,
                ?,
                ?, NOW()
            )
        ");

        $pmtctOther = null;
        if ((string)$_POST['maternal_pmtct_arvs'] === 'OTHER') {
            $pmtctOther = trim((string)($_POST['maternal_pmtct_other'] ?? ''));
            if ($pmtctOther === '') throw new Exception("maternal_pmtct_other is required when PMTCT is OTHER.");
        }

        $stmt->execute([
            $mother_id, $baby_id, $scopedClinicId,
            $serialNo, $hospital_number, $ancNoNorm,
            $mother_name, $mother_address, $mother_phone,
            $ageYears, $ageBand,
            (int)$_POST['ga_admission_weeks'], (string)$_POST['booked_status'], (int)$_POST['gravida'], (int)$_POST['parity'], (int)$_POST['children_alive'], trim((string)$_POST['past_obstetric_complications']),
            str_replace('T', ' ', (string)$_POST['admission_datetime']) . ':00', trim((string)$_POST['presentation_position']), trim((string)$_POST['abnormalities_on_admission']),
                str_replace('T', ' ', (string)$_POST['onset_of_labour_datetime']) . ':00', $durationStages, trim((string)$_POST['drugs_given_in_labour']),
                str_replace('T', ' ', (string)$_POST['delivery_datetime']) . ':00', (string)$_POST['delivery_method'], (int)$_POST['estimated_blood_loss_ml'], trim((string)$_POST['perineum']), (string)$_POST['placenta_membranes'],
                trim((string)$_POST['delivered_by_qualification']), trim((string)$_POST['assisted_by']),
                $birth_outcome, (string)$_POST['baby_sex'], (int)$_POST['birth_weight_grams'], trim((string)$_POST['apgar_score']), (string)$_POST['tetanus_protected'],
                (string)$_POST['hiv_status_on_admission'], (string)$_POST['tc_tested'], (string)$_POST['tc_result'],
            (string)$_POST['maternal_pmtct_arvs'], $pmtctOther, (string)$_POST['maternal_vitamin_a'],
            (string)$_POST['infant_feeding_method'], (string)$_POST['infant_breastfed_first_hour'], (string)$_POST['infant_teo_given'],
            (trim((string)($_POST['comments'] ?? '')) ?: null),
            (int)($_SESSION['user_id'] ?? 0),
        ]);

        $newId = (int)$pdo->lastInsertId();
        $pdo->commit();
        mch_audit_log($pdo, 'DELIVERY_REGISTER_CREATE', 'delivery_register', $newId, "Created delivery register entry id {$newId} (mother_id={$mother_id}).");

        // PRG: avoid double-submit and refresh serial number for the day of delivery.
        header('Location: delivery_register.php?' . http_build_query(['date' => $serialDate, 'saved' => '1']));
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

// Daily list
$entries = [];
if ($hasRegisterTable && $scopedClinicId > 0) {
    $from = $selectedDate . " 00:00:00";
    $to = $selectedDate . " 23:59:59";
    $stmt = $pdo->prepare("
        SELECT id, serial_no, hospital_number, mother_name, delivery_datetime, birth_outcome, baby_sex, birth_weight_grams, delivery_method
        FROM delivery_register
        WHERE clinic_id = ?
          AND delivery_datetime BETWEEN ? AND ?
        ORDER BY delivery_datetime ASC, id ASC
    ");
    $stmt->execute([$scopedClinicId, $from, $to]);
    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_nurse.php";

?>

<div class="ml-64 p-6 space-y-6 max-w-6xl">
  <div class="mch-card bg-white/90 p-6 border border-slate-100">
    <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold text-slate-900">Delivery Register</h1>
        <p class="text-sm text-slate-600">Replaces the daily ward delivery register book (nurse-only).</p>
      </div>
      <form method="GET" class="flex items-end gap-2">
        <div>
          <label class="block text-xs text-slate-500 mb-1">Date</label>
          <input type="date" name="date" value="<?= htmlspecialchars($selectedDate) ?>" class="border border-slate-200 p-2 rounded-lg bg-white">
        </div>
        <button type="submit" class="mch-btn px-4 py-2">Go</button>
        <?php if ($hasRegisterTable): ?>
          <a class="px-4 py-2 rounded-lg bg-slate-900 text-white hover:bg-slate-800"
             href="/mch_system/nurse_portal/delivery_register_export.php?date=<?= urlencode($selectedDate) ?>&format=html" target="_blank" rel="noopener">
            Print / Export
          </a>
        <?php endif; ?>
      </form>
    </div>

    <?php if (!$hasRegisterTable): ?>
      <div class="mt-4 mch-card bg-amber-50 border border-amber-200 text-amber-900 p-4 text-sm">
        Delivery Register is not installed. Run <span class="font-mono">sql/delivery_register.sql</span> on the database, then refresh.
      </div>
    <?php endif; ?>

    <?php if ($error): ?><div class="mt-4 bg-rose-100 text-rose-700 p-3 rounded-lg"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="mt-4 bg-emerald-100 text-emerald-700 p-3 rounded-lg"><?= htmlspecialchars($success) ?></div><?php endif; ?>
  </div>

  <?php if ($hasRegisterTable && $scopedClinicId > 0): ?>
    <div class="mch-card bg-white/90 p-6 border border-slate-100">
      <h2 class="text-lg font-semibold text-slate-900">Add Entry</h2>
      <form method="POST" class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
        <?= mch_csrf_input('nurse_delivery_register') ?>

        <div class="md:col-span-2">
          <label class="block text-sm font-semibold text-slate-700 mb-1">Mother</label>
          <select name="mother_id" id="mother_id" required class="w-full border border-slate-200 p-2 rounded-lg bg-white">
            <option value="">Select Mother</option>
            <?php foreach ($mothers as $m): ?>
              <option value="<?= (int)$m['id'] ?>"
                      <?= ((int)($_POST['mother_id'] ?? 0) === (int)$m['id']) ? 'selected' : '' ?>
                      data-hospital="<?= htmlspecialchars((string)($m['hospital_number'] ?? ''), ENT_QUOTES) ?>"
                      data-dob="<?= htmlspecialchars((string)($m['dob'] ?? ''), ENT_QUOTES) ?>"
                      data-gravida="<?= htmlspecialchars((string)($m['gravida'] ?? ''), ENT_QUOTES) ?>"
                      data-parity="<?= htmlspecialchars((string)($m['parity'] ?? ''), ENT_QUOTES) ?>"
                      data-national-id="<?= htmlspecialchars((string)($m['national_id_number'] ?? ''), ENT_QUOTES) ?>"
                      data-marital="<?= htmlspecialchars((string)($m['marital_status'] ?? ''), ENT_QUOTES) ?>"
                      data-pob="<?= htmlspecialchars((string)($m['place_of_birth'] ?? ''), ENT_QUOTES) ?>">
                <?= htmlspecialchars((string)$m['fullname']) ?> (<?= htmlspecialchars((string)$m['hospital_number']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
          <div id="mother_hint" class="hidden mt-2 text-xs rounded-lg border border-sky-200 bg-sky-50 text-sky-800 px-3 py-2"></div>
        </div>

        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">Serial #</label>
          <input type="number" name="serial_no" required value="<?= mch_val('serial_no', (string)$nextSerial) ?>" class="w-full border border-slate-200 p-2 rounded-lg bg-white" min="1">
          <p class="text-xs text-slate-500 mt-1">Auto-suggested for <?= htmlspecialchars($selectedDate) ?> (daily counter). You can change if needed.</p>
        </div>
        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">ANC No</label>
          <input type="text" name="anc_no" id="anc_no" required value="<?= mch_val('anc_no') ?>"
                 class="w-full border border-slate-200 p-2 rounded-lg bg-white"
                 placeholder="e.g. 2024P00807" pattern="[0-9]{4}[Pp][0-9]{5}" title="Format: YYYYP00000 (5 digits after P)">
          <p class="text-xs text-slate-500 mt-1">Format: <span class="font-mono">YYYYP00000</span> (5 digits after P), e.g. <span class="font-mono">2024P00807</span>.</p>
        </div>

        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">GA admission (weeks)</label>
          <input type="number" name="ga_admission_weeks" required value="<?= mch_val('ga_admission_weeks') ?>" class="w-full border border-slate-200 p-2 rounded-lg bg-white">
        </div>
        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">Booked (B/U)</label>
          <select name="booked_status" required class="w-full border border-slate-200 p-2 rounded-lg bg-white">
            <option value=""></option>
            <option value="B" <?= (($_POST['booked_status'] ?? '') === 'B') ? 'selected' : '' ?>>B</option>
            <option value="U" <?= (($_POST['booked_status'] ?? '') === 'U') ? 'selected' : '' ?>>U</option>
          </select>
        </div>

        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">Gravida</label>
          <input type="number" name="gravida" id="gravida" required value="<?= mch_val('gravida') ?>" class="w-full border border-slate-200 p-2 rounded-lg bg-white">
        </div>
        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">Parity</label>
          <input type="number" name="parity" id="parity" required value="<?= mch_val('parity') ?>" class="w-full border border-slate-200 p-2 rounded-lg bg-white">
        </div>

        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">Children alive</label>
          <input type="number" name="children_alive" required value="<?= mch_val('children_alive') ?>" class="w-full border border-slate-200 p-2 rounded-lg bg-white">
        </div>
        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">Past obstetric complications</label>
          <input type="text" name="past_obstetric_complications" required value="<?= mch_val('past_obstetric_complications') ?>" class="w-full border border-slate-200 p-2 rounded-lg bg-white">
        </div>

        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">Admission date/time</label>
          <input type="datetime-local" name="admission_datetime" required value="<?= mch_val('admission_datetime') ?>" class="w-full border border-slate-200 p-2 rounded-lg bg-white">
        </div>
        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">Presentation/position</label>
          <input type="text" name="presentation_position" required value="<?= mch_val('presentation_position') ?>" class="w-full border border-slate-200 p-2 rounded-lg bg-white">
        </div>

        <div class="md:col-span-2">
          <label class="block text-sm font-semibold text-slate-700 mb-1">Abnormalities on admission</label>
          <input type="text" name="abnormalities_on_admission" required value="<?= mch_val('abnormalities_on_admission') ?>" class="w-full border border-slate-200 p-2 rounded-lg bg-white">
        </div>

        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">Onset of labour date/time</label>
          <input type="datetime-local" name="onset_of_labour_datetime" required value="<?= mch_val('onset_of_labour_datetime') ?>" class="w-full border border-slate-200 p-2 rounded-lg bg-white">
        </div>
        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">Duration stages</label>
          <div class="grid grid-cols-1 md:grid-cols-3 gap-2">
            <div class="rounded-lg border border-slate-200 bg-white p-3">
              <div class="text-xs font-semibold text-slate-700">1st stage</div>
              <div class="mt-2 flex gap-2">
                <input type="number" name="stage1_hours" min="0" max="99" required value="<?= mch_val('stage1_hours', '0') ?>" class="min-w-0 w-full border border-slate-200 p-2 rounded-lg bg-white text-slate-900" placeholder="Hours">
                <input type="number" name="stage1_minutes" min="0" max="59" required value="<?= mch_val('stage1_minutes', '0') ?>" class="min-w-0 w-full border border-slate-200 p-2 rounded-lg bg-white text-slate-900" placeholder="Minutes">
              </div>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-3">
              <div class="text-xs font-semibold text-slate-700">2nd stage</div>
              <div class="mt-2 flex gap-2">
                <input type="number" name="stage2_hours" min="0" max="99" required value="<?= mch_val('stage2_hours', '0') ?>" class="min-w-0 w-full border border-slate-200 p-2 rounded-lg bg-white text-slate-900" placeholder="Hours">
                <input type="number" name="stage2_minutes" min="0" max="59" required value="<?= mch_val('stage2_minutes', '0') ?>" class="min-w-0 w-full border border-slate-200 p-2 rounded-lg bg-white text-slate-900" placeholder="Minutes">
              </div>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-3">
              <div class="text-xs font-semibold text-slate-700">3rd stage</div>
              <div class="mt-2 flex gap-2">
                <input type="number" name="stage3_hours" min="0" max="99" required value="<?= mch_val('stage3_hours', '0') ?>" class="min-w-0 w-full border border-slate-200 p-2 rounded-lg bg-white text-slate-900" placeholder="Hours">
                <input type="number" name="stage3_minutes" min="0" max="59" required value="<?= mch_val('stage3_minutes', '0') ?>" class="min-w-0 w-full border border-slate-200 p-2 rounded-lg bg-white text-slate-900" placeholder="Minutes">
              </div>
            </div>
          </div>
          <div class="mt-2 grid grid-cols-1 md:grid-cols-2 gap-2">
            <input type="text" id="duration_total_display" readonly class="border border-slate-200 p-2 rounded-lg bg-slate-50" value="">
            <input type="text" name="duration_of_stages" id="duration_of_stages" readonly class="border border-slate-200 p-2 rounded-lg bg-slate-50" value="<?= mch_val('duration_of_stages') ?>">
          </div>
          <p class="text-xs text-slate-500 mt-1">Total is auto-calculated from 1st + 2nd + 3rd stage.</p>
        </div>

        <div class="md:col-span-2">
          <label class="block text-sm font-semibold text-slate-700 mb-1">Drugs/GA (times)</label>
          <input type="text" name="drugs_given_in_labour" required value="<?= mch_val('drugs_given_in_labour') ?>" class="w-full border border-slate-200 p-2 rounded-lg bg-white">
        </div>

        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">Delivery date/time</label>
          <input type="datetime-local" name="delivery_datetime" required value="<?= mch_val('delivery_datetime') ?>" class="w-full border border-slate-200 p-2 rounded-lg bg-white">
        </div>
        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">Delivery method</label>
          <select name="delivery_method" required class="w-full border border-slate-200 p-2 rounded-lg bg-white">
            <option value=""></option>
            <?php foreach (['NVD'=>'NVD','BREECH'=>'Breech','CS'=>'C/S','FORCEPS'=>'Forceps','VACUUM'=>'Vacuum'] as $v => $lbl): ?>
              <option value="<?= htmlspecialchars($v) ?>" <?= (($_POST['delivery_method'] ?? '') === $v) ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">Estimated blood loss(ms)</label>
          <input type="number" name="estimated_blood_loss_ml" required value="<?= mch_val('estimated_blood_loss_ml') ?>" class="w-full border border-slate-200 p-2 rounded-lg bg-white">
        </div>
        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">Perineum</label>
          <input type="text" name="perineum" required value="<?= mch_val('perineum') ?>" class="w-full border border-slate-200 p-2 rounded-lg bg-white">
        </div>

        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">Placenta/membranes</label>
          <select name="placenta_membranes" required class="w-full border border-slate-200 p-2 rounded-lg bg-white">
            <option value=""></option>
            <?php foreach (['COMPLETE'=>'Complete','INCOMPLETE'=>'Incomplete','RAGGED'=>'Ragged'] as $v => $lbl): ?>
              <option value="<?= htmlspecialchars($v) ?>" <?= (($_POST['placenta_membranes'] ?? '') === $v) ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">Delivery by (qualification)</label>
          <input type="text" name="delivered_by_qualification" required value="<?= mch_val('delivered_by_qualification') ?>" class="w-full border border-slate-200 p-2 rounded-lg bg-white">
        </div>

        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">Assisted by</label>
          <input type="text" name="assisted_by" required value="<?= mch_val('assisted_by') ?>" class="w-full border border-slate-200 p-2 rounded-lg bg-white">
        </div>
        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">Birth outcome live birth/still birth</label>
          <select name="birth_outcome" required class="w-full border border-slate-200 p-2 rounded-lg bg-white">
            <option value=""></option>
            <?php foreach (['LIVE'=>'Live','FSB'=>'FSB','MSB'=>'MSB'] as $v => $lbl): ?>
              <option value="<?= htmlspecialchars($v) ?>" <?= (($_POST['birth_outcome'] ?? '') === $v) ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">Baby name (LIVE)</label>
          <input type="text" name="baby_name" value="<?= mch_val('baby_name') ?>" class="w-full border border-slate-200 p-2 rounded-lg bg-white" placeholder="Required for LIVE">
        </div>
        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">Sex (Boy/Girl)</label>
          <select name="baby_sex" required class="w-full border border-slate-200 p-2 rounded-lg bg-white">
            <option value=""></option>
            <option value="B" <?= (($_POST['baby_sex'] ?? '') === 'B') ? 'selected' : '' ?>>B</option>
            <option value="G" <?= (($_POST['baby_sex'] ?? '') === 'G') ? 'selected' : '' ?>>G</option>
          </select>
        </div>

        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">Birth weight (grams)</label>
          <input type="number" name="birth_weight_grams" required value="<?= mch_val('birth_weight_grams') ?>" class="w-full border border-slate-200 p-2 rounded-lg bg-white">
        </div>
        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">Apgar score</label>
          <input type="text" name="apgar_score" required value="<?= mch_val('apgar_score') ?>" class="w-full border border-slate-200 p-2 rounded-lg bg-white" placeholder="8/10">
        </div>

        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">Child protected agaisnt Tetanus</label>
          <select name="tetanus_protected" required class="w-full border border-slate-200 p-2 rounded-lg bg-white">
            <option value=""></option>
            <option value="YES" <?= (($_POST['tetanus_protected'] ?? '') === 'YES') ? 'selected' : '' ?>>Yes</option>
            <option value="NO" <?= (($_POST['tetanus_protected'] ?? '') === 'NO') ? 'selected' : '' ?>>No</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">HIV status on admission</label>
          <select name="hiv_status_on_admission" required class="w-full border border-slate-200 p-2 rounded-lg bg-white">
            <option value=""></option>
            <?php foreach (['1'=>'1','0_NOT_DUE'=>'0 not due retest','0_DUE_RETEST'=>'0 due retest','UNKNOWN'=>'Unknown'] as $v => $lbl): ?>
              <option value="<?= htmlspecialchars($v) ?>" <?= (($_POST['hiv_status_on_admission'] ?? '') === $v) ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">T&C IN LABOUR</label>
          <select name="tc_tested" required class="w-full border border-slate-200 p-2 rounded-lg bg-white">
            <option value=""></option>
            <option value="YES" <?= (($_POST['tc_tested'] ?? '') === 'YES') ? 'selected' : '' ?>>Yes</option>
            <option value="NO" <?= (($_POST['tc_tested'] ?? '') === 'NO') ? 'selected' : '' ?>>No</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">T&amp;C result</label>
          <select name="tc_result" required class="w-full border border-slate-200 p-2 rounded-lg bg-white">
            <option value=""></option>
            <option value="1" <?= (($_POST['tc_result'] ?? '') === '1') ? 'selected' : '' ?>>1</option>
            <option value="0" <?= (($_POST['tc_result'] ?? '') === '0') ? 'selected' : '' ?>>0</option>
          </select>
        </div>

        <div class="md:col-span-2">
          <label class="block text-sm font-semibold text-slate-700 mb-1">Maternal PMTCT ARVs taken</label>
          <select name="maternal_pmtct_arvs" id="maternal_pmtct_arvs" required class="w-full border border-slate-200 p-2 rounded-lg bg-white">
            <option value=""></option>
            <?php foreach (['TDF_3TC_EFV'=>'TDF+3TC+EFV','TDF_3TC_NVP'=>'TDF+3TC+NVP','SDNVP_AZT'=>'SDnVP+AZT','OTHER'=>'Other','NONE'=>'None'] as $v => $lbl): ?>
              <option value="<?= htmlspecialchars($v) ?>" <?= (($_POST['maternal_pmtct_arvs'] ?? '') === $v) ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
            <?php endforeach; ?>
          </select>
          <input type="text" name="maternal_pmtct_other" id="maternal_pmtct_other" value="<?= mch_val('maternal_pmtct_other') ?>" class="mt-2 w-full border border-slate-200 p-2 rounded-lg bg-white hidden" placeholder="If Other, specify">
        </div>

        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">Vitamin A</label>
          <select name="maternal_vitamin_a" required class="w-full border border-slate-200 p-2 rounded-lg bg-white">
            <option value=""></option>
            <option value="YES" <?= (($_POST['maternal_vitamin_a'] ?? '') === 'YES') ? 'selected' : '' ?>>Yes</option>
            <option value="NO" <?= (($_POST['maternal_vitamin_a'] ?? '') === 'NO') ? 'selected' : '' ?>>No</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">infant Feeding method</label>
          <select name="infant_feeding_method" required class="w-full border border-slate-200 p-2 rounded-lg bg-white">
            <option value=""></option>
            <option value="EBF" <?= (($_POST['infant_feeding_method'] ?? '') === 'EBF') ? 'selected' : '' ?>>EBF</option>
            <option value="ECF" <?= (($_POST['infant_feeding_method'] ?? '') === 'ECF') ? 'selected' : '' ?>>ECF</option>
          </select>
        </div>

        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">infant breastfed in the 1st hr of birth</label>
          <select name="infant_breastfed_first_hour" required class="w-full border border-slate-200 p-2 rounded-lg bg-white">
            <option value=""></option>
            <option value="YES" <?= (($_POST['infant_breastfed_first_hour'] ?? '') === 'YES') ? 'selected' : '' ?>>Yes</option>
            <option value="NO" <?= (($_POST['infant_breastfed_first_hour'] ?? '') === 'NO') ? 'selected' : '' ?>>No</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">infant TEO given</label>
          <select name="infant_teo_given" required class="w-full border border-slate-200 p-2 rounded-lg bg-white">
            <option value=""></option>
            <option value="YES" <?= (($_POST['infant_teo_given'] ?? '') === 'YES') ? 'selected' : '' ?>>Yes</option>
            <option value="NO" <?= (($_POST['infant_teo_given'] ?? '') === 'NO') ? 'selected' : '' ?>>No</option>
          </select>
        </div>

        <div class="md:col-span-2 rounded-xl border border-slate-200 bg-slate-50 p-4">
          <div class="text-sm font-semibold text-slate-800">Birth Record Verification (Mother)</div>
          <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-3">
            <div>
              <label class="block text-xs text-slate-600 mb-1">National ID</label>
              <input type="text" name="national_id_number" id="national_id_number" required value="<?= mch_val('national_id_number') ?>" class="w-full border border-slate-200 p-2 rounded-lg bg-white">
            </div>
            <div>
              <label class="block text-xs text-slate-600 mb-1">Marital status</label>
              <select name="marital_status" id="marital_status" required class="w-full border border-slate-200 p-2 rounded-lg bg-white">
                <option value=""></option>
                <?php foreach ([
                  'Married (in a union)',
                  'Living with Partner',
                  'Separated',
                  'Divorced',
                  'Widowed',
                ] as $v): ?>
                  <option value="<?= htmlspecialchars($v, ENT_QUOTES) ?>" <?= (($_POST['marital_status'] ?? '') === $v) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($v) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="block text-xs text-slate-600 mb-1">Place of birth</label>
              <input type="text" name="place_of_birth" id="place_of_birth" required value="<?= mch_val('place_of_birth') ?>" class="w-full border border-slate-200 p-2 rounded-lg bg-white">
            </div>
          </div>
        </div>

        <div class="md:col-span-2">
          <label class="block text-sm font-semibold text-slate-700 mb-1">Comments</label>
          <textarea name="comments" rows="2" class="w-full border border-slate-200 p-2 rounded-lg bg-white"><?= htmlspecialchars((string)($_POST['comments'] ?? ''), ENT_QUOTES) ?></textarea>
        </div>

        <div class="md:col-span-2">
          <button type="submit" class="mch-btn w-full py-2">Save</button>
        </div>
      </form>
    </div>

    <div class="mch-card bg-white/90 p-6 border border-slate-100">
      <div class="flex items-center justify-between">
        <h2 class="text-lg font-semibold text-slate-900">Entries (<?= htmlspecialchars($selectedDate) ?>)</h2>
        <div class="text-xs text-slate-500"><?= count($entries) ?> entries</div>
      </div>
      <?php if (count($entries) === 0): ?>
        <p class="text-slate-500 mt-3">No entries yet.</p>
      <?php else: ?>
        <div class="overflow-x-auto mt-3">
          <table class="w-full text-sm mch-table">
            <thead>
              <tr>
                <th class="p-2 text-left">Time</th>
                <th class="p-2 text-left">Serial</th>
                <th class="p-2 text-left">Hospital #</th>
                <th class="p-2 text-left">Mother</th>
                <th class="p-2 text-left">Outcome</th>
                <th class="p-2 text-left">Sex</th>
                <th class="p-2 text-left">Wt (g)</th>
                <th class="p-2 text-left">Method</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($entries as $r): ?>
                <tr class="border-t border-slate-200">
                  <td class="p-2"><?= htmlspecialchars(substr((string)$r['delivery_datetime'], 11, 5)) ?></td>
                  <td class="p-2"><?= htmlspecialchars((string)$r['serial_no']) ?></td>
                  <td class="p-2"><?= htmlspecialchars((string)$r['hospital_number']) ?></td>
                  <td class="p-2"><?= htmlspecialchars((string)$r['mother_name']) ?></td>
                  <td class="p-2"><?= htmlspecialchars((string)$r['birth_outcome']) ?></td>
                  <td class="p-2"><?= htmlspecialchars((string)$r['baby_sex']) ?></td>
                  <td class="p-2"><?= htmlspecialchars((string)$r['birth_weight_grams']) ?></td>
                  <td class="p-2"><?= htmlspecialchars((string)$r['delivery_method']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php if ($hasRegisterTable && $scopedClinicId > 0): ?>
<script>
  (function () {
    const sel = document.getElementById('mother_id');
    const anc = document.getElementById('anc_no');
    const gravida = document.getElementById('gravida');
    const parity = document.getElementById('parity');
    const hint = document.getElementById('mother_hint');
    const nid = document.getElementById('national_id_number');
    const marital = document.getElementById('marital_status');
    const pob = document.getElementById('place_of_birth');
    const pmtct = document.getElementById('maternal_pmtct_arvs');
    const pmtctOther = document.getElementById('maternal_pmtct_other');

    const babyName = document.querySelector('[name="baby_name"]');
    const babySex = document.querySelector('[name="baby_sex"]');
    const birthWeight = document.querySelector('[name="birth_weight_grams"]');
    const deliveryDt = document.querySelector('[name="delivery_datetime"]');
    const deliveryMethod = document.querySelector('[name="delivery_method"]');

    let lastBirthLookupMotherId = null;

    async function hydrateFromLatestBirth(motherId) {
      if (!motherId) return;
      lastBirthLookupMotherId = String(motherId);
      try {
        const res = await fetch(`/mch_system/api/mother_latest_birth.php?mother_id=${encodeURIComponent(motherId)}`, {
          credentials: 'same-origin',
          headers: { 'Accept': 'application/json' },
        });
        const json = await res.json();
        if (String(motherId) !== lastBirthLookupMotherId) return; // stale response
        if (!json || !json.ok || !json.data || !json.data.suggest) return;
        const s = json.data.suggest;

        if (babyName && babyName.value.trim() === '' && s.baby_name) babyName.value = s.baby_name;
        if (babySex && babySex.value.trim() === '' && s.baby_sex) babySex.value = s.baby_sex;
        if (birthWeight && birthWeight.value.trim() === '' && (s.birth_weight_grams || s.birth_weight_grams === 0)) birthWeight.value = String(s.birth_weight_grams);
        if (deliveryDt && deliveryDt.value.trim() === '' && s.delivery_datetime) deliveryDt.value = s.delivery_datetime;
        if (deliveryMethod && deliveryMethod.value.trim() === '' && s.delivery_method) deliveryMethod.value = s.delivery_method;
      } catch (e) {
        // ignore
      }
    }

    function normalizeMarital(v) {
      if (!v) return '';
      const s = String(v).trim();
      if (!s) return '';
      const lc = s.toLowerCase();
      const map = {
        'married': 'Married (includes those in a union)',
        'union': 'Married (includes those in a union)',
        'in a union': 'Married (includes those in a union)',
        'married/union': 'Married (includes those in a union)',
        'living with partner': 'Living with Partner',
        'living with a partner': 'Living with Partner',
        'cohabiting': 'Living with Partner',
        'separated': 'Separated',
        'divorced': 'Divorced',
        'widowed': 'Widowed',
        'widow': 'Widowed',
        'widower': 'Widowed',
      };
      return map[lc] || s;
    }

    function ageFromDob(dob) {
      if (!dob || !/^\\d{4}-\\d{2}-\\d{2}$/.test(dob)) return null;
      const dt = new Date(dob + 'T00:00:00');
      if (Number.isNaN(dt.getTime())) return null;
      const now = new Date();
      let age = now.getFullYear() - dt.getFullYear();
      const m = now.getMonth() - dt.getMonth();
      if (m < 0 || (m === 0 && now.getDate() < dt.getDate())) age--;
      return age;
    }

    function updateMother() {
      const opt = sel && sel.options ? sel.options[sel.selectedIndex] : null;
      if (!opt || !opt.value) {
        hint && hint.classList.add('hidden');
        return;
      }
      const hospital = opt.dataset.hospital || '';
      const age = ageFromDob(opt.dataset.dob || '');
      if (gravida && gravida.value.trim() === '' && opt.dataset.gravida) gravida.value = opt.dataset.gravida;
      if (parity && parity.value.trim() === '' && opt.dataset.parity) parity.value = opt.dataset.parity;
      if (nid && nid.value.trim() === '' && opt.dataset.nationalId) nid.value = opt.dataset.nationalId;
      if (marital && marital.value.trim() === '' && opt.dataset.marital) marital.value = normalizeMarital(opt.dataset.marital);
      if (pob && pob.value.trim() === '' && opt.dataset.pob) pob.value = opt.dataset.pob;
      hydrateFromLatestBirth(opt.value);
      if (hint) {
        hint.classList.remove('hidden');
        hint.textContent = `Hospital #: ${hospital}${age !== null ? ` • Age ${age}` : ''}`;
      }
    }
    sel && sel.addEventListener('change', updateMother);
    updateMother();

    // ANC No normalization: allow inputs like 2024p807 and normalize to 2024P00807.
    if (anc) {
      const normalize = () => {
        const raw = (anc.value || '').toString().trim();
        if (!raw) return;
        const compact = raw.replace(/\\s+/g, '').toUpperCase();
        const m = compact.match(/^(\\d{4})P(\\d{1,5})$/);
        if (m) {
          const year = m[1];
          const digits = m[2].padStart(5, '0');
          anc.value = year + 'P' + digits;
        }
      };
      anc.addEventListener('blur', normalize);
      anc.addEventListener('change', normalize);
    }

    // Labour stage duration calculation (auto total).
    (function () {
      const inputs = [
        'stage1_hours','stage1_minutes',
        'stage2_hours','stage2_minutes',
        'stage3_hours','stage3_minutes',
      ].map(name => document.querySelector(`[name="${name}"]`)).filter(Boolean);

      const total = document.getElementById('duration_total_display');
      const packed = document.getElementById('duration_of_stages');
      if (!total || !packed || inputs.length === 0) return;

      const n = (el, max) => {
        const v = parseInt((el.value || '0'), 10);
        if (Number.isNaN(v) || v < 0) return 0;
        if (max !== null && v > max) return max;
        return v;
      };

      const pad2 = (x) => String(x).padStart(2, '0');

      const calc = () => {
        const s1h = n(document.querySelector('[name="stage1_hours"]'), 99);
        const s1m = n(document.querySelector('[name="stage1_minutes"]'), 59);
        const s2h = n(document.querySelector('[name="stage2_hours"]'), 99);
        const s2m = n(document.querySelector('[name="stage2_minutes"]'), 59);
        const s3h = n(document.querySelector('[name="stage3_hours"]'), 99);
        const s3m = n(document.querySelector('[name="stage3_minutes"]'), 59);

        const minutes = (s1h*60+s1m) + (s2h*60+s2m) + (s3h*60+s3m);
        const th = Math.floor(minutes / 60);
        const tm = minutes % 60;

        total.value = `Total: ${th}h${pad2(tm)}m`;
        packed.value = `1st:${s1h}h${pad2(s1m)}m; 2nd:${s2h}h${pad2(s2m)}m; 3rd:${s3h}h${pad2(s3m)}m; Total:${th}h${pad2(tm)}m`;
      };

      inputs.forEach(el => {
        el.addEventListener('input', calc);
        el.addEventListener('change', calc);
      });
      calc();
    })();

    if (pmtct && pmtctOther) {
      const apply = () => {
        const show = pmtct.value === 'OTHER';
        pmtctOther.classList.toggle('hidden', !show);
        pmtctOther.required = show;
        if (!show) pmtctOther.value = '';
      };
      pmtct.addEventListener('change', apply);
      apply();
    }
  })();
</script>
<?php endif; ?>

<?php include __DIR__ . "/../includes/footer.php"; ?>
