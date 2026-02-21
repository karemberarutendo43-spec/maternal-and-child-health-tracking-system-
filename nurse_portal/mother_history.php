<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/cards.php";
require_once __DIR__ . "/../config/epi.php";
require_once __DIR__ . "/../config/bcn.php";
require_once __DIR__ . "/../config/crypto.php";
require_once __DIR__ . "/../config/scope.php";
require_once __DIR__ . "/../config/csrf.php";
require_once __DIR__ . "/../config/audit.php";

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'nurse'], true)) {
    header("Location: /mch_system/auth/login.php");
    exit;
}

$birth_error = '';
$birth_success = '';
$supportsBcn = db_has_column($pdo, 'babies', 'birth_confirmation_number');
$birth_confirmation_number = '';
$supportsDeliveryDate = db_has_column($pdo, 'mothers', 'delivery_date');
$supportsSafeguardingFlag = db_has_column($pdo, 'mothers', 'safeguarding_flag');
$supportsSafeguardingStatus = db_has_column($pdo, 'mothers', 'safeguarding_status');
$supportsSafeguardingReason = db_has_column($pdo, 'mothers', 'safeguarding_reason');
$supportsSafeguardingAckBy = db_has_column($pdo, 'mothers', 'safeguarding_ack_by');
$supportsSafeguardingAckAt = db_has_column($pdo, 'mothers', 'safeguarding_ack_at');
$supportsMustChangePassword = db_has_column($pdo, 'users', 'must_change_password');
$safeguarding_error = '';
$safeguarding_success = '';
$web_access_error = '';
$web_access_success = '';

// Flash message for safeguarding acknowledgements (PRG)
$flash = $_SESSION['flash_safeguarding_ack'] ?? null;
if (is_array($flash)) {
    $safeguarding_error = (string)($flash['error'] ?? '');
    $safeguarding_success = (string)($flash['success'] ?? '');
    unset($_SESSION['flash_safeguarding_ack']);
}

$flashWebAccess = $_SESSION['flash_web_access'] ?? null;
if (is_array($flashWebAccess)) {
    $web_access_error = (string)($flashWebAccess['error'] ?? '');
    $web_access_success = (string)($flashWebAccess['success'] ?? '');
    unset($_SESSION['flash_web_access']);
}

$mother_id = (int)($_GET['mother_id'] ?? ($_POST['mother_id'] ?? 0));
if ($mother_id <= 0) {
    die("Invalid mother ID.");
}

