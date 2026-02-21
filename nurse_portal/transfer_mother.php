<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/cards.php";
require_once __DIR__ . "/../config/csrf.php";

// Access check
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'nurse'])) {
    header("Location: /mch_system/auth/login.php");
    exit;
}

$page = 'transfer_mother';
include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_nurse.php";

$error = '';
$success = '';

$mother_id = (int)($_GET['mother_id'] ?? 0);
if (!$mother_id) {
    $error = "Mother not specified.";
}

$clinic_id = $_SESSION['clinic_id'] ?? null;
if (empty($clinic_id)) {
    $error = "Your clinic is not set. Please contact an admin to assign your clinic before transferring.";
}

$mother = null;
if ($mother_id) {
    $mstmt = $pdo->prepare("
        SELECT m.id, m.fullname, m.hospital_number, m.user_id, u.clinic_id
        FROM mothers m
        JOIN users u ON m.user_id = u.id
        WHERE m.id = ?
        LIMIT 1
    ");
    $mstmt->execute([$mother_id]);
    $mother = $mstmt->fetch(PDO::FETCH_ASSOC);
    if (!$mother) {
        $error = "Mother not found.";
    }
}

$clinics = $pdo->query("SELECT id, clinic_name FROM clinics ORDER BY clinic_name")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    mch_csrf_require_valid('nurse_transfer_mother');
    // Prevent duplicate pending requests for the same mother.
    try {
        $chk = $pdo->prepare("SELECT id FROM mother_transfers WHERE mother_id = ? AND status = 'PENDING' LIMIT 1");
        $chk->execute([$mother_id]);
        if ($chk->fetch(PDO::FETCH_ASSOC)) {
            $error = "This mother already has a pending transfer request. Please wait for admin approval.";
        }
    } catch (Throwable $e) {
        // ignore if table doesn't exist
    }

    $to_clinic = (int)($_POST['to_clinic'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');

    if (!$to_clinic) {
        $error = "Please select a destination clinic.";
    } elseif ($to_clinic === (int)$clinic_id) {
        $error = "Destination clinic must be different.";
    } else {
        // Strictly pending transfer: only admin approval moves the mother to the destination clinic.
        $pdo->beginTransaction();
        $ins = $pdo->prepare("
            INSERT INTO mother_transfers
            (mother_id, from_clinic_id, to_clinic_id, requested_by, reason, status, created_at)
            VALUES (?, ?, ?, ?, ?, 'PENDING', NOW())
        ");
        $ins->execute([
            $mother_id,
            $mother['clinic_id'],
            $to_clinic,
            $_SESSION['user_id'],
            $reason
        ]);
        $pdo->commit();
        $success = "Transfer requested. Pending admin approval.";
    }
}
?>

<div class="ml-64 p-6 max-w-3xl">
    <div class="mch-card bg-white/90 p-6 border border-slate-100">
        <h2 class="text-2xl font-semibold text-slate-900 mb-4">Transfer Mother</h2>

        <?php if ($error): ?>
            <div class="bg-rose-100 text-rose-700 p-3 rounded-lg mb-4"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="bg-emerald-100 text-emerald-700 p-3 rounded-lg mb-4"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <?php if ($mother): ?>
            <div class="mb-4 text-sm text-slate-700">
                <div><span class="font-semibold">Mother:</span> <?= htmlspecialchars($mother['fullname']) ?></div>
                <div><span class="font-semibold">Hospital #:</span> <?= htmlspecialchars($mother['hospital_number']) ?></div>
            </div>

            <form method="POST" class="space-y-4">
                <?= mch_csrf_input('nurse_transfer_mother') ?>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1">Destination Clinic</label>
                    <select name="to_clinic" class="w-full border border-slate-200 p-2 rounded-lg bg-white" required>
                        <option value="">Select clinic</option>
                        <?php foreach ($clinics as $c): ?>
                            <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['clinic_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1">Reason (optional)</label>
                    <textarea name="reason" class="w-full border border-slate-200 p-2 rounded-lg bg-white" rows="3"></textarea>
                </div>
                <button class="mch-btn px-4 py-2">Request Transfer</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . "/../includes/footer.php"; ?>
