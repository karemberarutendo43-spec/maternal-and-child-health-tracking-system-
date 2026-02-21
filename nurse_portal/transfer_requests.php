<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/cards.php";

auth_guard(['nurse', 'admin']);

$uid = (int)($_SESSION['user_id'] ?? 0);
$error = '';

$supportsSeen = db_has_column($pdo, 'mother_transfers', 'nurse_seen_at');
if ($supportsSeen && $uid > 0) {
    try {
        $pdo->prepare("
            UPDATE mother_transfers
            SET nurse_seen_at = NOW()
            WHERE requested_by = ?
              AND status = 'REJECTED'
              AND nurse_seen_at IS NULL
        ")->execute([$uid]);
    } catch (Throwable $e) {
        // ignore if table/column isn't available
    }
}

try {
    $stmt = $pdo->prepare("
        SELECT
            t.id,
            t.status,
            t.reason,
            t.created_at,
            t.approved_at,
            m.id AS mother_id,
            m.fullname,
            m.hospital_number,
            cf.clinic_name AS from_clinic,
            ct.clinic_name AS to_clinic
        FROM mother_transfers t
        JOIN mothers m ON t.mother_id = m.id
        LEFT JOIN clinics cf ON t.from_clinic_id = cf.id
        LEFT JOIN clinics ct ON t.to_clinic_id = ct.id
        WHERE t.requested_by = ?
        ORDER BY t.created_at DESC
        LIMIT 100
    ");
    $stmt->execute([$uid]);
    $transfers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $transfers = [];
    $error = "Transfers are not configured yet. Ask the admin to ensure the mother_transfers table exists.";
}

$page = 'transfer_requests';
include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_nurse.php";
?>

<div class="ml-64 p-6 max-w-6xl space-y-6">
    <div>
        <h1 class="text-3xl font-semibold text-slate-900 mb-1">My Transfer Requests</h1>
        <p class="text-slate-600">Track approvals and rejections for transfers you requested.</p>
    </div>

    <?php if ($error): ?>
        <div class="mch-card bg-amber-50 border border-amber-200 text-amber-800 p-4">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <div class="mch-card bg-white/90 p-6 border border-slate-100">
        <?php if (empty($transfers)): ?>
            <p class="text-slate-500">No transfer requests found.</p>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm mch-table mch-scroll">
                    <thead>
                        <tr>
                            <th class="p-2 text-left">Mother</th>
                            <th class="p-2 text-left">Hospital #</th>
                            <th class="p-2 text-left">From</th>
                            <th class="p-2 text-left">To</th>
                            <th class="p-2 text-left">Status</th>
                            <th class="p-2 text-left">Reason</th>
                            <th class="p-2 text-left">Requested</th>
                            <th class="p-2 text-left">Processed</th>
                            <th class="p-2 text-left">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transfers as $t): ?>
                            <?php
                                $st = strtoupper((string)($t['status'] ?? ''));
                                $badge = 'bg-slate-100 text-slate-700';
                                if ($st === 'PENDING') $badge = 'bg-amber-100 text-amber-800';
                                if ($st === 'APPROVED') $badge = 'bg-emerald-100 text-emerald-700';
                                if ($st === 'REJECTED') $badge = 'bg-rose-100 text-rose-700';
                            ?>
                            <tr class="border-t border-slate-200">
                                <td class="p-2"><?= htmlspecialchars((string)$t['fullname']) ?></td>
                                <td class="p-2"><?= htmlspecialchars((string)($t['hospital_number'] ?? '-')) ?></td>
                                <td class="p-2"><?= htmlspecialchars((string)($t['from_clinic'] ?? 'Unassigned')) ?></td>
                                <td class="p-2"><?= htmlspecialchars((string)($t['to_clinic'] ?? '-')) ?></td>
                                <td class="p-2">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs <?= $badge ?>">
                                        <?= htmlspecialchars($st ?: '-') ?>
                                    </span>
                                </td>
                                <td class="p-2"><?= htmlspecialchars((string)($t['reason'] ?: '-')) ?></td>
                                <td class="p-2 text-slate-500"><?= !empty($t['created_at']) ? htmlspecialchars(date('d M Y', strtotime((string)$t['created_at']))) : '-' ?></td>
                                <td class="p-2 text-slate-500"><?= !empty($t['approved_at']) ? htmlspecialchars(date('d M Y', strtotime((string)$t['approved_at']))) : '-' ?></td>
                                <td class="p-2">
                                    <a class="inline-flex items-center px-3 py-1 rounded-lg bg-slate-50 border border-slate-200 hover:bg-slate-100 text-sm"
                                       href="/mch_system/nurse_portal/mother_history.php?mother_id=<?= (int)$t['mother_id'] ?>">
                                        Open
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . "/../includes/footer.php"; ?>

