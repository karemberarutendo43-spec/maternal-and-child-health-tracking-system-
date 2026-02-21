<?php
require_once __DIR__ . "/session.php";
mch_session_start();

function mch_auth_disable_cache(): void
{
    // Reduce chance of sensitive pages being stored in browser cache / back-forward cache.
    if (headers_sent()) {
        return;
    }
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

function mch_auth_redirect_login(): void
{
    header("Location: /mch_system/auth/login.php");
    exit;
}

function mch_auth_require_login(): void
{
    if (empty($_SESSION['user_id']) || empty($_SESSION['role'])) {
        mch_auth_redirect_login();
    }
}

function mch_auth_require_role($allowed_roles): void
{
    mch_auth_require_login();

    if (!is_array($allowed_roles)) {
        $allowed_roles = [$allowed_roles];
    }

    if (count($allowed_roles) === 0) {
        return;
    }

    if (!in_array($_SESSION['role'], $allowed_roles, true)) {
        http_response_code(403);
        echo "<h1>403 Forbidden</h1>";
        echo "<p>You do not have permission to access this page.</p>";
        exit;
    }
}

function mch_auth_role_home_path(string $role): ?string
{
    $role = strtolower(trim($role));
    if ($role === 'admin') return '/mch_system/admin_portal/dashboard.php';
    if ($role === 'nurse') return '/mch_system/nurse_portal/dashboard.php';
    if ($role === 'mother') return '/mch_system/mother_portal/dashboard.php';
    if ($role === 'health_info') return '/mch_system/health_info_portal/dashboard.php';
    return null;
}

function mch_auth_redirect_wrong_portal(array $allowed_roles): void
{
    if (empty($_SESSION['user_id']) || empty($_SESSION['role'])) {
        return;
    }

    $role = (string)$_SESSION['role'];
    if (in_array($role, $allowed_roles, true)) {
        return;
    }

    $home = mch_auth_role_home_path($role);
    if (!$home) {
        return;
    }

    $path = (string)($_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? ''));
    if ($path !== '' && stripos($path, $home) !== false) {
        return;
    }

    header("Location: {$home}");
    exit;
}

function mch_auth_is_mother_pending(PDO $pdo, int $user_id): bool
{
    $stmt = $pdo->prepare("SELECT status FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    return $u && ($u['status'] ?? '') !== 'ACTIVE';
}

function mch_auth_mother_redirect_pending_if_needed(PDO $pdo): void
{
    if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'mother') {
        return;
    }

    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $isPendingPage = strpos($uri, '/mother_portal/pending.php') !== false;
    if ($isPendingPage) {
        return;
    }

    $uid = (int)$_SESSION['user_id'];
    if ($uid <= 0) {
        return;
    }

    if (mch_auth_is_mother_pending($pdo, $uid)) {
        header("Location: /mch_system/mother_portal/pending.php");
        exit;
    }
}

// Auto-redirect mothers to pending page until activated
if (!isset($pdo)) {
    require_once __DIR__ . "/db.php";
}
mch_auth_mother_redirect_pending_if_needed($pdo);

/**
 * ROLE-BASED AUTH GUARD
 * Usage:
 *   require_once "auth.php";
 *   auth_guard('nurse'); // Only nurse can access
 *   auth_guard(['nurse', 'admin']); // Multiple roles allowed
 */

function auth_guard($allowed_roles = [])
{
    mch_auth_require_role($allowed_roles);
}

function mch_auth_auto_guard(): void
{
    $path = strtolower((string)($_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? '')));

    // Never guard auth endpoints here.
    if (strpos($path, '/auth/') !== false) {
        return;
    }

    // APIs may use token-based auth; do not enforce sessions globally.
    if (strpos($path, '/api/') !== false) {
        return;
    }

    mch_auth_disable_cache();

    if (strpos($path, '/admin_portal/') !== false) {
        mch_auth_redirect_wrong_portal(['admin']);
        mch_auth_require_role('admin');
        return;
    }

    if (strpos($path, '/nurse_portal/') !== false) {
        mch_auth_redirect_wrong_portal(['nurse', 'admin']);
        mch_auth_require_role(['nurse', 'admin']);
        return;
    }

    if (strpos($path, '/health_info_portal/') !== false) {
        mch_auth_redirect_wrong_portal(['health_info']);
        mch_auth_require_role('health_info');
        return;
    }

    if (strpos($path, '/mother_portal/') !== false) {
        mch_auth_redirect_wrong_portal(['mother']);
        mch_auth_require_role('mother');
        // mother pending redirect handled earlier
        return;
    }

    if (
        strpos($path, '/anc/') !== false ||
        strpos($path, '/babies/') !== false ||
        strpos($path, '/deliveries/') !== false ||
        strpos($path, '/immunizations/') !== false ||
        strpos($path, '/reports/') !== false
    ) {
        mch_auth_redirect_wrong_portal(['nurse', 'admin']);
        mch_auth_require_role(['nurse', 'admin']);
        return;
    }

    if (strpos($path, '/account/') !== false) {
        // Account area is available to all logged-in roles. Do not redirect here.
        mch_auth_require_login();
        return;
    }
}

mch_auth_auto_guard();
