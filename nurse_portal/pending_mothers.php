<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";

// Access check
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'nurse'])) {
    header("Location: /mch_system/auth/login.php");
    exit;
}

$page = 'pending_mothers';
include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_nurse.php";

$clinic_id = $_SESSION['clinic_id'] ?? null;
$scope = $_GET['scope'] ?? 'all'; // all | clinic
$verified = $_GET['verified'] ?? 'all'; // all | verified

$where = "WHERE role = 'mother' AND status IN ('PENDING','APPROVED')";
$params = [];

if ($scope === 'clinic') {
    $where .= " AND (clinic_id IS NULL OR clinic_id = ?)";
    $params[] = $clinic_id;
}

if ($verified === 'verified') {
    $where .= " AND email_verified = 1";
}

$pendingStmt = $pdo->prepare("
    SELECT id, full_name, phone, email, created_at, clinic_id, email_verified, has_web_access
    FROM users
    {$where}
    ORDER BY created_at DESC
");
$pendingStmt->execute($params);
$pending = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="ml-64 p-6 max-w-6xl">
    <div class="mch-card bg-white/90 p-6 border border-slate-100">
        <h2 class="text-2xl font-semibold text-slate-900 mb-4">Booking Queue</h2>

        <div class="mb-4 grid grid-cols-1 md:grid-cols-5 gap-3">
            <input id="search_input" type="text" placeholder="Search name, phone, or email"
                   class="border border-slate-200 p-2 rounded-lg w-full bg-white">
            <input id="date_from" type="date" class="border border-slate-200 p-2 rounded-lg w-full bg-white">
            <input id="date_to" type="date" class="border border-slate-200 p-2 rounded-lg w-full bg-white">
            <select id="scope_filter" class="border border-slate-200 p-2 rounded-lg w-full bg-white">
                <option value="all" <?= $scope === 'all' ? 'selected' : '' ?>>All Clinics</option>
                <option value="clinic" <?= $scope === 'clinic' ? 'selected' : '' ?>>My Clinic</option>
            </select>
            <select id="verified_filter" class="border border-slate-200 p-2 rounded-lg w-full bg-white">
                <option value="all" <?= $verified === 'all' ? 'selected' : '' ?>>All (Verified + Unverified)</option>
                <option value="verified" <?= $verified === 'verified' ? 'selected' : '' ?>>Email Verified Only</option>
            </select>
        </div>

        <?php if (empty($pending)): ?>
            <div class="text-slate-600">No pending mothers at the moment.</div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm mch-table mch-scroll" id="pending_table">
                    <thead>
                        <tr>
                            <th class="text-left p-2">Name</th>
                            <th class="text-left p-2">Phone</th>
                            <th class="text-left p-2">Email</th>
                            <th class="text-left p-2">Verified</th>
                            <th class="text-left p-2">Web Access</th>
                            <th class="text-left p-2">Registered</th>
                            <th class="text-left p-2">Status</th>
                            <th class="text-left p-2">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending as $p): ?>
                            <tr class="border-t border-slate-200"
                                data-name="<?= htmlspecialchars(strtolower($p['full_name'])) ?>"
                                data-phone="<?= htmlspecialchars($p['phone']) ?>"
                                data-email="<?= htmlspecialchars(strtolower($p['email'])) ?>"
                                data-date="<?= htmlspecialchars(date('Y-m-d', strtotime($p['created_at']))) ?>">
                                <td class="p-2"><?= htmlspecialchars($p['full_name']) ?></td>
                                <td class="p-2"><?= htmlspecialchars($p['phone']) ?></td>
                                <td class="p-2"><?= htmlspecialchars($p['email']) ?></td>
                                <td class="p-2">
                                    <?php if ((int)$p['email_verified'] === 1): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-emerald-100 text-emerald-700">Yes</span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-amber-100 text-amber-700">No</span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-2">
                                    <?php if ((int)$p['has_web_access'] === 1): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-slate-100 text-slate-700">Yes</span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-slate-100 text-slate-500">No</span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-2"><?= htmlspecialchars(date('d M Y', strtotime($p['created_at']))) ?></td>
                                <td class="p-2">
                                    <?php
                                        $status = $p['status'] ?? 'PENDING';
                                        $badge = $status === 'APPROVED' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700';
                                    ?>
                                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold <?= $badge ?>">
                                        <?= htmlspecialchars($status) ?>
                                    </span>
                                </td>
                                <td class="p-2">
                                    <a class="inline-flex items-center px-3 py-1 rounded-lg bg-teal-50 border border-teal-200 text-teal-700 hover:bg-teal-100"
                                       href="/mch_system/nurse_portal/register_mother.php?existing_user_id=<?= (int)$p['id'] ?>">
                                        Book Pregnancy
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

<script>
    const searchInput = document.getElementById('search_input');
    const dateFrom = document.getElementById('date_from');
    const dateTo = document.getElementById('date_to');
    const rows = Array.from(document.querySelectorAll('#pending_table tbody tr'));
    const scopeFilter = document.getElementById('scope_filter');
    const verifiedFilter = document.getElementById('verified_filter');

    function applyFilters() {
        const q = (searchInput.value || '').toLowerCase().trim();
        const from = dateFrom.value || '';
        const to = dateTo.value || '';

        rows.forEach(row => {
            const name = row.getAttribute('data-name') || '';
            const phone = row.getAttribute('data-phone') || '';
            const email = row.getAttribute('data-email') || '';
            const date = row.getAttribute('data-date') || '';

            const matchesText = !q || name.includes(q) || phone.includes(q) || email.includes(q);
            const matchesFrom = !from || date >= from;
            const matchesTo = !to || date <= to;

            row.style.display = (matchesText && matchesFrom && matchesTo) ? '' : 'none';
        });
    }

    [searchInput, dateFrom, dateTo].forEach(el => el?.addEventListener('input', applyFilters));
    [scopeFilter, verifiedFilter].forEach(el => el?.addEventListener('change', () => {
        const params = new URLSearchParams(window.location.search);
        params.set('scope', scopeFilter.value);
        params.set('verified', verifiedFilter.value);
        window.location.search = params.toString();
    }));
</script>
