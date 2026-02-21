<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
$printMode = (string)($_GET['print'] ?? '') === '1';
include __DIR__ . "/../includes/header.php";
if (!$printMode) {
    include __DIR__ . "/../includes/sidebar_mother.php";
}

$user_id = (int)($_SESSION['user_id'] ?? 0);

$motherStmt = $pdo->prepare("SELECT * FROM mothers WHERE user_id = ? LIMIT 1");
$motherStmt->execute([$user_id]);
$mother = $motherStmt->fetch(PDO::FETCH_ASSOC) ?: null;

if (!$mother) {
    $error = "Your profile is not yet linked to a mother record. Please visit the clinic for activation.";
    $mother_id = 0;
} else {
    $error = '';
    $mother_id = (int)($mother['id'] ?? 0);
}

// ANC summary
$nextAnc = null;
if ($mother_id > 0) {
    $stmt = $pdo->prepare("
        SELECT visit_number, next_visit_date, status
        FROM anc_visits
        WHERE mother_id = ?
          AND status = 'PENDING'
        ORDER BY next_visit_date ASC, visit_number ASC
        LIMIT 1
    ");
    $stmt->execute([$mother_id]);
    $nextAnc = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// PNC summary
$lastPnc = null;
if ($mother_id > 0) {
    $stmt = $pdo->prepare("
        SELECT visit_date, notes
        FROM pnc_visits
        WHERE mother_id = ?
        ORDER BY visit_date DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([$mother_id]);
    $lastPnc = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Family planning summary
$fp = null;
try {
    if ($mother_id > 0 && (bool)$pdo->query("SHOW TABLES LIKE 'family_planning'")->fetchColumn()) {
        $stmt = $pdo->prepare("
            SELECT method, status, start_date, end_date
            FROM family_planning
            WHERE mother_id = ?
            ORDER BY start_date DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute([$mother_id]);
        $fp = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
} catch (Throwable $e) {
    $fp = null;
}

// Babies + immun + growth (latest)
$babies = [];
$shotsByBaby = [];
$growthByBaby = [];

if ($mother_id > 0) {
    $babiesStmt = $pdo->prepare("SELECT * FROM babies WHERE mother_id = ? ORDER BY dob DESC, id DESC");
    $babiesStmt->execute([$mother_id]);
    $babies = $babiesStmt->fetchAll(PDO::FETCH_ASSOC);

    $ids = array_values(array_filter(array_map(static fn ($b) => (int)($b['id'] ?? 0), $babies), static fn ($id) => $id > 0));
    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $shotsStmt = $pdo->prepare("
            SELECT baby_id, vaccine, due_date, status, given_date
            FROM immunizations
            WHERE baby_id IN ({$placeholders})
            ORDER BY baby_id ASC, due_date ASC, id ASC
        ");
        $shotsStmt->execute($ids);
        foreach ($shotsStmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $bid = (int)($s['baby_id'] ?? 0);
            if ($bid <= 0) continue;
            $shotsByBaby[$bid] ??= [];
            $shotsByBaby[$bid][] = $s;
        }

        try {
            $hasWeights = (bool)$pdo->query("SHOW TABLES LIKE 'baby_weights'")->fetchColumn();
        } catch (Throwable $e) {
            $hasWeights = false;
        }
        if (!empty($hasWeights)) {
            $wStmt = $pdo->prepare("
                SELECT baby_id, weight_kg, observed_date
                FROM baby_weights
                WHERE baby_id IN ({$placeholders})
                  AND deleted_at IS NULL
                ORDER BY observed_date DESC, id DESC
            ");
            $wStmt->execute($ids);
            foreach ($wStmt->fetchAll(PDO::FETCH_ASSOC) as $w) {
                $bid = (int)($w['baby_id'] ?? 0);
                if ($bid <= 0) continue;
                if (!isset($growthByBaby[$bid])) $growthByBaby[$bid] = [];
                if (count($growthByBaby[$bid]) < 5) {
                    $growthByBaby[$bid][] = $w;
                }
            }
        }
    }
}

function mch_days_label(string $ymd): string
{
    $ts = strtotime($ymd);
    if (!$ts) return '';
    $diffDays = (int)round(($ts - strtotime(date('Y-m-d'))) / 86400);
    if ($diffDays === 0) return 'today';
    if ($diffDays === 1) return 'in 1 day';
    if ($diffDays > 1) return 'in ' . $diffDays . ' days';
    if ($diffDays === -1) return '1 day ago';
    return abs($diffDays) . ' days ago';
}
?>

<style>
  @media print {
    #mchSidebarMother,
    #mch_offline_banner,
    .no-print {
      display: none !important;
    }

    body.mch-portal-bg::before,
    body.mch-portal-bg::after {
      display: none !important;
    }

    body, html {
      background: #ffffff !important;
      color: #0f172a !important;
    }

    #handbook_wrap {
      margin-left: 0 !important;
      padding: 0 !important;
      max-width: none !important;
    }

    .mch-card {
      box-shadow: none !important;
      background: #ffffff !important;
      border-color: #e2e8f0 !important;
    }
  }
</style>

<?php if ($printMode): ?>
  <script>
    // Keep user-gesture printing (button) as primary; try auto-print for desktop browsers.
    window.addEventListener('load', () => {
      try { setTimeout(() => window.print(), 250); } catch (e) {}
    });
  </script>
<?php endif; ?>

<div id="handbook_wrap" class="<?= $printMode ? 'p-6 max-w-6xl space-y-6' : 'ml-64 p-6 max-w-6xl space-y-6' ?>">
    <div class="flex flex-col gap-2 md:flex-row md:items-end md:justify-between">
        <div>
            <h1 class="text-3xl font-semibold text-slate-900 mb-1">My MCH Handbook</h1>
            <p class="text-slate-600">Your digital summary: appointments, babies, immunizations, and growth.</p>
        </div>
        <div class="flex items-center gap-2 no-print">
          <a class="px-3 py-2 rounded-lg border border-slate-200 bg-white text-slate-700 text-sm hover:bg-slate-50"
             target="_blank" rel="noopener"
             href="/mch_system/mother_portal/handbook.php?print=1">
              Open printable
          </a>
          <button class="px-3 py-2 rounded-lg bg-slate-900 text-white text-sm hover:bg-slate-800" type="button" onclick="window.print()">
            Print / Save PDF
          </button>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="mch-card bg-amber-50 border border-amber-200 text-amber-900 p-4"><?= htmlspecialchars($error) ?></div>
    <?php else: ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            <div class="mch-card bg-white/90 border border-slate-100 p-6 lg:col-span-2">
                <h2 class="text-lg font-semibold text-slate-900 mb-3">Mother Profile</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm text-slate-700">
                    <div><span class="font-semibold">Name:</span> <?= htmlspecialchars((string)($mother['fullname'] ?? '')) ?></div>
                    <div><span class="font-semibold">Hospital #:</span> <?= htmlspecialchars((string)($mother['hospital_number'] ?? '')) ?></div>
                    <div><span class="font-semibold">Phone:</span> <?= htmlspecialchars((string)($mother['phone'] ?? '')) ?></div>
                    <div><span class="font-semibold">Status:</span> <?= htmlspecialchars((string)($mother['pregnancy_status'] ?? '')) ?></div>
                    <div><span class="font-semibold">LMP:</span> <?= htmlspecialchars((string)($mother['lmp'] ?? '-')) ?></div>
                    <div><span class="font-semibold">EDD:</span> <?= htmlspecialchars((string)($mother['edd'] ?? '-')) ?></div>
                </div>
            </div>

            <div class="space-y-4">
                <div class="mch-card bg-white/90 border border-slate-100 p-6">
                    <h3 class="text-sm font-semibold text-slate-900">Next ANC Visit</h3>
                    <?php if ($nextAnc && !empty($nextAnc['next_visit_date'])): ?>
                        <div class="mt-2 text-2xl font-extrabold text-teal-700"><?= htmlspecialchars((string)$nextAnc['next_visit_date']) ?></div>
                        <div class="text-sm text-slate-600">Visit #<?= (int)($nextAnc['visit_number'] ?? 0) ?> (<?= mch_days_label((string)$nextAnc['next_visit_date']) ?>)</div>
                        <a class="inline-flex mt-3 text-sm font-semibold text-teal-700 hover:underline" href="/mch_system/mother_portal/anc_visits.php">View ANC timeline</a>
                    <?php else: ?>
                        <div class="mt-2 text-sm text-slate-500">No upcoming ANC visit found.</div>
                    <?php endif; ?>
                </div>

                <div class="mch-card bg-white/90 border border-slate-100 p-6">
                    <h3 class="text-sm font-semibold text-slate-900">Latest PNC</h3>
                    <?php if ($lastPnc && !empty($lastPnc['visit_date'])): ?>
                        <div class="mt-2 text-lg font-semibold text-slate-900"><?= htmlspecialchars((string)$lastPnc['visit_date']) ?></div>
                        <div class="text-sm text-slate-600"><?= mch_days_label((string)$lastPnc['visit_date']) ?></div>
                        <a class="inline-flex mt-3 text-sm font-semibold text-teal-700 hover:underline" href="/mch_system/mother_portal/pnc_visits.php">View PNC visits</a>
                    <?php else: ?>
                        <div class="mt-2 text-sm text-slate-500">No PNC visits recorded yet.</div>
                    <?php endif; ?>
                </div>

                <div class="mch-card bg-white/90 border border-slate-100 p-6">
                    <h3 class="text-sm font-semibold text-slate-900">Family Planning</h3>
                    <?php if ($fp && !empty($fp['method'])): ?>
                        <div class="mt-2 text-lg font-semibold text-slate-900"><?= htmlspecialchars((string)$fp['method']) ?></div>
                        <div class="text-sm text-slate-600">
                            <?= htmlspecialchars((string)($fp['status'] ?? '')) ?>
                            <?php if (!empty($fp['start_date'])): ?> &bull; since <?= htmlspecialchars((string)$fp['start_date']) ?><?php endif; ?>
                        </div>
                        <a class="inline-flex mt-3 text-sm font-semibold text-teal-700 hover:underline" href="/mch_system/mother_portal/family_planning.php">View details</a>
                    <?php else: ?>
                        <div class="mt-2 text-sm text-slate-500">No family planning record found.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="mch-card bg-white/90 border border-slate-100 p-6">
            <div class="flex items-center justify-between gap-3 mb-3">
                <h2 class="text-lg font-semibold text-slate-900">Babies, Immunizations & Growth</h2>
                <a class="text-sm font-semibold text-teal-700 hover:underline" href="/mch_system/mother_portal/babies.php">Open full baby cards</a>
            </div>

            <?php if (empty($babies)): ?>
                <p class="text-slate-500">No babies registered yet.</p>
            <?php else: ?>
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <?php foreach ($babies as $b): ?>
                        <?php
                          $bid = (int)($b['id'] ?? 0);
                          $shots = $shotsByBaby[$bid] ?? [];
                          $given = 0;
                          $pending = 0;
                          $nextDue = null;
                          foreach ($shots as $s) {
                            if (($s['status'] ?? '') === 'GIVEN') $given++;
                            else $pending++;
                            if (($s['status'] ?? '') !== 'GIVEN' && !empty($s['due_date'])) {
                                if ($nextDue === null || (string)$s['due_date'] < $nextDue) $nextDue = (string)$s['due_date'];
                            }
                          }
                          $recentGrowth = $growthByBaby[$bid] ?? [];
                        ?>
                        <div class="rounded-xl border border-slate-200 bg-white p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="font-semibold text-slate-900"><?= htmlspecialchars((string)($b['baby_name'] ?? 'Baby')) ?></div>
                                    <div class="text-sm text-slate-600">DOB: <?= htmlspecialchars((string)($b['dob'] ?? '-')) ?> &bull; <?= htmlspecialchars((string)($b['gender'] ?? '-')) ?></div>
                                </div>
                                <div class="text-right text-sm">
                                    <div class="font-semibold text-emerald-700"><?= (int)$given ?> given</div>
                                    <div class="text-slate-600"><?= (int)$pending ?> pending</div>
                                </div>
                            </div>

                            <?php if ($nextDue): ?>
                                <div class="mt-3 text-sm">
                                    <span class="font-semibold text-slate-900">Next due:</span>
                                    <span class="text-slate-700"><?= htmlspecialchars($nextDue) ?></span>
                                    <span class="text-slate-500">(<?= htmlspecialchars(mch_days_label($nextDue)) ?>)</span>
                                </div>
                            <?php endif; ?>

                            <div class="mt-4">
                                <div class="text-sm font-semibold text-slate-900 mb-2">Recent weights</div>
                                <?php if (empty($recentGrowth)): ?>
                                    <div class="text-sm text-slate-500">No weights recorded yet.</div>
                                <?php else: ?>
                                    <div class="space-y-1 text-sm text-slate-700">
                                        <?php foreach ($recentGrowth as $g): ?>
                                            <div class="flex items-center justify-between">
                                                <span><?= htmlspecialchars((string)$g['observed_date']) ?></span>
                                                <span class="font-semibold"><?= htmlspecialchars((string)$g['weight_kg']) ?> kg</span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    <?php endif; ?>
</div>

<?php include __DIR__ . "/../includes/footer.php"; ?>