$scopedClinicId = mch_scope_scoped_clinic_id();
if ((string)($_SESSION['role'] ?? '') === 'nurse' && $scopedClinicId <= 0) {
    die("Your account is not assigned to a clinic. Please contact Admin.");
}
if ($scopedClinicId > 0) {
    $scopeStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM mothers m
        JOIN users u ON u.id = m.user_id
        WHERE m.id = ?
          AND u.clinic_id = ?
        LIMIT 1
    ");
    $scopeStmt->execute([$mother_id, $scopedClinicId]);
    if ((int)$scopeStmt->fetchColumn() !== 1) {
        http_response_code(403);
        die("403 Forbidden: You do not have access to this mother record.");
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'enable_web_access') {
    mch_csrf_require_valid('nurse_enable_web_access');
    try {
        $mUserStmt = $pdo->prepare("
            SELECT u.id, u.phone, u.has_web_access
            FROM mothers m
            JOIN users u ON u.id = m.user_id
            WHERE m.id = ?
            LIMIT 1
        ");
        $mUserStmt->execute([$mother_id]);
        $uRow = $mUserStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$uRow) {
            throw new Exception("Mother user account not found.");
        }

        $userId = (int)($uRow['id'] ?? 0);
        if ($userId <= 0) {
            throw new Exception("Invalid mother user account.");
        }

        if ((int)($uRow['has_web_access'] ?? 0) === 1) {
            throw new Exception("Web access is already enabled for this mother.");
        }

        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $tmp = '';
        for ($i = 0; $i < 10; $i++) {
            $tmp .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        $hash = password_hash($tmp, PASSWORD_DEFAULT);

        $set = "has_web_access = 1, email_verified = 1, password_hash = ?";
        $params = [$hash];
        if ($supportsMustChangePassword) {
            $set .= ", must_change_password = 1";
        }
        $params[] = $userId;

        $upd = $pdo->prepare("UPDATE users SET {$set} WHERE id = ? AND role = 'mother' LIMIT 1");
        $upd->execute($params);

        mch_audit_log($pdo, 'ENABLE_WEB_ACCESS', 'users', $userId, "Enabled web access for mother_id {$mother_id}.");

        $_SESSION['flash_web_access'] = [
            'success' => "Web access enabled. Temporary password: {$tmp} (Mother must change password after login).",
        ];
    } catch (Throwable $e) {
        $_SESSION['flash_web_access'] = [
            'error' => $e->getMessage() ?: 'Failed to enable web access.',
        ];
    }

    header("Location: /mch_system/nurse_portal/mother_history.php?mother_id=" . (int)$mother_id, true, 303);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ack_safeguarding') {
    mch_csrf_require_valid('nurse_ack_safeguarding');
    try {
        if (!$supportsSafeguardingFlag) {
            throw new Exception("Safeguarding fields are not installed. Run sql/mothers_safeguarding_migrate.sql.");
        }

        $confirm = (string)($_POST['confirm_ack'] ?? '');
        if ($confirm !== '1') {
            throw new Exception("Please confirm acknowledgement to proceed.");
        }

        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid <= 0) {
            throw new Exception("Not logged in.");
        }

        $setParts = [];
        $params = [];
        if ($supportsSafeguardingStatus) { $setParts[] = "safeguarding_status = 'ACKED'"; }
        if ($supportsSafeguardingAckBy) { $setParts[] = "safeguarding_ack_by = ?"; $params[] = $uid; }
        if ($supportsSafeguardingAckAt) { $setParts[] = "safeguarding_ack_at = NOW()"; }
        if (count($setParts) === 0) {
            // Fallback: at least mark as flagged/acknowledged in status if present.
            $setParts[] = "safeguarding_flag = 1";
        }
        $params[] = $mother_id;

        $sql = "UPDATE mothers SET " . implode(', ', $setParts) . " WHERE id = ? LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        mch_audit_log($pdo, 'SAFEGUARDING_ACK', 'mothers', $mother_id, "Safeguarding acknowledgement recorded for mother_id {$mother_id}.");

        $_SESSION['flash_safeguarding_ack'] = [
            'success' => 'Safeguarding acknowledged. Continue with care and follow facility protocol.',
        ];
    } catch (Exception $e) {
        $_SESSION['flash_safeguarding_ack'] = [
            'error' => $e->getMessage(),
        ];
    }

    header("Location: /mch_system/nurse_portal/mother_history.php?mother_id=" . (int)$mother_id, true, 303);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'log_birth') {
    mch_csrf_require_valid('nurse_log_birth');
    $baby_name = trim($_POST['baby_name'] ?? '');
    $dob = $_POST['dob'] ?? '';
    $gender = $_POST['gender'] ?? '';
    $delivery_type = trim((string)($_POST['delivery_type'] ?? 'Vaginal'));
    $birth_outcome = trim((string)($_POST['birth_outcome'] ?? 'LIVE'));
    $cs_type = trim((string)($_POST['cs_type'] ?? ''));
    $cs_indication = trim((string)($_POST['cs_indication'] ?? ''));
    $decision_to_incision_minutes = trim((string)($_POST['decision_to_incision_minutes'] ?? ''));
    $delivery_complications = trim((string)($_POST['delivery_complications'] ?? ''));
    $birth_confirmation_number = trim((string)($_POST['birth_confirmation_number'] ?? ''));
    $autoAssignBcn = (string)($_POST['auto_assign_bcn'] ?? '') === '1';
    $birth_weight_raw = trim((string)($_POST['birth_weight'] ?? ''));
    $birth_weight = null;
    if ($birth_weight_raw !== '') {
        if (!is_numeric($birth_weight_raw)) {
            $birth_error = "Birth weight must be a number.";
        } else {
            $bw = (float)$birth_weight_raw;
            if ($bw < 0 || $bw > 5) {
                $birth_error = "Birth weight must be between 0 and 5 kg.";
            } else {
                $birth_weight = $birth_weight_raw;
            }
        }
    }

    if ($birth_error !== '') {
        // keep error as-is
    } elseif ($baby_name === '' || $dob === '' || !in_array($gender, ['Male', 'Female'], true)) {
        $birth_error = "Please provide baby name, date of birth, and gender.";
    } else {
        try {
            $pdo->beginTransaction();

            if (!in_array($delivery_type, ['Vaginal', 'C-Section', 'Vacuum', 'Breech', 'Forceps', 'Other'], true)) {
                $delivery_type = 'Vaginal';
            }
            if (!in_array($birth_outcome, ['LIVE', 'FSB', 'MSB', 'STILLBIRTH', 'NEONATAL_DEATH', 'MATERNAL_DEATH', 'OTHER'], true)) {
                $birth_outcome = 'LIVE';
            }
            if (in_array($birth_outcome, ['FSB', 'MSB', 'STILLBIRTH', 'NEONATAL_DEATH'], true) && $birth_weight_raw === '') {
                throw new Exception("Birth weight is required for non-live outcomes.");
            }
            if ($delivery_type === 'C-Section') {
                if (!in_array($cs_type, ['Elective', 'Emergency'], true)) {
                    throw new Exception("For C-Section, select Elective or Emergency.");
                }
                if ($cs_indication === '') {
                    throw new Exception("For C-Section, select an indication.");
                }
                if ($decision_to_incision_minutes !== '') {
                    if (!ctype_digit($decision_to_incision_minutes)) {
                        throw new Exception("Decision-to-incision time must be a whole number (minutes).");
                    }
                    $m = (int)$decision_to_incision_minutes;
                    if ($m < 0 || $m > 1440) {
                        throw new Exception("Decision-to-incision time must be between 0 and 1440 minutes.");
                    }
                }
            } else {
                $cs_type = '';
                $cs_indication = '';
                $decision_to_incision_minutes = '';
            }
            if ($delivery_complications !== '' && mb_strlen($delivery_complications) > 255) {
                throw new Exception("Delivery complications too long (max 255).");
            }

            $dup = $pdo->prepare("
                SELECT id FROM babies
                WHERE mother_id = ? AND baby_name = ? AND dob = ?
                LIMIT 1
            ");
            $dup->execute([$mother_id, $baby_name, $dob]);
            if ($dup->fetch()) {
                throw new Exception("This birth record already exists for the selected mother.");
            }

            if ($supportsBcn && $birth_confirmation_number !== '') {
                $dup = $pdo->prepare("SELECT id FROM babies WHERE birth_confirmation_number = ? LIMIT 1");
                $dup->execute([$birth_confirmation_number]);
                if ($dup->fetch(PDO::FETCH_ASSOC)) {
                    throw new Exception("Birth Confirmation Number already exists.");
                }
            }

            $supportsCard = babies_supports_card_uid($pdo);
            $card_uid = $supportsCard ? generate_card_uid() : null;

            if ($supportsBcn && $autoAssignBcn) {
                $clinicId = (int)($_SESSION['clinic_id'] ?? 0);
                $birth_confirmation_number = mch_bcn_assign_from_active_book($pdo, $clinicId);
            }

            if ($supportsCard && $supportsBcn) {
                $ins = $pdo->prepare("
                    INSERT INTO babies (mother_id, baby_name, dob, gender, birth_weight, card_uid, birth_confirmation_number, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $ins->execute([
                    $mother_id,
                    $baby_name,
                    $dob,
                    $gender,
                    $birth_weight,
                    $card_uid,
                    ($birth_confirmation_number !== '' ? $birth_confirmation_number : null)
                ]);
            } elseif ($supportsCard && !$supportsBcn) {
                $ins = $pdo->prepare("
                    INSERT INTO babies (mother_id, baby_name, dob, gender, birth_weight, card_uid, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                ");
                $ins->execute([
                    $mother_id,
                    $baby_name,
                    $dob,
                    $gender,
                    $birth_weight,
                    $card_uid
                ]);
            } elseif (!$supportsCard && $supportsBcn) {
                $ins = $pdo->prepare("
                    INSERT INTO babies (mother_id, baby_name, dob, gender, birth_weight, birth_confirmation_number, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                ");
                $ins->execute([
                    $mother_id,
                    $baby_name,
                    $dob,
                    $gender,
                    $birth_weight,
                    ($birth_confirmation_number !== '' ? $birth_confirmation_number : null)
                ]);
            } else {
                $ins = $pdo->prepare("
                    INSERT INTO babies (mother_id, baby_name, dob, gender, birth_weight, created_at)
                    VALUES (?, ?, ?, ?, ?, NOW())
                ");
                $ins->execute([
                    $mother_id,
                    $baby_name,
                    $dob,
                    $gender,
                    $birth_weight
                ]);
            }
            $baby_id = $pdo->lastInsertId();

            // Generate Immunization Schedule (Zimbabwe EPI)
            mch_epi_insert_schedule($pdo, (int)$baby_id, (string)$dob);

            // Save delivery record (optional, for reporting). Uses the deliveries table if configured.
            try {
                $hasDeliveries = (bool)$pdo->query("SHOW TABLES LIKE 'deliveries'")->fetchColumn();
            } catch (Throwable $e) {
                $hasDeliveries = false;
            }
            if ($hasDeliveries) {
                $hasCsType = db_has_column($pdo, 'deliveries', 'cs_type');
                $hasInd = db_has_column($pdo, 'deliveries', 'cs_indication');
                $hasD2i = db_has_column($pdo, 'deliveries', 'decision_to_incision_minutes');

                $cols = "mother_id, delivery_date, delivery_type, outcome, complications, created_by";
                $vals = "?, ?, ?, 'LIVE', ?, ?";
                $params = [
                    $mother_id,
                    $dob,
                    $delivery_type,
                    ($delivery_complications !== '' ? $delivery_complications : null),
                    (int)($_SESSION['user_id'] ?? 0),
                ];

                // Use selected outcome if the column supports it (safe on older schemas).
                try {
                    $hasOutcome = db_has_column($pdo, 'deliveries', 'outcome');
                } catch (Throwable $e) {
                    $hasOutcome = false;
                }
                if ($hasOutcome) {
                    $vals = "?, ?, ?, ?, ?, ?";
                    $params = [
                        $mother_id,
                        $dob,
                        $delivery_type,
                        $birth_outcome,
                        ($delivery_complications !== '' ? $delivery_complications : null),
                        (int)($_SESSION['user_id'] ?? 0),
                    ];
                }
                if ($hasCsType) { $cols .= ", cs_type"; $vals .= ", ?"; $params[] = ($cs_type !== '' ? $cs_type : null); }
                if ($hasInd) { $cols .= ", cs_indication"; $vals .= ", ?"; $params[] = ($cs_indication !== '' ? $cs_indication : null); }
                if ($hasD2i) { $cols .= ", decision_to_incision_minutes"; $vals .= ", ?"; $params[] = ($decision_to_incision_minutes !== '' ? (int)$decision_to_incision_minutes : null); }

                $sql = "INSERT INTO deliveries ({$cols}) VALUES ({$vals})
                        ON DUPLICATE KEY UPDATE
                          delivery_type = VALUES(delivery_type),
                          outcome = VALUES(outcome),
                          complications = VALUES(complications)";
                if ($hasCsType) $sql .= ", cs_type = VALUES(cs_type)";
                if ($hasInd) $sql .= ", cs_indication = VALUES(cs_indication)";
                if ($hasD2i) $sql .= ", decision_to_incision_minutes = VALUES(decision_to_incision_minutes)";
                $sql .= ", updated_at = NOW()";

                $dStmt = $pdo->prepare($sql);
                $dStmt->execute($params);
            }

            if ($supportsDeliveryDate) {
                $upd = $pdo->prepare("UPDATE mothers SET pregnancy_status = 'Postnatal', delivery_date = ? WHERE id = ?");
                $upd->execute([$dob, $mother_id]);
            } else {
                $upd = $pdo->prepare("UPDATE mothers SET pregnancy_status = 'Postnatal' WHERE id = ?");
                $upd->execute([$mother_id]);
            }

            $pdo->commit();
            mch_audit_log($pdo, 'DELIVERY_LOG', 'deliveries', null, "Logged delivery for mother_id {$mother_id} ({$delivery_type}, outcome={$birth_outcome})");
            $birth_success = "Birth entry logged. Mother status updated to Postnatal.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $birth_error = $e->getMessage();
        }
    }
}

$stmt = $pdo->prepare("
    SELECT
      m.*,
      c.clinic_name,
      u.id AS user_id,
      u.phone AS user_phone,
      u.email AS user_email,
      u.has_web_access,
      u.email_verified
    FROM mothers m
    LEFT JOIN users u ON m.user_id = u.id
    LEFT JOIN clinics c ON u.clinic_id = c.id
    WHERE m.id = ?
    LIMIT 1
");
$stmt->execute([$mother_id]);
$mother = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$mother) {
    die("Mother not found.");
}

$needsSafeguardingAck = false;
if ($supportsSafeguardingFlag) {
    $flagged = (int)($mother['safeguarding_flag'] ?? 0) === 1;
    $acked = false;
    if ($supportsSafeguardingAckAt) {
        $acked = !empty($mother['safeguarding_ack_at']);
    } elseif ($supportsSafeguardingStatus) {
        $acked = (string)($mother['safeguarding_status'] ?? '') === 'ACKED';
    }
    $needsSafeguardingAck = $flagged && !$acked;
}

$ancStmt = $pdo->prepare("
    SELECT id, visit_number, visit_date, next_visit_date, status, notes
    FROM anc_visits
    WHERE mother_id = ?
    ORDER BY visit_date ASC
");
$ancStmt->execute([$mother_id]);
$ancVisits = $ancStmt->fetchAll(PDO::FETCH_ASSOC);

$pncStmt = $pdo->prepare("
    SELECT p.visit_date, p.status, p.notes, b.baby_name
    FROM pnc_visits p
    LEFT JOIN babies b ON p.baby_id = b.id
    WHERE p.mother_id = ?
    ORDER BY p.visit_date DESC
");
$pncStmt->execute([$mother_id]);
$pncVisits = $pncStmt->fetchAll(PDO::FETCH_ASSOC);

$supportsCardList = babies_supports_card_uid($pdo);
$babyStmt = $pdo->prepare("
    SELECT id, baby_name, dob, gender, birth_weight" . ($supportsCardList ? ", card_uid" : "") . "
    FROM babies
    WHERE mother_id = ?
    ORDER BY dob ASC
");
$babyStmt->execute([$mother_id]);
$babies = $babyStmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_nurse.php";
?>

<div class="ml-64 p-6">
  <?php if ($safeguarding_error): ?>
    <div class="bg-rose-100 text-rose-700 p-3 rounded-lg mb-4"><?= htmlspecialchars($safeguarding_error) ?></div>
  <?php endif; ?>
  <?php if ($safeguarding_success): ?>
    <div class="bg-emerald-100 text-emerald-700 p-3 rounded-lg mb-4"><?= htmlspecialchars($safeguarding_success) ?></div>
  <?php endif; ?>

  <?php if ($web_access_error): ?>
    <div class="bg-rose-100 text-rose-700 p-3 rounded-lg mb-4"><?= htmlspecialchars($web_access_error) ?></div>
  <?php endif; ?>
  <?php if ($web_access_success): ?>
    <div class="bg-emerald-100 text-emerald-700 p-3 rounded-lg mb-4"><?= htmlspecialchars($web_access_success) ?></div>
  <?php endif; ?>

  <?php if ($needsSafeguardingAck): ?>
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/70">
      <div class="w-full max-w-lg rounded-2xl bg-white shadow-2xl border border-slate-200 p-6">
        <div class="flex items-start justify-between gap-4">
          <div>
            <div class="text-xs font-semibold tracking-widest text-rose-600 uppercase">Safeguarding / Protection</div>
            <h3 class="text-lg font-bold text-slate-900 mt-1">Urgent safeguarding action required</h3>
            <p class="text-sm text-slate-600 mt-2">
              This record is flagged for safeguarding. Please follow your facility protocol and acknowledge before continuing.
            </p>
            <?php if ($supportsSafeguardingReason && !empty($mother['safeguarding_reason'])): ?>
              <div class="mt-3 text-sm text-slate-700">
                <span class="font-semibold">Flag:</span> <?= htmlspecialchars((string)$mother['safeguarding_reason']) ?>
              </div>
            <?php elseif ($supportsSafeguardingStatus && !empty($mother['safeguarding_status'])): ?>
              <div class="mt-3 text-sm text-slate-700">
                <span class="font-semibold">Status:</span> <?= htmlspecialchars((string)$mother['safeguarding_status']) ?>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <form method="POST" class="mt-5 space-y-4">
          <?= mch_csrf_input('nurse_ack_safeguarding') ?>
          <input type="hidden" name="action" value="ack_safeguarding">
          <input type="hidden" name="mother_id" value="<?= (int)$mother_id ?>">

          <label class="flex items-start gap-3 text-sm text-slate-700">
            <input type="checkbox" name="confirm_ack" value="1" class="mt-1 rounded border-slate-300 text-rose-600 focus:ring-rose-500" required>
            <span>I acknowledge this safeguarding flag and confirm I will follow facility protocol.</span>
          </label>

          <div class="flex items-center justify-end gap-2">
            <button type="submit" class="px-4 py-2 rounded-lg bg-rose-600 text-white text-sm font-semibold hover:bg-rose-700">
              Acknowledge & Continue
            </button>
          </div>
        </form>
      </div>
    </div>
  <?php endif; ?>

  <div class="flex items-center justify-between mb-6">
    <div>
      <h2 class="text-2xl font-semibold text-slate-900">Clinical History</h2>
      <p class="text-sm text-slate-500">Mother profile and ANC timeline</p>
    </div>
    <div class="flex items-center gap-2">
      <a href="/mch_system/nurse_portal/epds_screen.php?mother_id=<?= (int)$mother_id ?>" class="px-4 py-2 rounded-lg bg-slate-900 text-white text-sm hover:bg-slate-800">
        EPDS (Mental Health)
      </a>
      <a href="/mch_system/nurse_portal/register_mother.php" class="mch-btn px-4 py-2">Back to Booking</a>
    </div>
  </div>

  <div id="anc-visits" class="mch-card bg-white/90 p-5 border border-slate-100 mb-6">
    <div class="text-sm text-slate-500 mb-1">Mother Care Card</div>
    <div class="text-lg font-semibold text-slate-900"><?= htmlspecialchars($mother['fullname']) ?></div>
    <div class="grid grid-cols-1 md:grid-cols-6 gap-3 text-sm text-slate-700 mt-3">
      <div><span class="font-semibold">Hospital #:</span> <?= htmlspecialchars($mother['hospital_number']) ?></div>
      <div><span class="font-semibold">Status:</span> <?= htmlspecialchars($mother['pregnancy_status']) ?></div>
      <div><span class="font-semibold">EDD:</span> <?= htmlspecialchars($mother['edd'] ?? '-') ?></div>
      <div><span class="font-semibold">Clinic:</span> <?= htmlspecialchars($mother['clinic_name'] ?? 'Unassigned') ?></div>
      <div><span class="font-semibold">Phone:</span> <?= htmlspecialchars((string)($mother['user_phone'] ?? $mother['phone'] ?? '-')) ?></div>
      <div class="flex items-center gap-2 flex-wrap">
        <span>
          <span class="font-semibold">Web Access:</span>
          <?php if ((int)($mother['has_web_access'] ?? 0) === 1): ?>
            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-emerald-100 text-emerald-700">Enabled</span>
          <?php else: ?>
            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-amber-100 text-amber-700">Clinic-managed</span>
          <?php endif; ?>
        </span>
        <?php if ((int)($mother['has_web_access'] ?? 0) !== 1): ?>
          <form method="POST" class="inline">
            <?= mch_csrf_input('nurse_enable_web_access') ?>
            <input type="hidden" name="action" value="enable_web_access">
            <input type="hidden" name="mother_id" value="<?= (int)$mother_id ?>">
            <button type="submit" class="px-3 py-1.5 rounded-lg bg-teal-50 border border-teal-200 text-teal-700 hover:bg-teal-100 text-xs">
              Enable + Temp Password
            </button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="mch-card bg-white/90 p-5 border border-slate-100 mb-6">
    <h3 class="text-lg font-semibold text-slate-900 mb-3">Log Birth Entry</h3>
    <?php if ($birth_error): ?>
      <div class="bg-rose-100 text-rose-700 p-3 rounded-lg mb-3"><?= htmlspecialchars($birth_error) ?></div>
    <?php endif; ?>
    <?php if ($birth_success): ?>
      <div class="bg-emerald-100 text-emerald-700 p-3 rounded-lg mb-3"><?= htmlspecialchars($birth_success) ?></div>
    <?php endif; ?>
    <form method="POST" class="grid grid-cols-1 md:grid-cols-4 gap-3" id="log_birth_form">
      <?= mch_csrf_input('nurse_log_birth') ?>
      <input type="hidden" name="action" value="log_birth">
      <input type="hidden" name="mother_id" value="<?= (int)$mother_id ?>">
      <input type="text" name="baby_name" class="border border-slate-200 p-2 rounded-lg bg-white" placeholder="Baby Full Name" required>
      <input type="date" name="dob" class="border border-slate-200 p-2 rounded-lg bg-white" required>
      <select name="gender" class="border border-slate-200 p-2 rounded-lg bg-white" required>
        <option value="">Select Sex</option>
        <option value="Female">Female</option>
        <option value="Male">Male</option>
      </select>
      <select name="delivery_type" id="delivery_type" class="border border-slate-200 p-2 rounded-lg bg-white" required>
        <option value="Vaginal">SVD (Vaginal)</option>
        <option value="C-Section">C-Section</option>
        <option value="Vacuum">Vacuum</option>
        <option value="Breech">Breech</option>
        <option value="Forceps">Forceps</option>
        <option value="Other">Other</option>
      </select>
      <select name="birth_outcome" id="birth_outcome" class="border border-slate-200 p-2 rounded-lg bg-white" required>
        <option value="LIVE">Live Birth</option>
        <option value="FSB">Fresh Stillbirth (FSB)</option>
        <option value="MSB">Macerated Stillbirth (MSB)</option>
        <option value="STILLBIRTH">Stillbirth (Unspecified)</option>
        <option value="NEONATAL_DEATH">Neonatal Death</option>
        <option value="OTHER">Other</option>
      </select>

      <div class="md:col-span-4 hidden rounded-xl border border-slate-200 bg-slate-50 p-4" id="cs_fields">
        <div class="text-sm font-semibold text-slate-900 mb-2">C-Section Details</div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
          <div>
            <label class="block text-xs font-semibold text-slate-600 mb-1">Type</label>
            <select name="cs_type" class="w-full border border-slate-200 p-2 rounded-lg bg-white">
              <option value="">Select</option>
              <option value="Elective">Elective</option>
              <option value="Emergency">Emergency</option>
            </select>
          </div>
          <div class="md:col-span-2">
            <label class="block text-xs font-semibold text-slate-600 mb-1">Indication</label>
            <select name="cs_indication" class="w-full border border-slate-200 p-2 rounded-lg bg-white">
              <option value="">Select</option>
              <option value="CPD">Cephalopelvic Disproportion (CPD)</option>
              <option value="Placenta Praevia">Placenta Praevia</option>
              <option value="Abnormal lie/presentation">Abnormal Lie/Presentation (Transverse/Breech)</option>
              <option value="Multiple gestation">Multiple Gestation</option>
              <option value="Fetal distress">Fetal Distress</option>
              <option value="Obstructed labor">Obstructed Labor</option>
              <option value="Cord prolapse">Cord Prolapse</option>
              <option value="Placental abruption">Placental Abruption</option>
              <option value="Previous C-Section">Previous C-Section (Scarred uterus)</option>
              <option value="Previous uterine rupture">Previous Uterine Rupture</option>
              <option value="Severe pre-eclampsia/eclampsia">Severe Pre-eclampsia / Eclampsia</option>
              <option value="Active genital herpes">Active Genital Herpes</option>
              <option value="Maternal exhaustion">Maternal Exhaustion</option>
              <option value="Other">Other</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-semibold text-slate-600 mb-1">Decision-to-incision (min)</label>
            <input type="number" min="0" max="1440" name="decision_to_incision_minutes" class="w-full border border-slate-200 p-2 rounded-lg bg-white" placeholder="e.g. 30">
          </div>
          <div class="md:col-span-3 text-xs text-slate-500">
            Quality metric: decision-to-incision time is monitored for emergency C-sections.
          </div>
        </div>
      </div>

      <div class="md:col-span-4">
        <label class="block text-xs font-semibold text-slate-600 mb-1">Delivery complications (optional)</label>
        <input type="text" name="delivery_complications" class="border border-slate-200 p-2 rounded-lg bg-white w-full" placeholder="e.g. PPH, tears, sepsis, referral">
      </div>
      <div class="md:col-span-4">
        <input type="text" name="birth_confirmation_number" class="border border-slate-200 p-2 rounded-lg bg-white w-full"
               placeholder="Birth Confirmation Number (optional)"
               value="<?= htmlspecialchars($birth_confirmation_number ?? '') ?>">
        <?php if (!$supportsBcn): ?>
          <p class="text-xs text-amber-700 mt-1">To enable this, run <span class="font-mono">sql/birth_confirmation_number.sql</span> on the database.</p>
        <?php else: ?>
          <label class="mt-2 inline-flex items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" name="auto_assign_bcn" value="1" class="w-4 h-4" <?= !empty($_POST) ? (!empty($autoAssignBcn) ? 'checked' : '') : 'checked' ?>>
            Auto-assign from clinic BCN book
          </label>
          <p class="text-xs text-slate-500 mt-1">Uses the active serial book configured for your clinic (Admin → Birth Confirmation Books).</p>
        <?php endif; ?>
      </div>
      <input type="number" step="0.01" min="0" max="5" name="birth_weight" class="border border-slate-200 p-2 rounded-lg bg-white" placeholder="Birth Weight (kg)">
      <div class="md:col-span-4">
        <button type="submit" class="mch-btn px-4 py-2">Confirm Birth Entry</button>
      </div>
    </form>
  </div>

  <div class="mch-card bg-white/90 p-5 border border-slate-100 mb-6">
    <h3 class="text-lg font-semibold text-slate-900 mb-3">ANC Visits</h3>
    <?php if (empty($ancVisits)): ?>
      <p class="text-slate-500">No ANC visits recorded.</p>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="w-full text-sm mch-table">
          <thead>
            <tr>
              <th class="p-2 text-left">Visit #</th>
              <th class="p-2 text-left">Visit Date</th>
              <th class="p-2 text-left">Next Visit</th>
              <th class="p-2 text-left">Status</th>
              <th class="p-2 text-left">Notes</th>
              <th class="p-2 text-left">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($ancVisits as $v): ?>
              <?php
                $status = $v['status'] ?? 'PENDING';
                $isComplete = $status === 'COMPLETED';
              ?>
              <tr class="border-t border-slate-200">
                <td class="p-2"><?= htmlspecialchars($v['visit_number'] ?? '-') ?></td>
                <td class="p-2"><?= htmlspecialchars($v['visit_date'] ?? '-') ?></td>
                <td class="p-2"><?= htmlspecialchars($v['next_visit_date'] ?? '-') ?></td>
                <td class="p-2">
                  <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs
                    <?= $isComplete ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' ?>">
                    <?= htmlspecialchars($status) ?>
                  </span>
                </td>
                <?php
                  $ancNotes = $v['notes'] ?? '';
                  $ancNotesPlain = mch_crypto_decrypt(is_string($ancNotes) ? $ancNotes : '');
                  $ancNotesPlain = trim((string)$ancNotesPlain) !== '' ? (string)$ancNotesPlain : 'N/A';
                ?>
                <td class="p-2"><?= htmlspecialchars($ancNotesPlain) ?></td>
                <td class="p-2">
                  <button type="button"
                          class="px-3 py-1 rounded-lg border text-sm <?= $isComplete ? 'bg-slate-50 border-slate-200' : 'bg-emerald-50 border-emerald-200 text-emerald-700' ?>"
                          data-visit-id="<?= (int)$v['id'] ?>"
                          data-visit-action="<?= $isComplete ? 'undo' : 'complete' ?>">
                    <?= $isComplete ? 'Undo' : 'Attend Visit' ?>
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="mch-card bg-white/90 p-5 border border-slate-100 mb-6">
    <h3 class="text-lg font-semibold text-slate-900 mb-3">PNC Visits</h3>
    <?php if (empty($pncVisits)): ?>
      <p class="text-slate-500">No PNC visits recorded.</p>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="w-full text-sm mch-table">
          <thead>
            <tr>
              <th class="p-2 text-left">Visit Date</th>
              <th class="p-2 text-left">Baby</th>
              <th class="p-2 text-left">Status</th>
              <th class="p-2 text-left">Notes</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($pncVisits as $v): ?>
              <tr class="border-t border-slate-200">
                <td class="p-2"><?= htmlspecialchars($v['visit_date']) ?></td>
                <td class="p-2"><?= htmlspecialchars($v['baby_name'] ?? '-') ?></td>
                <td class="p-2"><?= htmlspecialchars($v['status'] ?? '-') ?></td>
                <?php
                  $pncNotes = $v['notes'] ?? '';
                  $pncNotesPlain = mch_crypto_decrypt(is_string($pncNotes) ? $pncNotes : '');
                  $pncNotesPlain = trim((string)$pncNotesPlain) !== '' ? (string)$pncNotesPlain : 'N/A';
                ?>
                <td class="p-2"><?= htmlspecialchars($pncNotesPlain) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="mch-card bg-white/90 p-5 border border-slate-100">
    <h3 class="text-lg font-semibold text-slate-900 mb-3">Babies</h3>
    <?php if (empty($babies)): ?>
      <p class="text-slate-500">No babies registered yet.</p>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="w-full text-sm mch-table">
          <thead>
            <tr>
              <th class="p-2 text-left">Baby Name</th>
              <th class="p-2 text-left">DOB</th>
              <th class="p-2 text-left">Gender</th>
              <th class="p-2 text-left">Birth Weight (kg)</th>
              <th class="p-2 text-left">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($babies as $b): ?>
              <tr class="border-t border-slate-200">
                <td class="p-2"><?= htmlspecialchars($b['baby_name'] ?? '-') ?></td>
                <td class="p-2"><?= htmlspecialchars($b['dob'] ?? '-') ?></td>
                <td class="p-2"><?= htmlspecialchars($b['gender'] ?? '-') ?></td>
                <td class="p-2"><?= htmlspecialchars($b['birth_weight'] ?? '-') ?></td>
                <td class="p-2">
                  <a class="inline-flex items-center px-3 py-1 rounded-lg bg-sky-50 border border-sky-200 hover:bg-sky-100 text-sm"
                     href="/mch_system/nurse_portal/baby_history.php?baby_id=<?= (int)$b['id'] ?>">
                    Health Records
                  </a>
                  <?php if ($supportsCardList && !empty($b['card_uid'])): ?>
                    <a class="inline-flex items-center px-3 py-1 rounded-lg bg-white border border-slate-200 hover:bg-slate-50 text-sm ml-2"
                       href="/mch_system/nurse_portal/print_baby_card.php?baby_id=<?= (int)$b['id'] ?>">
                      Print QR Sticker
                    </a>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
  (function () {
    const sel = document.getElementById('delivery_type');
    const cs = document.getElementById('cs_fields');
    if (!sel || !cs) return;
    const apply = () => {
      cs.classList.toggle('hidden', sel.value !== 'C-Section');
    };
    sel.addEventListener('change', apply);
    apply();
  })();
</script>

<script>
  async function postAncToggle(payload) {
    const res = await fetch('/mch_system/anc/toggle_anc.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const data = await res.json().catch(() => ({}));
    return { res, data };
  }

  document.querySelectorAll('[data-visit-id]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const id = btn.getAttribute('data-visit-id');
      const action = btn.getAttribute('data-visit-action');
      if (!id || !action) return;

      try {
        let { data } = await postAncToggle({ id, action });

        if (!data.success && data.code === 'EARLY' && action === 'complete') {
          const reason = prompt((data.error || 'This ANC visit is scheduled for a future date.') + "\n\nEnter override reason (min 10 characters) to mark as completed today:");
          if (!reason) return;
          ({ data } = await postAncToggle({ id, action, override: true, override_reason: reason }));
        }

        if (!data.success) {
          alert(data.error || 'Failed to update visit.');
          return;
        }
        window.location.reload();
      } catch (e) {
        alert('Network error while updating visit.');
      }
    });
  });
</script>

<?php include __DIR__ . "/../includes/footer.php"; ?>
