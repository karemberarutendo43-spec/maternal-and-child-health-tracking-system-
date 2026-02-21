<?php
require_once __DIR__ . "/../config/session.php";
mch_session_start();
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth_tokens.php";

// Reduce browser caching of the login screen (helps on shared devices and back button).
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

$error = '';
$success = '';

$stickyIdentifier = '';
$stickyStaffId = '';
$stickyRememberMe = false;

// Preserve user input on failed login attempts (do not preserve password).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stickyIdentifier = trim((string)($_POST['identifier'] ?? ''));
    $stickyStaffId = trim((string)($_POST['staff_id'] ?? ''));
    $stickyRememberMe = !empty($_POST['remember_me']);
}

$hasAdminLevelColumn = false;
try {
    $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'admin_level'")->fetch(PDO::FETCH_ASSOC);
    $hasAdminLevelColumn = !empty($col);
} catch (Exception $e) {
    $hasAdminLevelColumn = false;
}
$adminLevelSelect = $hasAdminLevelColumn ? ", admin_level" : "";

$hasUsernameColumn = false;
try {
    $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'username'")->fetch(PDO::FETCH_ASSOC);
    $hasUsernameColumn = !empty($col);
} catch (Exception $e) {
    $hasUsernameColumn = false;
}

// Optional: force password change after admin reset
$hasMustChangePassword = false;
try {
    $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'must_change_password'")->fetch(PDO::FETCH_ASSOC);
    $hasMustChangePassword = !empty($col);
} catch (Throwable $e) {
    $hasMustChangePassword = false;
}
$mustChangeSelect = $hasMustChangePassword ? ", must_change_password" : "";

function mch_redirect_logged_in(PDO $pdo): void
{
    if (empty($_SESSION['user_id']) || empty($_SESSION['role'])) {
        return;
    }

    $role = (string)$_SESSION['role'];
    // If forced password change is enabled, redirect before entering the portals.
    try {
            $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'must_change_password'")->fetch(PDO::FETCH_ASSOC);
        if (!empty($col)) {
            $uid = (int)$_SESSION['user_id'];
            if ($uid > 0) {
                $stmt = $pdo->prepare("SELECT must_change_password FROM users WHERE id = ? LIMIT 1");
                $stmt->execute([$uid]);
                $must = (int)$stmt->fetchColumn();
                if ($must === 1) {
                    if (empty($_SESSION['flash_change_password_reason'])) {
                        $_SESSION['flash_change_password_reason'] = "Your password was reset by an administrator. Please set a new password to continue.";
                    }
                    header("Location: /mch_system/account/change_password.php");
                    exit;
                }
            }
        }
    } catch (Throwable $e) {
        // ignore
    }
    if ($role === 'mother') {
        $uid = (int)$_SESSION['user_id'];
        if ($uid > 0) {
            $stmt = $pdo->prepare("SELECT status FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$uid]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($u && ($u['status'] ?? '') !== 'ACTIVE') {
                header("Location: /mch_system/mother_portal/pending.php");
                exit;
            }
        }
        header("Location: /mch_system/mother_portal/dashboard.php");
        exit;
    }

    if ($role === 'nurse') {
        header("Location: /mch_system/nurse_portal/dashboard.php");
        exit;
    }

    if ($role === 'health_info') {
        header("Location: /mch_system/health_info_portal/dashboard.php");
        exit;
    }

    if ($role === 'admin') {
        header("Location: /mch_system/admin_portal/dashboard.php");
        exit;
    }
}

// Auto-login if a valid remember-me cookie exists.
mch_auth_try_restore_from_remember_cookie($pdo);
mch_redirect_logged_in($pdo);

