<?php
declare(strict_types=1);

function mch_uploads_dir(): string
{
    $base = (string)(getenv('MCH_UPLOADS_DIR') ?: '');
    if (trim($base) === '') {
        $base = __DIR__ . '/../uploads';
    }

    if (!is_dir($base) && !mkdir($base, 0755, true) && !is_dir($base)) {
        throw new RuntimeException('Unable to create uploads directory');
    }
    return rtrim($base, "/\\");
}

function mch_uploads_url_base(): string
{
    $base = (string)(getenv('MCH_UPLOADS_URL_BASE') ?: '');
    if (trim($base) === '') {
        $base = '/mch_system/uploads';
    }
    return rtrim($base, '/');
}

function mch_uploads_abs_path(string $relativePath): string
{
    $relativePath = ltrim($relativePath, "/\\");
    return mch_uploads_dir() . DIRECTORY_SEPARATOR . $relativePath;
}

function mch_uploads_public_url(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    return mch_uploads_url_base() . '/' . $relativePath;
}

