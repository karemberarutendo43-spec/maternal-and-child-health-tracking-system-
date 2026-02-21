<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";

$user_id = $_SESSION['user_id'];
$motherStmt = $pdo->prepare("SELECT * FROM mothers WHERE user_id = ? LIMIT 1");
$motherStmt->execute([$user_id]);
$mother = $motherStmt->fetch(PDO::FETCH_ASSOC);

if (!$mother) {
    header("Location: /mch_system/mother_portal/pending.php");
    exit;
}

include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_mother.php";

$mother_id = $mother['id'];

$fpStmt = $pdo->prepare("
    SELECT method, start_date, end_date, status, notes
    FROM family_planning
    WHERE mother_id = ?
    ORDER BY start_date DESC
");
$fpStmt->execute([$mother_id]);
$records = $fpStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="ml-64 p-6">
    <h1 class="text-2xl font-bold mb-4 text-purple-600">Family Planning</h1>
    <?php if (empty($records)): ?>
        <p class="text-gray-500">No family planning records yet.</p>
    <?php else: ?>
        <table class="w-full text-sm mch-table mch-scroll">
            <thead>
                <tr>
                    <th class="p-2 text-left">Method</th>
                    <th class="p-2 text-left">Start Date</th>
                    <th class="p-2 text-left">End Date</th>
                    <th class="p-2 text-left">Status</th>
                    <th class="p-2 text-left">Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $r): ?>
                    <tr class="border-t">
                        <td class="p-2"><?= htmlspecialchars($r['method']) ?></td>
                        <td class="p-2"><?= htmlspecialchars($r['start_date']) ?></td>
                        <td class="p-2"><?= htmlspecialchars($r['end_date'] ?? '-') ?></td>
                        <td class="p-2"><?= htmlspecialchars($r['status']) ?></td>
                        <td class="p-2"><?= htmlspecialchars(trim((string)($r['notes'] ?? '')) !== '' ? (string)$r['notes'] : 'N/A') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php include __DIR__ . "/../includes/footer.php"; ?>
