<?php
require_once __DIR__ . "/../config/session.php";
mch_session_start();
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/audit.php";
require_once __DIR__ . "/../config/csrf.php";

// Admin only
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: /mch_system/auth/login.php");
    exit;
}

$currentAdminLevel = $_SESSION['admin_level'] ?? 'SUPER';
$currentClinicId = (int)($_SESSION['clinic_id'] ?? 0);
$isSuperAdmin = ($currentAdminLevel === 'SUPER');

$hasAdminLevelColumn = false;
try {
    $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'admin_level'")->fetch(PDO::FETCH_ASSOC);
    $hasAdminLevelColumn = !empty($col);
} catch (Exception $e) {
    $hasAdminLevelColumn = false;
}

// prepare clinic options for the update dropdown
$clinics = [];
if ($isSuperAdmin) {
    $clinics = $pdo->query("SELECT id, clinic_name FROM clinics ORDER BY clinic_name")->fetchAll(PDO::FETCH_ASSOC);
} elseif ($currentClinicId > 0) {
    $clinicStmt = $pdo->prepare("SELECT id, clinic_name FROM clinics WHERE id = ? LIMIT 1");
    $clinicStmt->execute([$currentClinicId]);
    $row = $clinicStmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $clinics = [$row];
    }
}

$error = '';
$success = '';

// Flash message (PRG)
$flash = $_SESSION['flash_users'] ?? null;
if (is_array($flash)) {
    $error = (string)($flash['error'] ?? '');
    $success = (string)($flash['success'] ?? '');
    unset($_SESSION['flash_users']);
}

