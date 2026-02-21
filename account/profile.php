<?php
require_once __DIR__ . "/../config/session.php";
mch_session_start();
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/uploads.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: /mch_system/auth/login.php");
    exit;
}

$role = $_SESSION['role'] ?? '';
$error = '';
$success = '';

// Load user
$stmt = $pdo->prepare("SELECT id, full_name, phone, profile_photo FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    die("User not found");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $profile_photo = $user['profile_photo'] ?? null;

    if ($full_name === '') {
        $error = "Full name is required.";
    } elseif (!preg_match("/^[\\p{L}\\s'\\-\\.,]+$/u", $full_name)) {
        $error = "Full name must contain letters only (dots, commas, apostrophes, and hyphens allowed).";
    }

    if (!$error && !empty($_FILES['profile_photo']['name'])) {
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

    if (!$error) {
        $upd = $pdo->prepare("UPDATE users SET full_name = ?, profile_photo = ? WHERE id = ?");
        $upd->execute([$full_name, $profile_photo, $_SESSION['user_id']]);
        $_SESSION['full_name'] = $full_name;
        $success = "Profile updated successfully.";
        $user['full_name'] = $full_name;
        $user['profile_photo'] = $profile_photo;
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

<div class="ml-64 p-6 max-w-2xl">
    <div class="bg-white p-6 rounded-xl shadow">
        <div class="flex items-center justify-between gap-3 flex-wrap mb-4">
            <h2 class="text-xl font-bold text-teal-700">Edit Profile</h2>
            <a href="/mch_system/account/change_password.php" class="inline-flex items-center px-3 py-2 rounded-lg bg-slate-50 border border-slate-200 hover:bg-slate-100 text-sm">
                Change Password
            </a>
        </div>

        <?php if ($error): ?>
            <div class="bg-red-100 text-red-700 p-3 rounded mb-4"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="bg-green-100 text-green-700 p-3 rounded mb-4"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <div class="flex items-center gap-4 mb-6">
            <?php if (!empty($user['profile_photo'])): ?>
                <img src="<?= htmlspecialchars($user['profile_photo']) ?>" alt="Profile" class="w-16 h-16 rounded-full object-cover border">
            <?php else: ?>
                <div class="w-16 h-16 rounded-full bg-teal-600 text-white flex items-center justify-center font-bold text-xl">
                    <?= htmlspecialchars(strtoupper(substr(preg_replace('/\\s+/', '', $user['full_name']), 0, 2))) ?>
                </div>
            <?php endif; ?>
            <div>
                <div class="font-semibold"><?= htmlspecialchars($user['full_name']) ?></div>
                <div class="text-sm text-slate-500"><?= htmlspecialchars($user['phone']) ?></div>
            </div>
        </div>

        <form method="POST" enctype="multipart/form-data" class="space-y-4">
            <div>
                <label class="block text-sm font-semibold mb-1">Full Name</label>
                <input type="text" name="full_name" value="<?= htmlspecialchars($user['full_name']) ?>" class="w-full border p-2 rounded" required>
            </div>

            <div>
                <label class="block text-sm font-semibold mb-1">Profile Photo (optional)</label>
                <input type="file" name="profile_photo" accept="image/png,image/jpeg" class="w-full border p-2 rounded">
            </div>

            <button class="bg-teal-600 text-white px-4 py-2 rounded hover:bg-teal-700">
                Save Changes
            </button>
        </form>
    </div>
</div>
