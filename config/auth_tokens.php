<?php

if (!defined('MCH_AUTH_REMEMBER_COOKIE')) {
    define('MCH_AUTH_REMEMBER_COOKIE', 'mchts_remember');
}

function mch_auth_is_https(): bool
{
    $https = $_SERVER['HTTPS'] ?? '';
    if (!empty($https) && strtolower((string)$https) !== 'off') {
        return true;
    }
    $port = (int)($_SERVER['SERVER_PORT'] ?? 0);
    return $port === 443;
}

function mch_auth_cookie_path(): string
{
    return '/mch_system';
}

function mch_auth_set_cookie(string $name, string $value, int $expiresTs): void
{
    setcookie($name, $value, [
        'expires' => $expiresTs,
        'path' => mch_auth_cookie_path(),
        'secure' => mch_auth_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function mch_auth_clear_cookie(string $name): void
{
    setcookie($name, '', [
        'expires' => time() - 3600,
        'path' => mch_auth_cookie_path(),
        'secure' => mch_auth_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    unset($_COOKIE[$name]);
}

function mch_auth_base64url_encode(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function mch_auth_random_selector(): string
{
    return bin2hex(random_bytes(12)); // 24 hex chars
}

function mch_auth_random_token(): string
{
    return mch_auth_base64url_encode(random_bytes(32));
}

function mch_auth_parse_selector_token(string $cookieValue): ?array
{
    $cookieValue = trim($cookieValue);
    if ($cookieValue === '') {
        return null;
    }
    $parts = explode('.', $cookieValue, 2);
    if (count($parts) !== 2) {
        return null;
    }
    [$selector, $token] = $parts;
    $selector = trim($selector);
    $token = trim($token);
    if (!preg_match('/^[a-f0-9]{24}$/i', $selector)) {
        return null;
    }
    if (!preg_match('/^[A-Za-z0-9_-]{20,}$/', $token)) {
        return null;
    }
    return [strtolower($selector), $token];
}

function mch_auth_set_session_from_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['full_name'] = (string)($user['full_name'] ?? '');
    $_SESSION['role'] = (string)($user['role'] ?? '');

    $username = trim((string)($user['username'] ?? ''));
    if ($username !== '') {
        $_SESSION['username'] = $username;
    } else {
        unset($_SESSION['username']);
    }

    if (($_SESSION['role'] ?? '') === 'nurse') {
        $staff = trim((string)($user['staff_id'] ?? ''));
        if ($staff !== '') {
            $_SESSION['staff_id'] = $staff;
        } else {
            unset($_SESSION['staff_id']);
        }
    } else {
        unset($_SESSION['staff_id']);
    }

    $_SESSION['clinic_id'] = $user['clinic_id'] ?? null;
    if (($_SESSION['role'] ?? '') === 'admin') {
        // Default to SUPER for backward compatibility if column isn't migrated yet.
        $_SESSION['admin_level'] = (string)($user['admin_level'] ?? 'SUPER');
    } else {
        unset($_SESSION['admin_level']);
    }
    if (($user['role'] ?? '') === 'mother') {
        $_SESSION['mother_id'] = $user['linked_mother_id'] ?? null;
    }
}

function mch_auth_issue_remember_token(PDO $pdo, int $userId, int $days = 30): bool
{
    $selector = mch_auth_random_selector();
    $token = mch_auth_random_token();
    $tokenHash = hash('sha256', $token);
    $expiresTs = time() + ($days * 86400);
    $expiresAt = date('Y-m-d H:i:s', $expiresTs);

    $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    if ($ua !== null) {
        $ua = substr((string)$ua, 0, 255);
    }
    if ($ip !== null) {
        $ip = substr((string)$ip, 0, 45);
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO auth_remember_tokens
              (user_id, selector, token_hash, expires_at, created_at, user_agent, ip_address)
            VALUES
              (?, ?, ?, ?, NOW(), ?, ?)
        ");
        $stmt->execute([$userId, $selector, $tokenHash, $expiresAt, $ua, $ip]);
    } catch (Exception $e) {
        return false;
    }

    mch_auth_set_cookie(MCH_AUTH_REMEMBER_COOKIE, "{$selector}.{$token}", $expiresTs);
    return true;
}

function mch_auth_revoke_remember_token(PDO $pdo, string $cookieValue): void
{
    $parsed = mch_auth_parse_selector_token($cookieValue);
    if (!$parsed) {
        return;
    }
    [$selector] = $parsed;

    try {
        $stmt = $pdo->prepare("DELETE FROM auth_remember_tokens WHERE selector = ? LIMIT 1");
        $stmt->execute([$selector]);
    } catch (Exception $e) {
        // ignore if table not migrated
    }
}

function mch_auth_try_restore_from_remember_cookie(PDO $pdo): bool
{
    if (!empty($_SESSION['user_id']) && !empty($_SESSION['role'])) {
        return true;
    }

    $cookieValue = (string)($_COOKIE[MCH_AUTH_REMEMBER_COOKIE] ?? '');
    $parsed = mch_auth_parse_selector_token($cookieValue);
    if (!$parsed) {
        if ($cookieValue !== '') {
            mch_auth_clear_cookie(MCH_AUTH_REMEMBER_COOKIE);
        }
        return false;
    }
    [$selector, $token] = $parsed;

    try {
        $stmt = $pdo->prepare("
            SELECT rt.user_id, rt.token_hash, rt.expires_at, rt.revoked_at,
                   u.id, u.full_name, u.username, u.staff_id, u.role, u.linked_mother_id, u.status, u.email_verified, u.has_web_access, u.clinic_id, u.admin_level
            FROM auth_remember_tokens rt
            JOIN users u ON u.id = rt.user_id
            WHERE rt.selector = ?
            LIMIT 1
        ");
        $stmt->execute([$selector]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Table not migrated or column missing: retry without admin_level.
        try {
            $stmt = $pdo->prepare("
                SELECT rt.user_id, rt.token_hash, rt.expires_at, rt.revoked_at,
                       u.id, u.full_name, u.username, u.staff_id, u.role, u.linked_mother_id, u.status, u.email_verified, u.has_web_access, u.clinic_id
                FROM auth_remember_tokens rt
                JOIN users u ON u.id = rt.user_id
                WHERE rt.selector = ?
                LIMIT 1
            ");
            $stmt->execute([$selector]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e2) {
            // Table not migrated or DB error: ignore remember-me.
            return false;
        }
    }

    if (!$row) {
        mch_auth_clear_cookie(MCH_AUTH_REMEMBER_COOKIE);
        return false;
    }

    if (!empty($row['revoked_at']) || strtotime((string)$row['expires_at']) < time()) {
        try {
            $del = $pdo->prepare("DELETE FROM auth_remember_tokens WHERE selector = ? LIMIT 1");
            $del->execute([$selector]);
        } catch (Exception $e) {
        }
        mch_auth_clear_cookie(MCH_AUTH_REMEMBER_COOKIE);
        return false;
    }

    $expectedHash = (string)($row['token_hash'] ?? '');
    $actualHash = hash('sha256', $token);
    if ($expectedHash === '' || !hash_equals($expectedHash, $actualHash)) {
        // Possible stolen cookie: revoke this selector.
        try {
            $del = $pdo->prepare("DELETE FROM auth_remember_tokens WHERE selector = ? LIMIT 1");
            $del->execute([$selector]);
        } catch (Exception $e) {
        }
        mch_auth_clear_cookie(MCH_AUTH_REMEMBER_COOKIE);
        return false;
    }

    // Do not allow bypassing the same checks as regular login.
    if (($row['role'] ?? '') === 'mother') {
        if ((int)($row['has_web_access'] ?? 0) !== 1) {
            mch_auth_clear_cookie(MCH_AUTH_REMEMBER_COOKIE);
            return false;
        }
        if ((int)($row['email_verified'] ?? 0) !== 1) {
            mch_auth_clear_cookie(MCH_AUTH_REMEMBER_COOKIE);
            return false;
        }
    }

    mch_auth_set_session_from_user($row);

    // Rotate token on use.
    $newToken = mch_auth_random_token();
    $newHash = hash('sha256', $newToken);
    $expiresTs = time() + (30 * 86400);
    $expiresAt = date('Y-m-d H:i:s', $expiresTs);
    try {
        $upd = $pdo->prepare("
            UPDATE auth_remember_tokens
            SET token_hash = ?, expires_at = ?, last_used_at = NOW()
            WHERE selector = ?
            LIMIT 1
        ");
        $upd->execute([$newHash, $expiresAt, $selector]);
    } catch (Exception $e) {
    }
    mch_auth_set_cookie(MCH_AUTH_REMEMBER_COOKIE, "{$selector}.{$newToken}", $expiresTs);

    return true;
}
