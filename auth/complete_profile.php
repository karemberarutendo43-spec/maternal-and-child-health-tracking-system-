<?php
require_once __DIR__ . "/../config/session.php";
mch_session_start();
require_once __DIR__ . "/../config/db.php";

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'mother') {
    header("Location: /mch_system/auth/login.php");
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = trim((string)($_POST['phone'] ?? ''));
    $clinic_id = (int)($_POST['clinic_id'] ?? 0);

    if (!preg_match('/^7\\d{8}$/', $phone)) {
        $error = "Phone number must be 9 digits, start with 7, and exclude the leading 0 (e.g., 7XXXXXXXX).";
    } elseif ($clinic_id <= 0) {
        $error = "Please select a clinic.";
    } else {
        $phoneE164 = '+263' . $phone;
        $uid = (int)$_SESSION['user_id'];

        $dup = $pdo->prepare("SELECT id FROM users WHERE phone = ? AND id <> ? LIMIT 1");
        $dup->execute([$phoneE164, $uid]);
        if ($dup->fetch()) {
            $error = "This phone number is already in use.";
        } else {
            $upd = $pdo->prepare("UPDATE users SET phone = COALESCE(phone, ?), clinic_id = COALESCE(clinic_id, ?) WHERE id = ? AND role = 'mother'");
            $upd->execute([$phoneE164, $clinic_id, $uid]);
            $_SESSION['clinic_id'] = $clinic_id;
            $success = "Profile updated. You can now continue.";
        }
    }
}

$clinics = $pdo->query("SELECT id, clinic_name FROM clinics ORDER BY clinic_name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Complete Profile | MCHTS</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap');
    :root { --mch-primary:#0f766e; --mch-soft:#f8fafc; --mch-ink:#0f172a; }
    html, body { font-family: 'Plus Jakarta Sans', system-ui, -apple-system, Segoe UI, sans-serif; color: var(--mch-ink); }
    .auth-bg { background: radial-gradient(1200px 600px at 10% -10%, #e0f2fe 0%, transparent 60%), radial-gradient(1000px 500px at 110% 0%, #fef3c7 0%, transparent 55%), var(--mch-soft); }
    .auth-card { border-radius: 20px; box-shadow: 0 18px 40px rgba(15, 23, 42, 0.12); border: 1px solid #e2e8f0; background: rgba(255,255,255,0.92); backdrop-filter: blur(6px); }
    .mch-btn { background: var(--mch-primary); color: #fff; border-radius: 14px; box-shadow: 0 10px 22px rgba(15, 118, 110, 0.28); }
    .mch-btn:hover { box-shadow: 0 14px 26px rgba(15, 118, 110, 0.32); }
  </style>
</head>
<body class="min-h-screen flex items-center justify-center auth-bg p-6">

  <div class="auth-card w-full max-w-lg p-8">
    <h2 class="text-2xl font-bold text-teal-700 mb-2">Complete your profile</h2>
    <p class="text-sm text-slate-600 mb-6">Add your phone number and preferred clinic to continue.</p>

    <?php if ($error): ?>
      <div class="bg-rose-100 text-rose-700 p-3 rounded-lg mb-4 text-sm"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
      <div class="bg-emerald-100 text-emerald-700 p-3 rounded-lg mb-4 text-sm"><?= htmlspecialchars($success) ?></div>
      <div class="mt-4">
        <a href="/mch_system/mother_portal/pending.php" class="mch-btn px-4 py-2 inline-block text-sm font-semibold">Continue</a>
      </div>
    <?php else: ?>
      <form method="POST" class="space-y-4">
        <div>
          <label class="block mb-2 text-sm font-semibold text-slate-700">Phone number</label>
          <div class="flex">
            <span class="inline-flex items-center px-3 border border-r-0 rounded-l-lg text-slate-700 bg-slate-100">+263</span>
            <input id="phone_input" type="text" name="phone" class="border border-slate-200 p-2 rounded-r-lg w-full bg-white"
                   placeholder="7XXXXXXXX" pattern="7[0-9]{8}" minlength="9" maxlength="9" inputmode="numeric" required>
          </div>
          <div class="text-xs text-slate-500 mt-1">Enter 9 digits starting with 7 (after +263).</div>
        </div>

        <div>
          <label class="block mb-2 text-sm font-semibold text-slate-700">Preferred clinic</label>
          <select name="clinic_id" class="border border-slate-200 p-2 rounded-lg w-full bg-white" required>
            <option value="">Select clinic</option>
            <?php foreach ($clinics as $c): ?>
              <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['clinic_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <button class="mch-btn w-full py-2 text-sm font-semibold">Save</button>
      </form>
    <?php endif; ?>
  </div>

  <script>
    (function () {
      const phoneInput = document.getElementById('phone_input');
      if (!phoneInput) return;
      phoneInput.addEventListener('input', () => {
        let raw = (phoneInput.value || '').replace(/\D/g, '');
        if (raw.startsWith('263')) raw = raw.slice(3);
        if (raw.startsWith('0')) raw = raw.slice(1);
        if (raw.length > 0 && raw[0] !== '7') {
          phoneInput.value = '';
          return;
        }
        phoneInput.value = raw.slice(0, 9);
      });
    })();
  </script>

</body>
</html>
