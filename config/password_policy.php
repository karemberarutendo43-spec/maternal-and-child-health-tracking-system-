<?php
declare(strict_types=1);

function mch_password_min_length_for_role(string $role): int
{
    return $role === 'mother' ? 12 : 8;
}

function mch_password_blocklist_normalized(): array
{
    static $list = null;
    if (is_array($list)) {
        return $list;
    }

    $raw = [
        'password',
        'passw0rd',
        'qwerty',
        'qwertyuiop',
        'letmein',
        'admin',
        'administrator',
        'welcome',
        'iloveyou',
        'sunshine',
        'monkey',
        'dragon',
        'football',
        'baseball',
        'login',
        'mother',
        'baby',
        'mch',
        'mchts',

        '123456',
        '1234567',
        '12345678',
        '123456789',
        '1234567890',
        '12345678901',
        '123456789012',
        '111111',
        '11111111',
        '000000',
        '00000000',
        '987654321',
    ];

    $norm = [];
    foreach ($raw as $p) {
        $p = strtolower(trim($p));
        if ($p === '') continue;
        $norm[$p] = true;
    }

    $list = $norm;
    return $list;
}

function mch_password_validate(string $password, int $minLength): ?string
{
    if ($password === '') {
        return "Password is required.";
    }

    // Allow spaces, but avoid accidental leading/trailing whitespace.
    if (trim($password) !== $password) {
        return "Password must not start or end with spaces.";
    }

    if (strlen($password) < $minLength) {
        return "Password must be at least {$minLength} characters.";
    }

    $normalized = strtolower(preg_replace('/\\s+/', '', $password) ?? '');
    if ($normalized !== '' && isset(mch_password_blocklist_normalized()[$normalized])) {
        return "That password is too common. Please choose a longer passphrase (for example, 3–4 words).";
    }

    // Extra tiny guard: block repeated single-character passwords (e.g., 'aaaaaaaaaaaa').
    if (preg_match('/^(.)\\1{9,}$/', $normalized)) {
        return "That password is too easy to guess. Please choose a longer passphrase (for example, 3–4 words).";
    }

    return null;
}