// Reset user password (Admin Reset Flow)
if (isset($_POST['reset_password'])) {
    mch_csrf_require_valid('admin_users');
    $targetUserId = (int)($_POST['user_id'] ?? 0);
    $targetRole = (string)($_POST['role'] ?? '');

    try {
        if ($targetUserId <= 0) {
            throw new Exception("Invalid user.");
        }
        if (!in_array($targetRole, ['nurse', 'mother', 'admin', 'health_info'], true)) {
            throw new Exception("Invalid role.");
        }
        if (!$isSuperAdmin && $targetRole === 'admin') {
            throw new Exception("Only Super Admin can reset admin accounts.");
        }

        // Ensure must_change_password column exists
        $hasMustChange = false;
        try {
            $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'must_change_password'")->fetch(PDO::FETCH_ASSOC);
            $hasMustChange = !empty($col);
        } catch (Throwable $e) {
            $hasMustChange = false;
        }
        if (!$hasMustChange) {
            throw new Exception("Password reset is not enabled yet. Run sql/auth_password_reset.sql on the database.");
        }

        // Fetch target user (with clinic scoping for clinic admins)
        if ($isSuperAdmin) {
            $stmt = $pdo->prepare("SELECT id, full_name, clinic_id, role FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$targetUserId]);
        } else {
            if ($currentClinicId <= 0) {
                throw new Exception("Clinic admin is not assigned to a clinic.");
            }
            $stmt = $pdo->prepare("
                SELECT id, full_name, clinic_id, role
                FROM users
                WHERE id = ?
                  AND role IN ('nurse','mother','health_info')
                  AND clinic_id = ?
                LIMIT 1
            ");
            $stmt->execute([$targetUserId, $currentClinicId]);
        }
        $target = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$target) {
            throw new Exception("User not found (or not in your clinic scope).");
        }
        if (($target['role'] ?? '') !== $targetRole) {
            throw new Exception("Role mismatch.");
        }

        // Generate a temporary password for the user.
        // Avoid confusing characters.
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $tmp = '';
        for ($i = 0; $i < 10; $i++) {
            $tmp .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        $hash = password_hash($tmp, PASSWORD_DEFAULT);
        $upd = $pdo->prepare("UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ? LIMIT 1");
        $upd->execute([$hash, $targetUserId]);

        // Revoke remember-me sessions so the user must login using the new temporary password.
        try {
            $pdo->prepare("DELETE FROM auth_remember_tokens WHERE user_id = ?")->execute([$targetUserId]);
        } catch (Throwable $e) {
            // ignore if not migrated
        }

        mch_audit_log(
            $pdo,
            'RESET_PASSWORD',
            'users',
            $targetUserId,
            'Admin reset password for ' . (string)($target['full_name'] ?? 'user')
        );

        $_SESSION['flash_users'] = [
            'success' => "Temporary password set for " . (string)($target['full_name'] ?? 'user') . ": " . $tmp . " (User must change password on next login).",
        ];
        header("Location: users.php");
        exit;
    } catch (Throwable $e) {
        $_SESSION['flash_users'] = ['error' => $e->getMessage()];
        header("Location: users.php");
        exit;
    }
}

// Toggle user status
if (isset($_POST['toggle_user'])) {
    mch_csrf_require_valid('admin_users');
    $user_id = (int)($_POST['user_id'] ?? 0);
    $current_status = (string)($_POST['current_status'] ?? '');

    $new_status = ($current_status === 'ACTIVE') ? 'DISABLED' : 'ACTIVE';

    if ($user_id <= 0) {
        $error = "Invalid user.";
    } else {
        if ($isSuperAdmin) {
            $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
            $stmt->execute([$new_status, $user_id]);
        } else {
            // Clinic admins can only toggle nurses/mothers in their clinic.
            if ($currentClinicId <= 0) {
                $error = "Clinic admin is not assigned to a clinic.";
            } else {
                $stmt = $pdo->prepare("
                UPDATE users
                SET status = ?
                WHERE id = ?
                  AND role IN ('nurse','mother','health_info')
                  AND clinic_id = ?
                ");
                $stmt->execute([$new_status, $user_id, $currentClinicId]);
            }
        }
    }

    if ($error === '') {
        header("Location: users.php");
        exit;
    }
}

// Update admin/nurse clinic + username + staff ID (merged form)
if (isset($_POST['update_admin_user'])) {
    $user_id = (int)($_POST['user_id'] ?? 0);
    $clinic_id = (int)($_POST['clinic_id'] ?? 0);
    $username = trim($_POST['username'] ?? '');
    $staff_id = trim($_POST['staff_id'] ?? '');
    $role = trim($_POST['role'] ?? '');
    $admin_level = trim($_POST['admin_level'] ?? '');

    $is_admin = ($role === 'admin');
    $is_nurse = ($role === 'nurse');
    $is_health_info = ($role === 'health_info');

    $needs_clinic = ($is_nurse || $is_admin || $is_health_info);
    $needs_staff_id = ($is_nurse || $is_health_info);

    if (!$user_id) {
        $error = "Invalid user.";
    } elseif ($username === '') {
        $error = "Username is required.";
    } elseif (!preg_match('/^[A-Za-z][A-Za-z0-9_.-]{2,29}$/', $username)) {
        $error = "Username must be 3-30 characters (letters, numbers, dot, dash, underscore), starting with a letter.";
    } elseif (!$isSuperAdmin && $is_admin) {
        $error = "Only Super Admin can modify admin accounts.";
    } elseif ($needs_clinic && !$clinic_id) {
        $error = "Clinic is required.";
    } elseif ($needs_staff_id && $staff_id === '') {
        $error = "Staff ID is required for staff accounts.";
    } elseif ($is_admin && $admin_level !== '' && !in_array($admin_level, ['SUPER','CLINIC'], true)) {
        $error = "Invalid admin level.";
    } elseif (!$isSuperAdmin && $is_nurse && $clinic_id !== $currentClinicId) {
        $error = "Clinic admins can only assign users to their own clinic.";
    } elseif (!$isSuperAdmin && $is_health_info && $clinic_id !== $currentClinicId) {
        $error = "Clinic admins can only assign users to their own clinic.";
    } else {
        if ($isSuperAdmin) {
            if ($hasAdminLevelColumn) {
                $stmt = $pdo->prepare("
                    UPDATE users
                    SET clinic_id = ?, username = ?, staff_id = ?, admin_level = COALESCE(?, admin_level)
                    WHERE id = ? AND role IN ('nurse','admin','health_info')
                ");
                $stmt->execute([$clinic_id ?: null, $username, ($needs_staff_id ? ($staff_id ?: null) : ($staff_id ?: null)), ($is_admin ? ($admin_level ?: null) : null), $user_id]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE users
                    SET clinic_id = ?, username = ?, staff_id = ?
                    WHERE id = ? AND role IN ('nurse','admin','health_info')
                ");
                $stmt->execute([$clinic_id ?: null, $username, ($needs_staff_id ? ($staff_id ?: null) : ($staff_id ?: null)), $user_id]);
            }
        } else {
            $stmt = $pdo->prepare("
                UPDATE users
                SET clinic_id = ?, username = ?, staff_id = ?
                WHERE id = ?
                  AND role IN ('nurse','health_info')
                  AND clinic_id = ?
            ");
            $stmt->execute([$clinic_id ?: null, $username, $staff_id ?: null, $user_id, $currentClinicId]);
        }
        $success = "User updated successfully.";
    }

    if (!$error) {
        header("Location: users.php");
        exit;
    }
}

// Fetch users
$usersStmt = null;
if ($isSuperAdmin) {
    $usersStmt = $pdo->query("
        SELECT id, full_name, phone, role, status, created_at, clinic_id, username, staff_id" . ($hasAdminLevelColumn ? ", admin_level" : "") . "
        FROM users
        ORDER BY role, full_name
    ");
} else {
    if ($currentClinicId <= 0) {
        $users = [];
    } else {
        $usersStmt = $pdo->prepare("
            SELECT id, full_name, phone, role, status, created_at, clinic_id, username, staff_id" . ($hasAdminLevelColumn ? ", admin_level" : "") . "
            FROM users
            WHERE (role = 'admin' AND id = ?)
               OR (role IN ('nurse','mother','health_info') AND clinic_id = ?)
            ORDER BY role, full_name
        ");
        $usersStmt->execute([(int)$_SESSION['user_id'], $currentClinicId]);
    }
}
$users = isset($usersStmt) && $usersStmt ? $usersStmt->fetchAll(PDO::FETCH_ASSOC) : ($users ?? []);

include __DIR__ . "/../includes/header.php";
include __DIR__ . "/../includes/sidebar_admin.php";
?>

<div class="ml-64 p-6 max-w-6xl space-y-6">

    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div>
            <h1 class="text-3xl font-semibold text-slate-900 mb-2">Manage Users</h1>
            <p class="text-slate-600">Activate, deactivate and control system access</p>
        </div>
    </div>

    <div class="mch-card bg-white/90 p-6 border border-slate-100">
        <?php if ($error): ?>
            <div class="bg-rose-100 text-rose-700 p-3 rounded-lg mb-4"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="bg-emerald-100 text-emerald-700 p-3 rounded-lg mb-4"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <?php if (count($users) === 0): ?>
            <p class="text-gray-500">No users found.</p>
        <?php else: ?>
            <table class="w-full text-sm mch-table mch-scroll">
                <thead>
                    <tr>
                        <th class="p-2 text-left">Full Name</th>
                        <th class="p-2 text-left">Phone</th>
                        <th class="p-2 text-left">Role</th>
                        <th class="p-2 text-left">Status</th>
                        <th class="p-2 text-left">Action</th>
                        <th class="p-2 text-left">Clinic / Username / Staff ID</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td class="p-2 border-t border-slate-200"><?= htmlspecialchars($u['full_name']) ?></td>
                            <td class="p-2 border-t border-slate-200"><?= htmlspecialchars($u['phone']) ?></td>
                            <td class="p-2 border-t border-slate-200">
                                <span class="<?= $u['role'] === 'admin' ? 'text-slate-900 font-semibold' : 'text-teal-700' ?>">
                                    <?= ucfirst($u['role']) ?>
                                </span>
                            </td>
                            <td class="p-2 border-t border-slate-200">
                                <span class="<?= $u['status'] === 'ACTIVE' ? 'text-green-600 font-semibold' : 'text-red-600 font-semibold' ?>">
                                    <?= $u['status'] ?>
                                </span>
                            </td>
                            <td class="p-2 border-t border-slate-200">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <?php if ($u['role'] !== 'admin'): ?>
                                        <form method="POST" class="inline">
                                            <?= mch_csrf_input('admin_users') ?>
                                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                            <input type="hidden" name="current_status" value="<?= $u['status'] ?>">
                                            <button name="toggle_user"
                                                class="<?= $u['status'] === 'ACTIVE'
                                                    ? 'bg-rose-50 border border-rose-200 hover:bg-rose-100'
                                                    : 'bg-emerald-50 border border-emerald-200 hover:bg-emerald-100' ?>
                                                    px-3 py-1 rounded-lg text-sm">
                                                <?= $u['status'] === 'ACTIVE' ? 'Disable' : 'Activate' ?>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-gray-400 text-sm">Protected</span>
                                    <?php endif; ?>

                                    <?php
                                      $canReset = false;
                                      if ($u['role'] === 'admin') {
                                        $canReset = $isSuperAdmin && (int)$u['id'] !== (int)($_SESSION['user_id'] ?? 0);
                                      } else {
                                        $canReset = true;
                                      }
                                    ?>
                                    <?php if ($canReset): ?>
                                        <form method="POST" class="inline" onsubmit="return confirm('Reset password for this user? A temporary password will be shown once.');">
                                            <?= mch_csrf_input('admin_users') ?>
                                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                            <input type="hidden" name="role" value="<?= htmlspecialchars((string)$u['role']) ?>">
                                            <button name="reset_password" value="1" class="px-3 py-1 rounded-lg text-sm bg-amber-50 border border-amber-200 hover:bg-amber-100">
                                                Reset Password
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="p-2 border-t border-slate-200">
                                <?php if (in_array($u['role'], ['admin','nurse'], true)): ?>
                                    <form method="POST" class="flex items-center gap-2 flex-wrap">
                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                        <input type="hidden" name="role" value="<?= htmlspecialchars($u['role']) ?>">
                                        <select name="clinic_id" class="border border-slate-200 p-1 rounded-lg text-sm bg-white" <?= $u['role'] === 'nurse' ? 'required' : '' ?> <?= (!$isSuperAdmin && $u['role'] === 'admin') ? 'disabled' : '' ?>>
                                            <option value="">Select clinic</option>
                                            <?php foreach ($clinics as $c): ?>
                                                <option value="<?= (int)$c['id'] ?>" <?= (int)$u['clinic_id'] === (int)$c['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($c['clinic_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                         </select>
                                         <input type="text" name="username" value="<?= htmlspecialchars($u['username'] ?? '') ?>" placeholder="Username"
                                               class="border border-slate-200 p-1 rounded-lg text-sm w-28" required
                                               maxlength="30" pattern="[A-Za-z][A-Za-z0-9_]{2,29}"
                                               title="3-30 characters (letters, numbers, underscore), starting with a letter">
                                         <input type="text" name="staff_id" value="<?= htmlspecialchars($u['staff_id'] ?? '') ?>" placeholder="Staff ID"
                                               class="border border-slate-200 p-1 rounded-lg text-sm w-24" <?= $u['role'] === 'nurse' ? 'required' : '' ?>>
                                        <?php if ($isSuperAdmin && $hasAdminLevelColumn && ($u['role'] ?? '') === 'admin'): ?>
                                            <select name="admin_level" class="border border-slate-200 p-1 rounded-lg text-sm bg-white">
                                                <option value="">Admin level</option>
                                                <option value="SUPER" <?= ($u['admin_level'] ?? '') === 'SUPER' ? 'selected' : '' ?>>SUPER</option>
                                                <option value="CLINIC" <?= ($u['admin_level'] ?? '') === 'CLINIC' ? 'selected' : '' ?>>CLINIC</option>
                                            </select>
                                        <?php endif; ?>
                                        <button name="update_admin_user" class="bg-slate-900 text-white px-3 py-1 rounded-lg text-xs hover:bg-slate-800">
                                            Save
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-gray-400 text-sm">N/A</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

    </div>

</div>

<div class="ml-64 p-6 max-w-6xl">
    <div class="mch-card bg-white/90 p-6 border border-slate-100 flex flex-col md:flex-row md:items-center md:justify-between gap-3">
        <div>
            <h2 class="text-xl font-semibold text-slate-900">Need to add a new user?</h2>
            <p class="text-sm text-slate-600">Use the dedicated create user page for safety and audit logging.</p>
        </div>
        <a href="create_user.php" class="mch-btn px-6 py-2 whitespace-nowrap">Create User</a>
    </div>
</div>

<?php include __DIR__ . "/../includes/footer.php"; ?>
