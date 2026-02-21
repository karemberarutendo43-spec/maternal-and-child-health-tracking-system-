<?php
require_once __DIR__ . "/../config/session.php";
mch_session_start();

$provider = strtolower(trim($_GET['provider'] ?? ''));
$allowed = ['google' => 'Google'];

$label = $allowed[$provider] ?? null;

if ($provider === 'google') {
  require_once __DIR__ . "/../config/oauth_google.php";

  if (MCH_GOOGLE_OAUTH_CLIENT_ID === '' || MCH_GOOGLE_OAUTH_CLIENT_SECRET === '') {
    $label = null;
    $oauthMissing = true;
  } else {
    $oauthMissing = false;
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state_google'] = $state;
    $_SESSION['oauth_started_at'] = time();

    $params = [
      'client_id' => MCH_GOOGLE_OAUTH_CLIENT_ID,
      'redirect_uri' => MCH_GOOGLE_OAUTH_REDIRECT_URI,
      'response_type' => 'code',
      'scope' => 'openid email profile',
      'state' => $state,
      'prompt' => 'select_account',
    ];
    if (MCH_GOOGLE_OAUTH_HD !== '') {
      $params['hd'] = MCH_GOOGLE_OAUTH_HD;
    }
    $authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    header("Location: {$authUrl}");
    exit;
  }
} else {
  $oauthMissing = false;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Social Login | MCHTS</title>
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

  <div class="auth-card w-full max-w-lg p-8">
    <?php if (!$label): ?>
      <div class="bg-rose-100 text-rose-700 p-3 rounded-lg mb-4 text-sm">
        <?php if (!empty($oauthMissing)): ?>
          Google social login is not configured yet. Set `MCH_GOOGLE_OAUTH_CLIENT_ID` and `MCH_GOOGLE_OAUTH_CLIENT_SECRET`.
        <?php else: ?>
          Invalid provider.
        <?php endif; ?>
      </div>
    <?php else: ?>
      <h2 class="text-2xl font-bold text-teal-700 mb-2">Continue with <?= htmlspecialchars($label) ?></h2>
      <p class="text-sm text-slate-600 mb-4">Redirecting to Google…</p>
    <?php endif; ?>

    <div class="mt-6 flex gap-3">
      <a href="login.php" class="px-4 py-2 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 text-sm">Back</a>
      <a href="mother_signup.php" class="mch-btn px-4 py-2 text-sm font-semibold">Create mother account</a>
    </div>
  </div>

</body>
</html>
