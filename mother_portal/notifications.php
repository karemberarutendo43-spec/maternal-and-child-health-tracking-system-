<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'mother') {
    header("Location: /mch_system/auth/login.php");
    exit;
}

$uid = (int)($_SESSION['user_id'] ?? 0);
$motherId = 0;
if ($uid > 0) {
    $mstmt = $pdo->prepare("SELECT id, fullname FROM mothers WHERE user_id = ? LIMIT 1");
    $mstmt->execute([$uid]);
    $motherRow = $mstmt->fetch(PDO::FETCH_ASSOC);
    $motherId = (int)($motherRow['id'] ?? 0);
    $motherName = (string)($motherRow['fullname'] ?? 'Mother');
} else {
    $motherName = 'Mother';
}

$error = '';
$messages = [];
if ($motherId > 0) {
    try {
        // Mark all as seen when opening the page (if column exists).
        try {
            $pdo->prepare("UPDATE anc_sms_logs SET seen_at = NOW() WHERE mother_id = ? AND seen_at IS NULL")
                ->execute([$motherId]);
        } catch (Throwable $e) {
            // ignore if table/column isn't available yet
        }

        $stmt = $pdo->prepare("
            SELECT id, next_visit_date, status, message, created_at
            FROM anc_sms_logs
            WHERE mother_id = ?
            ORDER BY created_at DESC
            LIMIT 50
        ");
        $stmt->execute([$motherId]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $error = "Notifications are not configured yet. Ask the clinic to run `sql/anc_sms_logs.sql` on the database.";
    }
} else {
    $error = "Your account is not linked to a mother record yet.";
}

$page = 'notifications';
include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_mother.php";
?>

<div class="ml-64 p-6 space-y-6 max-w-4xl">
  <div>
    <h2 class="text-2xl font-semibold text-slate-900">Notifications</h2>
    <p class="text-slate-600">ANC reminders and clinic messages for <?= htmlspecialchars($motherName) ?>.</p>
  </div>

  <?php if ($error): ?>
    <div class="mch-card bg-amber-50 border border-amber-200 text-amber-800 p-4">
      <?= htmlspecialchars($error) ?>
    </div>
  <?php else: ?>
    <?php if (empty($messages)): ?>
      <div class="mch-card bg-white/90 p-6 border border-slate-100 text-slate-600">
        No notifications yet.
      </div>
    <?php else: ?>
      <div class="mch-card bg-white/90 p-2 border border-slate-100 divide-y divide-slate-100">
        <?php foreach ($messages as $m): ?>
          <div class="p-4">
            <div class="flex items-start justify-between gap-4">
              <div class="text-sm text-slate-900 font-semibold">
                ANC Reminder
              </div>
              <div class="text-xs text-slate-500">
                <?= !empty($m['created_at']) ? htmlspecialchars(date('d M Y, H:i', strtotime((string)$m['created_at']))) : '' ?>
              </div>
            </div>
            <div class="mt-2 text-sm text-slate-700">
              <?= htmlspecialchars((string)($m['message'] ?? '')) ?>
            </div>
            <?php if (!empty($m['next_visit_date'])): ?>
              <div class="mt-2 text-xs text-slate-500">
                Next visit date: <?= htmlspecialchars((string)$m['next_visit_date']) ?>
              </div>
            <?php endif; ?>
            <?php if (!empty($m['status'])): ?>
              <div class="mt-2">
                <?php
                  $st = strtoupper((string)$m['status']);
                  $badge = $st === 'SENT' ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700';
                ?>
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold <?= $badge ?>">
                  <?= htmlspecialchars($st) ?>
                </span>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php include __DIR__ . "/../includes/footer.php"; ?>

