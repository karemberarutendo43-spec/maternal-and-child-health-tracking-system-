<?php
declare(strict_types=1);

function mch_is_https_request(): bool
{
    $xfp = (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
    if (strtolower($xfp) === 'https') {
        return true;
    }
    $https = (string)($_SERVER['HTTPS'] ?? '');
    if ($https !== '' && strtolower($https) !== 'off') {
        return true;
    }
    $port = (string)($_SERVER['SERVER_PORT'] ?? '');
    return $port === '443';
}

function mch_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isHttps = mch_is_https_request();

    $ttl = (int)(getenv('MCH_SESSION_TTL') ?: 28800); // 8 hours
    if ($ttl > 0) {
        ini_set('session.gc_maxlifetime', (string)$ttl);
    }

    // If Redis is configured, use it for session storage (recommended for Cloud Run).
    $redisUrl = (string)(getenv('MCH_REDIS_URL') ?: '');
    $redisHost = (string)(getenv('MCH_REDIS_HOST') ?: '');
    $redisPort = (string)(getenv('MCH_REDIS_PORT') ?: '6379');
    $redisAuth = (string)(getenv('MCH_REDIS_PASSWORD') ?: '');

    $useRedis = trim($redisUrl) !== '' || trim($redisHost) !== '';
    if ($useRedis) {
        ini_set('session.save_handler', 'redis');
        if (trim($redisUrl) !== '') {
            ini_set('session.save_path', $redisUrl);
        } else {
            $path = "tcp://{$redisHost}:{$redisPort}";
            if (trim($redisAuth) !== '') {
                $path .= "?auth=" . rawurlencode($redisAuth);
            }
            ini_set('session.save_path', $path);
        }
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

