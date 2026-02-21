<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/cards.php";
require_once __DIR__ . "/../config/crypto.php";
require_once __DIR__ . "/../config/csrf.php";
require_once __DIR__ . "/../config/audit.php";

$error = '';
$success = '';
$created_mother_id = 0;
$clinic_id = $_SESSION['clinic_id'] ?? null;
$supportsHeight = db_has_column($pdo, 'mothers', 'height_cm');
$supportsBookingWeight = db_has_column($pdo, 'mothers', 'booking_weight_kg');
$supportsMuac = db_has_column($pdo, 'mothers', 'muac_cm');
$supportsHiv = db_has_column($pdo, 'mothers', 'hiv_status');
$supportsSyphilis = db_has_column($pdo, 'mothers', 'syphilis_status');
$supportsMalaria = db_has_column($pdo, 'mothers', 'malaria_status');
$supportsBlood = db_has_column($pdo, 'mothers', 'blood_group');
$supportsRh = db_has_column($pdo, 'mothers', 'rh_factor');
$supportsDistance = db_has_column($pdo, 'mothers', 'distance_km');
$supportsBarriers = db_has_column($pdo, 'mothers', 'care_barriers');
$supportsSafeguardingFlag = db_has_column($pdo, 'mothers', 'safeguarding_flag');
$supportsSafeguardingStatus = db_has_column($pdo, 'mothers', 'safeguarding_status');
$supportsSafeguardingReason = db_has_column($pdo, 'mothers', 'safeguarding_reason');
$supportsSafeguardingAckBy = db_has_column($pdo, 'mothers', 'safeguarding_ack_by');
$supportsSafeguardingAckAt = db_has_column($pdo, 'mothers', 'safeguarding_ack_at');
$supportsExtraRisk = $supportsHeight || $supportsBookingWeight || $supportsMuac || $supportsHiv || $supportsSyphilis || $supportsMalaria || $supportsBlood || $supportsRh || $supportsDistance || $supportsBarriers;
$tasksEnabled = false;
try {
  $tasksEnabled = (bool)$pdo->query("SHOW TABLES LIKE 'follow_up_tasks'")->fetchColumn();
} catch (Throwable $e) {
  $tasksEnabled = false;
}
$preselect_user_id = (int)($_GET['existing_user_id'] ?? 0);
$returnTo = (string)($_GET['return'] ?? '');
if (!in_array($returnTo, ['activation_queue', ''], true)) {
  $returnTo = '';
}

