<?php
$host = getenv('MCH_DB_HOST') ?: "localhost";
$port = getenv('MCH_DB_PORT') ?: "";
$db   = getenv('MCH_DB_NAME') ?: "mch_system";
$user = getenv('MCH_DB_USER') ?: "root";
$pass = getenv('MCH_DB_PASS') ?: "";

// Optional: connect via Unix socket (recommended for Cloud SQL on Cloud Run)
// Example: MCH_DB_SOCKET=/cloudsql/PROJECT:REGION:INSTANCE
$socket = getenv('MCH_DB_SOCKET') ?: "";

// Field-level encryption key (AES-256-GCM via config/crypto.php).
// Change this value for your deployment and keep it secret.
// Tip: In production, load this from a secure env/secret store instead of committing it.
if (!defined('MCH_CRYPTO_KEY')) {
    $envKey = getenv('MCH_CRYPTO_KEY');
    if ($envKey !== false && trim((string)$envKey) !== '') {
        define('MCH_CRYPTO_KEY', (string)$envKey);
    } else {
        define('MCH_CRYPTO_KEY', 'dCFX5igOvfNb2F8DfEfgKdb22wDLC+LTshd2qrIOy8Q=');
    }
}

try {
    $dsn = "";
    if (is_string($socket) && trim($socket) !== "") {
        $dsn = "mysql:unix_socket={$socket};dbname={$db};charset=utf8mb4";
    } else {
        $dsn = "mysql:host={$host}" . ($port !== "" ? ";port={$port}" : "") . ";dbname={$db};charset=utf8mb4";
    }
    $pdo = new PDO(
        $dsn,
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    die("Database connection failed");
}
