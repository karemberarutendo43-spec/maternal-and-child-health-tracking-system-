<?php
require_once __DIR__ . "/../config/session.php";
mch_session_start();
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/sms.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/csrf.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: /mch_system/auth/login.php");
    exit;
}

$adminLevel = (string)($_SESSION['admin_level'] ?? 'SUPER');
$adminClinicId = (int)($_SESSION['clinic_id'] ?? 0);
$adminAllClinics = ($adminLevel === 'SUPER' || $adminLevel === 'DISTRICT');
$canDecideTransfers = $adminAllClinics;
if (!$adminAllClinics && $adminClinicId <= 0) {
    die("Clinic admin is not assigned to a clinic.");
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mch_csrf_require_valid('admin_transfers');
    $action = $_POST['action'] ?? '';
    $transfer_id = (int)($_POST['transfer_id'] ?? 0);

    if ($transfer_id && in_array($action, ['approve', 'reject'], true)) {
        try {
            if (!$canDecideTransfers) {
                throw new Exception("Only District/Super Admin can approve or reject transfer requests.");
            }

            $tstmt = $pdo->prepare("
                SELECT t.id, t.mother_id, t.from_clinic_id, t.to_clinic_id, t.status, m.user_id
                FROM mother_transfers t
                JOIN mothers m ON t.mother_id = m.id
                WHERE t.id = ?
                LIMIT 1
            ");
            $tstmt->execute([$transfer_id]);
            $t = $tstmt->fetch(PDO::FETCH_ASSOC);

            if (!$t) {
                $error = "Transfer request not found.";
            } elseif ($t['status'] !== 'PENDING') {
                $error = "This transfer is already processed.";
            } else {
                if ($action === 'approve') {
                    $pdo->beginTransaction();
                    $upd = $pdo->prepare("
                        UPDATE mother_transfers
                        SET status = 'APPROVED', approved_by = ?, approved_at = NOW()
                        WHERE id = ?
                    ");
                    $upd->execute([$_SESSION['user_id'], $transfer_id]);

                    // Apply the clinic move on approval (strictly pending until approved).
                    $apply = $pdo->prepare("UPDATE users SET clinic_id = ? WHERE id = ?");
                    $apply->execute([$t['to_clinic_id'], $t['user_id']]);
                    $pdo->commit();

                    // Notify mother (email/SMS)
                    $notify = $pdo->prepare("
                        SELECT u.email, u.phone, m.fullname
                        FROM mothers m
                        JOIN users u ON m.user_id = u.id
                        WHERE m.id = ? LIMIT 1
                    ");
                    $notify->execute([$t['mother_id']]);
                    $n = $notify->fetch(PDO::FETCH_ASSOC);
                    if ($n) {
                        $msg = "Hello {$n['fullname']}, your clinic transfer has been approved.";
                        if (!empty($n['email'])) {
                            @mail($n['email'], "Clinic Transfer Approved", $msg, "From: no-reply@mchts.local");
                        }
                        if (!empty($n['phone']) && function_exists('sendSms')) {
                            sendSms($n['phone'], $msg);
                        }
                    }
                    $success = "Transfer approved.";
                } else {
                    // Reject: revert clinic_id back to from_clinic_id (safe for both old provisional transfers and new strict-pending ones).
                    $pdo->beginTransaction();
                    $upd = $pdo->prepare("
                        UPDATE mother_transfers
                        SET status = 'REJECTED', approved_by = ?, approved_at = NOW()
                        WHERE id = ?
                    ");
                    $upd->execute([$_SESSION['user_id'], $transfer_id]);

                    $revert = $pdo->prepare("UPDATE users SET clinic_id = ? WHERE id = ?");
                    $revert->execute([$t['from_clinic_id'], $t['user_id']]);
                    $pdo->commit();

                    // Notify mother (email/SMS)
                    $notify = $pdo->prepare("
                        SELECT u.email, u.phone, m.fullname
                        FROM mothers m
                        JOIN users u ON m.user_id = u.id
                        WHERE m.id = ? LIMIT 1
                    ");
                    $notify->execute([$t['mother_id']]);
                    $n = $notify->fetch(PDO::FETCH_ASSOC);
                    if ($n) {
                        $msg = "Hello {$n['fullname']}, your clinic transfer was rejected. Please contact your clinic.";
                        if (!empty($n['email'])) {
                            @mail($n['email'], "Clinic Transfer Update", $msg, "From: no-reply@mchts.local");
                        }
                        if (!empty($n['phone']) && function_exists('sendSms')) {
                            sendSms($n['phone'], $msg);
                        }
                    }
                    $success = "Transfer rejected and clinic reverted.";
                }
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = "Error: " . $e->getMessage();
        }
    }
}

$pendingSql = "
    SELECT t.id, t.mother_id, t.from_clinic_id, t.to_clinic_id, t.reason, t.created_at,
           m.fullname, m.hospital_number,
           cf.clinic_name AS from_clinic, ct.clinic_name AS to_clinic
    FROM mother_transfers t
    JOIN mothers m ON t.mother_id = m.id
    LEFT JOIN clinics cf ON t.from_clinic_id = cf.id
    LEFT JOIN clinics ct ON t.to_clinic_id = ct.id
    WHERE t.status = 'PENDING'
";
$pendingParams = [];
if (!$adminAllClinics) {
    $pendingSql .= " AND (t.from_clinic_id = ? OR t.to_clinic_id = ?)";
    $pendingParams = [$adminClinicId, $adminClinicId];
}
$pendingSql .= " ORDER BY t.created_at DESC";
$pendingStmt = $pdo->prepare($pendingSql);
$pendingStmt->execute($pendingParams);
$pending = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_admin.php";
?>

<div class="ml-64 p-6 max-w-6xl space-y-6">
    <div>
        <h1 class="text-3xl font-semibold text-slate-900 mb-1">Transfer Requests</h1>
        <p class="text-slate-600">Approve or reject clinic transfers requested by nurses.</p>
        <?php if (!$canDecideTransfers): ?>
            <div class="mt-2 text-sm text-slate-500">
                Clinic admins can view transfers involving their clinic. Approval/rejection is done by District/Super Admin.
            </div>
        <?php endif; ?>
    </div>

    <div class="mch-card bg-white/90 p-6 border border-slate-100">
        <?php if ($error): ?>
            <div class="bg-rose-100 text-rose-700 p-3 rounded-lg mb-4"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="bg-emerald-100 text-emerald-700 p-3 rounded-lg mb-4"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <?php if (empty($pending)): ?>
            <p class="text-slate-500">No pending transfer requests.</p>
        <?php else: ?>
            <table class="w-full text-sm mch-table mch-scroll">
                <thead>
                    <tr>
                        <th class="p-2 text-left">Mother</th>
                        <th class="p-2 text-left">Hospital #</th>
                        <th class="p-2 text-left">From Clinic</th>
                        <th class="p-2 text-left">To Clinic</th>
                        <th class="p-2 text-left">Reason</th>
                        <th class="p-2 text-left">Requested</th>
                        <th class="p-2 text-left">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pending as $p): ?>
                        <tr class="border-t border-slate-200">
                            <td class="p-2"><?= htmlspecialchars($p['fullname']) ?></td>
                            <td class="p-2"><?= htmlspecialchars($p['hospital_number']) ?></td>
                            <td class="p-2"><?= htmlspecialchars($p['from_clinic'] ?? 'Unassigned') ?></td>
                            <td class="p-2"><?= htmlspecialchars($p['to_clinic']) ?></td>
                            <td class="p-2"><?= htmlspecialchars($p['reason'] ?: '-') ?></td>
                            <td class="p-2"><?= htmlspecialchars(date('d M Y', strtotime($p['created_at']))) ?></td>
                            <td class="p-2">
                                <?php if ($canDecideTransfers): ?>
                                    <form method="POST" class="flex gap-2">
                                        <?= mch_csrf_input('admin_transfers') ?>
                                        <input type="hidden" name="transfer_id" value="<?= (int)$p['id'] ?>">
                                        <button name="action" value="approve" class="px-3 py-1 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs">
                                            Approve
                                        </button>
                                        <button name="action" value="reject" class="px-3 py-1 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-xs">
                                            Reject
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-slate-400 text-sm">View only</span>
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
