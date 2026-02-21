<?php
declare(strict_types=1);

function branding_settings_file_path(): string
{
    require_once __DIR__ . '/uploads.php';
    $dir = mch_uploads_abs_path('branding');
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException("Unable to create branding uploads directory");
    }
    return $dir . '/branding_settings.json';
}

function load_branding_settings(): array
{
    static $settings = null;
    if ($settings !== null) {
        return $settings;
    }
    $path = branding_settings_file_path();
    if (!is_file($path)) {
        $settings = [
            'admin' => '',
            'nurse' => '',
            'mother' => '',
        ];
        file_put_contents($path, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $settings;
    }
    $content = file_get_contents($path);
    $decoded = json_decode($content, true);
    if (!is_array($decoded)) {
        $decoded = [
            'admin' => '',
            'nurse' => '',
            'mother' => '',
        ];
    }
    $settings = array_merge([
        'admin' => '',
        'nurse' => '',
        'mother' => '',
    ], $decoded);
    return $settings;
}

function update_branding_setting(string $role, string $url): void
{
    $settings = load_branding_settings();
    $settings[$role] = $url;
    $path = branding_settings_file_path();
    file_put_contents($path, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function get_branding_image_url(string $role): ?string
{
    $settings = load_branding_settings();
    $value = trim((string)($settings[$role] ?? ''));
    if ($value === '') {
        return null;
    }
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
