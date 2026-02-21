<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/crypto.php";
require_once __DIR__ . "/../config/scope.php";
require_once __DIR__ . "/../config/audit.php";
require_once __DIR__ . "/../config/csrf.php";

// Access check
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'nurse'], true)) {
    header("Location: /mch_system/auth/login.php");
    exit;
}

$page = 'log_pnc_visit';
include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_nurse.php";

$error = '';
$success = '';

$scopedClinicId = mch_scope_scoped_clinic_id();
if ((string)($_SESSION['role'] ?? '') === 'nurse' && $scopedClinicId <= 0) {
    http_response_code(403);
    $error = "Your account is not assigned to a clinic. Please contact Admin.";
}

$mothers = [];
try {
    if ($scopedClinicId > 0) {
        $stmt = $pdo->prepare("
            SELECT m.id, m.fullname, m.hospital_number
            FROM mothers m
            JOIN users u ON u.id = m.user_id
            WHERE m.pregnancy_status = 'Postnatal'
              AND u.clinic_id = ?
            ORDER BY m.fullname
        ");
        $stmt->execute([$scopedClinicId]);
        $mothers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $mothers = $pdo->query("
            SELECT id, fullname, hospital_number
            FROM mothers
            WHERE pregnancy_status = 'Postnatal'
            ORDER BY fullname
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $mothers = [];
}

$babies = [];
try {
    if ($scopedClinicId > 0) {
        $stmt = $pdo->prepare("
            SELECT b.id, b.mother_id, b.baby_name
            FROM babies b
            JOIN mothers m ON b.mother_id = m.id
            JOIN users u ON m.user_id = u.id
            WHERE u.clinic_id = ?
            ORDER BY b.baby_name
        ");
        $stmt->execute([$scopedClinicId]);
        $babies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $babies = $pdo->query("
            SELECT id, mother_id, baby_name
            FROM babies
            ORDER BY baby_name
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $babies = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mch_csrf_require_valid('nurse_log_pnc_visit');
    $mother_id = (int)($_POST['mother_id'] ?? 0);
    $baby_id = (int)($_POST['baby_id'] ?? 0);
    $visit_date = trim((string)($_POST['visit_date'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));
    if ($notes === '' || preg_match('/^(n\\s*\\/\\s*a|na)$/i', $notes)) {
        $notes = null;
    }

    if (!$mother_id || !$visit_date) {
        $error = "Mother and visit date are required.";
    } else {
        try {
            $today = new DateTimeImmutable('today');
            try {
                $visitObj = new DateTimeImmutable($visit_date);
            } catch (Throwable $e) {
                throw new Exception("Invalid visit date.");
            }
            if ($visitObj > $today) {
                throw new Exception("Visit date cannot be in the future.");
            }

            // Scope check (nurses must only log for their clinic)
            if ($scopedClinicId > 0) {
                $scopeStmt = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM mothers m
                    JOIN users u ON u.id = m.user_id
                    WHERE m.id = ? AND u.clinic_id = ?
                    LIMIT 1
                ");
                $scopeStmt->execute([$mother_id, $scopedClinicId]);
                if ((int)$scopeStmt->fetchColumn() !== 1) {
                    throw new Exception("403 Forbidden: You do not have access to this mother record.");
                }
            }

            // Optional baby must belong to mother.
            if ($baby_id > 0) {
                $bstmt = $pdo->prepare("SELECT COUNT(*) FROM babies WHERE id = ? AND mother_id = ? LIMIT 1");
                $bstmt->execute([$baby_id, $mother_id]);
                if ((int)$bstmt->fetchColumn() !== 1) {
                    throw new Exception("Selected baby does not belong to the chosen mother.");
                }
            }

            $notesEnc = $notes !== null ? mch_crypto_encrypt($notes) : null;
            $stmt = $pdo->prepare("
                INSERT INTO pnc_visits (mother_id, baby_id, visit_date, status, notes, created_at)
                VALUES (?, ?, ?, 'COMPLETED', ?, NOW())
            ");
            $stmt->execute([
                $mother_id,
                $baby_id ?: null,
                $visit_date,
                $notesEnc
            ]);

            $newId = (int)$pdo->lastInsertId();
            mch_audit_log(
                $pdo,
                'PNC_LOG',
                'pnc_visits',
                $newId,
                "PNC visit logged: mother_id={$mother_id}" . ($baby_id ? "; baby_id={$baby_id}" : "") . "; visit_date={$visit_date}"
            );

            $success = "PNC visit logged successfully.";
        } catch (Exception $e) {
            $error = "Error: " . $e->getMessage();
        }
    }
}
?>

<div class="ml-64 p-6 max-w-3xl">
    <div class="mch-card bg-white/90 p-6 border border-slate-100">
        <h2 class="text-xl font-semibold text-slate-900 mb-4">Log PNC Visit</h2>

        <?php if ($error): ?>
            <div class="bg-rose-100 text-rose-700 p-3 rounded-lg mb-4"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="bg-emerald-100 text-emerald-700 p-3 rounded-lg mb-4"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <form method="POST" class="space-y-4">
            <?= mch_csrf_input('nurse_log_pnc_visit') ?>
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1">Mother (Postnatal)</label>
                <select name="mother_id" id="mother_id" required class="w-full border border-slate-200 p-2 rounded-lg bg-white">
                    <option value="">Select Mother</option>
                    <?php foreach ($mothers as $m): ?>
                        <option value="<?= (int)$m['id'] ?>">
                            <?= htmlspecialchars($m['fullname']) ?> (<?= htmlspecialchars($m['hospital_number']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1">Baby (Optional)</label>
                <select name="baby_id" id="baby_id" class="w-full border border-slate-200 p-2 rounded-lg bg-white">
                    <option value="">Select Baby</option>
                    <?php foreach ($babies as $b): ?>
                        <option value="<?= (int)$b['id'] ?>" data-mother="<?= (int)$b['mother_id'] ?>">
                            <?= htmlspecialchars($b['baby_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1">Visit Date</label>
                <input type="date" name="visit_date" required class="w-full border border-slate-200 p-2 rounded-lg bg-white">
            </div>

            <div>
                <div class="flex items-center justify-between mb-1">
                    <label class="block text-sm font-semibold text-slate-700">Notes</label>
                    <button type="button" class="text-xs px-2 py-1 rounded-lg border border-slate-200 bg-slate-50 hover:bg-slate-100"
                            id="pnc_notes_na_btn">N/A</button>
                </div>
                <textarea name="notes" id="pnc_notes" class="w-full border border-slate-200 p-2 rounded-lg bg-white" rows="3" placeholder="Optional notes"></textarea>
            </div>

            <button type="submit" class="mch-btn w-full py-2">Save PNC Visit</button>
        </form>
    </div>
</div>

<script>
  const motherSelect = document.getElementById('mother_id');
  const babySelect = document.getElementById('baby_id');
  const allBabyOptions = Array.from(babySelect?.options || []);

  function filterBabies() {
    const motherId = motherSelect.value;
    allBabyOptions.forEach(opt => {
      if (!opt.value) return;
      opt.hidden = motherId && opt.dataset.mother !== motherId;
    });
    if (babySelect && babySelect.value && babySelect.selectedOptions[0]?.hidden) {
      babySelect.value = '';
    }
  }

  motherSelect?.addEventListener('change', filterBabies);
  filterBabies();

  const pncNotesBtn = document.getElementById('pnc_notes_na_btn');
  const pncNotes = document.getElementById('pnc_notes');
  pncNotesBtn?.addEventListener('click', () => {
    if (!pncNotes) return;
    pncNotes.value = 'N/A';
    try { pncNotes.focus({ preventScroll: true }); } catch (e) {}
  });
</script>

<?php include __DIR__ . "/../includes/footer.php"; ?>
