<?php
require_once __DIR__ . "/../config/session.php";
mch_session_start();
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/csrf.php";

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: /mch_system/auth/login.php");
    exit;
}

$error = '';
$success = '';
$currentAdminLevel = $_SESSION['admin_level'] ?? 'SUPER';
$currentClinicId = (int)($_SESSION['clinic_id'] ?? 0);
$isSuperAdmin = ($currentAdminLevel === 'SUPER');
$isClinicAdmin = !$isSuperAdmin;
if ($isClinicAdmin && $currentClinicId <= 0) {
    die("Clinic admin is not assigned to a clinic.");
}

$hasEmailVerified = false;
$hasApprovedBy = false;
$hasApprovedAt = false;
try {
    $hasEmailVerified = !empty($pdo->query("SHOW COLUMNS FROM users LIKE 'email_verified'")->fetch(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
    $hasEmailVerified = false;
}
try {
    $hasApprovedBy = !empty($pdo->query("SHOW COLUMNS FROM users LIKE 'approved_by'")->fetch(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
    $hasApprovedBy = false;
}
try {
    $hasApprovedAt = !empty($pdo->query("SHOW COLUMNS FROM users LIKE 'approved_at'")->fetch(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
    $hasApprovedAt = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mch_csrf_require_valid('admin_activation_queue');
    if (isset($_POST['approve_mother_id'])) {
        $approve_id = (int)($_POST['approve_mother_id'] ?? 0);
        if ($approve_id > 0) {
            if ($isClinicAdmin) {
                if ($hasApprovedBy && $hasApprovedAt) {
                    $upd = $pdo->prepare("
                        UPDATE users
                        SET status = 'APPROVED', approved_by = ?, approved_at = NOW()
                        WHERE id = ?
                          AND role = 'mother'
                          AND status IN ('PENDING','APPROVED')
                          AND clinic_id = ?
                    ");
                    $upd->execute([$_SESSION['user_id'], $approve_id, $currentClinicId]);
                } else {
                    $upd = $pdo->prepare("
                        UPDATE users
                        SET status = 'APPROVED'
                        WHERE id = ?
                          AND role = 'mother'
                          AND status IN ('PENDING','APPROVED')
                          AND clinic_id = ?
                    ");
                    $upd->execute([$approve_id, $currentClinicId]);
                }
            } else {
                if ($hasApprovedBy && $hasApprovedAt) {
                    $upd = $pdo->prepare("
                        UPDATE users
                        SET status = 'APPROVED', approved_by = ?, approved_at = NOW()
                        WHERE id = ? AND role = 'mother' AND status IN ('PENDING','APPROVED')
                    ");
                    $upd->execute([$_SESSION['user_id'], $approve_id]);
                } else {
                    $upd = $pdo->prepare("
                        UPDATE users
                        SET status = 'APPROVED'
                        WHERE id = ? AND role = 'mother' AND status IN ('PENDING','APPROVED')
                    ");
                    $upd->execute([$approve_id]);
                }
            }
            $success = "Mother approved.";
        } else {
            $error = "Invalid mother.";
        }
    } elseif (isset($_POST['activate_user_id'])) {
        $activate_id = (int)($_POST['activate_user_id'] ?? 0);
        if ($activate_id > 0) {
            if ($isClinicAdmin) {
                $upd = $pdo->prepare("
                    UPDATE users
                    SET status = 'ACTIVE'
                    WHERE id = ?
                      AND status = 'DISABLED'
                      AND role IN ('mother','nurse')
                      AND clinic_id = ?
                ");
                $upd->execute([$activate_id, $currentClinicId]);
            } else {
                $upd = $pdo->prepare("
                    UPDATE users
                    SET status = 'ACTIVE'
                    WHERE id = ? AND status = 'DISABLED' AND role IN ('mother','nurse')
                ");
                $upd->execute([$activate_id]);
            }
            $success = "User activated.";
        } else {
            $error = "Invalid user.";
        }
    }
}

$clinicFilter = $isClinicAdmin ? $currentClinicId : (int)($_GET['clinic_id'] ?? 0);

// Summary counts
$mothersVerified = 0;
$mothersUnverified = 0;
$mothersQueueTotal = 0;
try {
    if ($hasEmailVerified) {
        $sumStmt = $pdo->prepare("
            SELECT
              SUM(CASE WHEN email_verified = 1 THEN 1 ELSE 0 END) AS verified,
              SUM(CASE WHEN email_verified = 0 OR email_verified IS NULL THEN 1 ELSE 0 END) AS unverified
            FROM users
            WHERE role = 'mother'
              AND status IN ('PENDING','APPROVED')
              AND (? = 0 OR clinic_id = ?)
        ");
        $sumStmt->execute([$clinicFilter, $clinicFilter]);
        $sumMothers = $sumStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $mothersVerified = (int)($sumMothers['verified'] ?? 0);
        $mothersUnverified = (int)($sumMothers['unverified'] ?? 0);
        $mothersQueueTotal = $mothersVerified + $mothersUnverified;
    } else {
        $sumStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM users
            WHERE role = 'mother'
              AND status IN ('PENDING','APPROVED')
              AND (? = 0 OR clinic_id = ?)
        ");
        $sumStmt->execute([$clinicFilter, $clinicFilter]);
        $mothersQueueTotal = (int)$sumStmt->fetchColumn();
        $mothersUnverified = $mothersQueueTotal;
    }
} catch (Throwable $e) {
    $mothersVerified = 0;
    $mothersUnverified = 0;
    $mothersQueueTotal = 0;
}

$disabledCountsStmt = $pdo->prepare("
    SELECT role, COUNT(*) AS cnt
    FROM users
    WHERE status = 'DISABLED'
      AND role IN ('mother','nurse')
      AND (? = 0 OR clinic_id = ?)
    GROUP BY role
");
$disabledCountsStmt->execute([$clinicFilter, $clinicFilter]);
$disabledCounts = $disabledCountsStmt->fetchAll(PDO::FETCH_KEY_PAIR);
$disabledMothers = (int)($disabledCounts['mother'] ?? 0);
$disabledNurses = (int)($disabledCounts['nurse'] ?? 0);

// Mothers awaiting approval (queue)
$emailVerifiedSelect = $hasEmailVerified ? ", u.email_verified" : "";
$queueStmt = $pdo->prepare("
    SELECT u.id, u.full_name, u.phone, u.email, u.status, u.created_at{$emailVerifiedSelect}, c.clinic_name
    FROM users u
    LEFT JOIN clinics c ON u.clinic_id = c.id
    WHERE u.role = 'mother'
      AND u.status IN ('PENDING','APPROVED')
      AND (? = 0 OR u.clinic_id = ?)
    ORDER BY u.created_at DESC
");
$queueStmt->execute([$clinicFilter, $clinicFilter]);
$queue = $queueStmt->fetchAll(PDO::FETCH_ASSOC);

// Disabled users awaiting activation
if ($clinicFilter > 0) {
    $disabledStmt = $pdo->prepare("
        SELECT u.id, u.full_name, u.phone, u.email, u.role, u.status, u.created_at, c.clinic_name
        FROM users u
        LEFT JOIN clinics c ON u.clinic_id = c.id
        WHERE u.status = 'DISABLED'
          AND u.role IN ('mother','nurse')
          AND u.clinic_id = ?
        ORDER BY u.role ASC, u.created_at DESC
    ");
    $disabledStmt->execute([$clinicFilter]);
} else {
    $disabledStmt = $pdo->query("
        SELECT u.id, u.full_name, u.phone, u.email, u.role, u.status, u.created_at, c.clinic_name
        FROM users u
        LEFT JOIN clinics c ON u.clinic_id = c.id
        WHERE u.status = 'DISABLED' AND u.role IN ('mother','nurse')
        ORDER BY u.role ASC, u.created_at DESC
    ");
}
$disabledUsers = $disabledStmt->fetchAll(PDO::FETCH_ASSOC);

$clinics = $pdo->query("SELECT id, clinic_name FROM clinics ORDER BY clinic_name")->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_admin.php";
?>

<div class="ml-64 p-6 max-w-6xl space-y-6">
    <div>
        <h1 class="text-3xl font-semibold text-slate-900 mb-1">Activation Queue</h1>
        <p class="text-slate-600">Mothers awaiting approval and users awaiting re-activation.</p>
    </div>

    <?php if ($error): ?>
        <div class="mch-card bg-rose-50 border border-rose-200 text-rose-700 p-4"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="mch-card bg-emerald-50 border border-emerald-200 text-emerald-700 p-4"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="mch-card bg-white/90 p-5 border border-slate-100">
            <div class="text-sm text-slate-500">Mothers in queue</div>
            <div class="text-3xl font-semibold text-slate-900"><?= (int)$mothersQueueTotal ?></div>
            <div class="mt-2 text-xs text-slate-600">
                <span class="text-emerald-700 font-semibold"><?= (int)$mothersVerified ?></span> verified ·
                <span class="text-amber-700 font-semibold"><?= (int)$mothersUnverified ?></span> unverified
            </div>
        </div>
        <div class="mch-card bg-white/90 p-5 border border-slate-100">
            <div class="text-sm text-slate-500">Disabled mothers</div>
            <div class="text-3xl font-semibold text-slate-900"><?= (int)$disabledMothers ?></div>
        </div>
        <div class="mch-card bg-white/90 p-5 border border-slate-100">
            <div class="text-sm text-slate-500">Disabled nurses</div>
            <div class="text-3xl font-semibold text-slate-900"><?= (int)$disabledNurses ?></div>
        </div>
    </div>

    <div class="mch-card bg-white/90 p-6 border border-slate-100 space-y-4">
        <div class="flex items-center justify-between gap-3 flex-wrap">
            <div>
                <h2 class="text-xl font-semibold text-slate-900">Mothers Approval Queue</h2>
                <p class="text-sm text-slate-600">Approve self-registered mothers.</p>
            </div>
            <?php if ($isSuperAdmin): ?>
              <form method="GET" class="flex items-center gap-3 flex-wrap">
                  <label class="text-sm text-slate-600">Clinic:</label>
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
              <div class="text-sm text-slate-500">Clinic-scoped view</div>
            <?php endif; ?>
        </div>

        <?php if (empty($queue)): ?>
            <p class="text-slate-500">No mothers in the queue.</p>
        <?php else: ?>
            <table class="w-full text-sm mch-table mch-scroll">
                <thead>
                    <tr>
                        <th class="p-2 text-left">Mother</th>
                        <th class="p-2 text-left">Phone</th>
                        <th class="p-2 text-left">Email</th>
                        <th class="p-2 text-left">Verified</th>
                        <th class="p-2 text-left">Clinic</th>
                        <th class="p-2 text-left">Status</th>
                        <th class="p-2 text-left">Registered</th>
                        <th class="p-2 text-left">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($queue as $q): ?>
                        <tr class="border-t border-slate-200">
                            <td class="p-2"><?= htmlspecialchars($q['full_name']) ?></td>
                            <td class="p-2"><?= htmlspecialchars($q['phone']) ?></td>
                            <td class="p-2"><?= htmlspecialchars($q['email']) ?></td>
                            <td class="p-2">
                                <?php if ((int)($q['email_verified'] ?? 0) === 1): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-emerald-100 text-emerald-700">Yes</span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-amber-100 text-amber-700">No</span>
                                <?php endif; ?>
                            </td>
                            <td class="p-2"><?= htmlspecialchars($q['clinic_name'] ?? 'Unassigned') ?></td>
                            <td class="p-2">
                                <?php
                                $status = $q['status'] ?? 'PENDING';
                                $badgeClass = $status === 'APPROVED' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700';
                                ?>
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold <?= $badgeClass ?>">
                                    <?= htmlspecialchars($status) ?>
                                </span>
                            </td>
                            <td class="p-2"><?= htmlspecialchars(date('d M Y', strtotime($q['created_at']))) ?></td>
                            <td class="p-2">
                                <?php if (($q['status'] ?? '') !== 'APPROVED'): ?>
                                    <form method="POST">
                                        <?= mch_csrf_input('admin_activation_queue') ?>
                                        <input type="hidden" name="approve_mother_id" value="<?= (int)$q['id'] ?>">
                                        <button class="px-3 py-1 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs hover:bg-emerald-100">
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

    <div class="mch-card bg-white/90 p-6 border border-slate-100 space-y-4">
        <div>
            <h2 class="text-xl font-semibold text-slate-900">Disabled Accounts</h2>
            <p class="text-sm text-slate-600">Activate disabled mother/nurse accounts.</p>
        </div>

        <?php if (empty($disabledUsers)): ?>
            <p class="text-slate-500">No disabled users.</p>
        <?php else: ?>
            <table class="w-full text-sm mch-table mch-scroll">
                <thead>
                    <tr>
                        <th class="p-2 text-left">Name</th>
                        <th class="p-2 text-left">Role</th>
                        <th class="p-2 text-left">Phone</th>
                        <th class="p-2 text-left">Email</th>
                        <th class="p-2 text-left">Clinic</th>
                        <th class="p-2 text-left">Disabled Since</th>
                        <th class="p-2 text-left">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($disabledUsers as $u): ?>
                        <tr class="border-t border-slate-200">
                            <td class="p-2"><?= htmlspecialchars($u['full_name']) ?></td>
                            <td class="p-2">
                                <span class="<?= ($u['role'] ?? '') === 'nurse' ? 'text-teal-700 font-semibold' : 'text-slate-900' ?>">
                                    <?= htmlspecialchars(ucfirst($u['role'])) ?>
                                </span>
                            </td>
                            <td class="p-2"><?= htmlspecialchars($u['phone']) ?></td>
                            <td class="p-2"><?= htmlspecialchars($u['email']) ?></td>
                            <td class="p-2"><?= htmlspecialchars($u['clinic_name'] ?? 'Unassigned') ?></td>
                            <td class="p-2"><?= htmlspecialchars(date('d M Y', strtotime($u['created_at']))) ?></td>
                            <td class="p-2">
                                <form method="POST">
                                    <?= mch_csrf_input('admin_activation_queue') ?>
                                    <input type="hidden" name="activate_user_id" value="<?= (int)$u['id'] ?>">
                                    <button class="px-3 py-1 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs hover:bg-emerald-100">
                                        Activate
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . "/../includes/footer.php"; ?>
