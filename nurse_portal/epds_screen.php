<?php
require_once __DIR__ . "/../config/session.php";
mch_session_start();
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/scope.php";
require_once __DIR__ . "/../config/csrf.php";
require_once __DIR__ . "/../config/audit.php";

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'nurse') {
    header("Location: /mch_system/auth/login.php");
    exit;
}

$scopedClinicId = mch_scope_scoped_clinic_id();
if ($scopedClinicId <= 0) {
    die("Your account is not assigned to a clinic. Please contact Admin.");
}

$motherId = (int)($_GET['mother_id'] ?? 0);
if ($motherId <= 0) {
    die("Missing mother_id.");
}

// Ensure table exists
$enabled = false;
try {
    $enabled = (bool)$pdo->query("SHOW TABLES LIKE 'epds_screenings'")->fetchColumn();
} catch (Throwable $e) {
    $enabled = false;
}

// Scope check + fetch mother
$mStmt = $pdo->prepare("
    SELECT m.*, u.clinic_id
    FROM mothers m
    JOIN users u ON m.user_id = u.id
    WHERE m.id = ?
      AND u.clinic_id = ?
    LIMIT 1
");
$mStmt->execute([$motherId, $scopedClinicId]);
$mother = $mStmt->fetch(PDO::FETCH_ASSOC);
if (!$mother) {
    die("Mother not found (or outside your clinic scope).");
}

$error = '';
$success = '';

// Defaults
$today = date('Y-m-d');
$screeningDate = (string)($_POST['screening_date'] ?? $today);
$scores = [];
for ($i = 1; $i <= 10; $i++) {
    $scores[$i] = isset($_POST["q{$i}"]) ? (int)$_POST["q{$i}"] : 0;
}
$notes = trim((string)($_POST['notes'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $enabled) {
    mch_csrf_require_valid('nurse_epds');
    try {
        if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $screeningDate)) {
            throw new Exception("Invalid screening date.");
        }
        if ($screeningDate > $today) {
            throw new Exception("Screening date cannot be in the future.");
        }
        if ($notes !== '' && mb_strlen($notes) > 255) {
            throw new Exception("Notes too long (max 255).");
        }

        $total = 0;
        for ($i = 1; $i <= 10; $i++) {
            $v = $scores[$i];
            if ($v < 0 || $v > 3) {
                throw new Exception("All EPDS scores must be between 0 and 3.");
            }
            $total += $v;
        }

        // Simple flagging (screening only; follow facility protocol)
        $risk = 'LOW';
        if ($scores[10] > 0) {
            $risk = 'URGENT';
        } elseif ($total >= 13) {
            $risk = 'HIGH';
        } elseif ($total >= 10) {
            $risk = 'MODERATE';
        }

        $ins = $pdo->prepare("
            INSERT INTO epds_screenings
              (mother_id, screening_date, q1,q2,q3,q4,q5,q6,q7,q8,q9,q10, total_score, risk_flag, notes, created_by, created_at)
            VALUES
              (?, ?, ?,?,?,?,?,?,?,?,?, ?, ?, ?, ?, NOW())
        ");
        $ins->execute([
            $motherId,
            $screeningDate,
            $scores[1], $scores[2], $scores[3], $scores[4], $scores[5],
            $scores[6], $scores[7], $scores[8], $scores[9], $scores[10],
            $total,
            $risk,
            ($notes !== '' ? $notes : null),
            (int)($_SESSION['user_id'] ?? 0),
        ]);

        mch_audit_log(
            $pdo,
            'EPDS_LOG',
            'epds',
            $motherId,
            "EPDS screening recorded (total {$total}, flag {$risk})"
        );

        $success = "EPDS score saved (Total {$total}, Flag {$risk}).";
        // Reset form
        $screeningDate = $today;
        for ($i = 1; $i <= 10; $i++) $scores[$i] = 0;
        $notes = '';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$recent = [];
if ($enabled) {
    $rStmt = $pdo->prepare("
        SELECT screening_date, total_score, risk_flag, notes, created_at
        FROM epds_screenings
        WHERE mother_id = ?
        ORDER BY screening_date DESC, id DESC
        LIMIT 10
    ");
    $rStmt->execute([$motherId]);
    $recent = $rStmt->fetchAll(PDO::FETCH_ASSOC);
}

include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_nurse.php";
?>

<div class="ml-64 p-6 max-w-5xl space-y-6">
    <div class="flex flex-col gap-2 md:flex-row md:items-end md:justify-between">
        <div>
            <h1 class="text-3xl font-semibold text-slate-900 mb-1">Maternal Mental Health (EPDS)</h1>
            <p class="text-slate-600">
                Mother: <?= htmlspecialchars((string)($mother['fullname'] ?? '')) ?> â€¢ Hospital #: <?= htmlspecialchars((string)($mother['hospital_number'] ?? '')) ?>
            </p>
        </div>
        <a href="/mch_system/nurse_portal/mother_history.php?mother_id=<?= (int)$motherId ?>" class="text-sm font-semibold text-slate-600 hover:text-slate-900">
            Back to Mother History
        </a>
    </div>

    <?php if (!$enabled): ?>
        <div class="mch-card bg-amber-50 border border-amber-200 text-amber-800 p-4">
            EPDS module is not configured yet. Ask Admin to run <span class="font-mono">sql/epds_screenings.sql</span>.
        </div>
    <?php else: ?>
        <div class="mch-card bg-amber-50 border border-amber-200 text-amber-900 p-4 text-sm">
            This page stores <span class="font-semibold">scores only</span> (0â€“3) for EPDS Q1â€“Q10. Use your facilityâ€™s official EPDS tool for question text and clinical interpretation.
        </div>

        <?php if ($error): ?>
            <div class="mch-card bg-rose-50 border border-rose-200 text-rose-700 p-4"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="mch-card bg-emerald-50 border border-emerald-200 text-emerald-700 p-4"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <div class="mch-card bg-white/90 border border-slate-100 p-6 space-y-4">
            <h2 class="text-lg font-semibold text-slate-900">Record EPDS Score</h2>
            <form method="POST" class="space-y-4">
                <?= mch_csrf_input('nurse_epds') ?>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1">Screening date</label>
                    <input type="date" name="screening_date" value="<?= htmlspecialchars($screeningDate) ?>" class="border border-slate-200 p-2 rounded-lg bg-white" required>
                </div>

                <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
                    <?php for ($i = 1; $i <= 10; $i++): ?>
                        <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                            <div class="text-xs text-slate-600 font-semibold mb-1">Q<?= $i ?></div>
                            <select name="q<?= $i ?>" class="w-full border border-slate-200 p-2 rounded-lg bg-white">
                                <?php for ($v = 0; $v <= 3; $v++): ?>
                                    <option value="<?= $v ?>" <?= (int)$scores[$i] === $v ? 'selected' : '' ?>><?= $v ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    <?php endfor; ?>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1">Notes (optional)</label>
                    <textarea name="notes" rows="2" class="w-full border border-slate-200 p-2 rounded-lg bg-white" placeholder="Referral, follow-up plan, etc."><?= htmlspecialchars($notes) ?></textarea>
                </div>

                <button class="mch-btn px-4 py-2">Save</button>
            </form>
            <div class="text-xs text-slate-500">
                Flag rules used here (for screening only): <span class="font-semibold">URGENT</span> if Q10 &gt; 0; <span class="font-semibold">HIGH</span> if total â‰¥ 13; <span class="font-semibold">MODERATE</span> if total â‰¥ 10.
            </div>
        </div>

        <div class="mch-card bg-white/90 border border-slate-100 p-6">
            <h2 class="text-lg font-semibold text-slate-900 mb-3">Recent Screenings</h2>
            <?php if (empty($recent)): ?>
                <p class="text-slate-500">No screenings recorded yet.</p>
            <?php else: ?>
                <table class="w-full text-sm mch-table mch-scroll">
                    <thead>
                        <tr>
                            <th class="p-2 text-left">Date</th>
                            <th class="p-2 text-left">Total</th>
                            <th class="p-2 text-left">Flag</th>
                            <th class="p-2 text-left">Notes</th>
                            <th class="p-2 text-left">Recorded</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent as $r): ?>
                            <?php
                              $flag = (string)($r['risk_flag'] ?? 'LOW');
                              $flagClass = $flag === 'URGENT' ? 'text-rose-700 font-semibold' : ($flag === 'HIGH' ? 'text-amber-700 font-semibold' : ($flag === 'MODERATE' ? 'text-sky-700 font-semibold' : 'text-slate-700'));
                            ?>
                            <tr class="border-t border-slate-200">
                                <td class="p-2"><?= htmlspecialchars((string)$r['screening_date']) ?></td>
                                <td class="p-2 font-semibold"><?= (int)($r['total_score'] ?? 0) ?></td>
                                <td class="p-2 <?= $flagClass ?>"><?= htmlspecialchars($flag) ?></td>
                                <td class="p-2"><?= htmlspecialchars((string)($r['notes'] ?? '-')) ?></td>
                                <td class="p-2 text-xs text-slate-500"><?= htmlspecialchars((string)($r['created_at'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . "/../includes/footer.php"; ?>
