<?php
require_once __DIR__ . "/../config/session.php";
mch_session_start();
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: /mch_system/auth/login.php");
    exit;
}

$role = $_SESSION['role'] ?? '';
$error = '';
$success = '';
$twofa_error = '';
$twofa_success = '';

$stmt = $pdo->prepare("SELECT id, full_name, phone, password_hash, totp_secret, totp_enabled, clinic_id FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    die("User not found");
}

$autoload = __DIR__ . "/../vendor/autoload.php";
$google2fa = null;
if (file_exists($autoload)) {
    require_once $autoload;
    if (class_exists("\\PragmaRX\\Google2FA\\Google2FA")) {
        $google2fa = new \PragmaRX\Google2FA\Google2FA();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_account') {
        $phone = trim($_POST['phone'] ?? '');
        $old_password = $_POST['old_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $repeat_password = $_POST['repeat_password'] ?? '';

        if ($phone === '') {
            $error = "Phone number is required.";
        } elseif (preg_match('/^7\\d{8}$/', $phone)) {
            $phone = '+263' . $phone;
        } elseif (preg_match('/^2637\\d{8}$/', $phone)) {
            $phone = '+' . $phone;
        } elseif (!preg_match('/^\\+2637\\d{8}$/', $phone)) {
            $error = "Phone number must be in the format +2637XXXXXXXX or 7XXXXXXXX.";
        }

        $changingPassword = $new_password !== '' || $repeat_password !== '';
        if (!$error && ($phone !== $user['phone'] || $changingPassword)) {
            if (!$old_password || !password_verify($old_password, $user['password_hash'])) {
                $error = "Old password is incorrect.";
            }
        }

        if (!$error && $changingPassword) {
            if ($new_password !== $repeat_password) {
                $error = "New passwords do not match.";
            } elseif (strlen($new_password) < 6) {
                $error = "New password must be at least 6 characters.";
            }
        }

        if (!$error) {
            $params = [];
            $sql = "UPDATE users SET phone = ?";
            $params[] = $phone;

            if ($changingPassword) {
                $sql .= ", password_hash = ?";
                $params[] = password_hash($new_password, PASSWORD_DEFAULT);
            }

            $sql .= " WHERE id = ?";
            $params[] = $_SESSION['user_id'];

            try {
                $upd = $pdo->prepare($sql);
                $upd->execute($params);
                $success = "Account settings updated.";
                $user['phone'] = $phone;
            } catch (Exception $e) {
                $error = "Failed to update account settings.";
            }
        }
    }

    if ($action === 'begin_2fa') {
        if (!$google2fa) {
            $twofa_error = "Google2FA library not installed. Run: composer require pragmarx/google2fa";
        } else {
            $secret = $google2fa->generateSecretKey();
            $_SESSION['pending_totp_secret'] = $secret;
            $twofa_success = "Scan the QR or enter the secret in your authenticator app.";
        }
    }

    if ($action === 'enable_2fa') {
        $code = trim($_POST['otp_code'] ?? '');
        $secret = $_SESSION['pending_totp_secret'] ?? '';
        if (!$google2fa) {
            $twofa_error = "Google2FA library not installed. Run: composer require pragmarx/google2fa";
        } elseif (!$secret) {
            $twofa_error = "No pending 2FA setup found.";
        } elseif (!$google2fa->verifyKey($secret, $code)) {
            $twofa_error = "Invalid OTP code.";
        } else {
            $upd = $pdo->prepare("UPDATE users SET totp_secret = ?, totp_enabled = 1 WHERE id = ?");
            $upd->execute([$secret, $_SESSION['user_id']]);
            unset($_SESSION['pending_totp_secret']);
            $user['totp_secret'] = $secret;
            $user['totp_enabled'] = 1;
            $twofa_success = "Two-factor authentication enabled.";
        }
    }

    if ($action === 'disable_2fa') {
        $upd = $pdo->prepare("UPDATE users SET totp_secret = NULL, totp_enabled = 0 WHERE id = ?");
        $upd->execute([$_SESSION['user_id']]);
        unset($_SESSION['pending_totp_secret']);
        $user['totp_secret'] = null;
        $user['totp_enabled'] = 0;
        $twofa_success = "Two-factor authentication disabled.";
    }

    if ($action === 'update_clinic') {
        if (!in_array($role, ['admin', 'nurse'], true)) {
            $error = "You are not allowed to update clinic settings.";
        } elseif ($role === 'nurse' && !empty($user['clinic_id'])) {
            $error = "Clinic is already set. Please contact an admin to change it.";
        } else {
            $clinic_id = (int)($_POST['clinic_id'] ?? 0);
            $clinic_code = trim($_POST['clinic_code'] ?? '');
            if (!$clinic_id || $clinic_code === '') {
                $error = "Clinic and authorization code are required.";
            } else {
                $cstmt = $pdo->prepare("SELECT id FROM clinics WHERE id = ? AND auth_code = ? LIMIT 1");
                $cstmt->execute([$clinic_id, $clinic_code]);
                $clinic = $cstmt->fetch(PDO::FETCH_ASSOC);
                if (!$clinic) {
                    $error = "Invalid clinic authorization code.";
                } else {
                    $upd = $pdo->prepare("UPDATE users SET clinic_id = ? WHERE id = ?");
                    $upd->execute([$clinic_id, $_SESSION['user_id']]);
                    $_SESSION['clinic_id'] = $clinic_id;
                    $success = "Clinic updated successfully.";
                    $user['clinic_id'] = $clinic_id;
                }
            }
        }
    }
}

$clinics = $pdo->query("SELECT id, clinic_code, clinic_name FROM clinics ORDER BY clinic_name")->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . "/../includes/header.php";
if ($role === 'admin') {
    include __DIR__ . "/../includes/sidebar_admin.php";
} elseif ($role === 'nurse') {
    include __DIR__ . "/../includes/sidebar_nurse.php";
} else {
    include __DIR__ . "/../includes/sidebar_mother.php";
}
?>

<div class="ml-64 p-6 max-w-3xl space-y-6">
    <div class="mch-card bg-white/90 p-6 border border-slate-100">
        <h2 class="text-2xl font-semibold mb-4 text-slate-900">Edit Account Settings</h2>

        <?php if ($error): ?>
            <div class="bg-rose-100 text-rose-700 p-3 rounded-lg mb-4"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="bg-emerald-100 text-emerald-700 p-3 rounded-lg mb-4"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <div class="p-4 rounded-xl border border-slate-200 bg-slate-50 mb-6">
            <h3 class="text-lg font-semibold text-slate-900">Appearance</h3>
            <p class="text-sm text-slate-600 mt-1">
                Dark Mode and light mode applied based on your operating system preference.
                To change it, open Settings, select Appearance, and choose a color theme.
            </p>

            <div class="mt-4">
                <label class="block text-sm font-semibold mb-1 text-slate-700">Color theme</label>
                <select id="mch_theme_select" class="w-full border border-slate-200 p-2 rounded-lg bg-white">
                    <option value="system">System (Recommended)</option>
                    <option value="light">Light</option>
                    <option value="dark">Dark</option>
                </select>
                <p class="text-xs text-slate-500 mt-2">Saved on this device/browser.</p>
            </div>
        </div>

        <script>
          (function () {
            const select = document.getElementById('mch_theme_select');
            if (!select) return;

            const current =
              (document.documentElement && document.documentElement.dataset && document.documentElement.dataset.theme)
                ? document.documentElement.dataset.theme
                : (typeof window.mchGetTheme === 'function' ? window.mchGetTheme() : 'system');

            if (current) select.value = current;

            select.addEventListener('change', () => {
              const v = select.value || 'system';
              if (typeof window.mchSetTheme === 'function') window.mchSetTheme(v);
            });
          })();
        </script>

        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="update_account">

            <div>
                <label class="block text-sm font-semibold mb-1">Phone Number</label>
                <?php
                $phone_local = $user['phone'];
                if (strpos($phone_local, '+263') === 0) {
                    $phone_local = substr($phone_local, 4);
                }
                ?>
                <div class="flex">
                    <span class="inline-flex items-center px-3 border border-r-0 rounded-l-lg text-slate-700 bg-slate-100">+263</span>
                    <input type="text" name="phone" value="<?= htmlspecialchars($phone_local) ?>"
                           pattern="7[0-9]{8}" minlength="9" maxlength="9" inputmode="numeric"
                           class="w-full border border-slate-200 p-2 rounded-r-lg bg-white" required>
                </div>
                <p class="text-xs text-slate-500 mt-1">Enter 9 digits starting with 7.</p>
            </div>

            <div>
                <label class="block text-sm font-semibold mb-1">Old Password</label>
                <div class="relative">
                    <input type="password" name="old_password" class="w-full border border-slate-200 p-2 rounded-lg pr-10 bg-white" id="old_password">
                    <button type="button" class="absolute right-3 top-2.5 text-slate-500 transition" id="toggle_old_password" aria-label="Show password">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" data-eye-open>
                            <path d="M12 5c-4.478 0-8.268 2.943-9.543 7 1.275 4.057 5.065 7 9.543 7 4.478 0 8.268-2.943 9.543-7C20.268 7.943 16.478 5 12 5zm0 11a4 4 0 110-8 4 4 0 010 8z"/>
                        </svg>
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 hidden" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" data-eye-closed>
                            <path d="M3.53 2.47a.75.75 0 00-1.06 1.06l2.1 2.1A12.318 12.318 0 002.457 12c1.275 4.057 5.065 7 9.543 7 2.19 0 4.215-.71 5.86-1.91l2.61 2.61a.75.75 0 101.06-1.06l-18-18zM12 17.5c-3.5 0-6.59-2.142-7.794-5.5a10.79 10.79 0 012.221-3.35l2.02 2.02A4 4 0 0012 16a3.98 3.98 0 002.81-1.16l1.95 1.95A7.94 7.94 0 0112 17.5zm3.78-6.72l-2.52-2.52a4.01 4.01 0 00-4.52 4.52l-2.1-2.1a10.834 10.834 0 014.36-2.04 3.99 3.99 0 014.78 4.14z"/>
                        </svg>
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-semibold mb-1">New Password</label>
                    <div class="relative">
                        <input type="password" name="new_password" class="w-full border border-slate-200 p-2 rounded-lg pr-10 bg-white" id="new_password">
                        <button type="button" class="absolute right-3 top-2.5 text-slate-500 transition" id="toggle_new_password" aria-label="Show password">
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
                    <label class="block text-sm font-semibold mb-1">Repeat New Password</label>
                    <div class="relative">
                        <input type="password" name="repeat_password" class="w-full border border-slate-200 p-2 rounded-lg pr-10 bg-white" id="repeat_password">
                        <button type="button" class="absolute right-3 top-2.5 text-slate-500 transition" id="toggle_repeat_password" aria-label="Show password">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" data-eye-open>
                                <path d="M12 5c-4.478 0-8.268 2.943-9.543 7 1.275 4.057 5.065 7 9.543 7 4.478 0 8.268-2.943 9.543-7C20.268 7.943 16.478 5 12 5zm0 11a4 4 0 110-8 4 4 0 010 8z"/>
                            </svg>
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 hidden" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" data-eye-closed>
                                <path d="M3.53 2.47a.75.75 0 00-1.06 1.06l2.1 2.1A12.318 12.318 0 002.457 12c1.275 4.057 5.065 7 9.543 7 2.19 0 4.215-.71 5.86-1.91l2.61 2.61a.75.75 0 101.06-1.06l-18-18zM12 17.5c-3.5 0-6.59-2.142-7.794-5.5a10.79 10.79 0 012.221-3.35l2.02 2.02A4 4 0 0012 16a3.98 3.98 0 002.81-1.16l1.95 1.95A7.94 7.94 0 0112 17.5zm3.78-6.72l-2.52-2.52a4.01 4.01 0 00-4.52 4.52l-2.1-2.1a10.834 10.834 0 014.36-2.04 3.99 3.99 0 014.78 4.14z"/>
                            </svg>
                        </button>
                    </div>
                </div>
            </div>

            <button class="mch-btn px-4 py-2">
                Update
            </button>
        </form>
    </div>

    <?php if (in_array($role, ['admin', 'nurse'], true)): ?>
    <div class="mch-card bg-white/90 p-6 border border-slate-100">
        <h3 class="text-lg font-semibold mb-2 text-slate-900">Clinic Settings</h3>
        <?php $clinicLocked = ($role === 'nurse' && !empty($user['clinic_id'])); ?>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="update_clinic">
            <div>
                <label class="block text-sm font-semibold mb-1">Select Clinic</label>
                <select name="clinic_id" class="w-full border border-slate-200 p-2 rounded-lg bg-white" required <?= $clinicLocked ? 'disabled' : '' ?>>
                    <option value="">Choose clinic</option>
                    <?php foreach ($clinics as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= (int)$user['clinic_id'] === (int)$c['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['clinic_name']) ?> (<?= htmlspecialchars($c['clinic_code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-semibold mb-1">Clinic Authorization Code</label>
                <input type="text" name="clinic_code" class="w-full border border-slate-200 p-2 rounded-lg bg-white" required <?= $clinicLocked ? 'disabled' : '' ?>>
            </div>
            <?php if ($clinicLocked): ?>
                <div class="text-sm text-slate-600">
                    Clinic is locked. Please contact an admin to change it.
                </div>
            <?php else: ?>
                <button class="mch-btn px-4 py-2">
                    Update Clinic
                </button>
            <?php endif; ?>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($role !== 'mother'): ?>
    <div class="mch-card bg-white/90 p-6 border border-slate-100">
        <h3 class="text-lg font-semibold mb-2 text-slate-900">Two-Factor Authentication (TOTP)</h3>

        <?php if ($twofa_error): ?>
            <div class="bg-rose-100 text-rose-700 p-3 rounded-lg mb-4"><?= htmlspecialchars($twofa_error) ?></div>
        <?php endif; ?>

        <?php if ($twofa_success): ?>
            <div class="bg-emerald-100 text-emerald-700 p-3 rounded-lg mb-4"><?= htmlspecialchars($twofa_success) ?></div>
        <?php endif; ?>

        <?php
        $pending_secret = $_SESSION['pending_totp_secret'] ?? '';
        if ($pending_secret && $google2fa) {
            $otpauth = $google2fa->getQRCodeUrl('MCHTS', $user['phone'], $pending_secret);
        }
        ?>

        <?php if (!$user['totp_enabled']): ?>
            <?php if (!$pending_secret): ?>
                <form method="POST">
                    <input type="hidden" name="action" value="begin_2fa">
                    <button class="mch-btn px-4 py-2">
                        Setup 2FA
                    </button>
                </form>
            <?php else: ?>
                <div class="mb-4 text-sm text-slate-600">
                    Secret Key: <b><?= htmlspecialchars($pending_secret) ?></b><br>
                    OTP URL: <code><?= htmlspecialchars($otpauth) ?></code>
                </div>
                <form method="POST" class="space-y-3">
                    <input type="hidden" name="action" value="enable_2fa">
                    <div>
                        <label class="block text-sm font-semibold mb-1">Enter OTP Code</label>
                        <input type="text" name="otp_code" class="w-full border border-slate-200 p-2 rounded-lg bg-white" required>
                    </div>
                    <button class="mch-btn px-4 py-2">
                        Enable 2FA
                    </button>
                </form>
            <?php endif; ?>
        <?php else: ?>
            <div class="mb-3 text-sm text-slate-600">
                2FA is currently enabled.
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="disable_2fa">
                <button class="bg-rose-600 text-white px-4 py-2 rounded-lg hover:bg-rose-700">
                    Disable 2FA
                </button>
            </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<script>
  function wireToggle(btnId, inputId) {
    const btn = document.getElementById(btnId);
    const input = document.getElementById(inputId);
    if (!btn || !input) return;
    const eyeOpen = btn.querySelector('[data-eye-open]');
    const eyeClosed = btn.querySelector('[data-eye-closed]');
    btn.addEventListener('click', () => {
      const isPassword = input.type === 'password';
      input.type = isPassword ? 'text' : 'password';
      btn.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
      eyeOpen?.classList.toggle('hidden', !isPassword);
      eyeClosed?.classList.toggle('hidden', isPassword);
      btn.classList.toggle('text-teal-600', isPassword);
      btn.classList.toggle('text-slate-500', !isPassword);
    });
  }
  wireToggle('toggle_old_password', 'old_password');
  wireToggle('toggle_new_password', 'new_password');
  wireToggle('toggle_repeat_password', 'repeat_password');
</script>

<?php include __DIR__ . "/../includes/footer.php"; ?>
