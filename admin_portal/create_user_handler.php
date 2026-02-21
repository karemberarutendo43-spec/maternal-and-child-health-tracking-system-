<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/csrf.php";

// Ensure this handler is not reachable without an admin session.
mch_auth_require_role('admin');

if (!defined('MCH_CREATE_USER_HANDLER_LOADED')) {
    define('MCH_CREATE_USER_HANDLER_LOADED', true);

    $createUserError = '';
    $createUserSuccess = '';
    $createUserClinics = [];

    // If included from another file, these variables may already exist.
    $currentAdminLevel = $currentAdminLevel ?? ($_SESSION['admin_level'] ?? 'SUPER');
    $currentClinicId = $currentClinicId ?? (int)($_SESSION['clinic_id'] ?? 0);
    $isSuperAdmin = $isSuperAdmin ?? ((string)$currentAdminLevel === 'SUPER');

    $hasAdminLevelColumn = $hasAdminLevelColumn ?? false;
    if ($hasAdminLevelColumn === false) {
        try {
            $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'admin_level'")->fetch(PDO::FETCH_ASSOC);
            $hasAdminLevelColumn = !empty($col);
        } catch (Throwable $e) {
            $hasAdminLevelColumn = false;
        }
    }

    $allowedUsername = '/^[A-Za-z][A-Za-z0-9_.-]{2,29}$/';
    $isCreateUserRequest = $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['create_user_submit']);

    if ($isCreateUserRequest) {
        mch_csrf_require_valid('admin_create_user');

        $username  = trim($_POST['username'] ?? '');
        $staff_id  = trim($_POST['staff_id'] ?? '');
        $password  = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        $role      = trim((string)($_POST['role'] ?? 'nurse'));
        $admin_level = trim((string)($_POST['admin_level'] ?? ''));
        $full_name = trim((string)($_POST['full_name'] ?? ''));
        $clinic_id = (int)($_POST['clinic_id'] ?? 0);

        if (!$isSuperAdmin) {
            $role = 'nurse';
            $admin_level = '';
            $clinic_id = $currentClinicId;
        }

        $is_admin = ($role === 'admin');
        $is_nurse = ($role === 'nurse');
        $is_health_info = ($role === 'health_info');
        if (!$is_admin && !$is_nurse && !$is_health_info) {
            $role = 'nurse';
            $is_nurse = true;
            $is_admin = false;
        }

        $effective_admin_level = $admin_level;
        if ($is_admin && $hasAdminLevelColumn && $effective_admin_level === '') {
            $effective_admin_level = 'CLINIC';
        }
        $needs_clinic = $is_nurse || $is_health_info || ($is_admin && (!$hasAdminLevelColumn || $effective_admin_level !== 'SUPER'));
        $needs_staff_id = ($is_nurse || $is_health_info);

        if (!$username || !$password) {
            $createUserError = "Username and password are required.";
        } elseif ($confirm_password === '' || $password !== $confirm_password) {
            $createUserError = "Passwords do not match.";
        } elseif ($full_name === '') {
            $createUserError = "Full name is required.";
        } elseif (!preg_match($allowedUsername, $username)) {
            $createUserError = "Username must be 3-30 characters (letters, numbers, dot, dash, underscore), starting with a letter.";
        } elseif ($needs_staff_id && $staff_id === '') {
            $createUserError = "Staff ID is required for staff accounts.";
        } elseif ($needs_clinic && $clinic_id <= 0) {
            $createUserError = "Clinic is required.";
        } elseif ($is_admin && $hasAdminLevelColumn && $admin_level !== '' && !in_array($admin_level, ['SUPER', 'CLINIC'], true)) {
            $createUserError = "Invalid admin level.";
        }

        if (!$createUserError) {
            $check = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            $check->execute([$username]);
            if ($check->fetch()) {
                $createUserError = "Username already exists.";
            }
        }

        if (!$createUserError) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            if ($is_admin && $hasAdminLevelColumn && $admin_level === '') {
                $admin_level = 'CLINIC';
            }
            $stmt = $pdo->prepare("
                INSERT INTO users (full_name, username, staff_id, phone, password_hash, role, status, has_web_access, email_verified, clinic_id, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 'ACTIVE', 1, 1, ?, NOW())
            ");
            $stmt->execute([
                $full_name,
                $username,
                ($needs_staff_id ? ($staff_id ?: null) : ($staff_id !== '' ? $staff_id : null)),
                null,
                $hash,
                $role,
                $clinic_id ?: null
            ]);

            if ($is_admin && $hasAdminLevelColumn && $admin_level !== '') {
                $upd = $pdo->prepare("UPDATE users SET admin_level = ? WHERE username = ? AND role = 'admin'");
                $upd->execute([$admin_level, $username]);
            }

            $createUserSuccess = ucfirst($role) . " created successfully.";
        }
    }

    if ($isSuperAdmin) {
        $createUserClinics = $pdo->query("SELECT id, clinic_name FROM clinics ORDER BY clinic_name")->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($currentClinicId > 0) {
        $cStmt = $pdo->prepare("SELECT id, clinic_name FROM clinics WHERE id = ? LIMIT 1");
        $cStmt->execute([$currentClinicId]);
        $row = $cStmt->fetch(PDO::FETCH_ASSOC);
        $createUserClinics = $row ? [$row] : [];
    }
}
