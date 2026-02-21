<?php
require_once __DIR__ . "/../config/session.php";
mch_session_start();
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth_tokens.php";

$provider = strtolower(trim($_GET['provider'] ?? ''));
$code = trim($_GET['code'] ?? '');
$error = trim($_GET['error'] ?? '');
$state = trim($_GET['state'] ?? '');

function mch_http_post_json(string $url, array $fields): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = (string)curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($errno) {
        return ['ok' => false, 'status' => 0, 'error' => $err ?: 'curl error'];
    }
    $json = json_decode($body, true);
    if (!is_array($json)) {
        return ['ok' => false, 'status' => $status, 'error' => 'Invalid JSON response'];
    }
    return ['ok' => ($status >= 200 && $status < 300), 'status' => $status, 'json' => $json];
}

function mch_http_get_json(string $url, string $bearerToken): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $bearerToken],
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = (string)curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($errno) {
        return ['ok' => false, 'status' => 0, 'error' => $err ?: 'curl error'];
    }
    $json = json_decode($body, true);
    if (!is_array($json)) {
        return ['ok' => false, 'status' => $status, 'error' => 'Invalid JSON response'];
    }
    return ['ok' => ($status >= 200 && $status < 300), 'status' => $status, 'json' => $json];
}

$uiError = '';

if ($provider !== 'google') {
    $uiError = 'Unsupported provider.';
} elseif ($error !== '') {
    $uiError = $error;
} elseif ($code === '') {
    $uiError = 'Missing authorization code.';
} else {
    require_once __DIR__ . "/../config/oauth_google.php";

    $expectedState = (string)($_SESSION['oauth_state_google'] ?? '');
    $startedAt = (int)($_SESSION['oauth_started_at'] ?? 0);
    unset($_SESSION['oauth_state_google'], $_SESSION['oauth_started_at']);

    if ($expectedState === '' || $state === '' || !hash_equals($expectedState, $state)) {
        $uiError = 'Invalid OAuth state. Please try again.';
    } elseif ($startedAt > 0 && (time() - $startedAt) > 10 * 60) {
        $uiError = 'OAuth session expired. Please try again.';
    } elseif (MCH_GOOGLE_OAUTH_CLIENT_ID === '' || MCH_GOOGLE_OAUTH_CLIENT_SECRET === '') {
        $uiError = 'Google OAuth is not configured.';
    } else {
        $tokenRes = mch_http_post_json('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => MCH_GOOGLE_OAUTH_CLIENT_ID,
            'client_secret' => MCH_GOOGLE_OAUTH_CLIENT_SECRET,
            'redirect_uri' => MCH_GOOGLE_OAUTH_REDIRECT_URI,
            'grant_type' => 'authorization_code',
        ]);

        if (!$tokenRes['ok']) {
            $uiError = 'Failed to exchange code for token.';
        } else {
            $accessToken = (string)($tokenRes['json']['access_token'] ?? '');
            if ($accessToken === '') {
                $uiError = 'Missing access token.';
            } else {
                $infoRes = mch_http_get_json('https://openidconnect.googleapis.com/v1/userinfo', $accessToken);
                if (!$infoRes['ok']) {
                    $uiError = 'Failed to fetch Google user profile.';
                } else {
                    $sub = (string)($infoRes['json']['sub'] ?? '');
                    $email = (string)($infoRes['json']['email'] ?? '');
                    $name = (string)($infoRes['json']['name'] ?? '');

                    if ($sub === '' || $email === '') {
                        $uiError = 'Google profile missing required fields.';
                    } else {
                        // Mothers only
                        try {
                            $pdo->beginTransaction();

                            // Ensure oauth table exists (if not migrated, show error)
                            $tbl = $pdo->query("SHOW TABLES LIKE 'oauth_identities'")->fetch(PDO::FETCH_ASSOC);
                            if (!$tbl) {
                                throw new Exception("DB table oauth_identities is missing. Run sql/oauth_identities.sql.");
                            }

                            $stmt = $pdo->prepare("
                                SELECT u.*
                                FROM oauth_identities oi
                                JOIN users u ON u.id = oi.user_id
                                WHERE oi.provider = 'google' AND oi.provider_user_id = ?
                                LIMIT 1
                            ");
                            $stmt->execute([$sub]);
                            $user = $stmt->fetch(PDO::FETCH_ASSOC);

                            if (!$user) {
                                // Link by email if a mother exists.
                                $uStmt = $pdo->prepare("SELECT * FROM users WHERE role='mother' AND email = ? LIMIT 1");
                                $uStmt->execute([$email]);
                                $user = $uStmt->fetch(PDO::FETCH_ASSOC);

                                if (!$user) {
                                    // Create a new mother account (web access).
                                    $pwd = bin2hex(random_bytes(16));
                                    $hash = password_hash($pwd, PASSWORD_DEFAULT);
                                    $ins = $pdo->prepare("
                                        INSERT INTO users
                                          (full_name, email, password_hash, role, status, email_verified, has_web_access, created_at)
                                        VALUES
                                          (?, ?, ?, 'mother', 'PENDING', 1, 1, NOW())
                                    ");
                                    $ins->execute([$name !== '' ? $name : $email, $email, $hash]);
                                    $userId = (int)$pdo->lastInsertId();
                                    $user = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
                                    $user->execute([$userId]);
                                    $user = $user->fetch(PDO::FETCH_ASSOC);
                                }

                                $link = $pdo->prepare("
                                    INSERT INTO oauth_identities (provider, provider_user_id, user_id, email, created_at)
                                    VALUES ('google', ?, ?, ?, NOW())
                                ");
                                $link->execute([$sub, (int)$user['id'], $email]);
                            }

                            $pdo->commit();

                            mch_auth_set_session_from_user($user);

                            // If missing phone or clinic, ask to complete profile.
                            $needsPhone = empty($user['phone']);
                            $needsClinic = empty($user['clinic_id']);
                            if ($needsPhone || $needsClinic) {
                                header("Location: /mch_system/auth/complete_profile.php");
                                exit;
                            }

                            // Otherwise go to portal.
                            header("Location: /mch_system/mother_portal/dashboard.php");
                            exit;
                        } catch (Exception $e) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }
                            $uiError = $e->getMessage();
                        }
                    }
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
<title>OAuth Callback | MCHTS</title>
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
</style>
</head>

<body class="min-h-screen flex items-center justify-center auth-bg p-6">
  <div class="auth-card w-full max-w-lg p-8">
    <h2 class="text-2xl font-bold text-teal-700 mb-2">Social login callback</h2>
    <p class="text-sm text-slate-600 mb-4">
      <?php if ($uiError): ?>
        We couldn't sign you in.
      <?php else: ?>
        Redirecting…
      <?php endif; ?>
    </p>

    <?php if ($uiError): ?>
      <div class="bg-rose-100 text-rose-700 p-3 rounded-lg mb-4 text-sm">
        OAuth error: <?= htmlspecialchars($uiError) ?>
      </div>
    <?php endif; ?>

    <div class="bg-slate-100 text-slate-700 p-3 rounded-lg text-xs font-mono break-all">
      provider=<?= htmlspecialchars($provider) ?>&amp; code=<?= htmlspecialchars($code) ?>
    </div>

    <div class="mt-6">
      <a href="login.php" class="text-teal-600 font-semibold hover:underline">Back to login</a>
    </div>
  </div>
</body>
</html>
