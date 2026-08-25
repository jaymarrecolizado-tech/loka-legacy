<?php
/**
 * MIGRATION 035: Email delivery mode + HTTP cron secret
 */

$envFile = __DIR__ . '/../.env';
$dbHost = 'localhost';
$dbName = 'fleetdb';
$dbUser = 'root';
$dbPass = '';
$dbCharset = 'utf8mb4';

if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }
        $name = trim($parts[0]);
        $value = trim($parts[1], " \t\"'");
        if ($name === 'DB_HOST') {
            $dbHost = $value;
        } elseif ($name === 'DB_NAME' || $name === 'DB_DATABASE') {
            $dbName = $value;
        } elseif ($name === 'DB_USER' || $name === 'DB_USERNAME') {
            $dbUser = $value;
        } elseif ($name === 'DB_PASSWORD') {
            $dbPass = $value;
        } elseif ($name === 'DB_CHARSET') {
            $dbCharset = $value;
        }
    }
}

echo "MIGRATION 035: email delivery mode...\n";

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=%s', $dbHost, $dbName, $dbCharset),
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $now = date('Y-m-d H:i:s');
    $cronSecret = bin2hex(random_bytes(16));

    $defaults = [
        ['email_delivery_mode', 'immediate', 'string', 'email'],
        ['cron_secret', $cronSecret, 'string', 'email'],
    ];

    $stmt = $pdo->prepare(
        "INSERT INTO settings (`key`, value, type, category, created_at, updated_at)
         SELECT ?, ?, ?, ?, ?, ?
         FROM DUAL
         WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = ?)"
    );

    foreach ($defaults as [$key, $value, $type, $category]) {
        $stmt->execute([$key, $value, $type, $category, $now, $now, $key]);
        echo "OK setting {$key}\n";
    }

    echo "MIGRATION 035 complete.\n";
} catch (Exception $e) {
    echo 'FAILED: ' . $e->getMessage() . "\n";
    exit(1);
}





