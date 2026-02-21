<?php
require_once __DIR__ . "/../config/session.php";
mch_session_start();
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/csrf.php";
require_once __DIR__ . "/../config/cards.php";

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: /mch_system/auth/login.php");
    exit;
}

$adminLevel = (string)($_SESSION['admin_level'] ?? 'SUPER');
$adminClinicId = (int)($_SESSION['clinic_id'] ?? 0);
$adminAllClinics = ($adminLevel === 'SUPER' || $adminLevel === 'DISTRICT');
if (!$adminAllClinics && $adminClinicId <= 0) {
    die("Clinic admin is not assigned to a clinic.");
}

$error = '';
$success = '';

$hasTables = false;
try {
    $hasTables = (bool)$pdo->query("SHOW TABLES LIKE 'commodity_stock_levels'")->fetchColumn();
} catch (Throwable $e) {
    $hasTables = false;
}

$selectedClinicId = $adminAllClinics ? (int)($_GET['clinic_id'] ?? 0) : $adminClinicId;
if (!$adminAllClinics) {
    $selectedClinicId = $adminClinicId;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasTables) {
    mch_csrf_require_valid('admin_stock');
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'set_reorder') {
            $clinicId = $adminAllClinics ? (int)($_POST['clinic_id'] ?? 0) : $adminClinicId;
            $commodityId = (int)($_POST['commodity_id'] ?? 0);
            $levelRaw = trim((string)($_POST['reorder_level'] ?? '0'));
            $level = is_numeric($levelRaw) ? (float)$levelRaw : -1;
            if ($clinicId <= 0) throw new Exception("Select a clinic.");
            if ($commodityId <= 0) throw new Exception("Invalid commodity.");
            if ($level < 0) throw new Exception("Reorder level must be >= 0.");
            if (!$adminAllClinics && $clinicId !== $adminClinicId) throw new Exception("Forbidden.");

            $pdo->beginTransaction();
            $pdo->prepare("
                INSERT INTO commodity_stock_levels (clinic_id, commodity_id, on_hand, reorder_level, updated_at)
                VALUES (?, ?, 0, ?, NOW())
                ON DUPLICATE KEY UPDATE reorder_level = VALUES(reorder_level), updated_at = NOW()
            ")->execute([$clinicId, $commodityId, $level]);
            $pdo->commit();
            $success = "Reorder level saved.";
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

$clinics = [];
if ($adminAllClinics) {
    $clinics = $pdo->query("SELECT id, clinic_name FROM clinics ORDER BY clinic_name")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare("SELECT id, clinic_name FROM clinics WHERE id = ? LIMIT 1");
    $stmt->execute([$adminClinicId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $clinics = $row ? [$row] : [];
}

$rows = [];
$lowCount = 0;
if ($hasTables) {
    $whereClinic = $selectedClinicId > 0 ? "WHERE l.clinic_id = ?" : "";
    $params = $selectedClinicId > 0 ? [$selectedClinicId] : [];
    $rowsStmt = $pdo->prepare("
        SELECT
          l.clinic_id,
          c.clinic_name,
          l.commodity_id,
          cm.code,
          cm.name,
          cm.unit,
          cm.active,
          l.on_hand,
          l.reorder_level,
          l.updated_at
        FROM commodity_stock_levels l
        JOIN commodities cm ON cm.id = l.commodity_id
        LEFT JOIN clinics c ON c.id = l.clinic_id
        {$whereClinic}
        ORDER BY c.clinic_name ASC, cm.active DESC, cm.name ASC
    ");
    $rowsStmt->execute($params);
    $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

    $lowStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM commodity_stock_levels l
        JOIN commodities cm ON cm.id = l.commodity_id
        WHERE cm.active = 1
          AND l.reorder_level > 0
          AND l.on_hand <= l.reorder_level
          AND (? = 0 OR l.clinic_id = ?)
    ");
    $lowStmt->execute([$selectedClinicId, $selectedClinicId]);
    $lowCount = (int)$lowStmt->fetchColumn();
}

include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_admin.php";
?>

<div class="ml-64 p-6 max-w-7xl space-y-6">
    <div class="flex flex-col gap-2 md:flex-row md:items-end md:justify-between">
        <div>
            <h1 class="text-3xl font-semibold text-slate-900 mb-1">Stock (Clinic Supplies)</h1>
            <p class="text-slate-600">Track on-hand levels and low-stock alerts per clinic.</p>
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
            Stock module is not configured yet. Run <span class="font-mono">sql/commodities_stock.sql</span> on the database.
        </div>
    <?php else: ?>
        <?php if ($error): ?>
            <div class="mch-card bg-rose-50 border border-rose-200 text-rose-700 p-4"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="mch-card bg-emerald-50 border border-emerald-200 text-emerald-700 p-4"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <div class="mch-card bg-white/90 p-6 border border-slate-100 space-y-4">
            <div class="flex flex-wrap gap-3 items-center justify-between">
                <div class="text-sm text-slate-600">
                    Tip: nurses record <span class="font-semibold">Receive/Issue/Adjust</span> transactions; this page is for monitoring and reorder thresholds.
                </div>
                <a class="text-sm font-semibold text-teal-700 hover:underline" href="/mch_system/admin_portal/commodities.php">Manage Commodities</a>
            </div>

            <form method="GET" class="flex flex-wrap gap-2 items-center">
                <?php if ($adminAllClinics): ?>
                    <label class="text-sm text-slate-600">Clinic:</label>
                    <select name="clinic_id" class="border border-slate-200 p-2 rounded-lg bg-white">
                        <option value="0">All Clinics</option>
                        <?php foreach ($clinics as $c): ?>
                            <option value="<?= (int)$c['id'] ?>" <?= $selectedClinicId === (int)$c['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['clinic_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button class="px-3 py-2 rounded-lg bg-slate-900 text-white text-sm">Apply</button>
                <?php else: ?>
                    <div class="text-sm text-slate-500">Clinic-scoped view</div>
                <?php endif; ?>
            </form>
        </div>

        <div class="mch-card bg-white/90 p-6 border border-slate-100">
            <?php if (empty($rows)): ?>
                <p class="text-slate-500">No stock levels yet. Ask nurses to record transactions, or set reorder levels to initialize rows.</p>
            <?php else: ?>
                <table class="w-full text-sm mch-table mch-scroll">
                    <thead>
                        <tr>
                            <th class="p-2 text-left">Clinic</th>
                            <th class="p-2 text-left">Code</th>
                            <th class="p-2 text-left">Commodity</th>
                            <th class="p-2 text-left">On Hand</th>
                            <th class="p-2 text-left">Reorder Level</th>
                            <th class="p-2 text-left">Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <?php
                              $low = ((int)$r['active'] === 1) && ((float)$r['reorder_level'] > 0) && ((float)$r['on_hand'] <= (float)$r['reorder_level']);
                            ?>
                            <tr class="border-t border-slate-200 <?= $low ? 'bg-rose-50' : '' ?>">
                                <td class="p-2"><?= htmlspecialchars((string)($r['clinic_name'] ?? 'Unknown')) ?></td>
                                <td class="p-2 font-mono"><?= htmlspecialchars((string)$r['code']) ?></td>
                                <td class="p-2">
                                    <div class="font-semibold text-slate-900"><?= htmlspecialchars((string)$r['name']) ?></div>
                                    <div class="text-xs text-slate-500"><?= htmlspecialchars((string)$r['unit']) ?></div>
                                </td>
                                <td class="p-2">
                                    <span class="<?= $low ? 'text-rose-700 font-semibold' : 'text-slate-900' ?>">
                                        <?= htmlspecialchars((string)$r['on_hand']) ?>
                                    </span>
                                </td>
                                <td class="p-2">
                                    <form method="POST" class="flex items-center gap-2">
                                        <?= mch_csrf_input('admin_stock') ?>
                                        <input type="hidden" name="action" value="set_reorder">
                                        <input type="hidden" name="clinic_id" value="<?= (int)$r['clinic_id'] ?>">
                                        <input type="hidden" name="commodity_id" value="<?= (int)$r['commodity_id'] ?>">
                                        <input name="reorder_level" type="number" step="0.01" min="0"
                                               value="<?= htmlspecialchars((string)$r['reorder_level']) ?>"
                                               class="border border-slate-200 p-1 rounded-lg bg-white w-28">
                                        <button class="px-3 py-1 rounded-lg bg-slate-900 text-white text-xs">Save</button>
                                    </form>
                                </td>
                                <td class="p-2 text-slate-500 text-xs"><?= htmlspecialchars((string)$r['updated_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

    <?php endif; ?>
</div>

<?php include __DIR__ . "/../includes/footer.php"; ?>
