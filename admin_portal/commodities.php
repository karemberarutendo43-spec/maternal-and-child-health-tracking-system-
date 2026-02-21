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
$canEditMaster = $adminAllClinics; // pilot rule: master list managed at district level

$error = '';
$success = '';

$hasTables = false;
try {
    $hasTables = (bool)$pdo->query("SHOW TABLES LIKE 'commodities'")->fetchColumn();
} catch (Throwable $e) {
    $hasTables = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hasTables) {
    mch_csrf_require_valid('admin_commodities');
    $action = (string)($_POST['action'] ?? '');

    try {
        if (!$canEditMaster) {
            throw new Exception("Only District/Super Admin can edit the commodity master list.");
        }

        if ($action === 'create') {
            $code = strtoupper(trim((string)($_POST['code'] ?? '')));
            $name = trim((string)($_POST['name'] ?? ''));
            $unit = trim((string)($_POST['unit'] ?? 'unit'));

            if ($code === '' || $name === '') {
                throw new Exception("Code and name are required.");
            }
            if (!preg_match('/^[A-Z0-9_-]{2,30}$/', $code)) {
                throw new Exception("Code must be 2–30 chars (A–Z, 0–9, underscore, dash).");
            }
            if (mb_strlen($name) > 150) throw new Exception("Name too long (max 150).");
            if ($unit === '') $unit = 'unit';
            if (mb_strlen($unit) > 30) throw new Exception("Unit too long (max 30).");

            $ins = $pdo->prepare("INSERT INTO commodities (code, name, unit, active, created_at) VALUES (?, ?, ?, 1, NOW())");
            $ins->execute([$code, $name, $unit]);
            $success = "Commodity added.";
        } elseif ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            $active = (int)($_POST['active'] ?? 1);
            if ($id <= 0) throw new Exception("Invalid commodity.");
            $new = $active ? 0 : 1;
            $pdo->prepare("UPDATE commodities SET active = ?, updated_at = NOW() WHERE id = ? LIMIT 1")->execute([$new, $id]);
            $success = "Commodity updated.";
        } elseif ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $unit = trim((string)($_POST['unit'] ?? 'unit'));
            if ($id <= 0) throw new Exception("Invalid commodity.");
            if ($name === '') throw new Exception("Name is required.");
            if (mb_strlen($name) > 150) throw new Exception("Name too long (max 150).");
            if ($unit === '') $unit = 'unit';
            if (mb_strlen($unit) > 30) throw new Exception("Unit too long (max 30).");
            $pdo->prepare("UPDATE commodities SET name = ?, unit = ?, updated_at = NOW() WHERE id = ? LIMIT 1")->execute([$name, $unit, $id]);
            $success = "Commodity updated.";
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$commodities = [];
if ($hasTables) {
    $commodities = $pdo->query("SELECT * FROM commodities ORDER BY active DESC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
}

include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_admin.php";
?>

<div class="ml-64 p-6 max-w-6xl space-y-6">
    <div>
        <h1 class="text-3xl font-semibold text-slate-900 mb-1">Commodities (Master List)</h1>
        <p class="text-slate-600">Used by clinic stock tracking and low-stock alerts.</p>
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

        <?php if ($canEditMaster): ?>
            <div class="mch-card bg-white/90 p-6 border border-slate-100 space-y-4">
                <h2 class="text-lg font-semibold text-slate-900">Add Commodity</h2>
                <form method="POST" class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
                    <?= mch_csrf_input('admin_commodities') ?>
                    <input type="hidden" name="action" value="create">
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Code</label>
                        <input name="code" class="w-full border border-slate-200 p-2 rounded-lg bg-white" placeholder="e.g. OX" required>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Name</label>
                        <input name="name" class="w-full border border-slate-200 p-2 rounded-lg bg-white" placeholder="e.g. Oxytocin" required>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Unit</label>
                        <input name="unit" class="w-full border border-slate-200 p-2 rounded-lg bg-white" placeholder="vials">
                    </div>
                    <div class="md:col-span-4">
                        <button class="mch-btn px-4 py-2">Add</button>
                    </div>
                </form>
            </div>
        <?php else: ?>
            <div class="text-sm text-slate-500">Clinic admins can view the list; District/Super Admin manages the master list.</div>
        <?php endif; ?>

        <div class="mch-card bg-white/90 p-6 border border-slate-100">
            <?php if (empty($commodities)): ?>
                <p class="text-slate-500">No commodities found.</p>
            <?php else: ?>
                <table class="w-full text-sm mch-table mch-scroll">
                    <thead>
                        <tr>
                            <th class="p-2 text-left">Code</th>
                            <th class="p-2 text-left">Name</th>
                            <th class="p-2 text-left">Unit</th>
                            <th class="p-2 text-left">Status</th>
                            <th class="p-2 text-left">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($commodities as $c): ?>
                            <tr class="border-t border-slate-200">
                                <td class="p-2 font-mono"><?= htmlspecialchars((string)$c['code']) ?></td>
                                <td class="p-2">
                                    <?php if ($canEditMaster): ?>
                                        <form method="POST" class="flex flex-wrap gap-2 items-center">
                                            <?= mch_csrf_input('admin_commodities') ?>
                                            <input type="hidden" name="action" value="update">
                                            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                            <input name="name" value="<?= htmlspecialchars((string)$c['name']) ?>" class="border border-slate-200 p-1 rounded-lg bg-white w-64">
                                            <input name="unit" value="<?= htmlspecialchars((string)$c['unit']) ?>" class="border border-slate-200 p-1 rounded-lg bg-white w-24">
                                            <button class="px-3 py-1 rounded-lg bg-slate-900 text-white text-xs">Save</button>
                                        </form>
                                    <?php else: ?>
                                        <?= htmlspecialchars((string)$c['name']) ?>
                                    <?php endif; ?>
                                </td>
                                <td class="p-2"><?= htmlspecialchars((string)$c['unit']) ?></td>
                                <td class="p-2">
                                    <span class="<?= (int)$c['active'] === 1 ? 'text-emerald-700 font-semibold' : 'text-slate-500' ?>">
                                        <?= (int)$c['active'] === 1 ? 'ACTIVE' : 'INACTIVE' ?>
                                    </span>
                                </td>
                                <td class="p-2">
                                    <?php if ($canEditMaster): ?>
                                        <form method="POST" class="inline">
                                            <?= mch_csrf_input('admin_commodities') ?>
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                            <input type="hidden" name="active" value="<?= (int)$c['active'] ?>">
                                            <button class="px-3 py-1 rounded-lg text-xs <?= (int)$c['active'] === 1 ? 'bg-rose-50 border border-rose-200 text-rose-700' : 'bg-emerald-50 border border-emerald-200 text-emerald-700' ?>">
                                                <?= (int)$c['active'] === 1 ? 'Disable' : 'Activate' ?>
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

    <?php endif; ?>
</div>

<?php include __DIR__ . "/../includes/footer.php"; ?>
