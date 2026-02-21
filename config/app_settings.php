<?php
declare(strict_types=1);

/**
 * Minimal key/value settings stored in the DB.
 *
 * Table: app_settings
 *  - key_name (PK)
 *  - value_text
 *  - updated_at
 */

function mch_settings_ensure_table(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS app_settings (
            key_name VARCHAR(190) PRIMARY KEY,
            value_text TEXT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function mch_settings_get(PDO $pdo, string $key, ?string $default = null): ?string
{
    mch_settings_ensure_table($pdo);

    $stmt = $pdo->prepare("SELECT value_text FROM app_settings WHERE key_name = ? LIMIT 1");
    $stmt->execute([$key]);
    $v = $stmt->fetchColumn();
    if ($v === false || $v === null) {
        return $default;
    }
    $v = is_string($v) ? trim($v) : '';
    return $v !== '' ? $v : $default;
}

function mch_settings_set(PDO $pdo, string $key, ?string $value): void
{
    mch_settings_ensure_table($pdo);

    $val = $value;
    if ($val !== null) {
        $val = trim((string)$val);
    }

    if ($val === null || $val === '') {
        mch_settings_delete($pdo, $key);
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO app_settings (key_name, value_text, updated_at)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE value_text = VALUES(value_text), updated_at = NOW()
    ");
    $stmt->execute([$key, $val]);
}

function mch_settings_delete(PDO $pdo, string $key): void
{
    mch_settings_ensure_table($pdo);
    $stmt = $pdo->prepare("DELETE FROM app_settings WHERE key_name = ? LIMIT 1");
    $stmt->execute([$key]);
}

