<?php
require_once __DIR__ . "/../config/session.php";
mch_session_start();
require_once __DIR__ . "/../config/db.php";

$error = '';
$success = '';

if (!isset($_SESSION['otp_email'])) {
    header("Location: login.php");
    exit;
}

/* RESEND OTP */
if (isset($_POST['resend'])) {

    $otp = rand(100000, 999999);
    $expires = date("Y-m-d H:i:s", strtotime("+10 minutes"));

    $pdo->prepare("
        UPDATE users
        SET email_otp = ?, email_otp_expires = ?
        WHERE email = ? AND role = 'mother'
    ")->execute([$otp, $expires, $_SESSION['otp_email']]);

    $_SESSION['otp_demo'] = $otp;
    $_SESSION['otp_last_sent'] = time();

    $subject = "Your MCHTS verification code";
    $message = "Your verification code is: $otp\n\nThis code expires in 10 minutes.";
    @mail($_SESSION['otp_email'], $subject, $message, "From: no-reply@mchts.local");

    $success = "A new verification code has been sent.";
}

/* VERIFY OTP */
if (isset($_POST['verify'])) {

    $entered = trim($_POST['otp'] ?? '');

    $stmt = $pdo->prepare("
        SELECT id, email_otp, email_otp_expires
        FROM users
        WHERE email = ? AND role = 'mother'
        LIMIT 1
    ");
    $stmt->execute([$_SESSION['otp_email']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        $error = "User not found.";
    } elseif ($user['email_otp'] !== $entered) {
        $error = "Invalid verification code.";
    } elseif (strtotime($user['email_otp_expires']) < time()) {
        $error = "Verification code expired.";
    } else {

        $pdo->prepare("
            UPDATE users
            SET email_verified=1,
                email_otp=NULL,
                email_otp_expires=NULL
            WHERE id=?
        ")->execute([$user['id']]);

        unset($_SESSION['otp_demo'], $_SESSION['otp_email']);
        $_SESSION['flash_pending_popup'] = "We have received your registration. Please wait for activation at the clinic.";

        header("Location: login.php?registered=1");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Verify OTP | MCHTS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<script src="https://cdn.tailwindcss.com"></script>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap');
  :root {
    --mch-primary: #0f766e;
    --mch-accent: #f97316;
    --mch-soft: #f8fafc;
    --mch-ink: #0f172a;
    --mch-muted: #64748b;
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

<body class="min-h-screen flex items-center justify-center auth-bg">

<div class="auth-card w-full max-w-md p-8 text-center">

    <h2 class="text-2xl font-bold text-teal-700 mb-2">
        Email Verification
    </h2>
    <p class="text-sm text-slate-500 mb-6">
        Enter the 6-digit code sent to your email
    </p>

    <p class="text-xs text-slate-400 mb-4">
        Demo OTP:
        <span class="font-bold tracking-widest text-teal-700">
            <?= $_SESSION['otp_demo'] ?>
        </span>
    </p>

    <?php if ($error): ?>
        <div class="bg-rose-100 text-rose-700 p-3 rounded-lg mb-4">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="bg-emerald-100 text-emerald-700 p-3 rounded-lg mb-4">
            <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <form method="POST" class="space-y-4">

        <input type="text"
               name="otp"
               maxlength="6"
               class="w-full p-4 text-center text-xl tracking-widest border border-slate-200 rounded-xl focus:ring-2 focus:ring-teal-500"
               placeholder="000000"
               required autofocus>

        <button name="verify"
                class="w-full mch-btn py-3 font-semibold">
            Verify
        </button>
    </form>

    <form method="POST" class="mt-4">
        <button name="resend"
                class="text-sm text-teal-600 hover:underline"
                <?= isset($_SESSION['otp_last_sent']) && time() - $_SESSION['otp_last_sent'] < 60 ? 'disabled class="opacity-50"' : '' ?>>
            Resend Code
        </button>
    </form>

</div>

</body>
</html>
