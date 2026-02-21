<?php
/**
 * Automated reminders for ANC and Immunizations.
 *
 * Usage (dry run):
 *   php cron/send_auto_reminders.php
 *
 * Usage (send):
 *   php cron/send_auto_reminders.php --send
 *
 * Options:
 *   --date=YYYY-MM-DD   Run as if "today" is this date (default: today)
 *   --clinic-id=ID      Restrict to a clinic_id
 *   --max=N             Max reminders per category (default: 200)
 *   --force-hours       Send even outside 08:00–18:00
 */

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/sms.php";

function arg_value(array $argv, string $name): ?string
{
    foreach ($argv as $a) {
        if (strpos($a, $name . '=') === 0) {
            return substr($a, strlen($name) + 1);
        }
    }
    return null;
}

function has_flag(array $argv, string $flag): bool
{
    return in_array($flag, $argv, true);
}

function table_exists(PDO $pdo, string $table): bool
{
    try {
        return (bool)$pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function within_hours(DateTimeImmutable $now, string $start = '08:00', string $end = '18:00'): bool
{
    $t = $now->format('H:i');
    return ($t >= $start && $t <= $end);
}

function safe_date_label(string $date): string
{
    try {
        return (new DateTimeImmutable($date))->format('d M Y');
    } catch (Throwable $e) {
        return $date;
    }
}

function limit_recent_anc(PDO $pdo, int $motherId, ?string $nextVisitDate, DateTimeImmutable $now): bool
{
    // true = already sent recently
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM anc_sms_logs
            WHERE mother_id = ?
              AND (? IS NULL OR next_visit_date = ?)
              AND created_at >= (NOW() - INTERVAL 24 HOUR)
        ");
        $stmt->execute([$motherId, $nextVisitDate, $nextVisitDate]);
        return ((int)$stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function limit_recent_immun(PDO $pdo, int $babyId, string $dueDate): bool
{
    // true = already sent recently
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM sms_logs
            WHERE baby_id = ?
              AND due_date = ?
              AND created_at >= (NOW() - INTERVAL 24 HOUR)
        ");
        $stmt->execute([$babyId, $dueDate]);
        return ((int)$stmt->fetchColumn()) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

$send = has_flag($argv, '--send');
$forceHours = has_flag($argv, '--force-hours');
$dateArg = arg_value($argv, '--date');
$clinicIdArg = arg_value($argv, '--clinic-id');
$maxArg = arg_value($argv, '--max');

$clinicId = $clinicIdArg !== null ? (int)$clinicIdArg : 0;
$max = $maxArg !== null ? max(1, (int)$maxArg) : 200;

$today = $dateArg && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateArg)
    ? new DateTimeImmutable($dateArg . ' 00:00:00')
    : new DateTimeImmutable('today');
$now = new DateTimeImmutable();

if (!$forceHours && !within_hours($now)) {
    echo "Outside send window (08:00–18:00). Use --force-hours to run anyway.\n";
    exit(0);
}

$hasAncLogs = table_exists($pdo, 'anc_sms_logs');
$hasImmunLogs = table_exists($pdo, 'sms_logs');

if (!$hasAncLogs) {
    echo "WARN: anc_sms_logs table not found. Run sql/anc_sms_logs.sql to enable tracking.\n";
}
if (!$hasImmunLogs) {
    echo "WARN: sms_logs table not found. Run sql/sms_logs.sql to enable tracking.\n";
}

$clinicFilter = '';
$clinicJoin = '';
$clinicParams = [];
if ($clinicId > 0) {
    // Mothers and babies are scoped via users.clinic_id (mother's user)
    $clinicJoin = " LEFT JOIN users u ON m.user_id = u.id LEFT JOIN clinics c ON u.clinic_id = c.id ";
    $clinicFilter = " AND u.clinic_id = ? ";
    $clinicParams[] = $clinicId;
} else {
    $clinicJoin = " LEFT JOIN users u ON m.user_id = u.id LEFT JOIN clinics c ON u.clinic_id = c.id ";
}

echo "Mode: " . ($send ? "SEND" : "DRY-RUN") . "\n";
echo "Run date: " . $today->format('Y-m-d') . "\n";
if ($clinicId > 0) echo "Clinic filter: {$clinicId}\n";

// -----------------------
// ANC reminders
// -----------------------
$ancSent = 0;
$ancOffsets = [3, 1, 0];
foreach ($ancOffsets as $d) {
    if ($ancSent >= $max) break;
    $target = $today->modify(($d >= 0 ? '+' : '') . $d . ' days')->format('Y-m-d');
    $stmt = $pdo->prepare("
        SELECT
            m.id AS mother_id,
            m.fullname,
            m.hospital_number,
            m.phone,
            MIN(a.next_visit_date) AS next_visit_date,
            COALESCE(c.clinic_name, '') AS clinic_name
        FROM anc_visits a
        JOIN mothers m ON a.mother_id = m.id
        {$clinicJoin}
        WHERE a.status = 'PENDING'
          AND a.next_visit_date = ?
          {$clinicFilter}
          AND m.phone IS NOT NULL AND m.phone <> ''
        GROUP BY m.id, m.fullname, m.hospital_number, m.phone, c.clinic_name
        ORDER BY m.id ASC
        LIMIT {$max}
    ");
    $stmt->execute(array_merge([$target], $clinicParams));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        if ($ancSent >= $max) break;
        $motherId = (int)$r['mother_id'];
        $phone = trim((string)$r['phone']);
        if ($phone === '') continue;

        $nextDate = (string)($r['next_visit_date'] ?? $target);
        $already = ($hasAncLogs && limit_recent_anc($pdo, $motherId, $nextDate, $now));
        if ($already) continue;

        $name = (string)($r['fullname'] ?? 'Mother');
        $hn = trim((string)($r['hospital_number'] ?? ''));
        $clinicName = trim((string)($r['clinic_name'] ?? ''));
        $clinicPart = $clinicName !== '' ? " at {$clinicName}" : '';

        $when = $d === 0 ? 'today' : ($d === 1 ? 'tomorrow' : "in {$d} days");
        $msg = "Mhoro Mai {$name}" . ($hn !== '' ? " (Hospital #: {$hn})" : "") . ", reminder: your ANC visit is due {$when} (" . safe_date_label($nextDate) . "){$clinicPart}. Please come to the clinic.";

        if ($send) {
            $ok = sendSms($phone, $msg);
            if ($hasAncLogs) {
                try {
                    $ins = $pdo->prepare("
                        INSERT INTO anc_sms_logs (mother_id, next_visit_date, status, message, created_by_user_id, created_at)
                        VALUES (?, ?, ?, ?, NULL, NOW())
                    ");
                    $ins->execute([$motherId, $nextDate !== '' ? $nextDate : null, $ok ? 'SENT' : 'FAILED', $msg]);
                } catch (Throwable $e) {
                }
            }
        } else {
            echo "[ANC {$when}] {$phone} :: {$msg}\n";
        }

        $ancSent++;
    }
}

// ANC overdue escalation (7 and 14 days)
if ($ancSent < $max) {
    $stmt = $pdo->prepare("
        SELECT
            m.id AS mother_id,
            m.fullname,
            m.hospital_number,
            m.phone,
            MIN(a.next_visit_date) AS next_visit_date,
            DATEDIFF(?, MIN(a.next_visit_date)) AS days_overdue,
            COALESCE(c.clinic_name, '') AS clinic_name
        FROM anc_visits a
        JOIN mothers m ON a.mother_id = m.id
        {$clinicJoin}
        WHERE a.status = 'PENDING'
          AND a.next_visit_date < ?
          {$clinicFilter}
          AND m.phone IS NOT NULL AND m.phone <> ''
        GROUP BY m.id, m.fullname, m.hospital_number, m.phone, c.clinic_name
        HAVING days_overdue IN (7, 14)
        ORDER BY days_overdue DESC, next_visit_date ASC
        LIMIT {$max}
    ");
    $todayStr = $today->format('Y-m-d');
    $stmt->execute(array_merge([$todayStr, $todayStr], $clinicParams));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        if ($ancSent >= $max) break;
        $motherId = (int)$r['mother_id'];
        $phone = trim((string)$r['phone']);
        if ($phone === '') continue;
        $nextDate = (string)($r['next_visit_date'] ?? '');
        $already = ($hasAncLogs && limit_recent_anc($pdo, $motherId, $nextDate, $now));
        if ($already) continue;

        $name = (string)($r['fullname'] ?? 'Mother');
        $hn = trim((string)($r['hospital_number'] ?? ''));
        $clinicName = trim((string)($r['clinic_name'] ?? ''));
        $clinicPart = $clinicName !== '' ? " at {$clinicName}" : '';
        $days = (int)($r['days_overdue'] ?? 0);

        $msg = "Mhoro Mai {$name}" . ($hn !== '' ? " (Hospital #: {$hn})" : "") . ", you missed your ANC visit scheduled for " . safe_date_label($nextDate) . " and it is now {$days} days overdue{$clinicPart}. Please visit the clinic as soon as possible.";

        if ($send) {
            $ok = sendSms($phone, $msg);
            if ($hasAncLogs) {
                try {
                    $ins = $pdo->prepare("
                        INSERT INTO anc_sms_logs (mother_id, next_visit_date, status, message, created_by_user_id, created_at)
                        VALUES (?, ?, ?, ?, NULL, NOW())
                    ");
                    $ins->execute([$motherId, $nextDate !== '' ? $nextDate : null, $ok ? 'SENT' : 'FAILED', $msg]);
                } catch (Throwable $e) {
                }
            }
        } else {
            echo "[ANC OVERDUE {$days}] {$phone} :: {$msg}\n";
        }

        $ancSent++;
    }
}

// -----------------------
// Immunization reminders
// -----------------------
$immSent = 0;
$immOffsets = [3, 1, 0];
foreach ($immOffsets as $d) {
    if ($immSent >= $max) break;
    $target = $today->modify(($d >= 0 ? '+' : '') . $d . ' days')->format('Y-m-d');
    $stmt = $pdo->prepare("
        SELECT
            b.id AS baby_id,
            b.baby_name,
            m.fullname AS mother_name,
            m.hospital_number,
            m.phone AS mother_phone,
            COALESCE(c.clinic_name, '') AS clinic_name,
            i.due_date,
            GROUP_CONCAT(i.vaccine ORDER BY i.vaccine SEPARATOR ', ') AS vaccines,
            COUNT(*) AS vaccine_count
        FROM immunizations i
        JOIN babies b ON i.baby_id = b.id
        JOIN mothers m ON b.mother_id = m.id
        {$clinicJoin}
        WHERE i.status = 'PENDING'
          AND i.due_date = ?
          {$clinicFilter}
          AND m.phone IS NOT NULL AND m.phone <> ''
        GROUP BY b.id, b.baby_name, m.fullname, m.hospital_number, m.phone, c.clinic_name, i.due_date
        ORDER BY b.id ASC
        LIMIT {$max}
    ");
    $stmt->execute(array_merge([$target], $clinicParams));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        if ($immSent >= $max) break;
        $babyId = (int)$r['baby_id'];
        $phone = trim((string)$r['mother_phone']);
        if ($phone === '') continue;
        $dueDate = (string)($r['due_date'] ?? $target);

        $already = ($hasImmunLogs && limit_recent_immun($pdo, $babyId, $dueDate));
        if ($already) continue;

        $babyName = (string)($r['baby_name'] ?? 'Baby');
        $motherName = (string)($r['mother_name'] ?? 'Mother');
        $hn = trim((string)($r['hospital_number'] ?? ''));
        $clinicName = trim((string)($r['clinic_name'] ?? ''));
        $clinicPart = $clinicName !== '' ? " at {$clinicName}" : '';
        $vaccines = trim((string)($r['vaccines'] ?? ''));
        $when = $d === 0 ? 'today' : ($d === 1 ? 'tomorrow' : "in {$d} days");

        $msg = "Mhoro Mai {$motherName}" . ($hn !== '' ? " (Hospital #: {$hn})" : "") . ", reminder: {$babyName} needs immunization(s) {$when} (" . safe_date_label($dueDate) . "){$clinicPart}. Vaccine(s): {$vaccines}.";

        if ($send) {
            $ok = sendSms($phone, $msg);
            if ($hasImmunLogs) {
                try {
                    $ins = $pdo->prepare("
                        INSERT INTO sms_logs (baby_id, vaccine, due_date, status, message, created_at)
                        VALUES (?, ?, ?, ?, ?, NOW())
                    ");
                    $ins->execute([$babyId, $vaccines !== '' ? $vaccines : 'Immunizations', $dueDate, $ok ? 'SENT' : 'FAILED', $msg]);
                } catch (Throwable $e) {
                }
            }
        } else {
            echo "[IMM {$when}] {$phone} :: {$msg}\n";
        }

        $immSent++;
    }
}

// Immunization overdue escalation (7 and 14 days based on most-overdue vaccine)
if ($immSent < $max) {
    $stmt = $pdo->prepare("
        SELECT
            b.id AS baby_id,
            b.baby_name,
            m.fullname AS mother_name,
            m.hospital_number,
            m.phone AS mother_phone,
            COALESCE(c.clinic_name, '') AS clinic_name,
            MIN(i.due_date) AS oldest_due,
            MAX(DATEDIFF(?, i.due_date)) AS max_overdue,
            GROUP_CONCAT(CONCAT(i.vaccine, ' (', DATE_FORMAT(i.due_date, '%d %b'), ')') ORDER BY i.due_date ASC SEPARATOR ', ') AS pending_vaccines
        FROM immunizations i
        JOIN babies b ON i.baby_id = b.id
        JOIN mothers m ON b.mother_id = m.id
        {$clinicJoin}
        WHERE i.status = 'PENDING'
          AND i.due_date < ?
          {$clinicFilter}
          AND m.phone IS NOT NULL AND m.phone <> ''
        GROUP BY b.id, b.baby_name, m.fullname, m.hospital_number, m.phone, c.clinic_name
        HAVING max_overdue IN (7, 14)
        ORDER BY max_overdue DESC, oldest_due ASC
        LIMIT {$max}
    ");
    $todayStr = $today->format('Y-m-d');
    $stmt->execute(array_merge([$todayStr, $todayStr], $clinicParams));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        if ($immSent >= $max) break;
        $babyId = (int)$r['baby_id'];
        $phone = trim((string)$r['mother_phone']);
        if ($phone === '') continue;

        $dueDate = (string)($r['oldest_due'] ?? '');
        $already = ($hasImmunLogs && $dueDate !== '' && limit_recent_immun($pdo, $babyId, $dueDate));
        if ($already) continue;

        $babyName = (string)($r['baby_name'] ?? 'Baby');
        $motherName = (string)($r['mother_name'] ?? 'Mother');
        $hn = trim((string)($r['hospital_number'] ?? ''));
        $clinicName = trim((string)($r['clinic_name'] ?? ''));
        $clinicPart = $clinicName !== '' ? " at {$clinicName}" : '';
        $days = (int)($r['max_overdue'] ?? 0);
        $pending = trim((string)($r['pending_vaccines'] ?? ''));

        $msg = "Mhoro Mai {$motherName}" . ($hn !== '' ? " (Hospital #: {$hn})" : "") . ", {$babyName} has overdue immunization(s) (now {$days} days overdue){$clinicPart}. Please visit the clinic. Pending: {$pending}.";

        if ($send) {
            $ok = sendSms($phone, $msg);
            if ($hasImmunLogs) {
                try {
                    $ins = $pdo->prepare("
                        INSERT INTO sms_logs (baby_id, vaccine, due_date, status, message, created_at)
                        VALUES (?, ?, ?, ?, ?, NOW())
                    ");
                    $ins->execute([$babyId, $pending !== '' ? $pending : 'Overdue immunizations', $dueDate !== '' ? $dueDate : $todayStr, $ok ? 'SENT' : 'FAILED', $msg]);
                } catch (Throwable $e) {
                }
            }
        } else {
            echo "[IMM OVERDUE {$days}] {$phone} :: {$msg}\n";
        }

        $immSent++;
    }
}

echo "Done. ANC reminders: {$ancSent}, Immun reminders: {$immSent}\n";

