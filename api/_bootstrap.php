<?php
declare(strict_types=1);

// Minimal JSON API bootstrap that reuses existing session auth + clinic scoping.
// NOTE: Keep API same-origin with the PHP app in production to avoid CORS complexity.

require_once __DIR__ . "/../config/session.php";
mch_session_start();

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/scope.php";
require_once __DIR__ . "/../config/cards.php";
require_once __DIR__ . "/../config/csrf.php";
require_once __DIR__ . "/../config/audit.php";

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function api_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function api_error(string $message, int $status = 400, array $extra = []): void
{
    api_json(['ok' => false, 'error' => $message] + $extra, $status);
}

function api_ok(array $data = []): void
{
    api_json(['ok' => true] + $data, 200);
}

function api_require_role(string $role): void
{
    if (!isset($_SESSION['user_id']) || (string)($_SESSION['role'] ?? '') !== $role) {
        api_error('Unauthorized', 401);
    }
}

function api_csrf_token(string $context): string
{
    return mch_csrf_token($context);
}

function api_csrf_require_valid(string $context): void
{
    $provided = $_POST['csrf_token'] ?? null;
    if (!mch_csrf_is_valid($context, is_string($provided) ? $provided : null)) {
        api_error('Invalid request token. Please refresh and try again.', 403);
    }
}
