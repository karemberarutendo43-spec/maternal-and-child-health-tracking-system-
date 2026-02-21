<?php
/**
 * AES-256-GCM field-level encryption helpers.
 *
 * Storage format: ENCv1:<base64(iv||tag||ciphertext)>
 * - iv: 12 bytes random
 * - tag: 16 bytes auth tag
 *
 * Notes:
 * - Only encrypt fields that do not need LIKE-search unless you add a separate searchable index.
 * - Keep MCH_CRYPTO_KEY secret (do not store in DB). Prefer environment/config management.
 */

function mch_crypto_is_enabled(): bool
{
    return defined('MCH_CRYPTO_KEY') && trim((string)MCH_CRYPTO_KEY) !== '';
}

function mch_crypto_key_bytes(): string
{
    if (!mch_crypto_is_enabled()) {
        throw new RuntimeException("Encryption key is not configured (MCH_CRYPTO_KEY).");
    }

    // Derive a 32-byte key from the configured secret.
    return hash('sha256', (string)MCH_CRYPTO_KEY, true);
}

function mch_crypto_encrypt(?string $plaintext): ?string
{
    if ($plaintext === null) return null;
    if ($plaintext === '') return '';
    if (!mch_crypto_is_enabled()) return $plaintext;

    // Avoid double-encrypting.
    if (strncmp($plaintext, 'ENCv1:', 6) === 0) {
        return $plaintext;
    }

    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt(
        $plaintext,
        'aes-256-gcm',
        mch_crypto_key_bytes(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        '',
        16
    );
    if ($ciphertext === false || $tag === '') {
        throw new RuntimeException("Failed to encrypt field.");
    }

    return 'ENCv1:' . base64_encode($iv . $tag . $ciphertext);
}

function mch_crypto_decrypt(?string $value): ?string
{
    if ($value === null) return null;
    if ($value === '') return '';
    if (!mch_crypto_is_enabled()) return $value;

    if (strncmp($value, 'ENCv1:', 6) !== 0) {
        // Backward compatible: plain text stored before encryption was enabled.
        return $value;
    }

    $b64 = substr($value, 6);
    $raw = base64_decode($b64, true);
    if ($raw === false || strlen($raw) < (12 + 16 + 1)) {
        // If value is malformed, return as-is rather than breaking the UI.
        return $value;
    }

    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ciphertext = substr($raw, 28);

    $plaintext = openssl_decrypt(
        $ciphertext,
        'aes-256-gcm',
        mch_crypto_key_bytes(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    // If decryption fails (wrong key), return the stored value so you notice it.
    return $plaintext === false ? $value : $plaintext;
}
