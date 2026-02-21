<?php
require_once __DIR__ . "/../config/session.php";
mch_session_start();
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth_tokens.php";

$error = '';
$success = '';
$demoLink = $_SESSION['password_reset_demo_link'] ?? '';
unset($_SESSION['password_reset_demo_link']);

// Detect whether password reset table exists (avoid leaking account existence based on configuration errors).
$resetConfigured = true;
try {
    $pdo->query("SELECT 1 FROM password_reset_tokens LIMIT 0");
} catch (Exception $e) {
    $resetConfigured = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif (!$resetConfigured) {
        $error = "Password reset is not configured yet. Run `sql/password_reset_tokens.sql` on the database.";
    } else {
        $generic = "If an account exists for that email, a password reset link has been sent.";
        $success = $generic;

        $stmt = $pdo->prepare("SELECT id, full_name, role, has_web_access FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            if (($user['role'] ?? '') === 'mother' && (int)($user['has_web_access'] ?? 0) !== 1) {
                // Clinic-managed mothers do not reset via web (still show generic message)
            } else {
                $selector = mch_auth_random_selector();
                $token = mch_auth_random_token();
                $tokenHash = hash('sha256', $token);
                $expiresTs = time() + (60 * 60); // 1 hour
                $expiresAt = date('Y-m-d H:i:s', $expiresTs);

                try {
                    $ins = $pdo->prepare("
                        INSERT INTO password_reset_tokens
                          (user_id, selector, token_hash, expires_at, created_at)
                        VALUES
                          (?, ?, ?, ?, NOW())
                    ");
                    $ins->execute([(int)$user['id'], $selector, $tokenHash, $expiresAt]);

                    $scheme = mch_auth_is_https() ? 'https' : 'http';
                    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                    $resetUrl = "{$scheme}://{$host}/mch_system/auth/reset_password.php?selector=" . rawurlencode($selector) . "&token=" . rawurlencode($token);

                    $subject = "MCHTS password reset";
                    $name = trim((string)($user['full_name'] ?? ''));
                    $greeting = $name !== '' ? "Hello {$name}," : "Hello,";
                    $message = "{$greeting}\n\nWe received a request to reset your MCHTS password.\n\nReset link (valid for 1 hour):\n{$resetUrl}\n\nIf you did not request this, you can ignore this email.";
                    @mail($email, $subject, $message, "From: no-reply@mchts.local");

                    $isLocal = (stripos((string)($_SERVER['HTTP_HOST'] ?? ''), 'localhost') !== false) ||
                               (strpos((string)($_SERVER['HTTP_HOST'] ?? ''), '127.0.0.1') !== false);
                    if ($isLocal) {
                        $_SESSION['password_reset_demo_link'] = $resetUrl;
                        $demoLink = $resetUrl;
                    }
                } catch (Exception $e) {
                    // Keep message generic to avoid leaking whether the email exists.
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
<title>Forgot Password | MCHTS</title>
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
      <h2 class="text-2xl font-bold text-teal-700">Forgot password</h2>
      <p class="text-sm text-slate-500">Enter your email to receive a reset link</p>
    </div>

    <?php if ($error): ?>
      <div class="bg-rose-100 text-rose-700 p-3 rounded-lg mb-4 text-sm"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
      <div class="bg-emerald-100 text-emerald-700 p-3 rounded-lg mb-4 text-sm"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <?php if ($demoLink): ?>
      <div class="bg-amber-100 text-amber-800 p-3 rounded-lg mb-4 text-xs break-all">
        Demo reset link: <a class="underline" href="<?= htmlspecialchars($demoLink) ?>"><?= htmlspecialchars($demoLink) ?></a>
      </div>
    <?php endif; ?>

    <form method="POST" class="space-y-4">
      <div>
        <label class="block text-sm font-semibold text-slate-700 mb-1">Email address</label>
        <input type="email" name="email" required class="w-full border border-slate-200 p-3 rounded-xl bg-white focus:ring-2 focus:ring-teal-500">
      </div>
      <button class="w-full mch-btn py-3 font-semibold">Send reset link</button>
    </form>

    <div class="text-center mt-6 text-sm">
      <a href="login.php" class="text-teal-600 font-semibold hover:underline">Back to login</a>
    </div>
  </div>

</body>
</html>
