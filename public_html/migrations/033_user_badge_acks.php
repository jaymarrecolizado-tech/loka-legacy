<?php
/**
 * MIGRATION 033: Per-user badge acknowledgements (clear counters after view)
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

echo "MIGRATION 033: user_badge_acks...\n";

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=%s', $dbHost, $dbName, $dbCharset),
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_badge_acks (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            badge_key VARCHAR(50) NOT NULL,
            seen_ids_json TEXT NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uq_user_badge (user_id, badge_key),
            KEY idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    echo "OK user_badge_acks\n";
    echo "MIGRATION 033 complete.\n";
} catch (Exception $e) {
    echo 'FAILED: ' . $e->getMessage() . "\n";
    exit(1);
}





