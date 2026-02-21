<?php
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo "Method not allowed\n";
    exit;
}

$expected = (string)(getenv('MCH_CRON_TOKEN') ?: '');
$provided = (string)($_SERVER['HTTP_X_CRON_TOKEN'] ?? ($_GET['token'] ?? ''));

if ($expected === '' || !hash_equals($expected, $provided)) {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

@set_time_limit(0);

// Reuse the existing CLI script by simulating argv.
// Supported query params:
// - date=YYYY-MM-DD
// - clinic_id=INT
// - max=INT
// - dry=1 (omit --send)
$argv = ['send_auto_reminders.php'];

if (!isset($_GET['dry']) || (string)$_GET['dry'] !== '1') {
    $argv[] = '--send';
}

$date = (string)($_GET['date'] ?? '');
if ($date !== '') {
    $argv[] = '--date=' . $date;
}

$clinicId = (int)($_GET['clinic_id'] ?? 0);
if ($clinicId > 0) {
    $argv[] = '--clinic-id=' . $clinicId;
}

$max = (int)($_GET['max'] ?? 0);
if ($max > 0) {
    $argv[] = '--max=' . $max;
}

$_SERVER['argv'] = $argv;
$_SERVER['argc'] = count($argv);

require __DIR__ . '/send_auto_reminders.php';

