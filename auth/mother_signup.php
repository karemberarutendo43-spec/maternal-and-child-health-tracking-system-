<?php
require_once __DIR__ . "/../config/session.php";
mch_session_start();
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/password_policy.php";
require_once __DIR__ . "/../config/uploads.php";

// Reduce browser caching of the signup screen (helps on shared devices and back button).
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

$error = '';
$clinics = $pdo->query("SELECT id, clinic_name FROM clinics ORDER BY clinic_name")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $full_name = trim($_POST['full_name'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $password  = $_POST['password'] ?? '';
    $profile_photo = null;
    $clinic_id = (int)($_POST['clinic_id'] ?? 0);

    if (!$full_name || !$phone || !$email || !$password) {
        $error = "All fields are required.";
    } else {
        if (preg_match('/^7\\d{8}$/', $phone)) {
            $phone = '+263' . $phone;
        } elseif (preg_match('/^2637\\d{8}$/', $phone)) {
            $phone = '+' . $phone;
        } elseif (!preg_match('/^\\+2637\\d{8}$/', $phone)) {
            $error = "Phone number must be in the format +2637XXXXXXXX or 7XXXXXXXX.";
        }
        if (!$error && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
        }

        if (!$error && $clinic_id <= 0) {
            $error = "Please select a clinic.";
        }

        if (!$error) {
            $msg = mch_password_validate((string)$password, mch_password_min_length_for_role('mother'));
            if ($msg) {
                $error = $msg;
            }
        }

        // Check duplicate phone or email
        $check = $pdo->prepare("SELECT id FROM users WHERE phone = ? OR email = ?");
        $check->execute([$phone, $email]);

        if ($check->fetch()) {
            $error = "Phone number or email already registered.";
        } else {

            if (!empty($_FILES['profile_photo']['name'])) {
                $file = $_FILES['profile_photo'];
                if ($file['error'] !== UPLOAD_ERR_OK) {
                    $error = "Profile photo upload failed.";
                } else {
                    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime = $finfo->file($file['tmp_name']);

                    if (!isset($allowed[$mime])) {
                        $error = "Profile photo must be a JPG or PNG image.";
                    } elseif ($file['size'] > 5 * 1024 * 1024) {
                        $error = "Profile photo must be 5MB or smaller.";
                    } else {
                        $uploadDir = mch_uploads_abs_path('profiles');
                        if (!is_dir($uploadDir)) {
                            mkdir($uploadDir, 0775, true);
                        }
                        $filename = uniqid('profile_', true) . "." . $allowed[$mime];
                        $destPath = $uploadDir . "/" . $filename;
                        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                            $error = "Failed to save profile photo.";
                        } else {
                            $profile_photo = mch_uploads_public_url("profiles/{$filename}");
                        }
                    }
                }
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);

            $otp = rand(100000, 999999);
            $expires = date("Y-m-d H:i:s", strtotime("+10 minutes"));

            if ($error) {
                throw new Exception($error);
            }

            $stmt = $pdo->prepare("
                INSERT INTO users 
                (full_name, phone, email, password_hash, role, status, email_otp, email_otp_expires, email_verified, profile_photo, has_web_access, clinic_id)
                VALUES (?, ?, ?, ?, 'mother', 'PENDING', ?, ?, 0, ?, 1, ?)
            ");
            $stmt->execute([$full_name, $phone, $email, $hash, $otp, $expires, $profile_photo, $clinic_id]);

            $_SESSION['otp_demo']  = $otp;
            $_SESSION['otp_email'] = $email;

            $subject = "Your MCHTS verification code";
            $message = "Hello $full_name,\n\nYour verification code is: $otp\n\nThis code expires in 10 minutes.";
            @mail($email, $subject, $message, "From: no-reply@mchts.local");

            header("Location: auth_verify.php");
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Mother Registration | MCHTS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="/mch_system/assets/output.css">
<style>
  @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap');
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
      rgba(34, 211, 238, 0.85) 18%,
      rgba(56, 189, 248, 0.0) 32%,
      rgba(167, 139, 250, 0.75) 52%,
      rgba(56, 189, 248, 0.0) 68%,
      rgba(34, 211, 238, 0.85) 84%,
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
        drop-shadow(0 0 14px rgba(34, 211, 238, 0.32))
        drop-shadow(0 0 34px rgba(56, 189, 248, 0.22));
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
        drop-shadow(0 0 14px rgba(34, 211, 238, 0.32))
        drop-shadow(0 0 34px rgba(56, 189, 248, 0.22));
    }
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
    transition: transform 0.12s ease, box-shadow 0.12s ease;
    box-shadow: 0 12px 28px rgba(14, 165, 233, 0.22);
  }
  .mch-btn:hover { transform: translateY(-1px); box-shadow: 0 18px 36px rgba(34, 211, 238, 0.28); }

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
    padding: 0.78rem 0.9rem;
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
  select.float-field {
    -webkit-appearance: none;
    -moz-appearance: none;
    appearance: none;
    padding-right: 2.5rem;
  }
  select.float-field:invalid { color: rgba(71, 85, 105, 0.9); }
  select.float-field:valid { color: #0b1220; }
  .clinic-select {
    background: #ffffff;
    color: #0b1220;
    border-color: rgba(148, 163, 184, 0.35);
  }
  .clinic-select:focus {
    background: #ffffff !important;
    border-color: rgba(14, 165, 233, 0.65) !important;
  }

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

  /* File input: hide the "Choose file / no file chosen" box until hover/focus. */
  .file-float-group .float-field[type="file"] {
    opacity: 0;
    border-color: transparent;
    background: transparent;
    cursor: pointer;
    transition: opacity 0.15s ease, border-color 0.15s ease, background 0.15s ease;
  }
  .file-float-group:hover .float-field[type="file"],
  .file-float-group:focus-within .float-field[type="file"],
  .file-float-group .float-field[type="file"][data-has-value="true"] {
    opacity: 1;
    border-color: rgba(148, 163, 184, 0.26);
    background: var(--mch-field-bg);
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
  .float-field:focus + .float-label,
  .float-field[data-has-value="true"] + .float-label,
  .float-group:hover .float-label {
    top: -0.62rem;
    font-size: 0.75rem;
    color: rgba(34, 211, 238, 0.88);
  }
  /* Selects don't have placeholders; keep label floated so it doesn't cover the selected text. */
  select.float-field + .float-label {
    top: -0.62rem;
    font-size: 0.75rem;
  }
  .phone-label { left: 3.75rem; }
</style>
</head>

<body class="min-h-screen auth-bg">

<div class="min-h-screen flex items-start justify-center p-4 py-8 sm:p-6 sm:py-10 lg:items-center">
  <div class="auth-center w-full max-w-md">
    <div id="auth_left_circle" class="auth-left-circle" role="img" aria-label="Maternity Tracking System">
      <div class="auth-left-circle-text">Maternity<br>Tracking System</div>
    </div>

    <div class="auth-card p-6 sm:p-8">

        <div class="text-center mb-4 sm:mb-6">
            <h2 class="text-2xl font-bold text-slate-100">
                Mother Registration
            </h2>
            <p class="text-sm text-slate-300/80">
                Create your account to access maternal services
            </p>
        </div>

        <?php if ($error): ?>
            <div class="bg-rose-100 text-rose-700 p-3 rounded-lg mb-4 text-sm">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-3 sm:space-y-4" enctype="multipart/form-data" autocomplete="off">

            <div class="float-group">
                <span class="absolute right-3 top-3 text-slate-500">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M7.5 6a4.5 4.5 0 119 0v.75H18a3 3 0 013 3v7.5a3 3 0 01-3 3H6a3 3 0 01-3-3v-7.5a3 3 0 013-3h1.5V6zm2.25 0a2.25 2.25 0 114.5 0v.75h-4.5V6z" clip-rule="evenodd"/>
                    </svg>
                </span>
                <input type="text" name="full_name"
                       class="float-field field pr-10 focus:ring-2 focus:ring-teal-500"
                       required autofocus placeholder=" " id="full_name"
                       autocomplete="name" autocapitalize="words" autocorrect="off" spellcheck="false">
                <label class="float-label" for="full_name">Full Name</label>
            </div>

            <div class="float-group">
                <div class="flex">
                    <span class="inline-flex items-center px-3 border border-r-0 rounded-l text-slate-700 bg-slate-100">+263</span>
                    <input type="text" name="phone"
                           class="float-field field pr-10 rounded-l-none rounded-r focus:ring-2 focus:ring-teal-500"
                           required placeholder=" " id="phone" inputmode="numeric"
                           pattern="7[0-9]{8}" minlength="9" maxlength="9"
                           title="Enter 9 digits starting with 7 (after +263)"
                           autocomplete="tel-national">
                    <label class="float-label phone-label" for="phone">Phone Number</label>
                </div>
                <span class="absolute right-3 top-3 text-slate-500">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M2 3.75A.75.75 0 012.75 3h2A2.25 2.25 0 017 5.25v13.5A2.25 2.25 0 014.75 21h-2A.75.75 0 012 20.25V3.75zM8.5 6.75A2.25 2.25 0 0110.75 4.5h6.5A2.25 2.25 0 0119.5 6.75v10.5A2.25 2.25 0 0117.25 19.5h-6.5A2.25 2.25 0 018.5 17.25V6.75z"/>
                    </svg>
                </span>
            </div>

            <div class="float-group">
                <input type="email" name="email"
                       class="float-field field pr-10 focus:ring-2 focus:ring-teal-500"
                       required placeholder=" " id="email"
                       autocomplete="email" autocapitalize="none" autocorrect="off" spellcheck="false">
                <label class="float-label" for="email">Email Address</label>
            </div>

            <div class="float-group">
                <select name="clinic_id" class="float-field field clinic-select focus:ring-2 focus:ring-teal-500" required id="clinic_id">
                    <option value="" disabled selected>Select clinic…</option>
                    <?php foreach ($clinics as $c): ?>
                        <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['clinic_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <label class="float-label" for="clinic_id">Preferred Clinic</label>
                <span class="absolute right-3 top-3 text-slate-500 pointer-events-none" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.24 4.24a.75.75 0 01-1.06 0L5.21 8.29a.75.75 0 01.02-1.08z" clip-rule="evenodd"/>
                    </svg>
                </span>
            </div>

            <div class="float-group">
                <input type="password" name="password"
                       class="float-field field pr-10 focus:ring-2 focus:ring-teal-500"
                       required minlength="12" placeholder=" " id="password"
                       autocomplete="new-password">
                <label class="float-label" for="password">Create Password</label>
                <button type="button" class="absolute right-3 top-3 text-slate-500 transition" id="toggle_password" aria-label="Show password">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" data-eye-open>
                        <path d="M12 5c-4.478 0-8.268 2.943-9.543 7 1.275 4.057 5.065 7 9.543 7 4.478 0 8.268-2.943 9.543-7C20.268 7.943 16.478 5 12 5zm0 11a4 4 0 110-8 4 4 0 010 8z"/>
                    </svg>
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 hidden" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" data-eye-closed>
                        <path d="M3.53 2.47a.75.75 0 00-1.06 1.06l2.1 2.1A12.318 12.318 0 002.457 12c1.275 4.057 5.065 7 9.543 7 2.19 0 4.215-.71 5.86-1.91l2.61 2.61a.75.75 0 101.06-1.06l-18-18zM12 17.5c-3.5 0-6.59-2.142-7.794-5.5a10.79 10.79 0 012.221-3.35l2.02 2.02A4 4 0 0012 16a3.98 3.98 0 002.81-1.16l1.95 1.95A7.94 7.94 0 0112 17.5zm3.78-6.72l-2.52-2.52a4.01 4.01 0 00-4.52 4.52l-2.1-2.1a10.834 10.834 0 014.36-2.04 3.99 3.99 0 014.78 4.14z"/>
                    </svg>
                </button>
                <div class="mt-1 text-xs text-slate-500">
                    Use a passphrase (12+ characters). Example: <span class="font-semibold">Blue Sky Happy Baby</span>
                </div>
            </div>

            <div class="text-sm">
                <button type="button" id="toggle_photo" class="text-teal-600 font-semibold hover:underline">
                    Add profile photo (optional)
                </button>
            </div>

            <div id="photo_section" class="hidden space-y-3 sm:space-y-4">
                <div class="float-group file-float-group">
                    <input type="file" name="profile_photo" accept="image/png,image/jpeg" class="float-field field" id="profile_photo">
                    <label class="float-label" for="profile_photo">Profile Photo (Optional)</label>
                </div>
                <div id="profile_preview" class="hidden">
                    <img id="profile_preview_img" src="" alt="Profile preview" class="w-20 h-20 rounded-full object-cover border border-slate-200">
                </div>
            </div>

            <button class="w-full mch-btn py-3 font-semibold transition">
                Create Account
            </button>
        </form>

        <div class="text-center mt-4 sm:mt-6 text-sm">
            <span class="text-slate-500">Already registered?</span>
            <a href="login.php"
               class="text-teal-600 font-semibold hover:underline">
                Login
            </a>
        </div>

        <div class="mt-4 sm:mt-6 text-xs text-slate-400 text-center">
            You will receive a one-time verification code to activate your account.
        </div>

    </div>
  </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", () => {
    const inputs = document.querySelectorAll("input");
    const togglePassword = document.getElementById("toggle_password");
    const passwordInput = document.getElementById("password");
    const floatFields = document.querySelectorAll(".field");
    const profileInput = document.getElementById("profile_photo");
    const photoSection = document.getElementById("photo_section");
    const togglePhoto = document.getElementById("toggle_photo");
    const previewWrap = document.getElementById("profile_preview");
    const previewImg = document.getElementById("profile_preview_img");
    const phoneInput = document.getElementById("phone");
    const fullNameInput = document.getElementById("full_name");
    const emailInput = document.getElementById("email");
    const clinicInput = document.getElementById("clinic_id");

    function clearSignupFields() {
        try {
            if (fullNameInput) fullNameInput.value = "";
            if (phoneInput) phoneInput.value = "";
            if (emailInput) emailInput.value = "";
            if (passwordInput) passwordInput.value = "";
            if (clinicInput) clinicInput.selectedIndex = 0;
            if (profileInput) profileInput.value = "";
            if (previewWrap) previewWrap.classList.add("hidden");
            if (previewImg) previewImg.src = "";
            floatFields.forEach((field) => updateFloatField(field));
        } catch (e) {}
    }

    // If the browser restores this page from back/forward cache, clear sensitive fields.
    window.addEventListener("pageshow", (event) => {
        try {
            if (event.persisted) clearSignupFields();
        } catch (e) {}
    });

    function updateFloatField(field) {
        const hasValue = field.value && field.value.toString().trim() !== '';
        field.dataset.hasValue = hasValue ? 'true' : 'false';
    }

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
                    try {
                        next.focus({ preventScroll: true });
                    } catch (err) {
                        next.focus();
                    }
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

    phoneInput?.addEventListener("input", () => {
        let raw = phoneInput.value.replace(/\D/g, '');
        if (raw.startsWith('263')) {
            raw = raw.slice(3);
        }
        if (raw.length > 0 && raw[0] !== '7') {
            phoneInput.value = '';
        } else {
            phoneInput.value = raw.slice(0, 9);
        }
        updateFloatField(phoneInput);
    });

    profileInput?.addEventListener("change", () => {
        const file = profileInput.files?.[0];
        if (!file) {
            previewWrap.classList.add("hidden");
            previewImg.src = "";
            return;
        }
        const url = URL.createObjectURL(file);
        previewImg.src = url;
        previewWrap.classList.remove("hidden");
    });

    togglePhoto?.addEventListener("click", () => {
        if (!photoSection) return;
        photoSection.classList.toggle("hidden");
        const isHidden = photoSection.classList.contains("hidden");
        togglePhoto.textContent = isHidden ? "Add profile photo (optional)" : "Hide profile photo";
        if (!isHidden) {
            try { profileInput?.focus({ preventScroll: true }); } catch (e) { profileInput?.focus(); }
        }
    });
});
</script>

</body>
</html>
