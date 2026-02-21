<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/scope.php";
require_once __DIR__ . "/../config/audit.php";
require_once __DIR__ . "/../config/cards.php";
require_once __DIR__ . "/../config/csrf.php";

// Access check
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'nurse'], true)) {
    header("Location: /mch_system/auth/login.php");
    exit;
}

$page = 'log_family_planning';
include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_nurse.php";

$error = '';
$success = '';

$scopedClinicId = mch_scope_scoped_clinic_id();
if ((string)($_SESSION['role'] ?? '') === 'nurse' && $scopedClinicId <= 0) {
    http_response_code(403);
    $error = "Your account is not assigned to a clinic. Please contact Admin.";
}

$supportsDeliveryDate = db_has_column($pdo, 'mothers', 'delivery_date');
$deliverySelect = $supportsDeliveryDate ? ", m.delivery_date" : "";
$mothers = [];
try {
    if ($scopedClinicId > 0) {
        $stmt = $pdo->prepare("
            SELECT m.id, m.fullname, m.hospital_number, m.pregnancy_status{$deliverySelect}
            FROM mothers m
            JOIN users u ON u.id = m.user_id
            WHERE u.clinic_id = ?
            ORDER BY m.fullname
        ");
        $stmt->execute([$scopedClinicId]);
        $mothers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $mothers = $pdo->query("
            SELECT id, fullname, hospital_number, pregnancy_status{$deliverySelect}
            FROM mothers
            ORDER BY fullname
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $mothers = [];
}

// For UI warnings (one ACTIVE record per mother).
$activeFpByMother = [];
try {
    $activeRows = $pdo->query("
        SELECT id, mother_id, method, start_date
        FROM family_planning
        WHERE status = 'ACTIVE'
        ORDER BY mother_id ASC, start_date DESC, id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($activeRows as $r) {
        $mid = (int)($r['mother_id'] ?? 0);
        if ($mid <= 0) continue;
        if (!isset($activeFpByMother[$mid])) {
            $activeFpByMother[$mid] = [
                'id' => (int)($r['id'] ?? 0),
                'method' => (string)($r['method'] ?? ''),
                'start_date' => (string)($r['start_date'] ?? ''),
            ];
        }
    }
} catch (Throwable $e) {
    $activeFpByMother = [];
}

$methods = [
    'Implant',
    'Injection',
    'Pill',
    'IUD',
    'Condom',
    'Natural',
    'Other'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mch_csrf_require_valid('nurse_log_family_planning');
    $mother_id = (int)($_POST['mother_id'] ?? 0);
    $method = trim((string)($_POST['method'] ?? ''));
    $other_method = trim((string)($_POST['other_method'] ?? ''));
    $start_date = trim((string)($_POST['start_date'] ?? ''));
    $end_date = trim((string)($_POST['end_date'] ?? ''));
    $endExisting = !empty($_POST['end_existing_active']);
    $endExistingReason = trim((string)($_POST['end_existing_reason'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));
    if ($notes === '' || preg_match('/^(n\\s*\\/\\s*a|na)$/i', $notes)) {
        $notes = null;
    }

    if (!$mother_id || !$method || !$start_date) {
        $error = "Mother, method, and start date are required.";
    } else {
        try {
            if ($method === 'Other') {
                if ($other_method === '') {
                    throw new Exception("Please specify the other method.");
                }
                if ($notes === null || mb_strlen(trim((string)$notes)) < 5) {
                    throw new Exception("Please add a short note (min 5 characters) when using 'Other'.");
                }
                $method = $other_method;
            }

            // Validate start date
            $today = new DateTimeImmutable('today');
            try {
                $startObj = new DateTimeImmutable($start_date);
            } catch (Throwable $e) {
                throw new Exception("Invalid start date.");
            }
            if ($startObj > $today) {
                throw new Exception("Start date cannot be in the future.");
            }

            // Validate mother + scope + pregnancy rules
            if ($scopedClinicId > 0) {
                $stmt = $pdo->prepare("
                    SELECT m.id, m.fullname, m.pregnancy_status{$deliverySelect}
                    FROM mothers m
                    JOIN users u ON u.id = m.user_id
                    WHERE m.id = ? AND u.clinic_id = ?
                    LIMIT 1
                ");
                $stmt->execute([$mother_id, $scopedClinicId]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT m.id, m.fullname, m.pregnancy_status{$deliverySelect}
                    FROM mothers m
                    WHERE m.id = ?
                    LIMIT 1
                ");
                $stmt->execute([$mother_id]);
            }
            $mother = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$mother) {
                throw new Exception("Mother not found (or you don't have access).");
            }

            $pregStatus = (string)($mother['pregnancy_status'] ?? '');
            $allowedWhilePregnant = ['Condom', 'Natural'];
            if ($pregStatus === 'Pregnant' && !in_array($method, $allowedWhilePregnant, true)) {
                throw new Exception("For pregnant mothers, only Condom or Natural methods are allowed. Start other methods after delivery.");
            }

            if ($supportsDeliveryDate && $pregStatus === 'Postnatal') {
                $delivery = trim((string)($mother['delivery_date'] ?? ''));
                if ($delivery !== '' && $delivery !== '0000-00-00') {
                    try {
                        $deliveryObj = new DateTimeImmutable($delivery);
                        if ($startObj < $deliveryObj) {
                            throw new Exception("Start date cannot be before delivery date ({$delivery}).");
                        }
                    } catch (Throwable $e) {
                        // ignore parse issues
                    }
                }
            }

            // If there's an ACTIVE FP record, require ending it first.
            $activeStmt = $pdo->prepare("
                SELECT id, method, start_date
                FROM family_planning
                WHERE mother_id = ? AND status = 'ACTIVE'
                ORDER BY start_date DESC, id DESC
                LIMIT 1
            ");
            $activeStmt->execute([$mother_id]);
            $existingActive = $activeStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($existingActive) {
                if (!$endExisting) {
                    $m = (string)($existingActive['method'] ?? '');
                    $sd = (string)($existingActive['start_date'] ?? '');
                    throw new Exception("This mother already has an ACTIVE family planning record ({$m} started {$sd}). Check 'End existing ACTIVE method' to continue.");
                }
                if (mb_strlen($endExistingReason) < 10) {
                    throw new Exception("End-existing reason must be at least 10 characters.");
                }

                $existingId = (int)($existingActive['id'] ?? 0);
                $endDateForExisting = $start_date;
                $endStmt = $pdo->prepare("
                    UPDATE family_planning
                    SET status = 'ENDED', end_date = ?
                    WHERE id = ? AND mother_id = ? AND status = 'ACTIVE'
                    LIMIT 1
                ");
                $endStmt->execute([$endDateForExisting, $existingId, $mother_id]);

                mch_audit_log(
                    $pdo,
                    'FP_END',
                    'family_planning',
                    $existingId,
                    "Ended ACTIVE family planning: mother_id={$mother_id}; end_date={$endDateForExisting}; reason={$endExistingReason}"
                );
            }

            $stmt = $pdo->prepare("
                INSERT INTO family_planning (mother_id, method, start_date, end_date, status, notes, created_at)
                VALUES (?, ?, ?, ?, 'ACTIVE', ?, NOW())
            ");
            $stmt->execute([
                $mother_id,
                $method,
                $start_date,
                $end_date !== '' ? $end_date : null,
                $notes
            ]);

            $newId = (int)$pdo->lastInsertId();
            mch_audit_log(
                $pdo,
                'FP_CREATE',
                'family_planning',
                $newId,
                "Family planning created: mother_id={$mother_id}; method={$method}; start_date={$start_date}" . ($end_date !== '' ? "; end_date={$end_date}" : "")
            );
            $success = "Family planning record saved.";
        } catch (Exception $e) {
            $error = "Error: " . $e->getMessage();
        }
    }
}
?>

<div class="ml-64 p-6 max-w-3xl">
    <div class="mch-card bg-white/90 p-6 border border-slate-100">
        <h2 class="text-xl font-semibold text-slate-900 mb-4">Family Planning</h2>

        <?php if ($error): ?>
            <div class="bg-rose-100 text-rose-700 p-3 rounded-lg mb-4"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="bg-emerald-100 text-emerald-700 p-3 rounded-lg mb-4"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <form method="POST" class="space-y-4">
            <?= mch_csrf_input('nurse_log_family_planning') ?>
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1">Mother</label>
                <select name="mother_id" id="mother_id" required class="w-full border border-slate-200 p-2 rounded-lg bg-white">
                    <option value="">Select Mother</option>
                    <?php foreach ($mothers as $m): ?>
                        <option value="<?= (int)$m['id'] ?>"
                                data-status="<?= htmlspecialchars((string)($m['pregnancy_status'] ?? '')) ?>"
                                <?= $supportsDeliveryDate ? 'data-delivery-date="' . htmlspecialchars((string)($m['delivery_date'] ?? '')) . '"' : '' ?>>
                            <?= htmlspecialchars($m['fullname']) ?> (<?= htmlspecialchars($m['hospital_number']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="text-xs text-slate-500 mt-1" id="mother_status_hint"></p>
            </div>

            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1">Method</label>
                <select name="method" id="method" required class="w-full border border-slate-200 p-2 rounded-lg bg-white">
                    <option value="">Select Method</option>
                    <?php foreach ($methods as $m): ?>
                        <option value="<?= htmlspecialchars($m) ?>"><?= htmlspecialchars($m) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="text-xs text-slate-500 mt-1" id="method_hint"></p>
            </div>

            <div id="other_method_wrap" class="hidden">
                <label class="block text-sm font-semibold text-slate-700 mb-1">Other Method</label>
                <input type="text" name="other_method" class="w-full border border-slate-200 p-2 rounded-lg bg-white">
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1">Start Date</label>
                    <input type="date" name="start_date" id="start_date" required class="w-full border border-slate-200 p-2 rounded-lg bg-white">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1">End Date (optional)</label>
                    <input type="date" name="end_date" class="w-full border border-slate-200 p-2 rounded-lg bg-white">
                </div>
            </div>

            <div id="active_fp_wrap" class="hidden border border-amber-200 bg-amber-50 rounded-lg p-3 text-sm text-amber-800">
                <div class="font-semibold mb-1">Active family planning exists</div>
                <div id="active_fp_text" class="text-amber-800"></div>
                <label class="mt-2 inline-flex items-center gap-2">
                    <input type="checkbox" name="end_existing_active" value="1" class="rounded border-amber-300 text-amber-600 focus:ring-amber-500">
                    End existing ACTIVE method and create new
                </label>
                <textarea name="end_existing_reason" id="end_existing_reason" class="w-full mt-2 border border-amber-200 p-2 rounded-lg bg-white" rows="2" placeholder="Reason (min 10 characters)"></textarea>
            </div>

            <div>
                <div class="flex items-center justify-between mb-1">
                    <label class="block text-sm font-semibold text-slate-700">Notes</label>
                    <button type="button" class="text-xs px-2 py-1 rounded-lg border border-slate-200 bg-slate-50 hover:bg-slate-100"
                            id="fp_notes_na_btn">N/A</button>
                </div>
                <textarea name="notes" class="w-full border border-slate-200 p-2 rounded-lg bg-white" rows="3"></textarea>
            </div>

            <button type="submit" class="mch-btn w-full py-2">Save Family Planning</button>
        </form>
    </div>
</div>

<script>
  const methodSelect = document.getElementById('method');
  const otherWrap = document.getElementById('other_method_wrap');
  const motherSelect = document.getElementById('mother_id');
  const motherStatusHint = document.getElementById('mother_status_hint');
  const methodHint = document.getElementById('method_hint');
  const startDateInput = document.getElementById('start_date');
  const activeFpWrap = document.getElementById('active_fp_wrap');
  const activeFpText = document.getElementById('active_fp_text');

  const activeFpByMother = <?= json_encode($activeFpByMother, JSON_UNESCAPED_SLASHES) ?>;
  const allowedWhilePregnant = new Set(['Condom', 'Natural']);

  methodSelect?.addEventListener('change', () => {
    otherWrap.classList.toggle('hidden', methodSelect.value !== 'Other');
  });

  function updateMotherRules() {
    const opt = motherSelect?.selectedOptions?.[0];
    const status = opt?.dataset?.status || '';
    const delivery = opt?.dataset?.deliveryDate || '';
    const mid = parseInt(motherSelect?.value || '0', 10);

    if (motherStatusHint) {
      motherStatusHint.textContent = status ? `Status: ${status}${delivery ? ` · Delivery: ${delivery}` : ''}` : '';
    }

    const pregnant = status === 'Pregnant';
    if (methodHint) {
      methodHint.textContent = pregnant
        ? 'Pregnant mothers: only Condom or Natural is allowed (others start after delivery).'
        : '';
    }

    // Disable disallowed methods for pregnant mothers (UI only; server enforces too).
    Array.from(methodSelect?.options || []).forEach(o => {
      if (!o.value) return;
      o.disabled = pregnant ? !allowedWhilePregnant.has(o.value) : false;
    });
    if (pregnant && methodSelect && methodSelect.value && !allowedWhilePregnant.has(methodSelect.value)) {
      methodSelect.value = '';
    }

    // Postnatal: set min start date to delivery date (if provided).
    if (startDateInput) {
      startDateInput.removeAttribute('min');
      if (status === 'Postnatal' && delivery) {
        startDateInput.min = delivery;
      }
    }

    // Active FP warning
    const active = mid > 0 ? activeFpByMother[mid] : null;
    if (activeFpWrap && activeFpText) {
      if (active && active.id) {
        activeFpText.textContent = `${active.method} (started ${active.start_date})`;
        activeFpWrap.classList.remove('hidden');
      } else {
        activeFpText.textContent = '';
        activeFpWrap.classList.add('hidden');
      }
    }
  }

  motherSelect?.addEventListener('change', updateMotherRules);
  updateMotherRules();

  const fpNotesBtn = document.getElementById('fp_notes_na_btn');
  const fpNotes = document.querySelector('textarea[name="notes"]');
  fpNotesBtn?.addEventListener('click', () => {
    if (!fpNotes) return;
    fpNotes.value = 'N/A';
    try { fpNotes.focus({ preventScroll: true }); } catch (e) {}
  });
</script>

<?php include __DIR__ . "/../includes/footer.php"; ?>
