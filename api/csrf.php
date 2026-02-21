<?php
require_once __DIR__ . "/_bootstrap.php";

if (empty($_SESSION['user_id'])) {
    api_error('Unauthorized', 401);
}

$context = trim((string)($_GET['context'] ?? 'default'));
if ($context === '' || strlen($context) > 60) {
    api_error('Invalid context', 400);
}

api_ok([
    'context' => $context,
    'csrf_token' => api_csrf_token($context),
]);

