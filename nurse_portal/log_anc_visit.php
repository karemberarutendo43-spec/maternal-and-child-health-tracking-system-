<?php
require_once '../config/db.php';
require_once '../config/auth.php';
require_once __DIR__ . "/../config/audit.php";
require_once __DIR__ . "/../config/sms.php";
require_once __DIR__ . "/../config/cards.php";
require_once __DIR__ . "/../config/crypto.php";
require_once __DIR__ . "/../config/scope.php";
require_once __DIR__ . "/../config/csrf.php";

// Access check
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'nurse'])) {
    header("Location: /mch_system/auth/login.php");
    exit;
}

$page = 'log_anc_visit';
include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_nurse.php";

$returnMotherId = (int)($_GET['mother_id'] ?? 0);
$backHref = $returnMotherId > 0
    ? "/mch_system/nurse_portal/anc_visits.php?mother_id={$returnMotherId}"
    : "/mch_system/nurse_portal/mother_list.php";

$error = '';
$success = '';

$scopedClinicId = mch_scope_scoped_clinic_id();
if ((string)($_SESSION['role'] ?? '') === 'nurse' && $scopedClinicId <= 0) {
    http_response_code(403);
    $error = "Your account is not assigned to a clinic. Please contact Admin.";
}

// Fetch all mothers who are currently pregnant
$mothers = [];
try {
    if ($scopedClinicId > 0) {
        $stmt = $pdo->prepare("
            SELECT m.id, m.fullname, m.hospital_number
            FROM mothers m
            JOIN users u ON u.id = m.user_id
            WHERE m.pregnancy_status = 'Pregnant'
              AND u.clinic_id = ?
            ORDER BY m.fullname
        ");
        $stmt->execute([$scopedClinicId]);
        $mothers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $mothers = $pdo->query("
            SELECT id, fullname, hospital_number
            FROM mothers
            WHERE pregnancy_status = 'Pregnant'
            ORDER BY fullname
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $mothers = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mch_csrf_require_valid('nurse_log_anc_visit');

    $mother_id = (int)($_POST['mother_id'] ?? 0);
    $visit_number = (int)($_POST['visit_number'] ?? 0);
    $visit_date = trim((string)($_POST['visit_date'] ?? ''));
    $overrideEarly = !empty($_POST['override_early']);
    $overrideReason = trim((string)($_POST['override_reason'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));
    if ($notes === '' || preg_match('/^(n\\s*\\/\\s*a|na)$/i', $notes)) {
        $notes = null;
    }

    if ($mother_id <= 0 || $visit_number < 1 || $visit_number > 8 || $visit_date === '') {
        $error = "Please fill all required fields.";
    }

    $today = new DateTimeImmutable('today');
    $visitDateObj = null;
    if ($error === '') {
        try {
            $visitDateObj = new DateTimeImmutable($visit_date);
        } catch (Throwable $e) {
            $error = "Invalid visit date.";
        }
    }

    if ($error === '' && $visitDateObj instanceof DateTimeImmutable && $visitDateObj > $today) {
        $error = "Visit date cannot be in the future.";
    }

    // Scope check (nurses must only log for their clinic)
    if ($error === '' && $scopedClinicId > 0) {
        $scopeStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM mothers m
            JOIN users u ON u.id = m.user_id
            WHERE m.id = ? AND u.clinic_id = ?
            LIMIT 1
        ");
        $scopeStmt->execute([$mother_id, $scopedClinicId]);
        if ((int)$scopeStmt->fetchColumn() !== 1) {
            $error = "403 Forbidden: You do not have access to this mother record.";
        }
    }

    $scheduledId = 0;
    $scheduledDateRaw = '';
    if ($error === '') {
        $schedStmt = $pdo->prepare("
            SELECT id, visit_date
            FROM anc_visits
            WHERE mother_id = ? AND visit_number = ?
            ORDER BY id ASC
            LIMIT 1
        ");
        $schedStmt->execute([$mother_id, $visit_number]);
        $sched = $schedStmt->fetch(PDO::FETCH_ASSOC);
        if ($sched) {
            $scheduledId = (int)($sched['id'] ?? 0);
            $scheduledDateRaw = trim((string)($sched['visit_date'] ?? ''));
        }
    }

    if ($error === '' && $visitDateObj instanceof DateTimeImmutable && $scheduledDateRaw !== '' && $scheduledDateRaw !== '0000-00-00') {
        try {
            $scheduledDateObj = new DateTimeImmutable($scheduledDateRaw);
            if ($visitDateObj < $scheduledDateObj) {
                if (!$overrideEarly) {
                    $error = "This ANC visit is scheduled for {$scheduledDateRaw}. Check 'Override early visit' to record it early.";
                } elseif (mb_strlen($overrideReason) < 10) {
                    $error = "Override reason must be at least 10 characters.";
                }
            }
        } catch (Throwable $e) {
            // ignore schedule parse issues
        }
    }

    $notesEnc = $notes !== null ? mch_crypto_encrypt($notes) : null;

    try {
        if ($error !== '') {
            throw new Exception($error);
        }

        // Calculate next ANC visit (28 days later)
        $next_visit_date = $visitDateObj ? $visitDateObj->modify('+28 days')->format('Y-m-d') : date('Y-m-d', strtotime($visit_date . ' +28 days'));

        $supportsClinical = db_has_column($pdo, 'anc_visits', 'clinical_status');
        if ($scheduledId > 0) {
            $stmt = $pdo->prepare($supportsClinical
                ? "UPDATE anc_visits SET visit_date = ?, notes = ?, status = 'COMPLETED', clinical_status = 1, next_visit_date = ? WHERE id = ? LIMIT 1"
                : "UPDATE anc_visits SET visit_date = ?, notes = ?, status = 'COMPLETED', next_visit_date = ? WHERE id = ? LIMIT 1"
            );
            $supportsClinical
                ? $stmt->execute([$visit_date, $notesEnc, $next_visit_date, $scheduledId])
                : $stmt->execute([$visit_date, $notesEnc, $next_visit_date, $scheduledId]);

            if ($overrideEarly && $overrideReason !== '') {
                mch_audit_log(
                    $pdo,
                    'ANC_OVERRIDE_EARLY',
                    'anc_visits',
                    $scheduledId,
                    "Early ANC visit logged: mother_id={$mother_id}; visit_number={$visit_number}; scheduled_date={$scheduledDateRaw}; actual_date={$visit_date}; reason={$overrideReason}"
                );
            }

            mch_audit_log(
                $pdo,
                'ANC_LOG',
                'anc_visits',
                $scheduledId,
                "ANC visit logged (updated schedule): mother_id={$mother_id}; visit_number={$visit_number}; visit_date={$visit_date}; next_visit_date={$next_visit_date}"
            );
        } else {
            $stmt = $pdo->prepare($supportsClinical ? "
                INSERT INTO anc_visits
                (mother_id, visit_number, visit_date, notes, status, clinical_status, next_visit_date, created_at)
                VALUES (?, ?, ?, ?, 'COMPLETED', 1, ?, NOW())
            " : "
                INSERT INTO anc_visits
                (mother_id, visit_number, visit_date, notes, status, next_visit_date, created_at)
                VALUES (?, ?, ?, ?, 'COMPLETED', ?, NOW())
            ");
            $stmt->execute([
                $mother_id,
                $visit_number,
                $visit_date,
                $notesEnc,
                $next_visit_date
            ]);
            $newId = (int)$pdo->lastInsertId();

            mch_audit_log(
                $pdo,
                'ANC_LOG',
                'anc_visits',
                $newId,
                "ANC visit logged (new record): mother_id={$mother_id}; visit_number={$visit_number}; visit_date={$visit_date}; next_visit_date={$next_visit_date}"
            );
        }

        $date_label = date('d M Y', strtotime($visit_date));
        $month_label = date('F Y', strtotime($visit_date));
        // Send SMS reminder (placeholder)
        $motherPhoneStmt = $pdo->prepare("SELECT phone, fullname FROM mothers WHERE id = ? LIMIT 1");
        $motherPhoneStmt->execute([$mother_id]);
        $mrow = $motherPhoneStmt->fetch(PDO::FETCH_ASSOC);
        if ($mrow && !empty($mrow['phone'])) {
            $msg = "Mhoro Mai {$mrow['fullname']}, reminder of your ANC visit on {$next_visit_date}.";
            sendSms($mrow['phone'], $msg);
        }

        $success = "ANC visit logged successfully on $date_label ($month_label). Next visit scheduled for $next_visit_date.";
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}
?>

<style>
  .float-group { position: relative; }
  .float-field { width: 100%; padding: 0.75rem; border: 1px solid #cbd5e1; border-radius: 0.5rem; background: white; }
  .float-label {
    position: absolute;
    left: 0.75rem;
    top: 0.75rem;
    color: #64748b;
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
    color: #475569;
  }

  /* Dark mode: keep floating labels readable and avoid border overlap. */
  html.dark .float-label {
    background: rgba(15, 23, 42, 0.88);
    color: #cbd5e1;
  }
  html.dark .float-field:focus + .float-label,
  html.dark .float-field[data-has-value="true"] + .float-label,
  html.dark .float-group:hover .float-label {
    color: #e2e8f0;
  }
</style>

<div class="ml-64 p-6 max-w-3xl">
    <div class="mch-card bg-white/90 p-6 border border-slate-100">
        <div class="flex items-center justify-between gap-4">
            <h2 class="text-2xl font-semibold text-slate-900 mb-4">Log ANC Visit</h2>
            <a href="<?= htmlspecialchars($backHref) ?>" class="mt-2 px-3 py-1.5 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 text-sm inline-flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
                Back to ANC Visits
            </a>
        </div>

        <?php if ($error): ?>
            <div class="bg-rose-100 text-rose-700 p-3 rounded-lg mb-4"><?= $error ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="bg-emerald-100 text-emerald-700 p-3 rounded-lg mb-4"><?= $success ?></div>
        <?php endif; ?>

        <form method="POST" class="space-y-4">
            <?= mch_csrf_input('nurse_log_anc_visit') ?>

            <div class="float-group">
                <select name="mother_id" required class="float-field field" id="mother_id">
                    <option value="" disabled selected></option>
                    <?php foreach ($mothers as $m): ?>
                        <option value="<?= $m['id'] ?>">
                            <?= htmlspecialchars($m['fullname']) ?> (<?= $m['hospital_number'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <label class="float-label" for="mother_id">Mother (Pregnant)</label>
            </div>

            <div class="float-group">
                <select name="visit_number" required class="float-field field" id="visit_number">
                    <option value="" disabled selected></option>
                    <?php for ($i = 1; $i <= 8; $i++): ?>
                        <option value="<?= $i ?>">ANC <?= $i ?></option>
                    <?php endfor; ?>
                </select>
                <label class="float-label" for="visit_number">ANC Visit Number</label>
            </div>

            <div class="float-group">
                <input type="date" name="visit_date" required class="float-field field" id="visit_date" placeholder=" ">
                <label class="float-label" for="visit_date">Visit Date</label>
            </div>

            <div class="border border-slate-200 rounded-lg p-3 bg-slate-50">
                <label class="inline-flex items-center gap-2 text-sm text-slate-700 select-none">
                    <input type="checkbox" name="override_early" value="1" class="rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                    Override early visit (requires reason)
                </label>
                <textarea name="override_reason" class="mt-2 float-field field" id="override_reason" placeholder="Reason (min 10 characters)"></textarea>
                <p class="text-xs text-slate-500 mt-1">Only use this if you are recording an ANC visit earlier than the scheduled date.</p>
            </div>

            <div class="float-group">
                <div class="flex items-center justify-between mb-1">
                    <span class="text-xs text-slate-500">Quick fill</span>
                    <button type="button" class="text-xs px-2 py-1 rounded-lg border border-slate-200 bg-slate-50 hover:bg-slate-100"
                            id="notes_na_btn">N/A</button>
                </div>
                <textarea name="notes" class="float-field field" id="notes" placeholder=" "></textarea>
                <label class="float-label" for="notes">Notes (Optional)</label>
            </div>

            <button type="submit" class="mch-btn w-full py-2">Save ANC Visit</button>
        </form>
    </div>
</div>

<?php include __DIR__ . "/../includes/footer.php"; ?>

<script>
  const floatFields = document.querySelectorAll(".field");

  function updateFloatField(field) {
    const hasValue = field.value && field.value.toString().trim() !== '';
    field.dataset.hasValue = hasValue ? 'true' : 'false';
  }

  floatFields.forEach((field) => {
    updateFloatField(field);
    field.addEventListener("input", () => updateFloatField(field));
    field.addEventListener("change", () => updateFloatField(field));
    field.addEventListener("blur", () => updateFloatField(field));
  });

  const notesBtn = document.getElementById('notes_na_btn');
  const notesField = document.getElementById('notes');
  notesBtn?.addEventListener('click', () => {
    if (!notesField) return;
    notesField.value = 'N/A';
    updateFloatField(notesField);
    try { notesField.focus({ preventScroll: true }); } catch (e) {}
  });
</script>
