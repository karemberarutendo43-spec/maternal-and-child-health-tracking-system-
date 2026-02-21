<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";

// Access check
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'nurse'], true)) {
    header("Location: /mch_system/auth/login.php");
    exit;
}

$page = 'activation_queue';
include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_nurse.php";

$flash = $_SESSION['flash_activation_queue'] ?? null;
$flashError = '';
$flashSuccess = '';
if (is_array($flash)) {
    $flashError = (string)($flash['error'] ?? '');
    $flashSuccess = (string)($flash['success'] ?? '');
    unset($_SESSION['flash_activation_queue']);
}

$clinic_id = $_SESSION['clinic_id'] ?? null;
$scope = $_GET['scope'] ?? 'clinic'; // clinic | all
$verified = $_GET['verified'] ?? 'all'; // all | verified

// --------------------
// Booking queue (PENDING / APPROVED)
// --------------------
$queueWhere = "WHERE role = 'mother' AND status IN ('PENDING','APPROVED')";
$queueParams = [];

if ($scope === 'clinic') {
    $queueWhere .= " AND (clinic_id IS NULL OR clinic_id = ?)";
    $queueParams[] = $clinic_id;
}

if ($verified === 'verified') {
    $queueWhere .= " AND email_verified = 1";
}

$queueStmt = $pdo->prepare("
    SELECT id, full_name, phone, email, created_at, clinic_id, email_verified, has_web_access, status
    FROM users
    {$queueWhere}
    ORDER BY created_at DESC
");
$queueStmt->execute($queueParams);
$queue = $queueStmt->fetchAll(PDO::FETCH_ASSOC);

$queueCountStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM users
    {$queueWhere}
");
$queueCountStmt->execute($queueParams);
$queueCount = (int)$queueCountStmt->fetchColumn();

// --------------------
// Unlinked ACTIVE mothers (can login but no mothers record)
// --------------------
$unlinkedWhere = "
    WHERE u.role = 'mother'
      AND u.status = 'ACTIVE'
      AND m.id IS NULL
";
$unlinkedParams = [];

if ($scope === 'clinic') {
    $unlinkedWhere .= " AND (u.clinic_id IS NULL OR u.clinic_id = ?)";
    $unlinkedParams[] = $clinic_id;
}

$unlinkedStmt = $pdo->prepare("
    SELECT u.id, u.full_name, u.phone, u.email, u.created_at, u.clinic_id, u.email_verified, u.has_web_access,
           c.clinic_name
    FROM users u
    LEFT JOIN mothers m ON m.user_id = u.id
    LEFT JOIN clinics c ON u.clinic_id = c.id
    {$unlinkedWhere}
    ORDER BY u.created_at DESC
");
$unlinkedStmt->execute($unlinkedParams);
$unlinked = $unlinkedStmt->fetchAll(PDO::FETCH_ASSOC);

$unlinkedCountStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM users u
    LEFT JOIN mothers m ON m.user_id = u.id
    {$unlinkedWhere}
");
$unlinkedCountStmt->execute($unlinkedParams);
$unlinkedCount = (int)$unlinkedCountStmt->fetchColumn();
?>

<div class="ml-64 p-6 max-w-6xl space-y-6">
    <div class="flex items-start justify-between gap-3 flex-wrap">
        <div>
            <h1 class="text-3xl font-semibold text-slate-900 mb-1">Activation Queue</h1>
            <p class="text-slate-600">Link mothers who can login but are not yet activated in the clinic.</p>
        </div>

        <form method="GET" class="flex items-center gap-3 flex-wrap">
            <label class="text-sm text-slate-600">Scope:</label>
            <select name="scope" class="border border-slate-200 p-2 rounded-lg bg-white">
                <option value="clinic" <?= $scope === 'clinic' ? 'selected' : '' ?>>My Clinic</option>
                <option value="all" <?= $scope === 'all' ? 'selected' : '' ?>>All Clinics</option>
            </select>
            <label class="text-sm text-slate-600">Verified:</label>
            <select name="verified" class="border border-slate-200 p-2 rounded-lg bg-white">
                <option value="all" <?= $verified === 'all' ? 'selected' : '' ?>>All</option>
                <option value="verified" <?= $verified === 'verified' ? 'selected' : '' ?>>Email Verified Only</option>
            </select>
            <button class="px-3 py-2 rounded-lg bg-slate-900 text-white text-sm">Apply</button>
        </form>
    </div>

    <?php if ($flashError): ?>
        <div class="mch-card bg-rose-50 border border-rose-200 text-rose-700 p-4"><?= htmlspecialchars($flashError) ?></div>
    <?php endif; ?>
    <?php if ($flashSuccess): ?>
        <div class="mch-card bg-emerald-50 border border-emerald-200 text-emerald-700 p-4"><?= htmlspecialchars($flashSuccess) ?></div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="mch-card bg-white/90 p-5 border border-slate-100">
            <div class="text-sm text-slate-500">Booking queue (PENDING/APPROVED)</div>
            <div class="text-3xl font-semibold text-slate-900"><?= (int)$queueCount ?></div>
        </div>
        <div class="mch-card bg-white/90 p-5 border border-slate-100">
            <div class="text-sm text-slate-500">Unlinked ACTIVE mothers</div>
            <div class="text-3xl font-semibold text-slate-900"><?= (int)$unlinkedCount ?></div>
            <div class="mt-1 text-xs text-slate-500">These mothers see: “Your profile is not yet linked…”</div>
        </div>
    </div>

    <div class="mch-card bg-white/90 p-6 border border-slate-100 space-y-4">
        <div>
            <h2 class="text-xl font-semibold text-slate-900">Unlinked Mothers (Activate)</h2>
            <p class="text-sm text-slate-600">Open the booking form to create the mother record and link the profile.</p>
        </div>

        <?php if (empty($unlinked)): ?>
            <p class="text-slate-500">No unlinked mothers found.</p>
        <?php else: ?>
            <table class="w-full text-sm mch-table mch-scroll">
                <thead>
                    <tr>
                        <th class="p-2 text-left">Mother</th>
                        <th class="p-2 text-left">Phone</th>
                        <th class="p-2 text-left">Email</th>
                        <th class="p-2 text-left">Clinic</th>
                        <th class="p-2 text-left">Web Access</th>
                        <th class="p-2 text-left">Registered</th>
                        <th class="p-2 text-left">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($unlinked as $u): ?>
                        <tr class="border-t border-slate-200">
                            <td class="p-2"><?= htmlspecialchars($u['full_name']) ?></td>
                            <td class="p-2"><?= htmlspecialchars($u['phone']) ?></td>
                            <td class="p-2"><?= htmlspecialchars($u['email']) ?></td>
                            <td class="p-2"><?= htmlspecialchars($u['clinic_name'] ?? 'Unassigned') ?></td>
                            <td class="p-2">
                                <?php if ((int)($u['has_web_access'] ?? 0) === 1): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-slate-100 text-slate-700">Yes</span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-slate-100 text-slate-500">No</span>
                                <?php endif; ?>
                            </td>
                            <td class="p-2"><?= htmlspecialchars(date('d M Y', strtotime($u['created_at']))) ?></td>
                            <td class="p-2">
                                <a class="inline-flex items-center px-3 py-1 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-700 hover:bg-emerald-100"
                                   href="/mch_system/nurse_portal/register_mother.php?existing_user_id=<?= (int)$u['id'] ?>&return=activation_queue">
                                    Activate / Link
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="mch-card bg-white/90 p-6 border border-slate-100 space-y-4">
        <div>
            <h2 class="text-xl font-semibold text-slate-900">Booking Queue</h2>
            <p class="text-sm text-slate-600">Mothers waiting for booking (self-registered).</p>
        </div>

        <?php if (empty($queue)): ?>
            <p class="text-slate-500">No mothers in the booking queue.</p>
        <?php else: ?>
            <table class="w-full text-sm mch-table mch-scroll">
                <thead>
                    <tr>
                        <th class="text-left p-2">Name</th>
                        <th class="text-left p-2">Phone</th>
                        <th class="text-left p-2">Email</th>
                        <th class="text-left p-2">Verified</th>
                        <th class="text-left p-2">Web Access</th>
                        <th class="text-left p-2">Registered</th>
                        <th class="text-left p-2">Status</th>
                        <th class="text-left p-2">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($queue as $p): ?>
                        <tr class="border-t border-slate-200">
                            <td class="p-2"><?= htmlspecialchars($p['full_name']) ?></td>
                            <td class="p-2"><?= htmlspecialchars($p['phone']) ?></td>
                            <td class="p-2"><?= htmlspecialchars($p['email']) ?></td>
                            <td class="p-2">
                                <?php if ((int)($p['email_verified'] ?? 0) === 1): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-emerald-100 text-emerald-700">Yes</span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-amber-100 text-amber-700">No</span>
                                <?php endif; ?>
                            </td>
                            <td class="p-2">
                                <?php if ((int)($p['has_web_access'] ?? 0) === 1): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-slate-100 text-slate-700">Yes</span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-slate-100 text-slate-500">No</span>
                                <?php endif; ?>
                            </td>
                            <td class="p-2"><?= htmlspecialchars(date('d M Y', strtotime($p['created_at']))) ?></td>
                            <td class="p-2">
                                <?php
                                    $status = $p['status'] ?? 'PENDING';
                                    $badge = $status === 'APPROVED' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700';
                                ?>
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold <?= $badge ?>">
                                    <?= htmlspecialchars($status) ?>
                                </span>
                            </td>
                            <td class="p-2">
                                <a class="inline-flex items-center px-3 py-1 rounded-lg bg-teal-50 border border-teal-200 text-teal-700 hover:bg-teal-100"
                                   href="/mch_system/nurse_portal/register_mother.php?existing_user_id=<?= (int)$p['id'] ?>&return=activation_queue">
                                    Book Pregnancy
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . "/../includes/footer.php"; ?>