if (isset($_GET['registered'])) {
    $success = "Registration successful. Please login.";
}
if (isset($_GET['reset'])) {
    $success = "Password reset successful. Please login.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $identifier = trim((string)($_POST['identifier'] ?? ''));
    $password   = $_POST['password'] ?? '';
    $staff_id   = trim((string)($_POST['staff_id'] ?? ''));
    $remember_me = !empty($_POST['remember_me']);

    $normalizePhone = function (string $raw): ?string {
        $raw = trim($raw);
        if ($raw === '') return null;
        if (preg_match('/^\\+2637\\d{8}$/', $raw)) return $raw;

        $digits = preg_replace('/\\D+/', '', $raw);
        if ($digits === null) $digits = '';
        if (strpos($digits, '263') === 0) {
            $digits = substr($digits, 3);
        }
        if (preg_match('/^7\\d{8}$/', $digits)) {
            return '+263' . $digits;
        }
        return null;
    };

    $motherPhone = $normalizePhone($identifier);

    if ($identifier === '' || $password === '') {
        $error = "All fields are required.";
    } else {
        $user = null;

        if ($motherPhone !== null) {
            $stmt = $pdo->prepare("
                SELECT id, full_name, username, staff_id, password_hash{$mustChangeSelect}, role, linked_mother_id, status, email, email_verified, has_web_access, clinic_id{$adminLevelSelect}
                FROM users
                WHERE phone = ? AND role = 'mother'
                LIMIT 1
            ");
            $stmt->execute([$motherPhone]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } else {
            if (!$hasUsernameColumn) {
                $error = "Staff login is not available on this system yet.";
            } else {
                $username = $identifier;

                // Admin: username + password.
                $stmt = $pdo->prepare("
                    SELECT id, full_name, username, staff_id, password_hash{$mustChangeSelect}, role, linked_mother_id, status, email, email_verified, has_web_access, clinic_id{$adminLevelSelect}
                    FROM users
                    WHERE username = ? AND role = 'admin'
                    LIMIT 1
                ");
                $stmt->execute([$username]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

                // Health Info: username + password.
                if (!$user) {
                    $stmt = $pdo->prepare("
                        SELECT id, full_name, username, staff_id, password_hash{$mustChangeSelect}, role, linked_mother_id, status, email, email_verified, has_web_access, clinic_id{$adminLevelSelect}
                        FROM users
                        WHERE username = ? AND role = 'health_info'
                        LIMIT 1
                    ");
                    $stmt->execute([$username]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                }

                // Nurse: username + staff_id + password.
                if (!$user) {
                    if ($staff_id === '') {
                        $probe = $pdo->prepare("SELECT id FROM users WHERE username = ? AND role = 'nurse' LIMIT 1");
                        $probe->execute([$username]);
                        if ($probe->fetchColumn()) {
                            $error = "Staff ID is required for nurses.";
                        }
                    } else {
                        $stmt = $pdo->prepare("
                            SELECT id, full_name, username, staff_id, password_hash{$mustChangeSelect}, role, linked_mother_id, status, email, email_verified, has_web_access, clinic_id{$adminLevelSelect}
                            FROM users
                            WHERE username = ? AND staff_id = ? AND role = 'nurse'
                            LIMIT 1
                        ");
                        $stmt->execute([$username, $staff_id]);
                        $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                    }
                }
            }
        }

        if (!$user) {
            if (empty($error)) {
                $error = "Invalid login credentials.";
            }
        } else {
            $passOk = password_verify($password, (string)$user['password_hash']);

            // Mothers: reduce phone-format confusion by accepting common phone variants as password too.
            // Example: stored password is "+2637XXXXXXXX", but user types "07XXXXXXXX" or "7XXXXXXXX".
            if (!$passOk && (($user['role'] ?? '') === 'mother')) {
                $normalizedPw = $normalizePhone((string)$password);
                $candidates = [$password];
                if ($normalizedPw !== null) {
                    $candidates[] = $normalizedPw;
                    if (preg_match('/^\\+2637\\d{8}$/', $normalizedPw)) {
                        $digits = substr($normalizedPw, 1); // 2637XXXXXXXX
                        $local = substr($digits, 3); // 7XXXXXXXX
                        $candidates[] = $digits;      // 2637XXXXXXXX
                        $candidates[] = $local;       // 7XXXXXXXX
                        $candidates[] = '0' . $local; // 07XXXXXXXX
                    }
                }

                foreach (array_values(array_unique($candidates)) as $cand) {
                    if ($cand === '') continue;
                    if (password_verify($cand, (string)$user['password_hash'])) {
                        $passOk = true;
                        break;
                    }
                }
            }

            if (!$passOk) {
                if (in_array(($user['role'] ?? ''), ['admin', 'nurse'], true)) {
                    $error = "Incorrect password. If your password was reset by Admin, use the temporary password they provided.";
                } else {
                    $error = "Invalid login credentials.";
                }
            } else {

                // Mothers: email verification + clinic activation
                if ($user['role'] === 'mother' && (int)$user['has_web_access'] !== 1) {
                    $error = "This account is clinic-managed. Please ask a nurse for assistance.";
                } elseif ($user['role'] === 'mother' && (int)$user['email_verified'] !== 1) {
                    $_SESSION['otp_email'] = $user['email'];
                    header("Location: auth_verify.php");
                    exit;
                } else {

                mch_auth_set_session_from_user($user);

                if ($remember_me) {
                    // If DB table isn't migrated yet, this simply won't persist (no error shown).
                    mch_auth_issue_remember_token($pdo, (int)$user['id'], 30);
                } else {
                    if (!empty($_COOKIE[MCH_AUTH_REMEMBER_COOKIE])) {
                        mch_auth_revoke_remember_token($pdo, (string)$_COOKIE[MCH_AUTH_REMEMBER_COOKIE]);
                    }
                    mch_auth_clear_cookie(MCH_AUTH_REMEMBER_COOKIE);
                }

                if ($hasMustChangePassword && (int)($user['must_change_password'] ?? 0) === 1) {
                    if (empty($_SESSION['flash_change_password_reason'])) {
                        $_SESSION['flash_change_password_reason'] = "Your password was reset by an administrator. Please set a new password to continue.";
                    }
                    header("Location: /mch_system/account/change_password.php");
                    exit;
                }

                if ($user['role'] === 'mother') {
                    if ($user['status'] !== 'ACTIVE') {
                        if (isset($_GET['registered']) && empty($_SESSION['flash_pending_popup'])) {
                            $_SESSION['flash_pending_popup'] = "We have received your registration. Please wait for activation at the clinic.";
                        }
                        header("Location: /mch_system/mother_portal/pending.php");
                    } else {
                        header("Location: /mch_system/mother_portal/dashboard.php");
                    }
                } elseif ($user['role'] === 'nurse') {
                    header("Location: /mch_system/nurse_portal/dashboard.php");
                } else {
                    header("Location: /mch_system/admin_portal/dashboard.php");
                }
                exit;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>MCHTS Login</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="/mch_system/assets/output.css">
<style>
  :root {
    --mch-primary: #0ea5e9;
    --mch-accent: #22d3ee;
    --mch-ink: #0f172a;
    --mch-ink-2: #0b1220;
    --mch-muted: #94a3b8;
    --mch-white: #e2e8f0;
  }
  html, body { font-family: 'Plus Jakarta Sans', system-ui, -apple-system, Segoe UI, sans-serif; color: var(--mch-white); background: #060b17; }

  .auth-bg {
    background:
      radial-gradient(1200px 800px at 10% -10%, rgba(34, 211, 238, 0.18) 0%, transparent 60%),
      radial-gradient(900px 600px at 110% 0%, rgba(14, 165, 233, 0.18) 0%, transparent 55%),
      radial-gradient(900px 700px at 40% 120%, rgba(167, 139, 250, 0.12) 0%, transparent 50%),
      linear-gradient(180deg, #050a14 0%, #08122a 40%, #050a14 100%);
    position: relative;
    overflow-x: hidden;
  }

  .auth-bg::before,
  .auth-bg::after {
    content: '';
    position: fixed;
    inset: -40%;
    pointer-events: none;
    z-index: 0;
    filter: blur(40px);
    opacity: 0.75;
    transform: translate3d(0,0,0);
  }
  .auth-bg::before {
    background:
      radial-gradient(closest-side, rgba(34, 211, 238, 0.22), transparent 70%),
      radial-gradient(closest-side, rgba(14, 165, 233, 0.20), transparent 72%);
    animation: auth-drift-1 10s ease-in-out infinite;
  }
  .auth-bg::after {
    background:
      radial-gradient(closest-side, rgba(56, 189, 248, 0.18), transparent 68%),
      radial-gradient(closest-side, rgba(167, 139, 250, 0.14), transparent 70%);
    animation: auth-drift-2 14s ease-in-out infinite;
  }

  @keyframes auth-drift-1 {
    0%, 100% { transform: translate(-6%, -4%) scale(1.02); }
    50% { transform: translate(6%, 4%) scale(1.08); }
  }
  @keyframes auth-drift-2 {
    0%, 100% { transform: translate(8%, -2%) scale(1.04); }
    50% { transform: translate(-8%, 6%) scale(1.10); }
  }

  @property --auth-spin {
    syntax: '<angle>';
    inherits: false;
    initial-value: 0deg;
  }

  .auth-card {
    position: relative;
    z-index: 1;
    border-radius: 26px;
    background: rgba(11, 18, 32, 0.66);
    border: 1px solid rgba(148, 163, 184, 0.20);
    --auth-edge-c1: rgba(34, 211, 238, 0.85);
    --auth-edge-c2: rgba(167, 139, 250, 0.75);
    --auth-edge-glow-1: rgba(34, 211, 238, 0.32);
    --auth-edge-glow-2: rgba(56, 189, 248, 0.22);
    box-shadow:
      0 24px 60px rgba(2, 6, 23, 0.65),
      inset 0 1px 0 rgba(255, 255, 255, 0.06);
    backdrop-filter: blur(18px);
    isolation: isolate;
  }

  .auth-card::before {
    content: '';
    position: absolute;
    inset: -2px;
    border-radius: inherit;
    background: conic-gradient(from var(--auth-spin),
      rgba(34, 211, 238, 0) 0%,
      var(--auth-edge-c1) 18%,
      rgba(56, 189, 248, 0.0) 32%,
      var(--auth-edge-c2) 52%,
      rgba(56, 189, 248, 0.0) 68%,
      var(--auth-edge-c1) 84%,
      rgba(34, 211, 238, 0) 100%
    );
    filter: blur(14px);
    opacity: 0.60;
    z-index: -2;
    animation: auth-spin 7s linear infinite;
    pointer-events: none;
  }

  /* Crisp glowing border (supported on Chromium; safe fallback above when masks are unsupported). */
  @supports (-webkit-mask-composite: xor) {
    .auth-card::before {
      inset: 0;
      padding: 2px;
      filter: none;
      opacity: 0.95;
      -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
      -webkit-mask-composite: xor;
      filter:
        drop-shadow(0 0 14px var(--auth-edge-glow-1))
        drop-shadow(0 0 34px var(--auth-edge-glow-2));
    }
  }
  @supports (mask-composite: exclude) {
    .auth-card::before {
      inset: 0;
      padding: 2px;
      filter: none;
      opacity: 0.95;
      mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
      mask-composite: exclude;
      filter:
        drop-shadow(0 0 14px var(--auth-edge-glow-1))
        drop-shadow(0 0 34px var(--auth-edge-glow-2));
    }
  }

  .auth-card--error {
    --auth-edge-c1: rgba(255, 51, 51, 0.92);
    --auth-edge-c2: rgba(255, 123, 123, 0.78);
    --auth-edge-glow-1: rgba(255, 51, 51, 0.34);
    --auth-edge-glow-2: rgba(255, 51, 51, 0.22);
    animation: auth-shake 0.22s ease-in-out 0s 2;
  }

  @keyframes auth-shake {
    0% { transform: translateX(0); }
    25% { transform: translateX(6px); }
    75% { transform: translateX(-6px); }
    100% { transform: translateX(0); }
  }

  .auth-card::after {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: inherit;
    background: linear-gradient(135deg, rgba(255,255,255,0.10), rgba(255,255,255,0.02));
    z-index: -1;
    pointer-events: none;
  }

  @keyframes auth-spin {
    to { --auth-spin: 360deg; }
  }

  @media (prefers-reduced-motion: reduce) {
    .auth-bg::before, .auth-bg::after, .auth-card::before { animation: none !important; }
  }

  .mch-btn {
    background: linear-gradient(135deg, rgba(14, 165, 233, 0.95), rgba(34, 211, 238, 0.92));
    color: #03111d;
    border-radius: 14px;
    transition: transform 0.12s ease, box-shadow 0.12s ease, filter 0.12s ease;
    box-shadow: 0 12px 28px rgba(14, 165, 233, 0.22);
  }
  .mch-btn:hover { transform: translateY(-1px); box-shadow: 0 18px 36px rgba(34, 211, 238, 0.28); filter: saturate(1.05); }
  .mch-btn:active { transform: translateY(0px); }
  .mch-btn.is-loading {
    pointer-events: none;
    opacity: 0.95;
    filter: saturate(0.95);
    animation: auth-btn-pulse 1.35s ease-in-out infinite;
  }
  @keyframes auth-btn-pulse {
    0%, 100% {
      box-shadow: 0 12px 28px rgba(14, 165, 233, 0.18);
      transform: translateY(0);
    }
    50% {
      box-shadow: 0 18px 40px rgba(34, 211, 238, 0.28);
      transform: translateY(-1px);
    }
  }

  .auth-center {
    position: relative;
  }
  .auth-left-circle {
    position: absolute;
    left: 0;
    top: 50%;
    transform: translate(calc(-100% - 1.25rem), -50%);
    width: 12.5rem;
    height: 12.5rem;
    border-radius: 9999px;
    border: 1px solid rgba(34, 211, 238, 0.28);
    background: rgba(34, 211, 238, 0.10);
    background-image: url('/mch_system/assets/images/maternity_circle.png');
    background-size: contain;
    background-position: center;
    background-repeat: no-repeat;
    box-shadow:
      0 0 60px rgba(34, 211, 238, 0.22),
      0 0 120px rgba(56, 189, 248, 0.12);
    pointer-events: none;
    display: grid;
    place-items: center;
  }
  .auth-left-circle-text {
    width: 70%;
    text-align: center;
    font-weight: 800;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    font-size: 0.80rem;
    line-height: 1.2;
    color: rgba(226, 232, 240, 0.96);
    text-shadow:
      0 2px 18px rgba(2, 6, 23, 0.75),
      0 0 18px rgba(34, 211, 238, 0.25);
    filter: drop-shadow(0 0 22px rgba(34, 211, 238, 0.18));
  }
  @media (max-width: 1023px) {
    .auth-left-circle {
      left: 50%;
      top: -4.5rem;
      transform: translateX(-50%);
      width: 9.5rem;
      height: 9.5rem;
    }
    .auth-left-circle-text {
      font-size: 0.72rem;
      width: 78%;
    }
  }

  .float-group {
    position: relative;
    --mch-field-bg: rgba(2, 6, 23, 0.25);
    --mch-label-bg: rgba(11, 18, 32, 0.96);
  }
  .float-field {
    width: 100%;
    padding: 0.85rem 0.9rem;
    border: 1px solid rgba(148, 163, 184, 0.26);
    border-radius: 0.85rem;
    background: var(--mch-field-bg);
    color: var(--mch-white);
    position: relative;
    z-index: 2;
    caret-color: var(--mch-white);
  }
  /* Keep input box color stable (no "change color" effect when typing/focus). */
  .float-field:focus {
    outline: none;
    background: var(--mch-field-bg) !important;
    border-color: rgba(148, 163, 184, 0.26) !important;
    box-shadow: none !important;
  }
  .float-field:focus-visible { outline: none; }

  /* Keep browser autofill from changing input background color (Edge/Chrome). */
  .float-field:-webkit-autofill,
  .float-field:-webkit-autofill:hover,
  .float-field:-webkit-autofill:focus,
  .float-field:-webkit-autofill:active,
  select.float-field:-webkit-autofill {
    -webkit-text-fill-color: var(--mch-white) !important;
    -webkit-box-shadow: 0 0 0 1000px var(--mch-field-bg) inset !important;
    box-shadow: 0 0 0 1000px var(--mch-field-bg) inset !important;
    transition: background-color 9999s ease-out 0s;
  }
  .float-label {
    position: absolute;
    left: 0.75rem;
    top: 0.75rem;
    color: rgba(34, 211, 238, 0.88);
    background: var(--mch-label-bg);
    padding: 0 0.4rem;
    border-radius: 999px;
    box-shadow: 0 0 0 3px var(--mch-label-bg);
    line-height: 1.2;
    transition: all 0.15s ease;
    pointer-events: none;
  }
  #toggle_password { z-index: 3; }
  .float-field:focus + .float-label,
  .float-field[data-has-value="true"] + .float-label,
  .float-group:hover .float-label {
    top: -0.62rem;
    font-size: 0.75rem;
    color: rgba(34, 211, 238, 0.88);
  }
  .phone-label { left: 3.75rem; }

</style>
</head>

<body class="min-h-screen auth-bg">

  <div class="min-h-screen flex items-center justify-center p-6">
    <div class="auth-center w-full max-w-md">
      <div id="auth_left_circle" class="auth-left-circle" role="img" aria-label="Maternity and child Tracking System">
        <div class="auth-left-circle-text">Maternity and <br>Child Tracking System</div>
      </div>

      <div class="auth-card p-8 <?= $error ? 'auth-card--error' : '' ?>">

        <div class="text-center mb-6">
            <h2 class="text-2xl font-bold text-slate-100">Welcome<span id="welcome_name"></span></h2>
            <p class="text-sm text-slate-300/80">
                Login to continue
            </p>
        </div>

        <?php if ($success): ?>
            <div class="bg-emerald-100 text-emerald-700 p-3 rounded-lg mb-4 text-sm">
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="bg-rose-100 text-rose-700 p-3 rounded-lg mb-4 text-sm">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <div id="mother_profile_wrap" class="hidden mb-4 text-center">
            <img id="mother_profile_img" src="" alt="Mother profile" class="w-20 h-20 rounded-full object-cover border border-slate-200 mx-auto">
        </div>

        <form method="POST" class="space-y-4" id="login_form" autocomplete="off">
            <!-- Autofill trap (prevents some browsers/password managers from filling the real fields on shared devices). -->
            <div aria-hidden="true" style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden;opacity:0;">
                <input type="text" autocomplete="username" tabindex="-1">
                <input type="password" autocomplete="new-password" tabindex="-1">
            </div>
 
            <div class="float-group" id="identifier_group">
                <input type="text" name="identifier"
                        class="float-field field pr-10 focus:ring-2 focus:ring-teal-500"
                        placeholder=" " id="identifier"
                        required
                       autocomplete="off"
                       autocapitalize="none" autocorrect="off" spellcheck="false"
                       readonly
                       data-lpignore="true"
                        value="<?= htmlspecialchars($stickyIdentifier, ENT_QUOTES) ?>">
                <label class="float-label" for="identifier">Phone (+2637...) or Username</label>
                <span class="absolute right-3 top-3 text-slate-500">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M2 3.75A.75.75 0 012.75 3h2A2.25 2.25 0 017 5.25v13.5A2.25 2.25 0 014.75 21h-2A.75.75 0 012 20.25V3.75zM8.5 6.75A2.25 2.25 0 0110.75 4.5h6.5A2.25 2.25 0 0119.5 6.75v10.5A2.25 2.25 0 0117.25 19.5h-6.5A2.25 2.25 0 018.5 17.25V6.75z"/>
                    </svg>
                </span>
            </div>

            <div class="float-group hidden" id="staff_group">
                <input type="text" name="staff_id"
                       class="float-field field pr-10 focus:ring-2 focus:ring-teal-500"
                       placeholder=" " id="staff_id"
                       autocomplete="off" autocapitalize="none" autocorrect="off" spellcheck="false"
                       readonly
                       data-lpignore="true"
                       value="<?= htmlspecialchars($stickyStaffId, ENT_QUOTES) ?>">
                <label class="float-label" for="staff_id">Staff ID</label>
            </div>
 
            <div class="float-group">
                <input type="password" name="password"
                       class="float-field field pr-10 focus:ring-2 focus:ring-teal-500"
                       required placeholder=" " id="password"
                       autocomplete="off"
                       readonly
                       data-lpignore="true">
                <label class="float-label" for="password">Password</label>
                <button type="button" class="absolute right-3 top-3 text-slate-500 transition" id="toggle_password" aria-label="Show password">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" data-eye-open>
                        <path d="M12 5c-4.478 0-8.268 2.943-9.543 7 1.275 4.057 5.065 7 9.543 7 4.478 0 8.268-2.943 9.543-7C20.268 7.943 16.478 5 12 5zm0 11a4 4 0 110-8 4 4 0 010 8z"/>
                    </svg>
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 hidden" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" data-eye-closed>
                        <path d="M3.53 2.47a.75.75 0 00-1.06 1.06l2.1 2.1A12.318 12.318 0 002.457 12c1.275 4.057 5.065 7 9.543 7 2.19 0 4.215-.71 5.86-1.91l2.61 2.61a.75.75 0 101.06-1.06l-18-18zM12 17.5c-3.5 0-6.59-2.142-7.794-5.5a10.79 10.79 0 012.221-3.35l2.02 2.02A4 4 0 0012 16a3.98 3.98 0 002.81-1.16l1.95 1.95A7.94 7.94 0 0112 17.5zm3.78-6.72l-2.52-2.52a4.01 4.01 0 00-4.52 4.52l-2.1-2.1a10.834 10.834 0 014.36-2.04 3.99 3.99 0 014.78 4.14z"/>
                    </svg>
                </button>
            </div>

        <div class="flex items-center justify-between text-sm">
                <label class="inline-flex items-center gap-2 text-slate-200/80 select-none">
                    <input type="checkbox" name="remember_me" value="1" class="rounded border-slate-300 text-teal-600 focus:ring-teal-500" <?= $stickyRememberMe ? 'checked' : '' ?>>
                    Remember me
                </label>
                <a id="forgot_password_link" href="forgot_password.php" class="text-teal-600 font-semibold hover:underline hidden">Forgot password?</a>
                <span id="admin_reset_hint" class="text-slate-500 hidden">For password reset: contact Admin</span>
            </div>

            <button class="w-full mch-btn py-3 font-semibold transition" id="login_btn">
                Login
            </button>
        </form>

        <div class="text-center mt-6 text-sm">
            <span class="text-slate-200/70">Mother?</span>
            <a href="mother_signup.php"
               class="text-teal-600 font-semibold hover:underline">
                Create an account
           </a>
        </div>
      </div>
    </div>
  </div>

<script>
document.addEventListener("DOMContentLoaded", () => {
    const inputs = document.querySelectorAll("input");
    const loginForm = document.getElementById("login_form");
    const loginBtn = document.getElementById("login_btn");
    const togglePassword = document.getElementById("toggle_password");
    const passwordInput = document.getElementById("password");
    const floatFields = document.querySelectorAll(".field");
    const identifierInput = document.getElementById("identifier");
    const staffIdInput = document.getElementById("staff_id");
    const staffGroup = document.getElementById("staff_group");
    const profileWrap = document.getElementById("mother_profile_wrap");
    const profileImg = document.getElementById("mother_profile_img");
    const welcomeName = document.getElementById("welcome_name");
    const forgotLink = document.getElementById("forgot_password_link");
    const adminResetHint = document.getElementById("admin_reset_hint");

    function updateFloatField(field) {
        const hasValue = field.value && field.value.toString().trim() !== '';
        field.dataset.hasValue = hasValue ? 'true' : 'false';
    }

    function clearAuthFields() {
        try {
            if (identifierInput) identifierInput.value = "";
            if (staffIdInput) staffIdInput.value = "";
            if (passwordInput) passwordInput.value = "";
            floatFields.forEach((field) => updateFloatField(field));
        } catch (e) {}
    }

    function unlockReadonly(el) {
        if (!el) return;
        const fn = () => {
            try { el.removeAttribute("readonly"); } catch (e) {}
        };
        el.addEventListener("focus", fn, { once: true });
        el.addEventListener("pointerdown", fn, { once: true });
        el.addEventListener("keydown", fn, { once: true });
    }
    unlockReadonly(identifierInput);
    unlockReadonly(staffIdInput);
    unlockReadonly(passwordInput);

    // Best-effort: clear browser/password-manager autofill without wiping user typing.
    let userStartedTyping = false;
    [identifierInput, staffIdInput, passwordInput].forEach((el) => {
        el?.addEventListener("input", () => { userStartedTyping = true; }, { passive: true });
    });
    function clearAutofillIfSafe() {
        try {
            if (userStartedTyping) return;
            const active = document.activeElement;
            if (active && active.tagName && ['INPUT', 'TEXTAREA', 'SELECT'].includes(active.tagName)) return;
            // Never keep a filled password on load (shared device safety).
            if (passwordInput && passwordInput.value) passwordInput.value = "";
            // Clear other fields only if they look auto-filled (non-empty before interaction).
            if (identifierInput && identifierInput.value && !<?= json_encode((bool)$stickyIdentifier) ?>) identifierInput.value = "";
            if (staffIdInput && staffIdInput.value && !<?= json_encode((bool)$stickyStaffId) ?>) staffIdInput.value = "";
            floatFields.forEach((field) => updateFloatField(field));
        } catch (e) {}
    }
    window.setTimeout(clearAutofillIfSafe, 160);
    window.setTimeout(clearAutofillIfSafe, 700);

    // If we arrived here from a logout redirect, clear any browser-restored/autofilled values.
    try {
        const params = new URLSearchParams(window.location.search);
        if (params.get("from_logout") === "1") {
            clearAuthFields();
            params.delete("from_logout");
            const qs = params.toString();
            const cleanUrl = window.location.pathname + (qs ? ("?" + qs) : "");
            window.history.replaceState({}, "", cleanUrl);
        }
    } catch (e) {}

    // If the browser restores this page from back/forward cache, clear sensitive fields.
    window.addEventListener("pageshow", (event) => {
        try {
            if (event.persisted) clearAuthFields();
            // Some browsers restore form values even when not marked persisted.
            clearAutofillIfSafe();
        } catch (e) {}
    });

    floatFields.forEach((field) => {
        updateFloatField(field);
        field.addEventListener("input", () => updateFloatField(field));
        field.addEventListener("change", () => updateFloatField(field));
        field.addEventListener("blur", () => updateFloatField(field));
    });

    inputs.forEach((el, i) => {
        el.addEventListener("keydown", e => {
            if (e.key === "Enter") {
                e.preventDefault();
                const next = inputs[i + 1];
                if (next && typeof next.focus === 'function') {
                    try { next.focus({ preventScroll: true }); } catch (err) { next.focus(); }
                }
            }
        });
    });

    const eyeOpen = togglePassword?.querySelector("[data-eye-open]");
    const eyeClosed = togglePassword?.querySelector("[data-eye-closed]");
    togglePassword?.addEventListener("click", () => {
        const isPassword = passwordInput.type === "password";
        passwordInput.type = isPassword ? "text" : "password";
        togglePassword.setAttribute("aria-label", isPassword ? "Hide password" : "Show password");
        eyeOpen?.classList.toggle("hidden", !isPassword);
        eyeClosed?.classList.toggle("hidden", isPassword);
        togglePassword.classList.toggle("text-teal-600", isPassword);
        togglePassword.classList.toggle("text-slate-500", !isPassword);
    });

    loginForm?.addEventListener("submit", () => {
        if (!loginBtn) return;
        try {
            loginBtn.classList.add("is-loading");
            loginBtn.setAttribute("aria-busy", "true");
            loginBtn.setAttribute("disabled", "disabled");
            loginBtn.textContent = "Verifying...";
        } catch (e) {}
    });

    function normalizePhone(raw) {
        const s = (raw || '').trim();
        if (s === '') return null;
        if (/[A-Za-z]/.test(s)) return null;

        // Keep only digits and optional leading '+'.
        const hasPlus = s.startsWith('+');
        const digits = s.replace(/\D/g, '');
        if (!digits) return null;

        // Normalize to +2637XXXXXXXX
        if (/^2637\d{8}$/.test(digits)) return '+' + digits;
        if (/^7\d{8}$/.test(digits)) return '+263' + digits;
        if (hasPlus && /^\+2637\d{8}$/.test(s.replace(/[^\d+]/g, ''))) return '+263' + digits.slice(3); // safety

        return null;
    }

    async function loadMotherProfileIfPossible() {
        if (!profileWrap || !profileImg || !welcomeName) return;
        const raw = (identifierInput?.value || '').trim();
        const phone = normalizePhone(raw);
        if (!phone) {
            profileWrap.classList.add("hidden");
            profileImg.src = "";
            welcomeName.textContent = "";
            return;
        }
        try {
            const res = await fetch(`/mch_system/auth/get_profile.php?phone=${encodeURIComponent(phone)}`);
            if (!res.ok) throw new Error("bad response");
            const data = await res.json();
            if (data.ok && data.profile_photo) {
                profileImg.src = data.profile_photo;
                profileWrap.classList.remove("hidden");
                welcomeName.textContent = data.full_name ? `, ${data.full_name}` : "";
            } else {
                profileWrap.classList.add("hidden");
                profileImg.src = "";
                welcomeName.textContent = data.full_name ? `, ${data.full_name}` : "";
            }
        } catch (e) {
            profileWrap.classList.add("hidden");
            profileImg.src = "";
            welcomeName.textContent = "";
        }
    }

    function updateUi() {
        const raw = (identifierInput?.value || '').trim();
        const hasAny = raw !== '';
        const phone = hasAny ? normalizePhone(raw) : null;
        const isPhoneComplete = !!phone;
        const isPhoneCandidate = hasAny && !/[A-Za-z]/.test(raw);

        staffGroup?.classList.toggle('hidden', !hasAny || isPhoneCandidate);
        if (staffIdInput) {
            staffIdInput.required = false;
            staffIdInput.disabled = !hasAny || isPhoneCandidate;
            if (!hasAny || isPhoneCandidate) staffIdInput.value = '';
            updateFloatField(staffIdInput);
        }

        if (forgotLink && adminResetHint) {
            if (!hasAny) {
                forgotLink.classList.add('hidden');
                adminResetHint.classList.add('hidden');
            } else if (isPhoneCandidate) {
                forgotLink.classList.remove('hidden');
                adminResetHint.classList.add('hidden');
            } else {
                forgotLink.classList.add('hidden');
                adminResetHint.classList.remove('hidden');
            }
        }

        if (isPhoneComplete) {
            loadMotherProfileIfPossible();
        } else {
            profileWrap?.classList.add("hidden");
            if (profileImg) profileImg.src = "";
            if (welcomeName) welcomeName.textContent = "";
        }
    }

    identifierInput?.addEventListener("input", () => {
        const raw = identifierInput.value || '';
        // If it looks like phone input, gently clean it up (keep + and digits only).
        if (!/[A-Za-z]/.test(raw)) {
            const cleaned = raw.replace(/[^\d+]/g, '');
            // allow only one leading '+'
            const normalized = cleaned.startsWith('+')
                ? '+' + cleaned.slice(1).replace(/\+/g, '')
                : cleaned.replace(/\+/g, '');
            // soft length cap: +2637XXXXXXXX (13 chars) or 7XXXXXXXX (9 chars)
            identifierInput.value = normalized.slice(0, 13);
        }
        updateFloatField(identifierInput);
        updateUi();
    });

    identifierInput?.addEventListener("blur", () => {
        updateUi();
        loadMotherProfileIfPossible();
    });

    updateUi();
});
</script>

</body>
</html>
