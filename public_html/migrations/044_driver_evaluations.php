<?php
/**
 * MIGRATION 044: Anonymous post-trip driver evaluations (GRAB-like)
 *
 * Adds:
 *  - driver_evaluations table (one invitation per passenger per trip)
 *  - settings defaults (idempotent)
 */

$envFile = __DIR__ . '/../.env';
$dbHost = 'localhost';
$dbName = 'fleetdb';
$dbUser = 'root';
$dbPass = '';
$dbCharset = 'utf8mb4';

if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $name = trim($parts[0]);
            $value = trim($parts[1], " \t\"'");
            switch ($name) {
                case 'DB_HOST':
                    $dbHost = $value;
                    break;
                case 'DB_NAME': case 'DB_DATABASE':
                    $dbName = $value;
                    break;
                case 'DB_USER': case 'DB_USERNAME':
                    $dbUser = $value;
                    break;
                case 'DB_PASSWORD':
                    $dbPass = $value;
                    break;
                case 'DB_CHARSET':
                    $dbCharset = $value;
                    break;
            }
        }
    }
}

echo "MIGRATION 044: Driver evaluations...\n";

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=%s', $dbHost, $dbName, $dbCharset),
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS driver_evaluations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            request_id INT UNSIGNED NOT NULL,
            driver_id INT UNSIGNED NOT NULL,
            evaluator_user_id INT UNSIGNED NULL,
            guest_label VARCHAR(50) NULL,
            token_hash CHAR(64) NOT NULL,
            rating_punctuality TINYINT UNSIGNED NULL,
            rating_safety TINYINT UNSIGNED NULL,
            rating_courtesy TINYINT UNSIGNED NULL,
            rating_driving TINYINT UNSIGNED NULL,
            rating_vehicle TINYINT UNSIGNED NULL,
            overall DECIMAL(3,2) NULL,
            remarks TEXT NULL,
            submitted_at DATETIME NULL,
            reminder_sent_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_token_hash (token_hash),
            UNIQUE KEY uq_request_evaluator (request_id, evaluator_user_id),
            KEY idx_driver (driver_id),
            KEY idx_request (request_id),
            KEY idx_reminder (submitted_at, reminder_sent_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "OK driver_evaluations\n";

    // --- Settings defaults (idempotent) ---
    $now = date('Y-m-d H:i:s');
    $defaults = [
        ['driver_evaluation_reminder_hours', '48', 'int', 'trips'],
        ['driver_evaluation_expiry_days', '30', 'int', 'trips'],
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

    echo "MIGRATION 044 complete.\n";
} catch (Exception $e) {
    echo 'FAILED: ' . $e->getMessage() . "\n";
    exit(1);
}
