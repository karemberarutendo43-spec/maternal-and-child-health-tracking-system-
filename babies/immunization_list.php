<?php
require_once "../config/db.php";
require_once "../config/auth.php";
$baby_id = $_GET['baby_id'];

$stmt = $pdo->prepare("SELECT * FROM immunizations WHERE baby_id = ?");
$stmt->execute([$baby_id]);
$shots = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html>

<head>
    <title>Immunization Schedule</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-slate-100 min-h-screen">

    <header class="bg-purple-700 text-white p-4">
        <h1 class="text-lg font-semibold">Immunization Schedule</h1>
    </header>

    <div class="max-w-4xl mx-auto p-6">
        <div class="bg-white rounded-xl shadow overflow-x-auto">
            <table class="min-w-full text-sm mch-scroll">
                <thead class="bg-slate-200">
                    <tr>
                        <th class="p-3">Action</th>
                        <th class="p-3">Vaccine</th>
                        <th class="p-3">Due Date</th>
                        <th class="p-3">Given Date</th>
                        <th class="p-3">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($shots as $s): ?>
                        <tr class="border-b">
    <td class="p-3"><?= $s['vaccine'] ?></td>
    <td class="p-3"><?= $s['due_date'] ?></td>
    <td class="p-3"><?= $s['given_date'] ?? '-' ?></td>

    <td class="p-3 font-semibold
        <?= $s['status'] == 'Given' ? 'text-green-600' : ($s['status'] == 'Missed' ? 'text-red-600' : 'text-orange-600') ?>">
        <?= $s['status'] ?>
    </td>

    <td class="p-3">
        <?php if ($s['status'] !== 'Given'): ?>
            <button
                onclick="markGiven(<?= $s['id'] ?>)"
                class="bg-green-600 hover:bg-green-700 text-white px-3 py-1 rounded text-xs">
                Mark Given
            </button>
        <?php else: ?>
            <span class="text-gray-400 text-xs">✓ Done</span>
        <?php endif; ?>
    </td>
</tr>

                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <script>
function markGiven(id) {
    if (!confirm("Confirm vaccine has been given?")) return;

    fetch("mark_immunization.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: "id=" + id
    })
    .then(res => res.text())
    .then(msg => {
        alert(msg);
        location.reload();
    });
}
</script>


</body>

</html>
