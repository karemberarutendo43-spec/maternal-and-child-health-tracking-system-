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

$error = '';
$success = '';

$hasTables = false;
try {
    $hasTables = (bool)$pdo->query("SHOW TABLES LIKE 'commodity_stock_tx'")->fetchColumn();
} catch (Throwable $e) {
    $hasTables = false;
}

// Load active commodities
$commodities = [];
if ($hasTables) {
    $commodities = $pdo->query("SELECT id, code, name, unit FROM commodities WHERE active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasTables) {
    mch_csrf_require_valid('nurse_stock');
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'tx') {
            $commodityId = (int)($_POST['commodity_id'] ?? 0);
            $txType = strtoupper(trim((string)($_POST['tx_type'] ?? '')));
            $qtyRaw = trim((string)($_POST['qty'] ?? ''));
            $txDate = (string)($_POST['tx_date'] ?? date('Y-m-d'));
            $ref = trim((string)($_POST['reference'] ?? ''));
            $notes = trim((string)($_POST['notes'] ?? ''));

            if ($commodityId <= 0) throw new Exception("Select a commodity.");
            if (!in_array($txType, ['RECEIVE', 'ISSUE', 'ADJUST'], true)) throw new Exception("Invalid transaction type.");
            if ($qtyRaw === '' || !is_numeric($qtyRaw)) throw new Exception("Quantity is required.");
            $qty = (float)$qtyRaw;
            if ($qty <= 0) throw new Exception("Quantity must be > 0.");
            if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $txDate)) throw new Exception("Invalid date.");
            if (strtotime($txDate) === false) throw new Exception("Invalid date.");
            if (strtotime($txDate) > time() + 86400) throw new Exception("Transaction date cannot be in the future.");
            if ($ref !== '' && mb_strlen($ref) > 60) throw new Exception("Reference too long (max 60).");
            if ($notes !== '' && mb_strlen($notes) > 255) throw new Exception("Notes too long (max 255).");

            // Ensure commodity exists and is active
            $chk = $pdo->prepare("SELECT code, name, unit FROM commodities WHERE id = ? AND active = 1 LIMIT 1");
            $chk->execute([$commodityId]);
            $cm = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$cm) throw new Exception("Commodity not found or inactive.");

            $pdo->beginTransaction();

            // Initialize level row
            $pdo->prepare("
                INSERT INTO commodity_stock_levels (clinic_id, commodity_id, on_hand, reorder_level, updated_at)
                VALUES (?, ?, 0, 0, NOW())
                ON DUPLICATE KEY UPDATE updated_at = updated_at
            ")->execute([$scopedClinicId, $commodityId]);

            // Read current
            $curStmt = $pdo->prepare("SELECT on_hand FROM commodity_stock_levels WHERE clinic_id = ? AND commodity_id = ? LIMIT 1 FOR UPDATE");
            $curStmt->execute([$scopedClinicId, $commodityId]);
            $onHand = (float)$curStmt->fetchColumn();

            $delta = $qty;
            if ($txType === 'ISSUE') {
                $delta = -$qty;
            } elseif ($txType === 'ADJUST') {
                // ADJUST means: set absolute stock to qty (so delta = qty - current)
                $delta = $qty - $onHand;
            }

            $newOnHand = $onHand + $delta;
            if ($newOnHand < 0) {
                throw new Exception("Not enough stock. Current on-hand is {$onHand}.");
            }

            $pdo->prepare("
                INSERT INTO commodity_stock_tx (clinic_id, commodity_id, tx_type, qty, tx_date, reference, notes, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ")->execute([
                $scopedClinicId,
                $commodityId,
                $txType,
                $qty,
                $txDate,
                ($ref !== '' ? $ref : null),
                ($notes !== '' ? $notes : null),
                (int)($_SESSION['user_id'] ?? 0),
            ]);

            $pdo->prepare("
                UPDATE commodity_stock_levels
                SET on_hand = ?, updated_at = NOW()
                WHERE clinic_id = ? AND commodity_id = ?
                LIMIT 1
            ")->execute([$newOnHand, $scopedClinicId, $commodityId]);

            mch_audit_log(
                $pdo,
                'STOCK_TX',
                'stock',
                $commodityId,
                "Stock {$txType} {$qty} for {$cm['name']} (new on-hand {$newOnHand})"
            );

            $pdo->commit();
            $success = "Stock updated. New on-hand: {$newOnHand} {$cm['unit']}.";
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

$levels = [];
$lowCount = 0;
if ($hasTables) {
    $stmt = $pdo->prepare("
        SELECT l.*, cm.code, cm.name, cm.unit
        FROM commodity_stock_levels l
        JOIN commodities cm ON cm.id = l.commodity_id
        WHERE l.clinic_id = ?
          AND cm.active = 1
        ORDER BY cm.name ASC
    ");
    $stmt->execute([$scopedClinicId]);
    $levels = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $lowStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM commodity_stock_levels l
        JOIN commodities cm ON cm.id = l.commodity_id
        WHERE l.clinic_id = ?
          AND cm.active = 1
          AND l.reorder_level > 0
          AND l.on_hand <= l.reorder_level
    ");
    $lowStmt->execute([$scopedClinicId]);
    $lowCount = (int)$lowStmt->fetchColumn();
}

include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_nurse.php";
?>

<div class="ml-64 p-6 max-w-6xl space-y-6">
    <div class="flex flex-col gap-2 md:flex-row md:items-end md:justify-between">
        <div>
            <h1 class="text-3xl font-semibold text-slate-900 mb-1">Stock (Clinic Supplies)</h1>
            <p class="text-slate-600">Record receipts/issues and monitor low-stock items.</p>
        </div>
        <?php if ($hasTables): ?>
            <div class="text-sm">
                <span class="px-3 py-1 rounded-full bg-rose-50 border border-rose-200 text-rose-700 font-semibold">
                    Low stock: <?= (int)$lowCount ?>
                </span>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!$hasTables): ?>
        <div class="mch-card bg-amber-50 border border-amber-200 text-amber-800 p-4">
            Stock module is not configured yet. Ask Admin to run <span class="font-mono">sql/commodities_stock.sql</span>.
        </div>
    <?php else: ?>
        <?php if ($error): ?>
            <div class="mch-card bg-rose-50 border border-rose-200 text-rose-700 p-4"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="mch-card bg-emerald-50 border border-emerald-200 text-emerald-700 p-4"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <div class="mch-card bg-white/90 p-6 border border-slate-100 space-y-4">
            <h2 class="text-lg font-semibold text-slate-900">Record Transaction</h2>
            <form method="POST" class="grid grid-cols-1 md:grid-cols-6 gap-3 items-end">
                <?= mch_csrf_input('nurse_stock') ?>
                <input type="hidden" name="action" value="tx">

                <div class="md:col-span-2">
                    <label class="block text-sm font-semibold text-slate-700 mb-1">Commodity</label>
                    <select name="commodity_id" class="w-full border border-slate-200 p-2 rounded-lg bg-white" required>
                        <option value="">Select</option>
                        <?php foreach ($commodities as $c): ?>
                            <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name'] . ' (' . $c['unit'] . ')') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1">Type</label>
                    <select name="tx_type" class="w-full border border-slate-200 p-2 rounded-lg bg-white" required>
                        <option value="RECEIVE">Receive</option>
                        <option value="ISSUE">Issue</option>
                        <option value="ADJUST">Adjust (set)</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1">Quantity</label>
                    <input name="qty" type="number" step="0.01" min="0.01" class="w-full border border-slate-200 p-2 rounded-lg bg-white" required>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1">Date</label>
                    <input name="tx_date" type="date" class="w-full border border-slate-200 p-2 rounded-lg bg-white" value="<?= date('Y-m-d') ?>" required>
                </div>

                <div class="md:col-span-2">
                    <label class="block text-sm font-semibold text-slate-700 mb-1">Reference (optional)</label>
                    <input name="reference" class="w-full border border-slate-200 p-2 rounded-lg bg-white" placeholder="e.g. GRV-123 / Invoice #">
                </div>

                <div class="md:col-span-4">
                    <label class="block text-sm font-semibold text-slate-700 mb-1">Notes (optional)</label>
                    <input name="notes" class="w-full border border-slate-200 p-2 rounded-lg bg-white" placeholder="Any details for supervisor / audit">
                </div>

                <div class="md:col-span-2">
                    <button class="mch-btn w-full py-2">Save</button>
                </div>
            </form>
        </div>

        <div class="mch-card bg-white/90 p-6 border border-slate-100">
            <h2 class="text-lg font-semibold text-slate-900 mb-3">Current Levels</h2>
            <?php if (empty($levels)): ?>
                <p class="text-slate-500">No stock levels yet. Record a transaction to initialize an item.</p>
            <?php else: ?>
                <table class="w-full text-sm mch-table mch-scroll">
                    <thead>
                        <tr>
                            <th class="p-2 text-left">Code</th>
                            <th class="p-2 text-left">Commodity</th>
                            <th class="p-2 text-left">On Hand</th>
                            <th class="p-2 text-left">Reorder Level</th>
                            <th class="p-2 text-left">Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($levels as $l): ?>
                            <?php $low = ((float)$l['reorder_level'] > 0) && ((float)$l['on_hand'] <= (float)$l['reorder_level']); ?>
                            <tr class="border-t border-slate-200 <?= $low ? 'bg-rose-50' : '' ?>">
                                <td class="p-2 font-mono"><?= htmlspecialchars((string)$l['code']) ?></td>
                                <td class="p-2">
                                    <div class="font-semibold text-slate-900"><?= htmlspecialchars((string)$l['name']) ?></div>
                                    <div class="text-xs text-slate-500"><?= htmlspecialchars((string)$l['unit']) ?></div>
                                </td>
                                <td class="p-2">
                                    <span class="<?= $low ? 'text-rose-700 font-semibold' : 'text-slate-900' ?>">
                                        <?= htmlspecialchars((string)$l['on_hand']) ?>
                                    </span>
                                </td>
                                <td class="p-2"><?= htmlspecialchars((string)$l['reorder_level']) ?></td>
                                <td class="p-2 text-xs text-slate-500"><?= htmlspecialchars((string)$l['updated_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="text-xs text-slate-500 mt-3">
                    Reorder levels are set by Admin/Clinical Admin in <span class="font-mono">admin_portal/stock.php</span>.
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . "/../includes/footer.php"; ?>
