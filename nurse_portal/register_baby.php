<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/cards.php";
require_once __DIR__ . "/../config/epi.php";
require_once __DIR__ . "/../config/bcn.php";
require_once __DIR__ . "/../config/scope.php";
require_once __DIR__ . "/../config/csrf.php";
require_once __DIR__ . "/../config/audit.php";

$page = 'register_baby';
include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_nurse.php";

$error = '';
$success = '';
$createdBabyId = 0;
$createdCardUid = '';

$scopedClinicId = mch_scope_scoped_clinic_id();
if ((string)($_SESSION['role'] ?? '') === 'nurse' && $scopedClinicId <= 0) {
    $mothers = [];
    $error = "Your account is not assigned to a clinic. Please contact Admin.";
} elseif ($scopedClinicId > 0) {
    $stmt = $pdo->prepare("
        SELECT m.id, m.fullname, m.hospital_number, m.pregnancy_status, m.edd
        FROM mothers m
        JOIN users u ON u.id = m.user_id
        WHERE u.clinic_id = ?
        ORDER BY m.fullname
    ");
    $stmt->execute([$scopedClinicId]);
    $mothers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $mothers = $pdo->query("
        SELECT id, fullname, hospital_number, pregnancy_status, edd
        FROM mothers
        ORDER BY fullname
    ")->fetchAll(PDO::FETCH_ASSOC);
}

$supportsBcn = db_has_column($pdo, 'babies', 'birth_confirmation_number');
$hasDeliveriesTable = false;
try {
    $hasDeliveriesTable = (bool)$pdo->query("SHOW TABLES LIKE 'deliveries'")->fetchColumn();
} catch (Throwable $e) {
    $hasDeliveriesTable = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mch_csrf_require_valid('nurse_register_baby');
    $mother_id = (int)$_POST['mother_id'];
    $baby_name = trim($_POST['baby_name']);
    $dob       = $_POST['dob'];
    $gender    = $_POST['gender'];
    $birth_confirmation_number = trim((string)($_POST['birth_confirmation_number'] ?? ''));
    $autoAssignBcn = (string)($_POST['auto_assign_bcn'] ?? '') === '1';
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

            // Scope check: prevent logging births for mothers from other clinics.
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
                    throw new Exception("You can only register a baby for mothers in your clinic.");
                }
            }

            // Validate DOB (no future birth dates).
            $today = date('Y-m-d');
            if (!empty($dob) && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', (string)$dob)) {
                if ((string)$dob > $today) {
                    throw new Exception("Date of birth cannot be in the future.");
                }
            }

            // Delivery plausibility check (EDD / pregnancy status).
            $overrideDeliveryCheck = (string)($_POST['override_delivery_check'] ?? '') === '1';
            $overrideReason = trim((string)($_POST['override_reason'] ?? ''));

            $mStmt = $pdo->prepare("SELECT id, fullname, pregnancy_status, edd FROM mothers WHERE id = ? LIMIT 1");
            $mStmt->execute([$mother_id]);
            $mRow = $mStmt->fetch(PDO::FETCH_ASSOC);
            if (!$mRow) {
                throw new Exception("Mother record not found.");
            }

            $pregStatus = (string)($mRow['pregnancy_status'] ?? '');
            $edd = (string)($mRow['edd'] ?? '');
            $needsOverride = false;
            $why = '';

            // If a delivery was already logged, allow baby registration even if pregnancy_status/EDD wasn't updated yet.
            $hasLiveDeliveryLogged = false;
            if ($hasDeliveriesTable) {
                try {
                    $dStmt = $pdo->prepare("
                        SELECT delivery_date, outcome
                        FROM deliveries
                        WHERE mother_id = ?
                        ORDER BY delivery_date DESC
                        LIMIT 1
                    ");
                    $dStmt->execute([$mother_id]);
                    $dRow = $dStmt->fetch(PDO::FETCH_ASSOC);
                    if ($dRow) {
                        $deliveryDate = (string)($dRow['delivery_date'] ?? '');
                        $deliveryOutcome = strtoupper((string)($dRow['outcome'] ?? ''));

                        // Baby registration/EPI schedule is for live births only.
                        if (in_array($deliveryOutcome, ['FSB', 'MSB', 'STILLBIRTH', 'NEONATAL_DEATH', 'MATERNAL_DEATH'], true)) {
                            throw new Exception("Cannot register baby: latest delivery outcome is {$deliveryOutcome}. Baby registration/EPI is for live births only.");
                        }
                        if ($deliveryOutcome === 'LIVE' && $deliveryDate !== '' && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $deliveryDate) && $deliveryDate <= $today) {
                            $hasLiveDeliveryLogged = true;
                        }
                    }
                } catch (Throwable $e) {
                    // If we intentionally blocked registration (e.g., non-live outcome), propagate the message.
                    if ($e instanceof Exception) {
                        throw $e;
                    }
                    $hasLiveDeliveryLogged = false;
                }
            }

            if (!$hasLiveDeliveryLogged) {
                if ($pregStatus === 'Pregnant') {
                    $needsOverride = true;
                    $why = "Mother is still marked as Pregnant.";
                    if ($edd !== '' && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $edd)) {
                        if ($edd > $today) {
                            $why = "Mother EDD is {$edd} (in the future).";
                        } else {
                            $why = "Mother is marked Pregnant (EDD {$edd}).";
                        }
                    }
                } elseif ($edd !== '' && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $edd) && $edd > $today) {
                    // Catch inconsistent data: non-pregnant status but future EDD still set.
                    $needsOverride = true;
                    $why = "Mother EDD is {$edd} (in the future).";
                }
            }

            if ($needsOverride && !$overrideDeliveryCheck) {
                throw new Exception("Cannot register baby: {$why} Please log delivery / update the mother record first, or use the override (with a reason).");
            }
            if ($needsOverride && $overrideDeliveryCheck) {
                if (mb_strlen($overrideReason) < 10) {
                    throw new Exception("Override reason is required (at least 10 characters).");
                }
                mch_audit_log(
                    $pdo,
                    'BABY_OVERRIDE_DELIVERY_CHECK',
                    'nurse_portal',
                    $mother_id,
                    "Override delivery check: mother_id={$mother_id}, status={$pregStatus}, edd={$edd}, reason={$overrideReason}"
                );
            }

 	        // Insert baby
 	        $card_uid = null;

            if ($supportsBcn && $autoAssignBcn) {
                $clinicId = (int)($_SESSION['clinic_id'] ?? 0);
                $birth_confirmation_number = mch_bcn_assign_from_active_book($pdo, $clinicId);
            }

            if ($supportsBcn && $birth_confirmation_number !== '') {
                $dup = $pdo->prepare("SELECT id FROM babies WHERE birth_confirmation_number = ? LIMIT 1");
                $dup->execute([$birth_confirmation_number]);
                if ($dup->fetch(PDO::FETCH_ASSOC)) {
                    throw new Exception("Birth Confirmation Number already exists.");
                }
            }

	        $supportsCard = babies_supports_card_uid($pdo);
	        if ($supportsCard) {
	            $card_uid = generate_card_uid();
	        }

            if ($supportsCard && $supportsBcn) {
	            $stmt = $pdo->prepare("
	                INSERT INTO babies
	                (mother_id, baby_name, dob, gender, birth_weight, card_uid, birth_confirmation_number, created_at)
	                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
	            ");
	            $stmt->execute([$mother_id, $baby_name, $dob, $gender, $birth_weight, $card_uid, ($birth_confirmation_number !== '' ? $birth_confirmation_number : null)]);
            } elseif ($supportsCard && !$supportsBcn) {
	            $stmt = $pdo->prepare("
	                INSERT INTO babies
	                (mother_id, baby_name, dob, gender, birth_weight, card_uid, created_at)
	                VALUES (?, ?, ?, ?, ?, ?, NOW())
	            ");
	            $stmt->execute([$mother_id, $baby_name, $dob, $gender, $birth_weight, $card_uid]);
            } elseif (!$supportsCard && $supportsBcn) {
	            $stmt = $pdo->prepare("
	                INSERT INTO babies
	                (mother_id, baby_name, dob, gender, birth_weight, birth_confirmation_number, created_at)
	                VALUES (?, ?, ?, ?, ?, ?, NOW())
	            ");
	            $stmt->execute([$mother_id, $baby_name, $dob, $gender, $birth_weight, ($birth_confirmation_number !== '' ? $birth_confirmation_number : null)]);
            } else {
	            $stmt = $pdo->prepare("
	                INSERT INTO babies
	                (mother_id, baby_name, dob, gender, birth_weight, created_at)
	                VALUES (?, ?, ?, ?, ?, NOW())
	            ");
	            $stmt->execute([$mother_id, $baby_name, $dob, $gender, $birth_weight]);
            }

	        $baby_id = $pdo->lastInsertId();

        // Generate Immunization Schedule (Zimbabwe EPI)
        mch_epi_insert_schedule($pdo, (int)$baby_id, (string)$dob);

	        $pdo->commit();
	        $success = "Baby registered and immunization schedule created successfully!";
	        if ($card_uid) {
	            $success .= " Card ID: " . format_card_uid($card_uid);
                $createdCardUid = (string)$card_uid;
	        } else {
	            $success .= " (Baby card is disabled: run sql/baby_card_uid.sql to enable card_uid.)";
	        }
            $createdBabyId = (int)$baby_id;
	    } catch (Exception $e) {
	        $pdo->rollBack();
	        $error = "Error: " . $e->getMessage();
	    }
}
?>

<div class="ml-64 p-6">
  <div class="mch-card bg-white/90 p-6 border border-slate-100 max-w-3xl">
    <h2 class="text-2xl font-semibold text-slate-900 mb-4">Register Baby</h2>

    <?php if ($error): ?>
      <div class="bg-rose-100 text-rose-700 p-3 rounded-lg mb-4"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
      <div class="bg-emerald-100 text-emerald-700 p-3 rounded-lg mb-4">
        <?= htmlspecialchars($success) ?>
        <?php if ($createdBabyId > 0): ?>
          <div class="mt-3 flex flex-wrap gap-2">
            <a class="inline-flex items-center px-3 py-2 rounded-lg bg-white border border-emerald-200 hover:bg-emerald-50 text-sm"
               href="/mch_system/nurse_portal/baby_history.php?baby_id=<?= (int)$createdBabyId ?>">
              Open Baby Record
            </a>
            <?php if ($createdCardUid !== ''): ?>
              <a class="inline-flex items-center px-3 py-2 rounded-lg bg-white border border-emerald-200 hover:bg-emerald-50 text-sm"
                 href="/mch_system/nurse_portal/print_baby_card.php?baby_id=<?= (int)$createdBabyId ?>">
                Print QR Sticker
              </a>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <form method="POST" class="space-y-4" id="register_baby_form">
      <?= mch_csrf_input('nurse_register_baby') ?>
      <div>
        <label class="block text-sm font-semibold text-slate-700 mb-1">Mother</label>
        <select id="mother_select" name="mother_id" required class="w-full border border-slate-200 p-2 rounded-lg bg-white">
          <option value="">Select Mother</option>
          <?php foreach ($mothers as $m): ?>
            <option value="<?= (int)$m['id'] ?>"
                    data-pregnancy-status="<?= htmlspecialchars((string)($m['pregnancy_status'] ?? ''), ENT_QUOTES) ?>"
                    data-edd="<?= htmlspecialchars((string)($m['edd'] ?? ''), ENT_QUOTES) ?>">
              <?= htmlspecialchars($m['fullname']) ?> (<?= $m['hospital_number'] ?>)
            </option>
          <?php endforeach; ?>
        </select>
        <div id="mother_delivery_hint" class="hidden mt-2 text-xs rounded-lg border border-amber-200 bg-amber-50 text-amber-800 px-3 py-2"></div>
      </div>

      <div>
        <label class="block text-sm font-semibold text-slate-700 mb-1">Baby Name</label>
        <input type="text" name="baby_name" required class="w-full border border-slate-200 p-2 rounded-lg bg-white">
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">Date of Birth</label>
          <input type="date" name="dob" required class="w-full border border-slate-200 p-2 rounded-lg bg-white">
        </div>
        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">Gender</label>
          <select name="gender" required class="w-full border border-slate-200 p-2 rounded-lg bg-white">
            <option value="">Select Gender</option>
            <option value="Male">Male</option>
            <option value="Female">Female</option>
          </select>
        </div>
      </div>

      <div>
        <label class="block text-sm font-semibold text-slate-700 mb-1">Birth Confirmation # (optional)</label>
        <input type="text" name="birth_confirmation_number" class="w-full border border-slate-200 p-2 rounded-lg bg-white"
               value="<?= htmlspecialchars($birth_confirmation_number ?? '') ?>"
               placeholder="e.g. BCN12345">
        <?php if (!$supportsBcn): ?>
          <p class="text-xs text-amber-700 mt-1">To enable this, run <span class="font-mono">sql/birth_confirmation_number.sql</span>.</p>
        <?php else: ?>
          <label class="mt-2 inline-flex items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" name="auto_assign_bcn" value="1" class="w-4 h-4" <?= !empty($_POST) ? ($autoAssignBcn ? 'checked' : '') : 'checked' ?>>
            Auto-assign from clinic BCN book
          </label>
          <p class="text-xs text-slate-500 mt-1">Uses the active serial book configured for your clinic (Admin → Birth Confirmation Books).</p>
        <?php endif; ?>
      </div>

      <div>
        <label class="block text-sm font-semibold text-slate-700 mb-1">Birth Weight (kg)</label>
        <input type="number" step="0.01" min="0" max="5" name="birth_weight" required class="w-full border border-slate-200 p-2 rounded-lg bg-white">
      </div>

      <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
        <label class="inline-flex items-center gap-2 text-sm text-slate-700">
          <input id="override_delivery_check" type="checkbox" name="override_delivery_check" value="1" class="w-4 h-4">
          Override delivery/EDD check (requires reason)
        </label>
        <div class="mt-3">
          <label class="block text-sm font-semibold text-slate-700 mb-1">Override reason</label>
          <textarea id="override_reason" name="override_reason" rows="2" class="w-full border border-slate-200 p-2 rounded-lg bg-white" placeholder="E.g. Delivered at home; EDD not updated yet."></textarea>
          <p class="text-xs text-slate-500 mt-1">Use only when needed. This action is logged.</p>
        </div>
      </div>

      <button type="submit" class="mch-btn w-full py-2">Register Baby</button>
    </form>

    <div id="baby_draft_hint" class="hidden mt-4 text-xs rounded-lg border border-sky-200 bg-sky-50 text-sky-800 px-3 py-2">
      Draft restored. <button type="button" id="baby_draft_clear" class="underline font-semibold">Clear draft</button>
    </div>
  </div>
</div>

<script>
  (function () {
    const select = document.getElementById('mother_select');
    const hint = document.getElementById('mother_delivery_hint');
    const overrideCb = document.getElementById('override_delivery_check');
    const overrideReason = document.getElementById('override_reason');
    if (!select || !hint || !overrideCb || !overrideReason) return;

    const today = new Date();
    const todayIso = new Date(today.getFullYear(), today.getMonth(), today.getDate()).toISOString().slice(0, 10);

    function update() {
      const opt = select.options[select.selectedIndex];
      const status = (opt && opt.dataset && opt.dataset.pregnancyStatus) ? opt.dataset.pregnancyStatus : '';
      const edd = (opt && opt.dataset && opt.dataset.edd) ? opt.dataset.edd : '';

      const eddLooksIso = /^\\d{4}-\\d{2}-\\d{2}$/.test(edd);
      const eddFuture = eddLooksIso && edd > todayIso;
      const needsOverride = (status === 'Pregnant') || eddFuture;

      if (!opt || !opt.value) {
        hint.classList.add('hidden');
        hint.textContent = '';
        return;
      }

      if (needsOverride) {
        hint.classList.remove('hidden');
        hint.textContent = eddFuture
          ? `Warning: Mother EDD is ${edd} (in the future). Normally, log delivery / update the mother first. You can use the override with a reason if needed.`
          : `Warning: Mother is marked as Pregnant. Normally, log delivery / update the mother first. You can use the override with a reason if needed.`;
      } else {
        hint.classList.add('hidden');
        hint.textContent = '';
      }
    }

    overrideCb.addEventListener('change', () => {
      overrideReason.required = overrideCb.checked;
      if (!overrideCb.checked) overrideReason.value = '';
    });

    select.addEventListener('change', update);
    update();
  })();
</script>

<script>
  (function () {
    const form = document.getElementById('register_baby_form');
    if (!form) return;
    const key = 'mch_draft_register_baby_v1';
    const hint = document.getElementById('baby_draft_hint');
    const clearBtn = document.getElementById('baby_draft_clear');

    const fields = Array.from(form.querySelectorAll('input, select, textarea'))
      .filter(el => el.name && el.type !== 'hidden' && el.type !== 'password' && el.type !== 'submit' && el.type !== 'button');

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

<?php include __DIR__ . "/../includes/footer.php"; ?>
