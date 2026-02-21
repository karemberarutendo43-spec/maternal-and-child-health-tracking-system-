<?php
require_once __DIR__ . "/../config/session.php";
mch_session_start();
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/scope.php";
require_once __DIR__ . "/../config/audit.php";

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'health_info') {
    header("Location: /mch_system/auth/login.php");
    exit;
}

$hasRegisterTable = false;
try {
    $hasRegisterTable = (bool)$pdo->query("SHOW TABLES LIKE 'delivery_register'")->fetchColumn();
} catch (Throwable $e) {
    $hasRegisterTable = false;
}

$q = trim((string)($_GET['q'] ?? ''));
$limit = 50;
$results = [];
$error = '';

$hasNat = false;
try {
    $hasNat = db_has_column($pdo, 'mothers', 'national_id_number');
} catch (Throwable $e) {
    $hasNat = false;
}

if ($q !== '' && $hasRegisterTable) {
    try {
        $like = '%' . $q . '%';
        $sql = "
            SELECT
              r.id AS register_id,
              r.delivery_datetime,
              r.hospital_number,
              r.anc_no,
              r.mother_name,
              r.mother_address,
              r.mother_phone,
              r.birth_outcome,
              r.baby_sex,
              r.birth_weight_grams,
              r.clinic_id,
              c.clinic_name,
              " . ($hasNat ? "m.national_id_number AS national_id_number" : "NULL AS national_id_number") . "
            FROM delivery_register r
            JOIN mothers m ON m.id = r.mother_id
            LEFT JOIN clinics c ON c.id = r.clinic_id
            WHERE r.mother_name LIKE ?
               OR r.hospital_number LIKE ?
               OR r.anc_no LIKE ?
               " . ($hasNat ? " OR m.national_id_number LIKE ? " : "") . "
            ORDER BY r.delivery_datetime DESC, r.id DESC
            LIMIT {$limit}
        ";
        $params = [$like, $like, $like];
        if ($hasNat) $params[] = $like;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        mch_audit_log($pdo, 'BIRTH_RECORD_SEARCH', 'health_info', null, "Search birth records q=" . substr($q, 0, 60));
    } catch (Throwable $e) {
        $error = "Search failed. Please try again.";
        $results = [];
    }
}

include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_health_info.php";
?>

<div class="ml-64 p-6 space-y-6 max-w-6xl">
    <div class="mch-card bg-white/90 p-6 border border-slate-100">
        <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold text-slate-900">Birth Records Search</h1>
                <p class="text-sm text-slate-600">Search by mother name, hospital number, ANC number<?= $hasNat ? ', or national ID number' : '' ?>.</p>
            </div>
        </div>

        <?php if (!$hasRegisterTable): ?>
            <div class="mt-4 mch-card bg-amber-50 border border-amber-200 text-amber-900 p-4 text-sm">
                Delivery register is not installed. Run <span class="font-mono">sql/delivery_register.sql</span> on the database.
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="mt-4 bg-rose-100 text-rose-700 p-3 rounded-lg"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="GET" class="mt-4 flex flex-col md:flex-row gap-2">
            <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Type mother name / hospital # / ANC # / ID #"
                   class="flex-1 border border-slate-200 p-2 rounded-lg bg-white">
            <button type="submit" class="mch-btn px-5 py-2">Search</button>
        </form>

        <?php if ($q !== '' && $hasRegisterTable): ?>
            <div class="mt-4 text-xs text-slate-500"><?= count($results) ?> results</div>

            <?php if (count($results) === 0): ?>
                <p class="mt-3 text-slate-600">No matching deliveries found.</p>
            <?php else: ?>
                <div class="overflow-x-auto mt-3">
                    <table class="w-full text-sm mch-table">
                        <thead>
                        <tr>
                            <th class="p-2 text-left">Delivery</th>
                            <th class="p-2 text-left">Hospital #</th>
                            <th class="p-2 text-left">ANC #</th>
                            <th class="p-2 text-left">Mother</th>
                            <th class="p-2 text-left">ID #</th>
                            <th class="p-2 text-left">Outcome</th>
                            <th class="p-2 text-left">Baby</th>
                            <th class="p-2 text-left">Place of Birth</th>
                            <th class="p-2 text-left">Action</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($results as $r): ?>
                            <tr class="border-t border-slate-200">
                                <td class="p-2"><?= htmlspecialchars((string)$r['delivery_datetime']) ?></td>
                                <td class="p-2"><?= htmlspecialchars((string)($r['hospital_number'] ?? '')) ?></td>
                                <td class="p-2"><?= htmlspecialchars((string)($r['anc_no'] ?? '')) ?></td>
                                <td class="p-2">
                                    <div class="font-semibold text-slate-900"><?= htmlspecialchars((string)$r['mother_name']) ?></div>
                                    <div class="text-xs text-slate-500"><?= htmlspecialchars((string)$r['mother_phone']) ?></div>
                                </td>
                                <td class="p-2"><?= htmlspecialchars((string)($r['national_id_number'] ?? '-')) ?></td>
                                <td class="p-2"><?= htmlspecialchars((string)$r['birth_outcome']) ?></td>
                                <td class="p-2"><?= htmlspecialchars((string)$r['baby_sex']) ?> • <?= htmlspecialchars((string)$r['birth_weight_grams']) ?>g</td>
                                <td class="p-2"><?= htmlspecialchars((string)($r['clinic_name'] ?? '')) ?></td>
                                <td class="p-2">
                                    <a class="inline-flex items-center px-3 py-1 rounded-lg bg-slate-900 text-white hover:bg-slate-800"
                                       href="/mch_system/health_info_portal/view_birth_record.php?register_id=<?= (int)$r['register_id'] ?>" target="_blank" rel="noopener">
                                        View / Print
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . "/../includes/footer.php"; ?>
