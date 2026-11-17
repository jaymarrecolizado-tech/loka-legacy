<?php
/**
 * MIGRATION 061: consumed AI confirm-token nonces (Plan #40 hardening)
 *
 * Confirm tokens are HMAC-signed, user-bound, TTL 120s. This table makes them
 * single-use: a successfully verified token's nonce is recorded and replay is
 * rejected. Run: php migrations/061_ai_confirm_nonces.php
 */
require __DIR__ . '/_load_env.php';
$dbHost = 'localhost';
$dbName = 'old_loka_db';
$dbUser = 'root';
$dbPass = '';
$dbCharset = 'utf8mb4';

if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) continue;
        $name = trim($parts[0]);
        $value = trim($parts[1], " \t\"'");
        if ($name === 'DB_HOST') $dbHost = $value;
        elseif ($name === 'DB_DATABASE' || $name === 'DB_NAME') $dbName = $value;
        elseif ($name === 'DB_USERNAME' || $name === 'DB_USER') $dbUser = $value;
        elseif ($name === 'DB_PASSWORD') $dbPass = $value;
        elseif ($name === 'DB_CHARSET') $dbCharset = $value;
    }
}

try {
    $pdo = new PDO("mysql:host={$dbHost};dbname={$dbName};charset={$dbCharset}", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS ai_confirm_nonces (
            nonce VARCHAR(24) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (nonce),
            INDEX idx_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    echo "MIGRATION 061: ai_confirm_nonces ready.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Migration 061 failed: " . $e->getMessage() . "\n");
    exit(1);
}
