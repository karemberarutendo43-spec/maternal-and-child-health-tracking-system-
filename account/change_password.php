<?php
require_once __DIR__ . "/../config/session.php";
mch_session_start();
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/audit.php";
require_once __DIR__ . "/../config/password_policy.php";

mch_auth_require_login();

$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) {
    header("Location: /mch_system/auth/login.php");
    exit;
}

$error = '';
$success = '';

$hasMustChange = false;
try {
    $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'must_change_password'")->fetch(PDO::FETCH_ASSOC);
    $hasMustChange = !empty($col);
} catch (Throwable $e) {
    $hasMustChange = false;
}

$stmt = $pdo->prepare("SELECT id, full_name, password_hash" . ($hasMustChange ? ", must_change_password" : "") . " FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$uid]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    die("User not found.");
}

$mustChange = $hasMustChange ? ((int)($user['must_change_password'] ?? 0) === 1) : false;
$flashReason = (string)($_SESSION['flash_change_password_reason'] ?? '');
unset($_SESSION['flash_change_password_reason']);

$role = (string)($_SESSION['role'] ?? '');
$minLen = mch_password_min_length_for_role($role);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = (string)($_POST['current_password'] ?? '');
    $new = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    if ($current === '' || $new === '' || $confirm === '') {
        $error = "All fields are required.";
    } elseif (!password_verify($current, (string)($user['password_hash'] ?? ''))) {
        $error = "Current password is incorrect.";
    } elseif ($new !== $confirm) {
        $error = "New password confirmation does not match.";
    } else {
        $msg = mch_password_validate($new, $minLen);
        if ($msg) {
            $error = $msg;
        } else {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            if ($hasMustChange) {
                $upd = $pdo->prepare("UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ? LIMIT 1");
                $upd->execute([$hash, $uid]);
            } else {
                $upd = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ? LIMIT 1");
                $upd->execute([$hash, $uid]);
            }

            mch_audit_log($pdo, 'CHANGE_PASSWORD', 'account', $uid, 'User changed own password');

            $success = "Password updated successfully.";
            // Refresh locally cached hash
            $user['password_hash'] = $hash;
            $mustChange = false;
        }
    }
}

include __DIR__ . "/../includes/header.php";
if ($role === 'admin') {
    include __DIR__ . "/../includes/sidebar_admin.php";
} elseif ($role === 'nurse') {
    include __DIR__ . "/../includes/sidebar_nurse.php";
} else {
    include __DIR__ . "/../includes/sidebar_mother.php";
}
?>

