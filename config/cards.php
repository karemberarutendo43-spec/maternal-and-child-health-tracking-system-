<?php

function db_has_column(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = "{$table}.{$column}";
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
            LIMIT 1
        ");
        $stmt->execute([$table, $column]);
        $cache[$key] = (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $cache[$key] = false;
    }

    return $cache[$key];
}

function babies_supports_card_uid(PDO $pdo): bool
{
    return db_has_column($pdo, 'babies', 'card_uid');
}

function normalize_card_uid(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }

    // If the QR contains a URL, extract the card UID from query params.
    if (preg_match('/[?&](?:card_uid|cid)=([^&#]+)/i', $raw, $m)) {
        $raw = urldecode($m[1]);
    }

    // Remove common prefixes.
    $raw = preg_replace('/^MCHTS[:\\s-]*/i', '', $raw);
    $raw = preg_replace('/^CID[:\\s-]*/i', '', $raw);

    // Keep only alphanumeric characters and normalize casing.
    // Symbols like dashes/spaces are allowed in user input but are removed here.
    $raw = preg_replace('/[^a-zA-Z0-9]/', '', $raw);
    $raw = strtoupper($raw);

    // Card UID is 16 chars (letters + numbers).
    if (strlen($raw) > 16) {
        $raw = substr($raw, 0, 16);
    }

    return $raw;
}

function format_card_uid(string $uid): string
{
    $uid = normalize_card_uid($uid);
    if ($uid === '') {
        return '';
    }
    return implode('-', str_split($uid, 4));
}

function generate_card_uid(): string
{
    // 16-char alphanumeric ID (A-Z, 0-9). Keep it QR-alphanumeric safe.
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $out = '';
    for ($i = 0; $i < 16; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
}

function get_app_base_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return "{$scheme}://{$host}/mch_system";
}

function build_baby_card_qr_payload(string $card_uid): string
{
    $card_uid = normalize_card_uid($card_uid);
    if ($card_uid === '') {
        return '';
    }
    $base = get_app_base_url();
    return "{$base}/nurse_portal/baby_history.php?card_uid=" . rawurlencode($card_uid);
}
