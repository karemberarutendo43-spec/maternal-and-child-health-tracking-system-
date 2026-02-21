<?php
require_once __DIR__ . "/../config/session.php";
mch_session_start();
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth_tokens.php";
require_once __DIR__ . "/../config/password_policy.php";

$error = '';
$success = '';

$selector = trim($_GET['selector'] ?? ($_POST['selector'] ?? ''));
$token = trim($_GET['token'] ?? ($_POST['token'] ?? ''));

$userRow = null;
if ($selector !== '' && $token !== '') {
    try {
        $stmt = $pdo->prepare("
            SELECT prt.id AS reset_id, prt.user_id, prt.token_hash, prt.expires_at, prt.used_at,
                   u.id, u.full_name, u.email, u.role
            FROM password_reset_tokens prt
            JOIN users u ON u.id = prt.user_id
            WHERE prt.selector = ?
            LIMIT 1
        ");
        $stmt->execute([$selector]);
        $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $error = "Password reset is not configured yet. Run `sql/password_reset_tokens.sql` on the database.";
    }
}

$tokenOk = false;
if ($userRow && empty($error)) {
    if (!empty($userRow['used_at'])) {
        $error = "This reset link has already been used.";
    } elseif (strtotime((string)$userRow['expires_at']) < time()) {
        $error = "This reset link has expired.";
    } else {
        $expectedHash = (string)($userRow['token_hash'] ?? '');
        $actualHash = hash('sha256', $token);
        if ($expectedHash !== '' && hash_equals($expectedHash, $actualHash)) {
            $tokenOk = true;
        } else {
            $error = "Invalid reset link.";
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenOk) {
    $newPassword = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    $role = (string)($userRow['role'] ?? '');
    $minLen = mch_password_min_length_for_role($role);

    $msg = mch_password_validate($newPassword, $minLen);
    if ($msg) {
        $error = $msg;
    } elseif ($newPassword !== $confirm) {
        $error = "Passwords do not match.";
    } else {
        try {
            $pdo->beginTransaction();

            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $upd = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ? LIMIT 1");
            $upd->execute([$hash, (int)$userRow['user_id']]);

            $mark = $pdo->prepare("UPDATE password_reset_tokens SET used_at = NOW() WHERE id = ? LIMIT 1");
            $mark->execute([(int)$userRow['reset_id']]);

            // Revoke all remember-me sessions for this user.
            try {
                $pdo->prepare("DELETE FROM auth_remember_tokens WHERE user_id = ?")->execute([(int)$userRow['user_id']]);
            } catch (Exception $e) {
                // ignore if not migrated
            }

            $pdo->commit();

            mch_auth_clear_cookie(MCH_AUTH_REMEMBER_COOKIE);
            $success = "Password updated successfully. You can now login.";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = "Failed to reset password. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Reset Password | MCHTS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<script src="https://cdn.tailwindcss.com"></script>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap');
  :root {
    --mch-primary: #0f766e;
    --mch-soft: #f8fafc;
    --mch-ink: #0f172a;
  }
  html, body { font-family: 'Plus Jakarta Sans', system-ui, -apple-system, Segoe UI, sans-serif; color: var(--mch-ink); }
  .auth-bg {
    background: radial-gradient(1200px 600px at 10% -10%, #e0f2fe 0%, transparent 60%),
                radial-gradient(1000px 500px at 110% 0%, #fef3c7 0%, transparent 55%),
                var(--mch-soft);
  }
  .auth-card {
    border-radius: 20px;
    box-shadow: 0 18px 40px rgba(15, 23, 42, 0.12);
    border: 1px solid #e2e8f0;
    background: rgba(255, 255, 255, 0.92);
    backdrop-filter: blur(6px);
  }
  .mch-btn {
    background: var(--mch-primary);
    color: #fff;
    border-radius: 14px;
    transition: transform 0.12s ease, box-shadow 0.12s ease;
    box-shadow: 0 10px 22px rgba(15, 118, 110, 0.28);
  }
  .mch-btn:hover { transform: translateY(-1px); box-shadow: 0 14px 26px rgba(15, 118, 110, 0.32); }
</style>
</head>

<body class="min-h-screen flex items-center justify-center auth-bg p-6">

  <div class="auth-card w-full max-w-md p-8">
    <div class="text-center mb-6">
      <h2 class="text-2xl font-bold text-teal-700">Reset password</h2>
      <p class="text-sm text-slate-500">Choose a new password for your account</p>
    </div>

    <?php if ($error): ?>
      <div class="bg-rose-100 text-rose-700 p-3 rounded-lg mb-4 text-sm"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
      <div class="bg-emerald-100 text-emerald-700 p-3 rounded-lg mb-4 text-sm"><?= htmlspecialchars($success) ?></div>
      <div class="text-center mt-4">
        <a class="text-teal-600 font-semibold hover:underline" href="login.php">Back to login</a>
      </div>
    <?php elseif ($tokenOk): ?>
      <?php
        $formRole = (string)($userRow['role'] ?? '');
        $minLenUi = mch_password_min_length_for_role($formRole);
      ?>

      <form method="POST" class="space-y-4">
        <input type="hidden" name="selector" value="<?= htmlspecialchars($selector, ENT_QUOTES) ?>">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES) ?>">

        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">New password</label>
          <div class="relative">
            <input type="password" name="password" required minlength="<?= (int)$minLenUi ?>" class="w-full border border-slate-200 p-3 rounded-xl bg-white focus:ring-2 focus:ring-teal-500 pr-10" id="reset_new_password">
            <button type="button" class="absolute right-3 top-3 text-slate-500 hover:text-slate-700" data-toggle-password="reset_new_password" aria-label="Show password">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" data-eye-open>
                <path d="M12 5c-4.478 0-8.268 2.943-9.543 7 1.275 4.057 5.065 7 9.543 7 4.478 0 8.268-2.943 9.543-7C20.268 7.943 16.478 5 12 5zm0 11a4 4 0 110-8 4 4 0 010 8z"/>
              </svg>
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 hidden" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" data-eye-closed>
                <path d="M3.53 2.47a.75.75 0 00-1.06 1.06l2.1 2.1A12.318 12.318 0 002.457 12c1.275 4.057 5.065 7 9.543 7 2.19 0 4.215-.71 5.86-1.91l2.61 2.61a.75.75 0 101.06-1.06l-18-18zM12 17.5c-3.5 0-6.59-2.142-7.794-5.5a10.79 10.79 0 012.221-3.35l2.02 2.02A4 4 0 0012 16a3.98 3.98 0 002.81-1.16l1.95 1.95A7.94 7.94 0 0112 17.5zm3.78-6.72l-2.52-2.52a4.01 4.01 0 00-4.52 4.52l-2.1-2.1a10.834 10.834 0 014.36-2.04 3.99 3.99 0 014.78 4.14z"/>
              </svg>
            </button>
          </div>
          <?php if ($formRole === 'mother'): ?>
            <p class="mt-2 text-xs text-slate-500">Use a passphrase (12+ characters). Example: <span class="font-semibold">Blue Sky Happy Baby</span></p>
          <?php endif; ?>
        </div>
        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1">Confirm password</label>
          <div class="relative">
            <input type="password" name="confirm_password" required minlength="<?= (int)$minLenUi ?>" class="w-full border border-slate-200 p-3 rounded-xl bg-white focus:ring-2 focus:ring-teal-500 pr-10" id="reset_confirm_password">
            <button type="button" class="absolute right-3 top-3 text-slate-500 hover:text-slate-700" data-toggle-password="reset_confirm_password" aria-label="Show password">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" data-eye-open>
                <path d="M12 5c-4.478 0-8.268 2.943-9.543 7 1.275 4.057 5.065 7 9.543 7 4.478 0 8.268-2.943 9.543-7C20.268 7.943 16.478 5 12 5zm0 11a4 4 0 110-8 4 4 0 010 8z"/>
              </svg>
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 hidden" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" data-eye-closed>
                <path d="M3.53 2.47a.75.75 0 00-1.06 1.06l2.1 2.1A12.318 12.318 0 002.457 12c1.275 4.057 5.065 7 9.543 7 2.19 0 4.215-.71 5.86-1.91l2.61 2.61a.75.75 0 101.06-1.06l-18-18zM12 17.5c-3.5 0-6.59-2.142-7.794-5.5a10.79 10.79 0 012.221-3.35l2.02 2.02A4 4 0 0012 16a3.98 3.98 0 002.81-1.16l1.95 1.95A7.94 7.94 0 0112 17.5zm3.78-6.72l-2.52-2.52a4.01 4.01 0 00-4.52 4.52l-2.1-2.1a10.834 10.834 0 014.36-2.04 3.99 3.99 0 014.78 4.14z"/>
              </svg>
            </button>
          </div>
        </div>

        <button class="w-full mch-btn py-3 font-semibold">Update password</button>
        <a href="login.php" class="block w-full text-center py-3 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 font-semibold">
          Cancel and go to login
        </a>
      </form>

      <div class="text-center mt-6 text-sm">
        <a href="forgot_password.php" class="text-slate-600 hover:underline">Need a new link?</a>
      </div>
    <?php else: ?>
      <div class="text-center mt-2 text-sm">
        <a href="forgot_password.php" class="text-teal-600 font-semibold hover:underline">Request a new reset link</a>
      </div>
    <?php endif; ?>
  </div>

</body>
</html>

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
