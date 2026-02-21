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

$pncStmt = $pdo->prepare("
    SELECT p.visit_date, p.status, p.notes, b.baby_name
    FROM pnc_visits p
    LEFT JOIN babies b ON p.baby_id = b.id
    WHERE p.mother_id = ?
    ORDER BY p.visit_date DESC
");
$pncStmt->execute([$mother_id]);
$pncVisits = $pncStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="ml-64 p-6">
    <h1 class="text-2xl font-bold mb-4 text-rose-600">Postnatal Care Visits</h1>
    <?php if (empty($pncVisits)): ?>
        <p class="text-gray-500">No PNC visits recorded yet.</p>
    <?php else: ?>
        <table class="w-full text-sm mch-table mch-scroll">
            <thead>
                <tr>
                    <th class="p-2 text-left">Visit Date</th>
                    <th class="p-2 text-left">Baby</th>
                    <th class="p-2 text-left">Status</th>
                    <th class="p-2 text-left">Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pncVisits as $v): ?>
                    <tr class="border-t">
                        <td class="p-2"><?= htmlspecialchars($v['visit_date']) ?></td>
                        <td class="p-2"><?= htmlspecialchars($v['baby_name'] ?? '-') ?></td>
                        <td class="p-2"><?= htmlspecialchars($v['status']) ?></td>
                        <td class="p-2"><?= htmlspecialchars(trim((string)($v['notes'] ?? '')) !== '' ? (string)$v['notes'] : 'N/A') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php include __DIR__ . "/../includes/footer.php"; ?>
