<?php
declare(strict_types=1);

// Lightweight connectivity probe endpoint.
// Public by design: returns no sensitive information.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

echo json_encode([
    'ok' => true,
    'ts' => time(),
]);

