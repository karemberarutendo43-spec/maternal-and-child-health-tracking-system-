<?php
declare(strict_types=1);

/**
 * Minimal CSRF protection for form POST requests.
 *
 * Usage:
 *   require_once __DIR__ . "/../config/csrf.php";
 *   // In form:
 *   echo mch_csrf_input('some_context');
 *   // On POST:
 *   mch_csrf_require_valid('some_context');
 */

require_once __DIR__ . "/session.php";
mch_session_start();

function mch_csrf_context_key(string $context): string
{
    $context = trim($context);
    return $context !== '' ? $context : 'default';
}

function mch_csrf_token(string $context = 'default'): string
{
    $key = mch_csrf_context_key($context);
    if (!isset($_SESSION['csrf_tokens']) || !is_array($_SESSION['csrf_tokens'])) {
        $_SESSION['csrf_tokens'] = [];
    }

    if (!empty($_SESSION['csrf_tokens'][$key]) && is_string($_SESSION['csrf_tokens'][$key])) {
        return (string)$_SESSION['csrf_tokens'][$key];
    }

    $token = bin2hex(random_bytes(32));
    $_SESSION['csrf_tokens'][$key] = $token;
    return $token;
}

function mch_csrf_input(string $context = 'default'): string
{
    $token = mch_csrf_token($context);
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES) . '">';
}

function mch_csrf_is_valid(string $context, ?string $provided): bool
{
    $key = mch_csrf_context_key($context);
    $expected = $_SESSION['csrf_tokens'][$key] ?? '';
    if (!is_string($expected) || $expected === '') {
        return false;
    }
    if (!is_string($provided) || $provided === '') {
        return false;
    }
    return hash_equals($expected, $provided);
}

function mch_csrf_require_valid(string $context = 'default'): void
{
    $provided = $_POST['csrf_token'] ?? null;
    if (!mch_csrf_is_valid($context, is_string($provided) ? $provided : null)) {
        http_response_code(403);
        echo "<h1>403 Forbidden</h1>";
        echo "<p>Invalid request token. Please refresh the page and try again.</p>";
        exit;
    }
}