// Flash messages (PRG pattern) to prevent form re-submission on refresh.
$flash = $_SESSION['flash_register_mother'] ?? null;
if (is_array($flash)) {
  $error = (string)($flash['error'] ?? '');
  $success = (string)($flash['success'] ?? '');
  $created_mother_id = (int)($flash['created_mother_id'] ?? 0);
  unset($_SESSION['flash_register_mother']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  mch_csrf_require_valid('nurse_register_mother');
  $action = $_POST['action'] ?? '';
  $returnTo = (string)($_POST['return'] ?? $returnTo);
  if (!in_array($returnTo, ['activation_queue', ''], true)) {
    $returnTo = '';
  }

    if ($action === 'undo_booking') {
    $undo_mother_id = (int)($_POST['mother_id'] ?? 0);
    if ($undo_mother_id <= 0) {
      $error = "Invalid undo request.";
    } else {
      try {
        $last = $_SESSION['last_booking'] ?? null;
        $last_mother_id = (int)($last['mother_id'] ?? 0);
        $last_user_id = (int)($last['user_id'] ?? 0);
        $last_user_was_new = (int)($last['user_was_new'] ?? 0) === 1;
        $last_prev_user_status = $last['prev_user_status'] ?? null;
        $last_at = (int)($last['at'] ?? 0);

        // Only allow undo for the last booking in this session, and only within a short window.
        if ($last_mother_id <= 0 || $last_mother_id !== $undo_mother_id) {
          throw new Exception("Undo is only available for the last booking you created.");
        }
        if ($last_at <= 0 || (time() - $last_at) > (30 * 60)) {
          throw new Exception("Undo window expired (30 minutes).");
        }

        // Confirm ownership / scoping (same clinic or unassigned).
        $mStmt = $pdo->prepare("
          SELECT m.id, m.user_id, m.created_at, u.clinic_id
          FROM mothers m
          LEFT JOIN users u ON m.user_id = u.id
          WHERE m.id = ?
          LIMIT 1
        ");
        $mStmt->execute([$undo_mother_id]);
        $mRow = $mStmt->fetch(PDO::FETCH_ASSOC);
        if (!$mRow) {
          throw new Exception("Mother record not found (may already be removed).");
        }
        if (!empty($mRow['clinic_id']) && (int)$mRow['clinic_id'] !== (int)$clinic_id) {
          throw new Exception("You can only undo records from your clinic.");
        }

        // Safety checks: don't allow undo if there are already babies or non-auto ANC activity.
        $bCountStmt = $pdo->prepare("SELECT COUNT(*) FROM babies WHERE mother_id = ?");
        $bCountStmt->execute([$undo_mother_id]);
        $babyCount = (int)$bCountStmt->fetchColumn();
        if ($babyCount > 0) {
          throw new Exception("Cannot undo: this mother already has baby records.");
        }

        $ancStmt = $pdo->prepare("
          SELECT COUNT(*)
          FROM anc_visits
          WHERE mother_id = ?
            AND (
              status <> 'PENDING'
              OR COALESCE(notes,'') <> 'Scheduled automatically'
            )
        ");
        $ancStmt->execute([$undo_mother_id]);
        $ancActive = (int)$ancStmt->fetchColumn();
        if ($ancActive > 0) {
          throw new Exception("Cannot undo: ANC visits have already been edited/logged for this mother.");
        }

        $pdo->beginTransaction();

        // Remove auto-generated ANC schedule first.
        $pdo->prepare("DELETE FROM anc_visits WHERE mother_id = ?")->execute([$undo_mother_id]);

        // Remove any follow-up tasks created for this mother (best-effort).
        try {
          if ($tasksEnabled) {
            $pdo->prepare("DELETE FROM follow_up_tasks WHERE subject_type = 'mother' AND subject_id = ?")->execute([$undo_mother_id]);
          }
        } catch (Throwable $e) {
          // ignore
        }

        // Remove the mother record.
        $pdo->prepare("DELETE FROM mothers WHERE id = ?")->execute([$undo_mother_id]);

        // Revert the linked user.
        if ($last_user_id > 0) {
          if ($last_user_was_new) {
            // If we created the user in this booking, remove it.
            $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'mother'")->execute([$last_user_id]);
          } else {
            // If this was a self-registered user, unlink and revert their status back.
            $restoreStatus = in_array($last_prev_user_status, ['PENDING', 'APPROVED'], true) ? $last_prev_user_status : 'PENDING';
            $pdo->prepare("UPDATE users SET linked_mother_id = NULL, status = ? WHERE id = ? AND role = 'mother'")->execute([
              $restoreStatus,
              $last_user_id
            ]);
          }
        }

        $pdo->commit();

        unset($_SESSION['last_booking']);
        $success = "Undo complete: the last booking has been removed.";
 	  } catch (Exception $e) {
 	    if ($pdo->inTransaction()) {
 	      $pdo->rollBack();
 	    }
        $msg = $e->getMessage();
        if (strpos($msg, 'Duplicate entry') !== false && strpos($msg, "for key 'phone'") !== false) {
          $msg = "This phone number already exists. If the mother already has an account, use the link option (select the mother) instead of creating a new one.";
        }
 	    $error = $msg;
 		  }
		}
  } else {
  $fullname = trim($_POST['fullname']);
  $phone    = trim($_POST['phone']);
  $dob      = $_POST['dob'];
    $hospital_number = trim((string)($_POST['hospital_number'] ?? ''));
  $gravida  = $_POST['gravida'] ?? null;
  $parity   = $_POST['parity'] ?? null;
  $lmp      = $_POST['lmp'] ?: null;
  $edd      = $_POST['edd'] ?: null;
    $address  = trim($_POST['address']);
    $high_risk = isset($_POST['high_risk']) ? 1 : 0;
    $risk_level = trim($_POST['risk_level'] ?? '');
    $risk_level_other = trim($_POST['risk_level_other'] ?? '');

    $height_cm = $supportsHeight ? trim((string)($_POST['height_cm'] ?? '')) : '';
    $booking_weight_kg = $supportsBookingWeight ? trim((string)($_POST['booking_weight_kg'] ?? '')) : '';
    $muac_cm = $supportsMuac ? trim((string)($_POST['muac_cm'] ?? '')) : '';
    $hiv_status = $supportsHiv ? trim((string)($_POST['hiv_status'] ?? '')) : '';
    $syphilis_status = $supportsSyphilis ? trim((string)($_POST['syphilis_status'] ?? '')) : '';
    $malaria_status = $supportsMalaria ? trim((string)($_POST['malaria_status'] ?? '')) : '';
    $blood_group = $supportsBlood ? trim((string)($_POST['blood_group'] ?? '')) : '';
    $rh_factor = $supportsRh ? trim((string)($_POST['rh_factor'] ?? '')) : '';
    $distance_km = $supportsDistance ? trim((string)($_POST['distance_km'] ?? '')) : '';
    $care_barriers = $supportsBarriers ? trim((string)($_POST['care_barriers'] ?? '')) : '';

  // This system is focused on pregnancy tracking; new mother bookings are always created as Pregnant.
  $pregnancy_status = 'Pregnant';
  $existing_user_id = (int)($_POST['existing_user_id'] ?? 0);
  $has_web_access = isset($_POST['has_web_access']) ? 1 : 0;
  $prev_user_status = null;
  $clinic_id_int = (int)($clinic_id ?? 0);
  if ($clinic_id_int <= 0) {
    $error = "Your nurse account is not assigned to a clinic.";
  }

    // Hospital number is auto-generated per clinic per year (e.g., 1/26).

    // Validate full name: letters (including accents), spaces, apostrophes, hyphens, dots, commas
    if (!$error && !$existing_user_id && !preg_match("/^[\\p{L}\\s'\\-\\.,]+$/u", $fullname)) {
        $error = "Full name must contain letters only (dots, commas, apostrophes, and hyphens allowed).";
    }

    // Validate Zimbabwe phone: 9 digits, starts with 7 (no leading 0)
    if (!$error && !$existing_user_id && !preg_match('/^7\\d{8}$/', $phone)) {
        $error = "Phone number must be 9 digits, start with 7, and exclude the leading 0 (e.g., 7XXXXXXXX).";
    }

  // Normalize to +263 format
  if (!$error && !$existing_user_id) {
    $phone = '+263' . $phone;
  }

  // If nurse typed a phone that already exists as a mother user, auto-link it.
  // This prevents DB duplicate phone errors for self-registered mothers.
  if (!$error && !$existing_user_id && $phone !== '') {
    $digits = preg_replace('/\D+/', '', $phone);
    if (strpos($digits, '263') === 0) {
      $digitsLocal = substr($digits, 3); // 7XXXXXXXX
    } else {
      $digitsLocal = ltrim($digits, '0'); // fallback
    }
    $phoneE164 = '+263' . $digitsLocal;
    $phoneNoPlus = '263' . $digitsLocal;
    $phoneLocal0 = '0' . $digitsLocal;
    $phoneLocal = $digitsLocal;

    $uByPhone = $pdo->prepare("
      SELECT id, role
      FROM users
      WHERE phone IN (?, ?, ?, ?, ?)
      LIMIT 1
    ");
    $uByPhone->execute([$phoneE164, $phoneNoPlus, $phone, $phoneLocal0, $phoneLocal]);
    $uRow = $uByPhone->fetch(PDO::FETCH_ASSOC);
    if ($uRow) {
      if (($uRow['role'] ?? '') !== 'mother') {
        $error = "This phone number is already used by a staff account.";
      } else {
        $existing_user_id = (int)$uRow['id'];
      }
    }
  }

  // Default password for mother (phone-based, can be changed later)
  $rawPassword = $phone;
  $passwordHash = password_hash($rawPassword, PASSWORD_DEFAULT);

  try {
    if ($error) {
      throw new Exception($error);
    }

    // pregnancy_status is fixed to Pregnant for new bookings.

    if ($lmp && !$edd) {
      $edd = date('Y-m-d', strtotime($lmp . ' +280 days'));
    } elseif ($edd && !$lmp) {
      $lmp = date('Y-m-d', strtotime($edd . ' -280 days'));
    }

    // Auto high-risk flags (Zimbabwe-style, without sensitive categories):
    // - Age extremes (<18 or >35)
    // - Grand multiparity (parity >= 5)
    // - MUAC < 23cm (if captured)
    // - Height < 152cm (if captured)
    $autoRiskReason = '';
    $age = null;
    $safeguardingFlag = 0;
    $safeguardingStatus = null;
    $safeguardingReason = null;

    if ((string)$dob === '') {
      throw new Exception("Date of Birth is required.");
    }

    $dobYmd = (string)$dob;
    $dobDt = DateTimeImmutable::createFromFormat('Y-m-d', $dobYmd);
    if (!$dobDt || $dobDt->format('Y-m-d') !== $dobYmd) {
      throw new Exception("Invalid Date of Birth. Please verify the year.");
    }
    $today = new DateTimeImmutable('today');
    if ($dobDt > $today) {
      throw new Exception("Invalid Date of Birth. Date cannot be in the future.");
    }
    $age = (int)$dobDt->diff($today)->y;

    // Safeguarding workflow:
    // - Hard block for biologically impossible ages (likely data entry errors).
    // - Allow save for vulnerable minors but flag + audit for mandatory follow-up.
    if ($age < 9) {
      throw new Exception("Invalid Date of Birth. Please verify the year.");
    }
    if ($age <= 12) {
      $safeguardingFlag = 1;
      $safeguardingStatus = 'URGENT';
      $safeguardingReason = "Safeguarding urgent (age {$age})";
      if ($pregnancy_status === 'Pregnant') {
        $high_risk = 1;
        if ($risk_level === '') {
          $risk_level = 'Safeguarding urgent (Age <=12)';
        }
      }
    } elseif ($age <= 17) {
      $safeguardingFlag = 1;
      $safeguardingStatus = 'MINOR';
      $safeguardingReason = "Minor pregnancy (13-17)";
      if ($pregnancy_status === 'Pregnant') {
        $high_risk = 1;
        if ($risk_level === '') {
          $risk_level = 'Minor pregnancy (13-17)';
        }
      }
    }

    if ($age !== null && ($age < 18 || $age > 35)) {
      $autoRiskReason = ($age < 18) ? 'Adolescent (<18)' : 'Advanced age (>35)';
    }
    if ($autoRiskReason === '' && is_numeric($parity) && (int)$parity >= 5) {
      $autoRiskReason = 'Grand multiparity (Parity 5+)';
    }
    if ($autoRiskReason === '' && $supportsMuac && $muac_cm !== '' && is_numeric($muac_cm) && (float)$muac_cm < 23) {
      $autoRiskReason = 'MUAC < 23cm';
    }
    if ($autoRiskReason === '' && $supportsHeight && $height_cm !== '' && is_numeric($height_cm) && (float)$height_cm < 152) {
      $autoRiskReason = 'Height < 152cm';
    }
    if ($autoRiskReason !== '' && $high_risk !== 1) {
      $high_risk = 1;
      if ($risk_level === '') {
        $risk_level = $autoRiskReason;
      }
    }

    // Additional risk: infectious diseases / Rh negative.
    // These fields are optional but if positive/abnormal they should trigger high-risk follow-up.
    $statusFlags = [];
    $hivNorm = $supportsHiv ? strtoupper(trim((string)$hiv_status)) : '';
    $syphNorm = $supportsSyphilis ? strtoupper(trim((string)$syphilis_status)) : '';
    $malNorm = $supportsMalaria ? strtoupper(trim((string)$malaria_status)) : '';
    $rhNorm = $supportsRh ? strtoupper(trim((string)$rh_factor)) : '';

    if ($hivNorm === 'POSITIVE') $statusFlags[] = 'HIV+';
    if ($syphNorm === 'POSITIVE') $statusFlags[] = 'Syphilis+';
    if ($malNorm === 'POSITIVE') $statusFlags[] = 'Malaria+';
    if ($rhNorm === 'NEG' || $rhNorm === 'NEGATIVE') $statusFlags[] = 'Rh-';

    if ($pregnancy_status === 'Pregnant' && !empty($statusFlags)) {
      $high_risk = 1;
      if ($risk_level === '') {
        // Pick a single display reason in a stable priority order.
        if (in_array('HIV+', $statusFlags, true)) $risk_level = 'HIV Positive';
        elseif (in_array('Syphilis+', $statusFlags, true)) $risk_level = 'Syphilis Positive';
        elseif (in_array('Malaria+', $statusFlags, true)) $risk_level = 'Malaria Positive';
        elseif (in_array('Rh-', $statusFlags, true)) $risk_level = 'Rh Negative';
      }
    }

    $pdo->beginTransaction();

    if ($existing_user_id) {
      $uStmt = $pdo->prepare("SELECT id, full_name, phone, status FROM users WHERE id = ? AND role = 'mother' LIMIT 1");
      $uStmt->execute([$existing_user_id]);
      $existing = $uStmt->fetch(PDO::FETCH_ASSOC);
      if (!$existing) {
        throw new Exception("Selected mother account not found.");
      }
      $prev_user_status = $existing['status'] ?? null;
      // Enforce clinic scoping: if already assigned to another clinic, block activation
      $cStmt = $pdo->prepare("SELECT clinic_id FROM users WHERE id = ? LIMIT 1");
      $cStmt->execute([$existing_user_id]);
      $cRow = $cStmt->fetch(PDO::FETCH_ASSOC);
      if (!empty($cRow['clinic_id']) && (int)$cRow['clinic_id'] !== (int)$clinic_id) {
        throw new Exception("This mother is assigned to another clinic and cannot be activated here.");
      }
      $fullname = $existing['full_name'];
      $phone = $existing['phone'];
      $has_web_access = 1;
    }

    // 1️⃣ Prevent duplicate mother
    // Auto-generate hospital number: sequence per clinic per year (e.g., 1/26).
    $yearFull = (int)date('Y');
    $yy = (int)date('y');
    $tbl = $pdo->query("SHOW TABLES LIKE 'hospital_number_sequences'")->fetch(PDO::FETCH_ASSOC);
    if (!$tbl) {
      throw new Exception("DB table hospital_number_sequences is missing. Run sql/hospital_number_sequences.sql.");
    }
    $seqStmt = $pdo->prepare("SELECT last_number FROM hospital_number_sequences WHERE clinic_id = ? AND year = ? FOR UPDATE");
    $seqStmt->execute([$clinic_id_int, $yearFull]);
    $seqRow = $seqStmt->fetch(PDO::FETCH_ASSOC);
    $nextNum = 1;
    if ($seqRow) {
      $nextNum = ((int)($seqRow['last_number'] ?? 0)) + 1;
      $updSeq = $pdo->prepare("UPDATE hospital_number_sequences SET last_number = ? WHERE clinic_id = ? AND year = ?");
      $updSeq->execute([$nextNum, $clinic_id_int, $yearFull]);
    } else {
      $insSeq = $pdo->prepare("INSERT INTO hospital_number_sequences (clinic_id, year, last_number) VALUES (?, ?, ?)");
      $insSeq->execute([$clinic_id_int, $yearFull, 1]);
      $nextNum = 1;
    }
    $hospital_number = $nextNum . '/' . str_pad((string)$yy, 2, '0', STR_PAD_LEFT);

        $check = $pdo->prepare("
            SELECT m.id
            FROM mothers m
            LEFT JOIN users u ON m.user_id = u.id
            WHERE m.phone = ?
               OR (m.hospital_number = ? AND u.clinic_id = ?)
            LIMIT 1
        ");
        $check->execute([$phone, $hospital_number, $clinic_id_int]);

    if ($check->fetch()) {
      throw new Exception("Mother already registered with this phone or hospital number.");
    }

    // 2️⃣ Create USER account for mother (if not self-registered)
    if ($existing_user_id) {
      $user_id = $existing_user_id;
    } else {
      $userStmt = $pdo->prepare("
              INSERT INTO users (full_name, phone, password_hash, role, status, email_verified, has_web_access, clinic_id, created_at)
              VALUES (?, ?, ?, 'mother', 'ACTIVE', ?, ?, ?, NOW())
          ");
      $email_verified = $has_web_access ? 1 : 0;
      $userStmt->execute([$fullname, $phone, $has_web_access ? $passwordHash : null, $email_verified, $has_web_access, $clinic_id]);
      $user_id = $pdo->lastInsertId();
    }

    // 3️⃣ Create MOTHER profile
    $address_enc = mch_crypto_encrypt($address);

    $cols = [
      'user_id','hospital_number','fullname','dob','phone','address',
      'gravida','parity','lmp','edd','pregnancy_status','high_risk','risk_level'
    ];
    $params = [
      $user_id,
      $hospital_number,
      $fullname,
      $dob,
      $phone,
      $address_enc,
      $gravida !== null && $gravida !== '' ? (int)$gravida : null,
      $parity !== null && $parity !== '' ? (int)$parity : null,
      $lmp,
      $edd,
      $pregnancy_status,
      $high_risk,
      $high_risk ? ($risk_level === 'Other' && $risk_level_other ? $risk_level_other : $risk_level) : null
    ];

    if ($supportsHeight) { $cols[] = 'height_cm'; $params[] = ($height_cm !== '' ? $height_cm : null); }
    if ($supportsBookingWeight) { $cols[] = 'booking_weight_kg'; $params[] = ($booking_weight_kg !== '' ? $booking_weight_kg : null); }
    if ($supportsMuac) { $cols[] = 'muac_cm'; $params[] = ($muac_cm !== '' ? $muac_cm : null); }
    if ($supportsHiv) { $cols[] = 'hiv_status'; $params[] = ($hiv_status !== '' ? $hiv_status : null); }
    if ($supportsSyphilis) { $cols[] = 'syphilis_status'; $params[] = ($syphilis_status !== '' ? $syphilis_status : null); }
    if ($supportsMalaria) { $cols[] = 'malaria_status'; $params[] = ($malaria_status !== '' ? $malaria_status : null); }
    if ($supportsBlood) { $cols[] = 'blood_group'; $params[] = ($blood_group !== '' ? $blood_group : null); }
    if ($supportsRh) { $cols[] = 'rh_factor'; $params[] = ($rh_factor !== '' ? $rh_factor : null); }
    if ($supportsDistance) { $cols[] = 'distance_km'; $params[] = ($distance_km !== '' ? $distance_km : null); }
    if ($supportsBarriers) { $cols[] = 'care_barriers'; $params[] = ($care_barriers !== '' ? $care_barriers : null); }

    if ($supportsSafeguardingFlag) { $cols[] = 'safeguarding_flag'; $params[] = (int)$safeguardingFlag; }
    if ($supportsSafeguardingStatus) { $cols[] = 'safeguarding_status'; $params[] = ($safeguardingStatus !== null ? $safeguardingStatus : null); }
    if ($supportsSafeguardingReason) { $cols[] = 'safeguarding_reason'; $params[] = ($safeguardingReason !== null ? $safeguardingReason : null); }
    // ack fields are intentionally left NULL on creation
    if ($supportsSafeguardingAckBy) { $cols[] = 'safeguarding_ack_by'; $params[] = null; }
    if ($supportsSafeguardingAckAt) { $cols[] = 'safeguarding_ack_at'; $params[] = null; }

    $colSql = implode(', ', $cols);
    $qSql = implode(', ', array_fill(0, count($cols), '?'));
    $motherStmt = $pdo->prepare("INSERT INTO mothers ({$colSql}, created_at) VALUES ({$qSql}, NOW())");
    $motherStmt->execute($params);

    $mother_id = $pdo->lastInsertId();
    $upd = $pdo->prepare("UPDATE users SET linked_mother_id = ?, status = 'ACTIVE', clinic_id = IFNULL(clinic_id, ?) WHERE id = ?");
    $upd->execute([$mother_id, $clinic_id, $user_id]);

    // Auto-create follow-up tasks for positive/abnormal statuses (no SMS cost).
    if ($tasksEnabled && $pregnancy_status === 'Pregnant' && !empty($statusFlags)) {
      $nurseUserId = (int)($_SESSION['user_id'] ?? 0);
      $dueEdd = ($edd ? DateTimeImmutable::createFromFormat('Y-m-d', (string)$edd) : null);

      $insTask = $pdo->prepare("
        INSERT INTO follow_up_tasks
          (clinic_id, task_type, subject_type, subject_id, due_date, priority, status, assigned_to, created_by, note, created_at)
        VALUES
          (?, ?, 'mother', ?, ?, ?, 'OPEN', ?, ?, ?, NOW())
      ");

      $mkDue = function (int $daysFromNow, ?DateTimeImmutable $fallbackEdd = null): ?string {
        $today = new DateTimeImmutable('today');
        if ($fallbackEdd instanceof DateTimeImmutable) {
          return $fallbackEdd->format('Y-m-d');
        }
        return $today->modify('+' . $daysFromNow . ' days')->format('Y-m-d');
      };

      foreach ($statusFlags as $flag) {
        $taskType = '';
        $priority = 'MEDIUM';
        $note = '';
        $due = null;

        if ($flag === 'HIV+') {
          $taskType = 'HIV_FOLLOWUP';
          $priority = 'HIGH';
          $note = 'PMTCT/ART follow-up: confirm ART, counselling, and plan infant prophylaxis.';
          $due = $mkDue(7);
        } elseif ($flag === 'Syphilis+') {
          $taskType = 'SYPHILIS_FOLLOWUP';
          $priority = 'HIGH';
          $note = 'Syphilis follow-up: ensure treatment recorded and partner notification.';
          $due = $mkDue(7);
        } elseif ($flag === 'Malaria+') {
          $taskType = 'MALARIA_FOLLOWUP';
          $priority = 'HIGH';
          $note = 'Malaria follow-up: confirm treatment and review danger signs.';
          $due = $mkDue(3);
        } elseif ($flag === 'Rh-') {
          $taskType = 'RH_FOLLOWUP';
          $priority = 'MEDIUM';
          $note = 'Rh negative: plan prophylaxis/tracking per facility protocol.';
          // If EDD provided, use it as a planning anchor; otherwise due in 14 days.
          $due = $mkDue(14, $dueEdd);
        }

        if ($taskType !== '') {
          $insTask->execute([
            $clinic_id_int,
            $taskType,
            (int)$mother_id,
            $due,
            $priority,
            $nurseUserId > 0 ? $nurseUserId : null,
            $nurseUserId > 0 ? $nurseUserId : null,
            $note,
          ]);
          mch_audit_log($pdo, 'TASK_CREATE', 'follow_up_tasks', null, "Auto-created {$taskType} for mother_id {$mother_id}");
        }
      }
    }

    // Auto-create ANC visit schedule for Pregnant mothers (1..8)
    if ($pregnancy_status === 'Pregnant') {
      $checkVisits = $pdo->prepare("SELECT COUNT(*) FROM anc_visits WHERE mother_id = ?");
      $checkVisits->execute([$mother_id]);
      $visitCount = (int)$checkVisits->fetchColumn();

      if ($visitCount === 0) {
        $startDate = $lmp ?: date('Y-m-d');
        $supportsClinical = db_has_column($pdo, 'anc_visits', 'clinical_status');
        $visitStmt = $pdo->prepare($supportsClinical ? "
          INSERT INTO anc_visits
          (mother_id, visit_number, visit_date, notes, status, clinical_status, next_visit_date, created_at)
          VALUES (?, ?, ?, ?, ?, 0, ?, NOW())
        " : "
          INSERT INTO anc_visits
          (mother_id, visit_number, visit_date, notes, status, next_visit_date, created_at)
          VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");

        for ($i = 1; $i <= 8; $i++) {
          $visitDate = date('Y-m-d', strtotime($startDate . ' +' . (($i - 1) * 28) . ' days'));
          $nextDate = date('Y-m-d', strtotime($visitDate . ' +28 days'));
          $visitStmt->execute([
            $mother_id,
            $i,
            $visitDate,
            'Scheduled automatically',
            'PENDING',
            $nextDate
          ]);
        }
      }
    }

    $pdo->commit();

    if ($safeguardingFlag === 1) {
      mch_audit_log(
        $pdo,
        'SAFEGUARDING_FLAG',
        'mothers',
        (int)$mother_id,
        "Safeguarding flag triggered: " . (string)($safeguardingStatus ?? 'FLAGGED') . " (age {$age})."
      );
    }

    if (!empty($statusFlags)) {
      mch_audit_log(
        $pdo,
        'RISK_FLAG',
        'mothers',
        (int)$mother_id,
        "Auto risk review: " . implode(', ', $statusFlags)
      );
    }

    $created_mother_id = (int)$mother_id;
    $_SESSION['last_booking'] = [
      'mother_id' => (int)$mother_id,
      'user_id' => (int)$user_id,
      'user_was_new' => $existing_user_id ? 0 : 1,
      'prev_user_status' => $prev_user_status,
      'at' => time(),
    ];
    if ($has_web_access || $existing_user_id) {
      $success = "Mother registered successfully. Default login password is her phone number.";
    } else {
      $success = "Mother registered successfully. This account is clinic-managed (no web access).";
    }
	  } catch (Exception $e) {
	    if ($pdo->inTransaction()) {
	      $pdo->rollBack();
	    }
	    $error = $e->getMessage();
		  }
		}

  $_SESSION['flash_register_mother'] = [
    'error' => $error,
    'success' => $success,
    'created_mother_id' => $created_mother_id,
  ];

  $redirectUrl = '/mch_system/nurse_portal/register_mother.php';
  $redirectExistingUserId = (int)($_POST['existing_user_id'] ?? 0);
  if ($returnTo === 'activation_queue') {
    $_SESSION['flash_activation_queue'] = [
      'error' => $error,
      'success' => $success !== '' ? $success : ($error === '' ? 'Saved.' : ''),
    ];
    $redirectUrl = '/mch_system/nurse_portal/activation_queue.php';
  } else {
    if ($redirectExistingUserId > 0) {
      $redirectUrl .= '?existing_user_id=' . $redirectExistingUserId;
    }
  }

  header("Location: {$redirectUrl}", true, 303);
  exit;
}

// Fetch lists AFTER booking so the "Existing Records" table reflects the new record immediately.
$pendingUsers = $pdo->query("
    SELECT id, full_name, phone, email
    FROM users
    WHERE role = 'mother'
      AND status IN ('PENDING','APPROVED')
      AND has_web_access = 1
      AND (email_verified = 1 OR id = " . (int)$preselect_user_id . ")
    ORDER BY full_name
")->fetchAll(PDO::FETCH_ASSOC);

// Next hospital number (display hint only; actual assignment happens on submit in a transaction).
$nextHospitalNumberHint = '';
try {
  $clinic_id_int = (int)($_SESSION['clinic_id'] ?? 0);
  if ($clinic_id_int > 0) {
    $tbl = $pdo->query("SHOW TABLES LIKE 'hospital_number_sequences'")->fetch(PDO::FETCH_ASSOC);
    if ($tbl) {
      $yearFull = (int)date('Y');
      $yy = (int)date('y');
      $stmt = $pdo->prepare("SELECT last_number FROM hospital_number_sequences WHERE clinic_id = ? AND year = ? LIMIT 1");
      $stmt->execute([$clinic_id_int, $yearFull]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);
      $next = ((int)($row['last_number'] ?? 0)) + 1;
      $nextHospitalNumberHint = $next . '/' . str_pad((string)$yy, 2, '0', STR_PAD_LEFT);
    }
  }
} catch (Exception $e) {
  $nextHospitalNumberHint = '';
}

$lastBooking = $_SESSION['last_booking'] ?? null;
$lastBookingMotherId = (int)($lastBooking['mother_id'] ?? 0);

$perList = max(10, min(100, (int)($_GET['per_list'] ?? 25)));
$pageList = max(1, (int)($_GET['page_list'] ?? 1));
$offsetList = ($pageList - 1) * $perList;

$totalMothers = (int)$pdo->query("SELECT COUNT(*) FROM mothers")->fetchColumn();
$totalPagesList = max(1, (int)ceil($totalMothers / $perList));

$mothersStmt = $pdo->prepare("
    SELECT m.id, m.fullname, m.hospital_number, m.pregnancy_status, m.edd,
           c.clinic_name
    FROM mothers m
    LEFT JOIN users u ON m.user_id = u.id
    LEFT JOIN clinics c ON u.clinic_id = c.id
    ORDER BY m.fullname
    LIMIT ? OFFSET ?
");
$mothersStmt->bindValue(1, $perList, PDO::PARAM_INT);
$mothersStmt->bindValue(2, $offsetList, PDO::PARAM_INT);
$mothersStmt->execute();
$allMothers = $mothersStmt->fetchAll(PDO::FETCH_ASSOC);

$babiesStmt = $pdo->prepare("
    SELECT b.id, b.baby_name, b.dob, b.gender, b.birth_weight, m.fullname AS mother_name
    FROM babies b
    JOIN mothers m ON b.mother_id = m.id
    ORDER BY b.dob DESC
    LIMIT ?
");
$babiesStmt->bindValue(1, $perList, PDO::PARAM_INT);
$babiesStmt->execute();
$allBabies = $babiesStmt->fetchAll(PDO::FETCH_ASSOC);

$page = 'register_mother';
include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_nurse.php";
?>

<style>
  .float-group { position: relative; }
  .float-field { width: 100%; padding: 0.75rem; border: 1px solid #cbd5e1; border-radius: 0.5rem; background: white; }
  .float-label {
    position: absolute;
    left: 0.75rem;
    top: 0.75rem;
    color: #059669;
    background: white;
    padding: 0 0.25rem;
    transition: all 0.15s ease;
    pointer-events: none;
  }
  .float-field:focus + .float-label,
  .float-field[data-has-value="true"] + .float-label,
  .float-group:hover .float-label {
    top: -0.5rem;
    font-size: 0.75rem;
    color: #059669;
  }
  .phone-label { left: 3.25rem; }

  /* Dark mode: keep floating labels readable and prevent border line overlap. */
  html.dark .float-label {
    background: rgba(15, 23, 42, 0.88);
    color: #a7f3d0;
  }
  html.dark .float-field:focus + .float-label,
  html.dark .float-field[data-has-value="true"] + .float-label,
  html.dark .float-group:hover .float-label {
    color: #a7f3d0;
  }
  .toggle-records-btn {
    transition: background-color 0.2s ease, border-color 0.2s ease, color 0.2s ease;
  }
  .toggle-records-btn.viewing {
    background-color: #f97316;
    border-color: #f97316;
    color: #fff;
  }
  .toggle-records-btn.viewing span[data-icon] {
    filter: drop-shadow(0 0 3px rgba(255, 255, 255, 0.9));
  }
</style>

<div class="ml-64 p-6">
  <div class="mch-card bg-white/90 p-6 border border-slate-100 max-w-5xl mb-6">
    <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-3">
      <div>
        <h2 class="text-xl font-semibold text-slate-900">Existing Records (Avoid Duplicates)</h2>
        <p class="text-xs text-slate-500 mt-2">Tap “View Mothers” to reveal the register and use the filters.</p>
      </div>
      <button type="button" id="toggle_existing_records" class="flex items-center gap-2 px-4 py-2 rounded-lg border border-slate-200 bg-white text-sm font-semibold text-slate-700 hover:bg-slate-50 toggle-records-btn" aria-expanded="false">
        <span class="text-xl leading-none" aria-hidden="true" data-icon>👁️</span>
        <span data-label>View Mothers</span>
      </button>
    </div>

    <div id="existing_records_section" class="mch-card bg-white/90 p-6 border border-slate-100 max-w-5xl mt-6 hidden">
      <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
        <input id="search_existing" type="text" placeholder="Search name, hospital #, baby"
               class="border border-slate-200 p-2 rounded-lg w-full bg-white">
        <select id="filter_existing" class="border border-slate-200 p-2 rounded-lg bg-white">
          <option value="all" selected>All</option>
          <option value="pregnant">Pregnant</option>
          <option value="postnatal">Postnatal</option>
          <option value="baby">Baby</option>
        </select>
        <button type="button" id="clear_existing" class="mch-btn px-4 py-2">Clear</button>
      </div>

      <div id="mothers_table_wrap" class="overflow-x-auto">
        <table class="w-full text-sm mch-table" id="mothers_table">
          <thead>
            <tr>
              <th class="p-2 text-left">Mother</th>
              <th class="p-2 text-left">Hospital #</th>
              <th class="p-2 text-left">Status</th>
              <th class="p-2 text-left">EDD</th>
              <th class="p-2 text-left">Clinic</th>
              <th class="p-2 text-left">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($allMothers as $m): ?>
              <tr class="border-t border-slate-200"
                  data-name="<?= htmlspecialchars(strtolower($m['fullname'])) ?>"
                  data-hospital="<?= htmlspecialchars(strtolower($m['hospital_number'])) ?>"
                  data-status="<?= htmlspecialchars(strtolower($m['pregnancy_status'])) ?>"
                  data-edd="<?= htmlspecialchars($m['edd'] ?? '') ?>"
                  data-clinic="<?= htmlspecialchars($m['clinic_name'] ?? 'Unassigned') ?>">
                <td class="p-2"><?= htmlspecialchars($m['fullname']) ?></td>
                <td class="p-2"><?= htmlspecialchars($m['hospital_number']) ?></td>
                <td class="p-2"><?= htmlspecialchars($m['pregnancy_status']) ?></td>
                <td class="p-2"><?= htmlspecialchars($m['edd'] ?? '-') ?></td>
                <td class="p-2"><?= htmlspecialchars($m['clinic_name'] ?? 'Unassigned') ?></td>
                <td class="p-2">
                  <div class="flex flex-wrap gap-2">
                    <button type="button" class="px-3 py-1 rounded-lg bg-slate-50 border border-slate-200 hover:bg-slate-100 text-sm"
                            data-care-btn
                            data-id="<?= (int)$m['id'] ?>"
                            data-fullname="<?= htmlspecialchars($m['fullname']) ?>"
                            data-status="<?= htmlspecialchars($m['pregnancy_status']) ?>"
                            data-edd="<?= htmlspecialchars($m['edd'] ?? '-') ?>"
                            data-clinic="<?= htmlspecialchars($m['clinic_name'] ?? 'Unassigned') ?>">
                      View Care Card
                    </button>
                    <?php if ((int)$m['id'] === (int)$created_mother_id || (int)$m['id'] === (int)$lastBookingMotherId): ?>
                      <form method="POST" class="inline" onsubmit="return confirm('Undo this booking? This will remove the mother record and the auto ANC schedule if nothing has been logged yet.');">
                        <?= mch_csrf_input('nurse_register_mother') ?>
                        <input type="hidden" name="action" value="undo_booking">
                        <input type="hidden" name="mother_id" value="<?= (int)$m['id'] ?>">
                        <button type="submit" class="px-3 py-1 rounded-lg bg-rose-50 border border-rose-200 hover:bg-rose-100 text-sm text-rose-700">
                          Undo
                        </button>
                      </form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div id="babies_table_wrap" class="overflow-x-auto mt-6 hidden">
        <table class="w-full text-sm mch-table" id="babies_table">
          <thead>
            <tr>
              <th class="p-2 text-left">Baby</th>
              <th class="p-2 text-left">Mother</th>
              <th class="p-2 text-left">DOB</th>
              <th class="p-2 text-left">Gender</th>
              <th class="p-2 text-left">Birth Weight</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($allBabies as $b): ?>
              <tr class="border-t border-slate-200"
                  data-name="<?= htmlspecialchars(strtolower($b['baby_name'])) ?>"
                  data-mother="<?= htmlspecialchars(strtolower($b['mother_name'])) ?>">
                <td class="p-2"><?= htmlspecialchars($b['baby_name']) ?></td>
                <td class="p-2"><?= htmlspecialchars($b['mother_name']) ?></td>
                <td class="p-2"><?= htmlspecialchars($b['dob']) ?></td>
                <td class="p-2"><?= htmlspecialchars($b['gender']) ?></td>
                <td class="p-2"><?= htmlspecialchars($b['birth_weight'] ?? '-') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <p class="text-xs text-slate-500 mt-3">Use the search/filter controls to limit the visible records.</p>
    </div>

    <div id="care_card" class="mt-6 hidden">
      <div class="mch-card bg-white/90 p-5 border border-slate-100">
        <div class="text-sm text-slate-500 mb-1">Mother Care Card</div>
        <div class="text-lg font-semibold text-slate-900" id="care_name">-</div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 text-sm text-slate-700 mt-3">
          <div><span class="font-semibold">Status:</span> <span id="care_status">-</span></div>
          <div><span class="font-semibold">EDD:</span> <span id="care_edd">-</span></div>
          <div><span class="font-semibold">Clinic:</span> <span id="care_clinic">-</span></div>
        </div>
        <div class="mt-4 flex gap-3">
          <a id="care_history_btn" class="mch-btn px-4 py-2" href="#">Clinical History</a>
          <button type="button" id="care_close_btn" class="px-4 py-2 rounded-lg bg-slate-50 border border-slate-200 hover:bg-slate-100">
            Close
          </button>
        </div>
      </div>
    </div>
  </div>

  <div class="mch-card bg-white/90 p-6 border border-slate-100 max-w-4xl">
    <h2 class="text-2xl font-semibold text-slate-900 mb-4">Book Pregnancy</h2>

    <?php if ($error): ?>
      <div class="bg-rose-100 text-rose-700 p-3 rounded-lg mb-4"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
      <div class="bg-emerald-100 text-emerald-700 p-3 rounded-lg mb-4">
        <?= htmlspecialchars($success) ?>
        <?php if ($created_mother_id): ?>
          <div class="mt-2">
            <a class="text-teal-700 font-semibold hover:underline"
               href="/mch_system/nurse_portal/print_mother_card.php?mother_id=<?= (int)$created_mother_id ?>">
              Print ANC Card
            </a>
          </div>
          <div class="mt-2">
            <form method="POST" class="inline" onsubmit="return confirm('Undo this booking? This will remove the mother record and the auto ANC schedule if nothing has been logged yet.');">
              <?= mch_csrf_input('nurse_register_mother') ?>
              <input type="hidden" name="action" value="undo_booking">
              <input type="hidden" name="mother_id" value="<?= (int)$created_mother_id ?>">
              <button type="submit" class="px-3 py-1 rounded-lg bg-rose-50 border border-rose-200 hover:bg-rose-100 text-sm text-rose-700">
                Undo This Booking
              </button>
            </form>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <form method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-4" id="book_pregnancy_form">
      <?= mch_csrf_input('nurse_register_mother') ?>
      <?php if ($returnTo !== ''): ?>
        <input type="hidden" name="return" value="<?= htmlspecialchars($returnTo) ?>">
      <?php endif; ?>

      <?php if ($returnTo === 'activation_queue' && $preselect_user_id > 0): ?>
        <div class="md:col-span-2 mch-card bg-sky-50 border border-sky-200 text-sky-800 p-4">
          You are linking a self-registered mother. Fill the form and submit to activate; you will be returned to the Activation Queue.
        </div>
      <?php endif; ?>
      <div class="md:col-span-2">
        <label class="block text-sm font-semibold mb-1 text-slate-600">Link Self-Registered Mother (Optional)</label>
        <select name="existing_user_id" id="existing_user_id" class="w-full border border-slate-200 p-2 rounded-lg bg-white">
          <option value="">Select a pending mother (email verified)</option>
          <?php foreach ($pendingUsers as $pu): ?>
            <option value="<?= $pu['id'] ?>" data-fullname="<?= htmlspecialchars($pu['full_name']) ?>" data-phone="<?= htmlspecialchars($pu['phone']) ?>"
              <?= $preselect_user_id === (int)$pu['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($pu['full_name']) ?> — <?= htmlspecialchars($pu['phone']) ?><?= $pu['email'] ? ' • ' . htmlspecialchars($pu['email']) : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="md:col-span-2 flex items-center gap-2">
        <input type="checkbox" name="has_web_access" id="has_web_access" value="1" class="h-4 w-4 rounded border-slate-300">
        <label for="has_web_access" class="text-sm text-slate-600">Mother has smartphone/data (enable web access)</label>
      </div>
      <div class="float-group">
        <input type="text" name="hospital_number" class="float-field field bg-slate-50" id="hospital_number" placeholder=" "
               value="<?= htmlspecialchars($nextHospitalNumberHint !== '' ? $nextHospitalNumberHint : 'Auto') ?>" readonly tabindex="-1">
        <label class="float-label" for="hospital_number">Hospital Number</label>
        <div class="text-xs text-slate-500 mt-1">Assigned automatically per clinic per year (e.g., 1/26).</div>
      </div>
      <div class="float-group">
        <input type="text" name="fullname" required class="float-field field" id="fullname" placeholder=" ">
        <label class="float-label" for="fullname">Full Name</label>
      </div>
      <div class="float-group">
        <input type="date" name="dob" required class="float-field field" id="dob" placeholder=" ">
        <label class="float-label" for="dob">Date of Birth</label>
      </div>
      <div class="float-group">
        <div class="flex">
          <span class="inline-flex items-center px-3 border border-r-0 rounded-l text-slate-700 bg-slate-100">+263</span>
        <input type="text" name="phone" pattern="7[0-9]{8}" minlength="9" maxlength="9" inputmode="numeric" title="Enter 9 digits starting with 7 (after +263)" required class="float-field field rounded-l-none rounded-r w-full" id="phone_input" autocomplete="tel" placeholder=" ">
          <label class="float-label phone-label" for="phone_input">Phone</label>
        </div>
      </div>
      <div class="float-group">
        <input type="text" name="address" class="float-field field" id="address" placeholder=" ">
        <label class="float-label" for="address">Address</label>
      </div>
      <div class="float-group">
        <input type="text" name="age_display" readonly class="float-field field bg-slate-50" id="age_display" placeholder=" ">
        <label class="float-label" for="age_display">Age</label>
      </div>

      <input type="hidden" name="pregnancy_status" value="Pregnant">
      <div class="float-group">
        <input type="number" name="gravida" min="1" required class="float-field field" id="gravida" placeholder=" ">
        <label class="float-label" for="gravida">Gravida</label>
      </div>
      <div class="float-group">
        <input type="number" name="parity" min="0" required class="float-field field" id="parity" placeholder=" ">
        <label class="float-label" for="parity">Parity</label>
      </div>

      <div class="float-group md:col-span-1">
        <input type="date" name="lmp" class="float-field field" id="lmp" placeholder=" ">
        <label class="float-label" for="lmp">LMP (Last Menstrual Period)</label>
        <p class="text-xs text-slate-500 mt-1">Used to calculate the estimated due date (EDD).</p>
      </div>
      <div class="float-group md:col-span-1">
        <input type="date" name="edd" class="float-field field" id="edd" placeholder=" ">
        <label class="float-label" for="edd">EDD (Estimated Due Date)</label>
        <p class="text-xs text-slate-500 mt-1">If you enter EDD, LMP will be calculated automatically.</p>
      </div>

      <div class="md:col-span-2" id="high_risk_wrap">
        <label class="flex items-center gap-2">
          <input type="checkbox" name="high_risk" value="1" id="high_risk">
          High Risk Pregnancy
        </label>
        <p class="text-xs text-slate-500 mt-1">
          Mark if this pregnancy needs closer monitoring (e.g., hypertension, diabetes, prior complications).
        </p>
        <div class="mt-2 grid grid-cols-1 md:grid-cols-2 gap-2" id="risk_reason_wrap">
          <div class="float-group">
            <select name="risk_level" class="float-field field" id="risk_level">
              <option value="" disabled selected></option>
              <option value="Hypertension">Hypertension</option>
              <option value="Diabetes">Diabetes</option>
              <option value="Previous complications">Previous complications</option>
              <option value="Twin pregnancy">Twin pregnancy</option>
              <option value="Anemia">Anemia</option>
              <option value="Other">Other</option>
            </select>
            <label class="float-label" for="risk_level">Risk Reason</label>
          </div>
          <div class="float-group">
            <input type="text" name="risk_level_other" class="float-field field" id="risk_level_other" placeholder=" ">
            <label class="float-label" for="risk_level_other">If Other, Specify</label>
          </div>
        </div>
      </div>

      <div class="md:col-span-2 rounded-xl border border-slate-200 bg-slate-50 p-4" id="extra_risk_wrap">
        <div class="flex items-start justify-between gap-3">
          <div>
            <div class="text-sm font-semibold text-slate-900">Additional Risk Factors (Optional)</div>
            <div class="text-xs text-slate-500">Extra screening fields for better follow-up and reporting.</div>
          </div>
          <button type="button"
                  class="<?= $supportsExtraRisk ? '' : 'hidden' ?> text-xs font-semibold text-slate-700 hover:text-slate-900 underline"
                  id="extra_risk_toggle"
                  aria-expanded="false"
                  aria-controls="extra_risk_panel">
            Show
          </button>
          <?php if (!$supportsExtraRisk): ?>
            <div class="text-xs text-amber-700">
              Run <span class="font-mono">sql/mothers_additional_risk_factors_migrate.sql</span>
            </div>
          <?php endif; ?>
        </div>

        <?php if ($supportsExtraRisk): ?>
          <div class="mt-3 grid grid-cols-1 md:grid-cols-3 gap-3 hidden" id="extra_risk_panel">
            <?php if ($supportsHeight): ?>
              <div>
                <label class="block text-xs font-semibold text-slate-600 mb-1">Height (cm)</label>
                <input type="number" step="0.1" min="80" max="220" name="height_cm" class="w-full border border-slate-200 p-2 rounded-lg bg-white" placeholder="e.g. 160">
              </div>
            <?php endif; ?>
            <?php if ($supportsBookingWeight): ?>
              <div>
                <label class="block text-xs font-semibold text-slate-600 mb-1">Booking Weight (kg)</label>
                <input type="number" step="0.1" min="20" max="200" name="booking_weight_kg" class="w-full border border-slate-200 p-2 rounded-lg bg-white" placeholder="e.g. 55">
              </div>
            <?php endif; ?>
            <?php if ($supportsMuac): ?>
              <div>
                <label class="block text-xs font-semibold text-slate-600 mb-1">MUAC (cm)</label>
                <input type="number" step="0.1" min="10" max="60" name="muac_cm" class="w-full border border-slate-200 p-2 rounded-lg bg-white" placeholder="e.g. 24">
              </div>
            <?php endif; ?>

            <?php if ($supportsHiv): ?>
              <div>
                <label class="block text-xs font-semibold text-slate-600 mb-1">HIV Status</label>
                <select name="hiv_status" class="w-full border border-slate-200 p-2 rounded-lg bg-white">
                  <option value="">Unknown</option>
                  <option value="NEGATIVE">Negative</option>
                  <option value="POSITIVE">Positive</option>
                </select>
              </div>
            <?php endif; ?>
            <?php if ($supportsSyphilis): ?>
              <div>
                <label class="block text-xs font-semibold text-slate-600 mb-1">Syphilis Status</label>
                <select name="syphilis_status" class="w-full border border-slate-200 p-2 rounded-lg bg-white">
                  <option value="">Unknown</option>
                  <option value="NEGATIVE">Negative</option>
                  <option value="POSITIVE">Positive</option>
                </select>
              </div>
            <?php endif; ?>
            <?php if ($supportsMalaria): ?>
              <div>
                <label class="block text-xs font-semibold text-slate-600 mb-1">Malaria Status</label>
                <select name="malaria_status" class="w-full border border-slate-200 p-2 rounded-lg bg-white">
                  <option value="">Unknown</option>
                  <option value="NEGATIVE">Negative</option>
                  <option value="POSITIVE">Positive</option>
                </select>
              </div>
            <?php endif; ?>

            <?php if ($supportsBlood): ?>
              <div>
                <label class="block text-xs font-semibold text-slate-600 mb-1">Blood Group</label>
                <select name="blood_group" class="w-full border border-slate-200 p-2 rounded-lg bg-white">
                  <option value="">Unknown</option>
                  <option value="A">A</option>
                  <option value="B">B</option>
                  <option value="AB">AB</option>
                  <option value="O">O</option>
                </select>
              </div>
            <?php endif; ?>
            <?php if ($supportsRh): ?>
              <div>
                <label class="block text-xs font-semibold text-slate-600 mb-1">Rh Factor</label>
                <select name="rh_factor" class="w-full border border-slate-200 p-2 rounded-lg bg-white">
                  <option value="">Unknown</option>
                  <option value="POS">Positive</option>
                  <option value="NEG">Negative</option>
                </select>
              </div>
            <?php endif; ?>
            <?php if ($supportsDistance): ?>
              <div>
                <label class="block text-xs font-semibold text-slate-600 mb-1">Distance to Facility (km)</label>
                <input type="number" step="0.1" min="0" max="500" name="distance_km" class="w-full border border-slate-200 p-2 rounded-lg bg-white" placeholder="e.g. 12">
              </div>
            <?php endif; ?>
            <?php if ($supportsBarriers): ?>
              <div class="md:col-span-3">
                <label class="block text-xs font-semibold text-slate-600 mb-1">Care Barriers / Preferences</label>
                <input type="text" name="care_barriers" class="w-full border border-slate-200 p-2 rounded-lg bg-white" placeholder="e.g. transport challenges; needs referral">
              </div>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="mt-3 text-sm text-slate-600">Not enabled on this database yet.</div>
        <?php endif; ?>
      </div>

      <div class="md:col-span-2">
        <button type="submit" class="mch-btn w-full py-2">
          Book Pregnancy
        </button>
      </div>
      <div class="md:col-span-2">
        <button type="button" id="reset_booking_form" class="w-full py-2 rounded-lg bg-slate-50 border border-slate-200 hover:bg-slate-100">
          Undo / Reset Form
        </button>
      </div>
    </form>

    <div id="mother_draft_hint" class="hidden mt-4 text-xs rounded-lg border border-sky-200 bg-sky-50 text-sky-800 px-3 py-2 md:col-span-2">
      Draft restored. <button type="button" id="mother_draft_clear" class="underline font-semibold">Clear draft</button>
    </div>
  </div>
</div>

<?php include __DIR__ . "/../includes/footer.php"; ?>

<script>
  (function () {
    const form = document.getElementById('book_pregnancy_form');
    if (!form) return;
    const key = 'mch_draft_register_mother_v1';
    const hint = document.getElementById('mother_draft_hint');
    const clearBtn = document.getElementById('mother_draft_clear');

    const fields = Array.from(form.querySelectorAll('input, select, textarea'))
      .filter(el => el.name && el.type !== 'hidden' && el.type !== 'password' && el.type !== 'submit' && el.type !== 'button' && !el.readOnly);

    const save = () => {
      const data = {};
      fields.forEach(el => {
        if (el.type === 'checkbox') data[el.name] = el.checked ? '1' : '0';
        else data[el.name] = el.value ?? '';
      });
      try { localStorage.setItem(key, JSON.stringify({ at: Date.now(), data })); } catch (e) {}
    };

    const restore = () => {
      let raw = null;
      try { raw = localStorage.getItem(key); } catch (e) { raw = null; }
      if (!raw) return;
      try {
        const parsed = JSON.parse(raw);
        const data = parsed && parsed.data ? parsed.data : null;
        if (!data) return;
        fields.forEach(el => {
          if (!(el.name in data)) return;
          if (el.type === 'checkbox') el.checked = data[el.name] === '1';
          else el.value = data[el.name];
        });
        hint?.classList.remove('hidden');
      } catch (e) {}
    };

    const clear = () => {
      try { localStorage.removeItem(key); } catch (e) {}
      hint?.classList.add('hidden');
    };

    fields.forEach(el => el.addEventListener('input', save));
    fields.forEach(el => el.addEventListener('change', save));
    form.addEventListener('submit', () => clear());
    clearBtn?.addEventListener('click', clear);

    restore();
  })();
</script>

<script>
  const lmpInput = document.getElementById('lmp');
  const eddInput = document.getElementById('edd');
  const dobInput = document.getElementById('dob');
  const ageDisplay = document.getElementById('age_display');
  const phoneInput = document.getElementById('phone_input');
  const hospitalNumberInput = document.getElementById('hospital_number');
  const fullnameInput = document.getElementById('fullname');
  const existingUserSelect = document.getElementById('existing_user_id');
  const hasWebAccess = document.getElementById('has_web_access');
  const floatFields = document.querySelectorAll('.field');
  const careCard = document.getElementById('care_card');
  const careName = document.getElementById('care_name');
  const careStatus = document.getElementById('care_status');
  const careEdd = document.getElementById('care_edd');
  const careClinic = document.getElementById('care_clinic');
  const careHistoryBtn = document.getElementById('care_history_btn');
  const careCloseBtn = document.getElementById('care_close_btn');
  const resetBookingFormBtn = document.getElementById('reset_booking_form');
  const bookForm = document.getElementById('book_pregnancy_form');
  const searchExisting = document.getElementById('search_existing');
  const filterExisting = document.getElementById('filter_existing');
  const clearExisting = document.getElementById('clear_existing');
  const mothersTable = document.getElementById('mothers_table');
  const babiesTable = document.getElementById('babies_table');
  const mothersWrap = document.getElementById('mothers_table_wrap');
  const babiesWrap = document.getElementById('babies_table_wrap');
  const toggleExistingRecordsBtn = document.getElementById('toggle_existing_records');
  const existingRecordsSection = document.getElementById('existing_records_section');

  function updateFloatField(field) {
    const hasValue = field.value && field.value.toString().trim() !== '';
    field.dataset.hasValue = hasValue ? 'true' : 'false';
  }

  floatFields.forEach((field) => {
    updateFloatField(field);
    field.addEventListener('input', () => updateFloatField(field));
    field.addEventListener('change', () => updateFloatField(field));
    field.addEventListener('blur', () => updateFloatField(field));
  });

  function addDays(dateStr, days) {
    const d = new Date(dateStr);
    if (Number.isNaN(d.getTime())) return '';
    d.setDate(d.getDate() + days);
    return d.toISOString().slice(0, 10);
  }

  lmpInput?.addEventListener('change', () => {
    if (lmpInput.value) {
      eddInput.value = addDays(lmpInput.value, 280);
      updateFloatField(eddInput);
    }
    updateFloatField(lmpInput);
  });

  eddInput?.addEventListener('change', () => {
    if (eddInput.value) {
      lmpInput.value = addDays(eddInput.value, -280);
      updateFloatField(lmpInput);
    }
    updateFloatField(eddInput);
  });

  const gravidaInput = document.getElementById('gravida');
  const parityInput = document.getElementById('parity');
  const highRiskWrap = document.getElementById('high_risk_wrap');
  const highRiskInput = document.getElementById('high_risk');
  const riskLevelInput = document.getElementById('risk_level');
  const riskLevelOtherInput = document.getElementById('risk_level_other');
  const riskReasonWrap = document.getElementById('risk_reason_wrap');
  // Pregnancy fields are always enabled for new bookings (status is fixed to Pregnant).

  // Optional: collapse Additional Risk Factors until needed.
  (function () {
    const toggle = document.getElementById('extra_risk_toggle');
    const panel = document.getElementById('extra_risk_panel');
    if (!toggle || !panel) return;

    const hasAnyValue = () => {
      const fields = panel.querySelectorAll('input, select, textarea');
      for (const el of fields) {
        const v = (el.value || '').toString().trim();
        if (v !== '') return true;
      }
      return false;
    };

    const setOpen = (open) => {
      panel.classList.toggle('hidden', !open);
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      toggle.textContent = open ? 'Hide' : 'Show';
    };

    // Open automatically if any optional field already has a value (e.g. after validation error).
    setOpen(hasAnyValue());

    toggle.addEventListener('click', () => {
      const open = panel.classList.contains('hidden');
      setOpen(open);
    });
  })();

  highRiskInput?.addEventListener('change', () => {
    if (!highRiskInput.checked) {
      riskLevelInput.value = '';
      riskLevelOtherInput.value = '';
    }
    riskReasonWrap.style.display = highRiskInput.checked ? 'grid' : 'none';
    riskLevelInput.disabled = !highRiskInput.checked;
    riskLevelOtherInput.disabled = !highRiskInput.checked;
    updateFloatField(riskLevelInput);
    updateFloatField(riskLevelOtherInput);
  });

  if (riskLevelInput) {
    riskLevelInput.disabled = !highRiskInput?.checked;
  }

  if (riskLevelOtherInput) {
    riskLevelOtherInput.disabled = !highRiskInput?.checked;
  }

  if (riskReasonWrap) {
    riskReasonWrap.style.display = highRiskInput?.checked ? 'grid' : 'none';
  }

  riskLevelInput?.addEventListener('change', () => {
    const isOther = riskLevelInput.value === 'Other';
    riskLevelOtherInput.disabled = !isOther || !highRiskInput.checked;
    if (!isOther) {
      riskLevelOtherInput.value = '';
    }
    updateFloatField(riskLevelInput);
    updateFloatField(riskLevelOtherInput);
  });

  function calculateAge(dateStr) {
    const dob = new Date(dateStr);
    if (Number.isNaN(dob.getTime())) return '';
    const today = new Date();
    let age = today.getFullYear() - dob.getFullYear();
    const m = today.getMonth() - dob.getMonth();
    if (m < 0 || (m === 0 && today.getDate() < dob.getDate())) {
      age--;
    }
    return age >= 0 ? String(age) : '';
  }

  dobInput?.addEventListener('change', () => {
    ageDisplay.value = dobInput.value ? calculateAge(dobInput.value) : '';
    updateFloatField(ageDisplay);
    updateFloatField(dobInput);
  });

  phoneInput?.addEventListener('input', () => {
    let raw = phoneInput.value.replace(/\D/g, '');
    if (raw.startsWith('263')) {
      raw = raw.slice(3);
    }
    if (raw.length > 0 && raw[0] !== '7') {
      phoneInput.value = '';
    } else {
      phoneInput.value = raw.slice(0, 9);
    }
    updateFloatField(phoneInput);
  });

  hospitalNumberInput?.addEventListener('input', () => {
    hospitalNumberInput.value = hospitalNumberInput.value.replace(/\D/g, '');
    updateFloatField(hospitalNumberInput);
  });

  fullnameInput?.addEventListener('input', () => {
    fullnameInput.value = fullnameInput.value.replace(/[^\p{L}\s'\-\.,]/gu, '');
    updateFloatField(fullnameInput);
  });

  existingUserSelect?.addEventListener('change', () => {
    const opt = existingUserSelect.options[existingUserSelect.selectedIndex];
    const hasExisting = !!opt && !!opt.value;
    if (hasExisting) {
      fullnameInput.value = opt.getAttribute('data-fullname') || '';
      phoneInput.value = (opt.getAttribute('data-phone') || '').replace('+263', '');
      fullnameInput.readOnly = true;
      phoneInput.readOnly = true;
      hasWebAccess.checked = true;
      hasWebAccess.disabled = true;
      updateFloatField(fullnameInput);
      updateFloatField(phoneInput);
    } else {
      fullnameInput.readOnly = false;
      phoneInput.readOnly = false;
      hasWebAccess.disabled = false;
    }
  });

  if (existingUserSelect && existingUserSelect.value) {
    const opt = existingUserSelect.options[existingUserSelect.selectedIndex];
    fullnameInput.value = opt.getAttribute('data-fullname') || '';
    phoneInput.value = (opt.getAttribute('data-phone') || '').replace('+263', '');
    fullnameInput.readOnly = true;
    phoneInput.readOnly = true;
    hasWebAccess.checked = true;
    hasWebAccess.disabled = true;
    updateFloatField(fullnameInput);
    updateFloatField(phoneInput);
  }

  function unlockLinkedMotherFields() {
    fullnameInput.readOnly = false;
    phoneInput.readOnly = false;
    hasWebAccess.disabled = false;
  }

  function resetBookingForm() {
    // Prefer to reset all fields to blank, and remove any "linked mother" locks.
    unlockLinkedMotherFields();
    if (existingUserSelect) existingUserSelect.value = '';

    // Reset the form controls (keeps HTML defaults, but we immediately clear key ones).
    const form = resetBookingFormBtn?.closest('form');
    form?.reset();

    // Clear fields explicitly for predictable behavior.
    [hospitalNumberInput, fullnameInput, dobInput, phoneInput, ageDisplay].forEach((el) => {
      if (el) el.value = '';
    });
    // Re-enable default behavior for optional link and web access.
    if (hasWebAccess) {
      hasWebAccess.checked = false;
      hasWebAccess.disabled = false;
    }

    // Ensure float labels match cleared values.
    floatFields.forEach(updateFloatField);
    updateFloatField(ageDisplay);
  }

  document.querySelectorAll('[data-care-btn]').forEach(btn => {
    btn.addEventListener('click', () => {
      careName.textContent = btn.dataset.fullname || '-';
      careStatus.textContent = btn.dataset.status || '-';
      careEdd.textContent = btn.dataset.edd || '-';
      careClinic.textContent = btn.dataset.clinic || '-';
      careHistoryBtn.href = `/mch_system/nurse_portal/mother_history.php?mother_id=${btn.dataset.id}`;
      careCard.classList.remove('hidden');
      try {
        careCard.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      } catch (e) {}
    });
  });

  careCloseBtn?.addEventListener('click', () => {
    careName.textContent = '-';
    careStatus.textContent = '-';
    careEdd.textContent = '-';
    careClinic.textContent = '-';
    careHistoryBtn.href = '#';
    careCard.classList.add('hidden');
  });

  resetBookingFormBtn?.addEventListener('click', resetBookingForm);

  function filterExistingRows() {
    const q = (searchExisting?.value || '').toLowerCase().trim();
    const filter = filterExisting?.value || 'all';

    if (filter === 'baby') {
      mothersWrap?.classList.add('hidden');
      babiesWrap?.classList.remove('hidden');
    } else {
      mothersWrap?.classList.remove('hidden');
      babiesWrap?.classList.add('hidden');
    }

    if (mothersTable) {
      const rows = Array.from(mothersTable.querySelectorAll('tbody tr'));
      rows.forEach(row => {
        const name = row.dataset.name || '';
        const hospital = row.dataset.hospital || '';
        const status = row.dataset.status || '';
        const matchText = !q || name.includes(q) || hospital.includes(q);
        const matchStatus = filter === 'all'
          || (filter === 'pregnant' && status === 'pregnant')
          || (filter === 'postnatal' && (status === 'postnatal' || status === 'child'));
        row.style.display = (matchText && matchStatus) ? '' : 'none';
      });
    }

    if (babiesTable) {
      const rows = Array.from(babiesTable.querySelectorAll('tbody tr'));
      rows.forEach(row => {
        const name = row.dataset.name || '';
        const mother = row.dataset.mother || '';
        const matchText = !q || name.includes(q) || mother.includes(q);
        row.style.display = matchText ? '' : 'none';
      });
    }
  }

  searchExisting?.addEventListener('input', filterExistingRows);
  filterExisting?.addEventListener('change', filterExistingRows);
  clearExisting?.addEventListener('click', () => {
    if (searchExisting) searchExisting.value = '';
    if (filterExisting) filterExisting.value = 'all';
    filterExistingRows();
  });
  filterExistingRows();

  toggleExistingRecordsBtn?.addEventListener('click', () => {
    if (!existingRecordsSection) return;
    const isHidden = existingRecordsSection.classList.toggle('hidden');
    const isVisible = !isHidden;
    const iconSpan = toggleExistingRecordsBtn.querySelector('[data-icon]');
    toggleExistingRecordsBtn.classList.toggle('viewing', isVisible);
    toggleExistingRecordsBtn.setAttribute('aria-expanded', String(isVisible));
    if (iconSpan) iconSpan.textContent = isVisible ? '🚫' : '👁️';
  });

  // Reduce accidental bookings: confirm before submitting.
  bookForm?.addEventListener('submit', (e) => {
    const opt = existingUserSelect?.options?.[existingUserSelect.selectedIndex];
    const linkedName = opt?.value ? (opt.getAttribute('data-fullname') || '') : '';
    const hospitalNo = hospitalNumberInput?.value || '';
    const name = linkedName || (fullnameInput?.value || '');
    const msg = `Confirm booking pregnancy for:\n\nName: ${name || '-'}\nHospital #: ${hospitalNo || '-'}\n\nProceed?`;
    if (!confirm(msg)) {
      e.preventDefault();
      e.stopPropagation();
    }
  });

</script>
