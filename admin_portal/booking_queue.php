<?php
require_once __DIR__ . "/../config/session.php";
mch_session_start();
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/csrf.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: /mch_system/auth/login.php");
    exit;
}

$error = '';
$success = '';
$adminLevel = $_SESSION['admin_level'] ?? 'SUPER';
$adminClinicId = (int)($_SESSION['clinic_id'] ?? 0);
$isSuperAdmin = ($adminLevel === 'SUPER');
if (!$isSuperAdmin && $adminClinicId <= 0) {
    die("Clinic admin is not assigned to a clinic.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_id'])) {
    mch_csrf_require_valid('admin_booking_queue');
    $approve_id = (int)$_POST['approve_id'];
    if ($approve_id) {
        $upd = $pdo->prepare("UPDATE users SET status = 'APPROVED', approved_by = ?, approved_at = NOW() WHERE id = ? AND role = 'mother'");
        $upd->execute([$_SESSION['user_id'], $approve_id]);
        $success = "Booking approved.";
    }
}

$clinicFilter = $isSuperAdmin ? (int)($_GET['clinic_id'] ?? 0) : $adminClinicId;
$queueStmt = $pdo->prepare("
    SELECT u.id, u.full_name, u.phone, u.email, u.status, u.created_at, u.approved_by, u.approved_at, c.clinic_name
    FROM users u
    LEFT JOIN clinics c ON u.clinic_id = c.id
    WHERE u.role = 'mother'
      AND u.email_verified = 1
      AND u.status IN ('PENDING','APPROVED')
      AND (? = 0 OR u.clinic_id = ?)
    ORDER BY u.created_at DESC
");
$queueStmt->execute([$clinicFilter, $clinicFilter]);
$queue = $queueStmt->fetchAll(PDO::FETCH_ASSOC);

$clinics = $pdo->query("SELECT id, clinic_name FROM clinics ORDER BY clinic_name")->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_admin.php";
?>

<div class="ml-64 p-6 max-w-6xl space-y-6">
    <div>
        <h1 class="text-3xl font-semibold text-slate-900 mb-1">Booking Queue (Admin)</h1>
        <p class="text-slate-600">Approve self-registered mothers before clinic booking.</p>
    </div>

    <div class="mch-card bg-white/90 p-6 border border-slate-100">
        <?php if ($error): ?>
            <div class="bg-rose-100 text-rose-700 p-3 rounded-lg mb-4"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="bg-emerald-100 text-emerald-700 p-3 rounded-lg mb-4"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <?php if ($isSuperAdmin): ?>
            <form method="GET" class="mb-4 flex flex-wrap gap-3 items-center">
                <label class="text-sm text-slate-600">Filter by clinic:</label>
                <select name="clinic_id" class="border border-slate-200 p-2 rounded-lg bg-white">
                    <option value="0">All Clinics</option>
                    <?php foreach ($clinics as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= $clinicFilter === (int)$c['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['clinic_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button class="px-3 py-2 rounded-lg bg-slate-900 text-white text-sm">Apply</button>
            </form>
        <?php else: ?>
            <div class="mb-4 text-sm text-slate-500">Clinic-scoped view</div>
        <?php endif; ?>

        <?php if (empty($queue)): ?>
            <p class="text-slate-500">No mothers in the booking queue.</p>
        <?php else: ?>
            <table class="w-full text-sm mch-table mch-scroll">
                <thead>
                    <tr>
                        <th class="p-2 text-left">Mother</th>
                        <th class="p-2 text-left">Phone</th>
                        <th class="p-2 text-left">Email</th>
                        <th class="p-2 text-left">Clinic</th>
                        <th class="p-2 text-left">Status</th>
                        <th class="p-2 text-left">Registered</th>
                        <th class="p-2 text-left">Approved By</th>
                        <th class="p-2 text-left">Approved At</th>
                        <th class="p-2 text-left">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($queue as $q): ?>
                        <tr class="border-t border-slate-200">
                            <td class="p-2"><?= htmlspecialchars($q['full_name']) ?></td>
                            <td class="p-2"><?= htmlspecialchars($q['phone']) ?></td>
                            <td class="p-2"><?= htmlspecialchars($q['email']) ?></td>
                            <td class="p-2"><?= htmlspecialchars($q['clinic_name'] ?? 'Unassigned') ?></td>
                            <td class="p-2">
                                <?php
                                $status = $q['status'];
                                $badgeClass = $status === 'APPROVED' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700';
                                ?>
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold <?= $badgeClass ?>">
                                    <?= htmlspecialchars($status) ?>
                                </span>
                            </td>
                            <td class="p-2"><?= htmlspecialchars(date('d M Y', strtotime($q['created_at']))) ?></td>
                            <td class="p-2"><?= $q['approved_by'] ? (int)$q['approved_by'] : '-' ?></td>
                            <td class="p-2"><?= $q['approved_at'] ? htmlspecialchars(date('d M Y', strtotime($q['approved_at']))) : '-' ?></td>
                            <td class="p-2">
                                <?php if ($q['status'] !== 'APPROVED'): ?>
                                    <form method="POST">
                                        <?= mch_csrf_input('admin_booking_queue') ?>
                                        <input type="hidden" name="approve_id" value="<?= (int)$q['id'] ?>">
                                        <button class="px-3 py-1 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs">
                                            Approve
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-slate-500 text-xs">Approved</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . "/../includes/footer.php"; ?>
