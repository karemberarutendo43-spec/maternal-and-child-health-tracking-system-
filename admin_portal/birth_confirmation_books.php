<?php
require_once __DIR__ . "/../config/session.php";
mch_session_start();
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/cards.php";
require_once __DIR__ . "/../config/csrf.php";

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: /mch_system/auth/login.php");
    exit;
}

$adminLevel = (string)($_SESSION['admin_level'] ?? 'SUPER');
$adminClinicId = (int)($_SESSION['clinic_id'] ?? 0);
$allClinics = ($adminLevel === 'SUPER' || $adminLevel === 'DISTRICT');
if (!$allClinics && $adminClinicId <= 0) {
    die("Clinic admin is not assigned to a clinic.");
}

$error = '';
$success = '';

// Ensure table exists
$booksEnabled = false;
try {
    $booksEnabled = (bool)$pdo->query("SHOW TABLES LIKE 'birth_confirmation_books'")->fetchColumn();
} catch (Throwable $e) {
    $booksEnabled = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $booksEnabled) {
    mch_csrf_require_valid('admin_bcn_books');
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'create') {
            $clinicId = $allClinics ? (int)($_POST['clinic_id'] ?? 0) : $adminClinicId;
            $bookName = trim((string)($_POST['book_name'] ?? ''));
            $prefix = trim((string)($_POST['prefix'] ?? ''));
            $startNo = (int)($_POST['start_no'] ?? 0);
            $endNo = (int)($_POST['end_no'] ?? 0);
            $padLen = (int)($_POST['pad_len'] ?? 4);
            $makeActive = (int)($_POST['make_active'] ?? 0) === 1;

            if ($clinicId <= 0) {
                throw new Exception("Select a clinic.");
            }
            if (!$allClinics && $clinicId !== $adminClinicId) {
                throw new Exception("You can only manage your clinic.");
            }
            if ($bookName === '') {
                throw new Exception("Book name is required.");
            }
            if (mb_strlen($bookName) > 100) {
                throw new Exception("Book name is too long (max 100 characters).");
            }
            if ($startNo <= 0 || $endNo <= 0 || $endNo < $startNo) {
                throw new Exception("Invalid range. Ensure start_no and end_no are positive and end_no >= start_no.");
            }
            if ($padLen < 1) $padLen = 1;
            if ($padLen > 12) $padLen = 12;

            // Prevent accidentally taking an already-used number range (per clinic + prefix).
            $overlap = $pdo->prepare("
                SELECT COUNT(*)
                FROM birth_confirmation_books
                WHERE clinic_id = ?
                  AND prefix = ?
                  AND NOT (end_no < ? OR start_no > ?)
            ");
            $overlap->execute([$clinicId, $prefix, $startNo, $endNo]);
            if ((int)$overlap->fetchColumn() > 0) {
                throw new Exception("This range overlaps with an existing book for the same clinic and prefix.");
            }

            $pdo->beginTransaction();
            if ($makeActive) {
                $pdo->prepare("UPDATE birth_confirmation_books SET active = 0 WHERE clinic_id = ?")->execute([$clinicId]);
            }
            $ins = $pdo->prepare("
                INSERT INTO birth_confirmation_books
                  (clinic_id, book_name, prefix, start_no, end_no, next_no, pad_len, active, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $ins->execute([
                $clinicId,
                $bookName,
                $prefix,
                $startNo,
                $endNo,
                $startNo,
                $padLen,
                $makeActive ? 1 : 0,
            ]);
            $pdo->commit();
            $success = "BCN book created" . ($makeActive ? " and activated." : ".");
        } elseif ($action === 'activate') {
            $bookId = (int)($_POST['book_id'] ?? 0);
            if ($bookId <= 0) throw new Exception("Invalid book.");

            $stmt = $pdo->prepare("SELECT id, clinic_id FROM birth_confirmation_books WHERE id = ? LIMIT 1");
            $stmt->execute([$bookId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new Exception("Book not found.");

            $clinicId = (int)$row['clinic_id'];
            if (!$allClinics && $clinicId !== $adminClinicId) {
                throw new Exception("You can only manage your clinic.");
            }

            $pdo->beginTransaction();
            $pdo->prepare("UPDATE birth_confirmation_books SET active = 0 WHERE clinic_id = ?")->execute([$clinicId]);
            $pdo->prepare("UPDATE birth_confirmation_books SET active = 1, updated_at = NOW() WHERE id = ?")->execute([$bookId]);
            $pdo->commit();
            $success = "BCN book activated.";
        } elseif ($action === 'set_next') {
            $bookId = (int)($_POST['book_id'] ?? 0);
            $nextNo = (int)($_POST['next_no'] ?? 0);
            if ($bookId <= 0) throw new Exception("Invalid book.");
            if ($nextNo <= 0) throw new Exception("Invalid next number.");

            $stmt = $pdo->prepare("SELECT id, clinic_id, start_no, end_no FROM birth_confirmation_books WHERE id = ? LIMIT 1");
            $stmt->execute([$bookId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new Exception("Book not found.");

            $clinicId = (int)$row['clinic_id'];
            if (!$allClinics && $clinicId !== $adminClinicId) {
                throw new Exception("You can only manage your clinic.");
            }

            $startNo = (int)$row['start_no'];
            $endNo = (int)$row['end_no'];
            if ($nextNo < $startNo || $nextNo > $endNo) {
                throw new Exception("Next number must be within the book range.");
            }

            $pdo->prepare("UPDATE birth_confirmation_books SET next_no = ?, updated_at = NOW() WHERE id = ? LIMIT 1")
                ->execute([$nextNo, $bookId]);
            $success = "Next number updated.";
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

$clinics = [];
if ($allClinics) {
    $clinics = $pdo->query("SELECT id, clinic_name FROM clinics ORDER BY clinic_name")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare("SELECT id, clinic_name FROM clinics WHERE id = ? LIMIT 1");
    $stmt->execute([$adminClinicId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $clinics = $row ? [$row] : [];
}

$books = [];
if ($booksEnabled) {
    if ($allClinics) {
        $books = $pdo->query("
            SELECT b.*, c.clinic_name
            FROM birth_confirmation_books b
            LEFT JOIN clinics c ON b.clinic_id = c.id
            ORDER BY c.clinic_name ASC, b.active DESC, b.created_at DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->prepare("
            SELECT b.*, c.clinic_name
            FROM birth_confirmation_books b
            LEFT JOIN clinics c ON b.clinic_id = c.id
            WHERE b.clinic_id = ?
            ORDER BY b.active DESC, b.created_at DESC
        ");
        $stmt->execute([$adminClinicId]);
        $books = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$page = 'bcn_books';
include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_admin.php";
?>

<div class="ml-64 p-6 max-w-6xl space-y-6">
    <div>
        <h1 class="text-3xl font-semibold text-slate-900 mb-1">Birth Confirmation Books</h1>
        <p class="text-slate-600">Configure per-clinic BCN serial books for fast, safe baby registration.</p>
    </div>

    <?php if (!$booksEnabled): ?>
        <div class="mch-card bg-amber-50 border border-amber-200 text-amber-800 p-4">
            BCN books are not configured yet. Run <span class="font-mono">sql/birth_confirmation_books.sql</span> on the database.
        </div>
    <?php else: ?>
        <?php if ($error): ?>
            <div class="mch-card bg-rose-50 border border-rose-200 text-rose-700 p-4"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="mch-card bg-emerald-50 border border-emerald-200 text-emerald-700 p-4"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <div class="mch-card bg-white/90 p-6 border border-slate-100 space-y-4">
            <h2 class="text-lg font-semibold text-slate-900">Create New Book</h2>
            <form method="POST" class="grid grid-cols-1 md:grid-cols-6 gap-3 items-end">
                <?= mch_csrf_input('admin_bcn_books') ?>
                <input type="hidden" name="action" value="create">
                <div class="md:col-span-2">
                    <label class="block text-sm font-semibold text-slate-700 mb-1">Clinic</label>
                    <select name="clinic_id" class="w-full border border-slate-200 p-2 rounded-lg bg-white" <?= $allClinics ? '' : 'disabled' ?>>
                        <?php foreach ($clinics as $c): ?>
                            <option value="<?= (int)$c['id'] ?>" <?= (!$allClinics && (int)$c['id'] === $adminClinicId) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['clinic_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!$allClinics): ?>
                        <input type="hidden" name="clinic_id" value="<?= (int)$adminClinicId ?>">
                    <?php endif; ?>
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-semibold text-slate-700 mb-1">Book Name</label>
                    <input name="book_name" class="w-full border border-slate-200 p-2 rounded-lg bg-white" placeholder="e.g. Book A (2026)" required>
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-semibold text-slate-700 mb-1">Prefix</label>
                    <input name="prefix" class="w-full border border-slate-200 p-2 rounded-lg bg-white" placeholder="e.g. MTD-CL01-">
                    <p class="text-xs text-slate-500 mt-1">Use prefix to guarantee uniqueness across 27 clinics.</p>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1">Start No</label>
                    <input name="start_no" type="number" min="1" class="w-full border border-slate-200 p-2 rounded-lg bg-white" value="1" required>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1">End No</label>
                    <input name="end_no" type="number" min="1" class="w-full border border-slate-200 p-2 rounded-lg bg-white" value="100" required>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1">Pad Len</label>
                    <input name="pad_len" type="number" min="1" max="12" class="w-full border border-slate-200 p-2 rounded-lg bg-white" value="4">
                </div>
                <div class="md:col-span-2 flex items-center gap-2">
                    <input id="make_active" name="make_active" value="1" type="checkbox" class="w-4 h-4">
                    <label for="make_active" class="text-sm text-slate-700">Make active</label>
                </div>
                <div class="md:col-span-2">
                    <button class="mch-btn px-4 py-2 w-full">Create Book</button>
                </div>
            </form>
        </div>

        <div class="mch-card bg-white/90 p-6 border border-slate-100">
            <h2 class="text-lg font-semibold text-slate-900 mb-4">Books</h2>
            <?php if (empty($books)): ?>
                <p class="text-slate-500">No books created yet.</p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm mch-table mch-scroll">
                        <thead>
                            <tr>
                                <th class="p-2 text-left">Clinic</th>
                                <th class="p-2 text-left">Book</th>
                                <th class="p-2 text-left">Prefix</th>
                                <th class="p-2 text-left">Range</th>
                                <th class="p-2 text-left">Next</th>
                                <th class="p-2 text-left">Active</th>
                                <th class="p-2 text-left">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($books as $b): ?>
                                <?php $isActive = (int)($b['active'] ?? 0) === 1; ?>
                                <tr class="border-t border-slate-200">
                                    <td class="p-2"><?= htmlspecialchars((string)($b['clinic_name'] ?? '')) ?></td>
                                    <td class="p-2"><?= htmlspecialchars((string)($b['book_name'] ?? ('Book #' . (int)$b['id']))) ?></td>
                                    <td class="p-2 font-mono"><?= htmlspecialchars((string)($b['prefix'] ?? '')) ?></td>
                                    <td class="p-2"><?= (int)$b['start_no'] ?> - <?= (int)$b['end_no'] ?></td>
                                    <td class="p-2 font-mono"><?= (int)$b['next_no'] ?></td>
                                    <td class="p-2">
                                        <?php if ($isActive): ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-emerald-100 text-emerald-700">Active</span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-slate-100 text-slate-600">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-2">
                                        <div class="flex flex-wrap gap-2">
                                            <?php if (!$isActive): ?>
                                                <form method="POST">
                                                    <?= mch_csrf_input('admin_bcn_books') ?>
                                                    <input type="hidden" name="action" value="activate">
                                                    <input type="hidden" name="book_id" value="<?= (int)$b['id'] ?>">
                                                    <button class="px-3 py-1 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs">Activate</button>
                                                </form>
                                            <?php endif; ?>
                                            <form method="POST" class="flex items-center gap-2">
                                                <?= mch_csrf_input('admin_bcn_books') ?>
                                                <input type="hidden" name="action" value="set_next">
                                                <input type="hidden" name="book_id" value="<?= (int)$b['id'] ?>">
                                                <input name="next_no" type="number" min="1" class="w-24 border border-slate-200 p-1 rounded-lg bg-white text-xs" value="<?= (int)$b['next_no'] ?>">
                                                <button class="px-3 py-1 rounded-lg bg-slate-50 border border-slate-200 text-slate-700 text-xs">Set Next</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . "/../includes/footer.php"; ?>
