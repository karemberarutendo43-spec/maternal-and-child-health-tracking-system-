<?php
require_once __DIR__ . "/../config/session.php";
mch_session_start();
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth_tokens.php";

// Revoke remember-me token if present.
if (!empty($_COOKIE[MCH_AUTH_REMEMBER_COOKIE])) {
    mch_auth_revoke_remember_token($pdo, (string)$_COOKIE[MCH_AUTH_REMEMBER_COOKIE]);
}
mch_auth_clear_cookie(MCH_AUTH_REMEMBER_COOKIE);

// Destroy all session data
$_SESSION = [];
session_unset();
session_destroy();

// Redirect directly to login (avoid exposing internal unlock URLs).
header("Location: /mch_system/auth/login.php?from_logout=1");
exit;