<div class="ml-64 p-6 max-w-xl space-y-6">
    <div class="mch-card bg-white/90 p-6 border border-slate-100">
        <h1 class="text-2xl font-semibold text-slate-900 mb-2">Change Password</h1>
        <?php if ($mustChange): ?>
            <p class="text-sm text-amber-700">You must change your password before continuing.</p>
        <?php else: ?>
            <p class="text-sm text-slate-600">Update your account password.</p>
        <?php endif; ?>
    </div>

    <?php if ($flashReason): ?>
        <div class="mch-card bg-amber-50 border border-amber-200 text-amber-800 p-4">
            <?= htmlspecialchars($flashReason) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="mch-card bg-rose-50 border border-rose-200 text-rose-700 p-4"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="mch-card bg-emerald-50 border border-emerald-200 text-emerald-700 p-4"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <div class="mch-card bg-white/90 p-6 border border-slate-100">
        <form method="POST" class="space-y-4">
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1">Current Password</label>
                <div class="relative">
                    <input type="password" name="current_password" class="w-full border border-slate-200 p-2 rounded-lg bg-white pr-10" required id="current_password">
                    <button type="button" class="absolute right-2 top-2 text-slate-500 hover:text-slate-700" data-toggle-password="current_password" aria-label="Show password">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" data-eye-open>
                            <path d="M12 5c-4.478 0-8.268 2.943-9.543 7 1.275 4.057 5.065 7 9.543 7 4.478 0 8.268-2.943 9.543-7C20.268 7.943 16.478 5 12 5zm0 11a4 4 0 110-8 4 4 0 010 8z"/>
                        </svg>
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 hidden" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" data-eye-closed>
                            <path d="M3.53 2.47a.75.75 0 00-1.06 1.06l2.1 2.1A12.318 12.318 0 002.457 12c1.275 4.057 5.065 7 9.543 7 2.19 0 4.215-.71 5.86-1.91l2.61 2.61a.75.75 0 101.06-1.06l-18-18zM12 17.5c-3.5 0-6.59-2.142-7.794-5.5a10.79 10.79 0 012.221-3.35l2.02 2.02A4 4 0 0012 16a3.98 3.98 0 002.81-1.16l1.95 1.95A7.94 7.94 0 0112 17.5zm3.78-6.72l-2.52-2.52a4.01 4.01 0 00-4.52 4.52l-2.1-2.1a10.834 10.834 0 014.36-2.04 3.99 3.99 0 014.78 4.14z"/>
                        </svg>
                    </button>
                </div>
            </div>
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1">New Password</label>
                <div class="relative">
                    <input type="password" name="new_password" class="w-full border border-slate-200 p-2 rounded-lg bg-white pr-10" required minlength="<?= (int)$minLen ?>" id="new_password">
                    <button type="button" class="absolute right-2 top-2 text-slate-500 hover:text-slate-700" data-toggle-password="new_password" aria-label="Show password">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" data-eye-open>
                            <path d="M12 5c-4.478 0-8.268 2.943-9.543 7 1.275 4.057 5.065 7 9.543 7 4.478 0 8.268-2.943 9.543-7C20.268 7.943 16.478 5 12 5zm0 11a4 4 0 110-8 4 4 0 010 8z"/>
                        </svg>
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 hidden" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" data-eye-closed>
                            <path d="M3.53 2.47a.75.75 0 00-1.06 1.06l2.1 2.1A12.318 12.318 0 002.457 12c1.275 4.057 5.065 7 9.543 7 2.19 0 4.215-.71 5.86-1.91l2.61 2.61a.75.75 0 101.06-1.06l-18-18zM12 17.5c-3.5 0-6.59-2.142-7.794-5.5a10.79 10.79 0 012.221-3.35l2.02 2.02A4 4 0 0012 16a3.98 3.98 0 002.81-1.16l1.95 1.95A7.94 7.94 0 0112 17.5zm3.78-6.72l-2.52-2.52a4.01 4.01 0 00-4.52 4.52l-2.1-2.1a10.834 10.834 0 014.36-2.04 3.99 3.99 0 014.78 4.14z"/>
                        </svg>
                    </button>
                </div>
                <?php if ($role === 'mother'): ?>
                    <p class="mt-2 text-xs text-slate-500">Use a passphrase (12+ characters). Example: <span class="font-semibold">Blue Sky Happy Baby</span></p>
                <?php endif; ?>
            </div>
            <div>
                <label class="block text-sm font-semibold text-slate-700 mb-1">Confirm New Password</label>
                <div class="relative">
                    <input type="password" name="confirm_password" class="w-full border border-slate-200 p-2 rounded-lg bg-white pr-10" required minlength="<?= (int)$minLen ?>" id="confirm_password">
                    <button type="button" class="absolute right-2 top-2 text-slate-500 hover:text-slate-700" data-toggle-password="confirm_password" aria-label="Show password">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" data-eye-open>
                            <path d="M12 5c-4.478 0-8.268 2.943-9.543 7 1.275 4.057 5.065 7 9.543 7 4.478 0 8.268-2.943 9.543-7C20.268 7.943 16.478 5 12 5zm0 11a4 4 0 110-8 4 4 0 010 8z"/>
                        </svg>
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 hidden" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" data-eye-closed>
                            <path d="M3.53 2.47a.75.75 0 00-1.06 1.06l2.1 2.1A12.318 12.318 0 002.457 12c1.275 4.057 5.065 7 9.543 7 2.19 0 4.215-.71 5.86-1.91l2.61 2.61a.75.75 0 101.06-1.06l-18-18zM12 17.5c-3.5 0-6.59-2.142-7.794-5.5a10.79 10.79 0 012.221-3.35l2.02 2.02A4 4 0 0012 16a3.98 3.98 0 002.81-1.16l1.95 1.95A7.94 7.94 0 0112 17.5zm3.78-6.72l-2.52-2.52a4.01 4.01 0 00-4.52 4.52l-2.1-2.1a10.834 10.834 0 014.36-2.04 3.99 3.99 0 014.78 4.14z"/>
                        </svg>
                    </button>
                </div>
            </div>

            <button class="mch-btn w-full py-2">Update Password</button>
        </form>
    </div>
</div>

<script>
  (function () {
    document.querySelectorAll('[data-toggle-password]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const id = btn.getAttribute('data-toggle-password');
        const input = document.getElementById(id);
        if (!input) return;

        const openEye = btn.querySelector('[data-eye-open]');
        const closedEye = btn.querySelector('[data-eye-closed]');

        const isPassword = input.type === 'password';
        input.type = isPassword ? 'text' : 'password';

        if (openEye && closedEye) {
          openEye.classList.toggle('hidden', isPassword);
          closedEye.classList.toggle('hidden', !isPassword);
        }
        btn.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
      });
    });
  })();
</script>

<?php include __DIR__ . "/../includes/footer.php"; ?>
