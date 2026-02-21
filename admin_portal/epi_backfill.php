<?php
require_once __DIR__ . "/../config/session.php";
mch_session_start();
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/epi.php";
require_once __DIR__ . "/../config/csrf.php";

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: /mch_system/auth/login.php");
    exit;
}

$adminLevel = $_SESSION['admin_level'] ?? 'SUPER';
$adminClinicId = (int)($_SESSION['clinic_id'] ?? 0);
if ($adminLevel !== 'SUPER' && $adminClinicId <= 0) {
    die("Clinic admin is not assigned to a clinic.");
}

$error = '';
$success = '';

function mch_days_between(string $aYmd, string $bYmd): int
{
    $a = strtotime($aYmd);
    $b = strtotime($bYmd);
    if (!$a || !$b) return 999999;
    return (int)round(abs($a - $b) / 86400);
}

function mch_ymd_add_days(string $ymd, int $days): string
{
    $ts = strtotime($ymd . ' +' . $days . ' days');
    return $ts ? date('Y-m-d', $ts) : '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mch_csrf_require_valid('admin_epi_backfill');
    $mode = (string)($_POST['mode'] ?? 'add_only');
    $addOnly = ($mode === 'add_only');
    $cleanDeprecated = ($mode === 'add_and_cleanup');

    try {
        $pdo->beginTransaction();

        $whereClinic = '';
        $params = [];
        if ($adminLevel !== 'SUPER') {
            $whereClinic = "AND u.clinic_id = ?";
            $params[] = $adminClinicId;
        }

        $stmt = $pdo->prepare("
            SELECT b.id AS baby_id, b.dob
            FROM babies b
            JOIN mothers m ON b.mother_id = m.id
            LEFT JOIN users u ON m.user_id = u.id
            WHERE b.dob IS NOT NULL AND b.dob <> ''
              {$whereClinic}
            ORDER BY b.id ASC
        ");
        $stmt->execute($params);
        $babies = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $added = 0;
        $cleaned = 0;

        // Backfill: add any missing schedule items for every baby.
        foreach ($babies as $b) {
            $babyId = (int)($b['baby_id'] ?? 0);
            $dob = (string)($b['dob'] ?? '');
            if ($babyId <= 0 || $dob === '') continue;

            $before = (int)$pdo->query("SELECT COUNT(*) FROM immunizations WHERE baby_id = " . (int)$babyId)->fetchColumn();
            mch_epi_insert_schedule($pdo, $babyId, $dob);
            $after = (int)$pdo->query("SELECT COUNT(*) FROM immunizations WHERE baby_id = " . (int)$babyId)->fetchColumn();
            if ($after > $before) {
                $added += ($after - $before);
            }

            if ($cleanDeprecated) {
                // Remove deprecated 5-year DTP/OPV booster entries that were inserted by older schedule code.
                // Only delete if still PENDING (never delete GIVEN history).
                $sel = $pdo->prepare("
                    SELECT id, vaccine, due_date, status
                    FROM immunizations
                    WHERE baby_id = ?
                      AND status = 'PENDING'
                ");
                $sel->execute([$babyId]);
                $rows = $sel->fetchAll(PDO::FETCH_ASSOC);

                $expected5y = mch_ymd_add_days($dob, 1825);
                foreach ($rows as $r) {
                    $immId = (int)($r['id'] ?? 0);
                    $label = (string)($r['vaccine'] ?? '');
                    $due = (string)($r['due_date'] ?? '');
                    if ($immId <= 0 || $label === '' || $due === '' || $expected5y === '') continue;

                    // Candidate: looks like a booster at ~5 years and normalizes to DTPB/OPVB.
                    $code = mch_epi_normalize_vaccine_code($label);
                    if (!in_array($code, ['DTPB', 'OPVB'], true)) continue;

                    $looks5y = preg_match('/\\b5\\s*YEAR/i', $label) || preg_match('/\\b5\\s*YEARS/i', $label) || preg_match('/\\(\\s*5\\s*YEARS\\s*\\)/i', $label);
                    if (!$looks5y) continue;

                    if (mch_days_between($due, $expected5y) > 7) continue;

                    $del = $pdo->prepare("DELETE FROM immunizations WHERE id = ? AND status = 'PENDING'");
                    $del->execute([$immId]);
                    $cleaned += (int)$del->rowCount();
                }
            }
        }

        $pdo->commit();
        if ($addOnly) {
            $success = "Backfill completed. Added {$added} missing immunization rows.";
        } else {
            $success = "Backfill completed. Added {$added} missing rows; cleaned {$cleaned} deprecated pending 5-year booster rows.";
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = "Error: " . $e->getMessage();
    }
}

include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_admin.php";
?>

<div class="ml-64 p-6 max-w-3xl space-y-5">
  <div>
    <h1 class="text-2xl font-semibold text-slate-900">EPI Backfill (Safe)</h1>
    <p class="text-slate-600 text-sm">
      Use this after updating the Zimbabwe EPI schedule to add missing immunizations for existing babies.
      It will not modify or delete any GIVEN records.
    </p>
  </div>

  <?php if ($error): ?>
    <div class="mch-card bg-rose-50 border border-rose-200 text-rose-700 p-4"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <?php if ($success): ?>
    <div class="mch-card bg-emerald-50 border border-emerald-200 text-emerald-700 p-4"><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>

  <div class="mch-card bg-white/90 p-6 border border-slate-100 space-y-4">
    <div class="text-sm text-slate-700">
      <div><span class="font-semibold">Scope:</span> <?= $adminLevel === 'SUPER' ? 'All clinics' : 'Your clinic only' ?></div>
      <div class="text-xs text-slate-500 mt-2">
        Recommended: “Add missing only”. Use cleanup only if you want to remove old pending 5-year DTP/OPV booster entries (they moved to 18 months; 5 years is now Td1).
      </div>
    </div>

    <form method="POST" class="flex flex-col gap-3">
      <?= mch_csrf_input('admin_epi_backfill') ?>
      <button name="mode" value="add_only" class="mch-btn px-4 py-2 w-full">
        Add Missing Immunizations Only (Recommended)
      </button>
      <button name="mode" value="add_and_cleanup" class="px-4 py-2 w-full rounded-xl border border-rose-200 bg-rose-50 hover:bg-rose-100 text-rose-700 font-semibold">
        Add Missing + Cleanup Deprecated Pending 5-Year Boosters
      </button>
    </form>
  </div>

  <div class="text-xs text-slate-500">
    Tip: Run DB updates first if needed: <span class="font-mono">sql/clinical_status.sql</span> (optional), then use this tool.
  </div>
</div>

<?php include __DIR__ . "/../includes/footer.php"; ?>
