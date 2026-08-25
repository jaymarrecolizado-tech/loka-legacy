<?php
/**
 * MIGRATION 037: Fix password_reset_tokens table schema
 *
 * The original table had remember_tokens-style columns (selector, hashed_token, expires).
 * Auth.php expects: token (SHA-256 hash), expires_at, used, used_at, created_at.
 * This migration drops and recreates the table with the correct schema.
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
        if (strpos(trim($line), '#') === 0) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $name = trim($parts[0]);
            $value = trim($parts[1], " \t\n\r\0\x0B\"'");
            switch ($name) {
                case 'DB_HOST': $dbHost = $value; break;
                case 'DB_NAME': case 'DB_DATABASE': $dbName = $value; break;
                case 'DB_USER': case 'DB_USERNAME': $dbUser = $value; break;
                case 'DB_PASSWORD': $dbPass = $value; break;
                case 'DB_CHARSET': $dbCharset = $value; break;
            }
        }
    }
}

echo "=== MIGRATION 037: Fix password_reset_tokens Schema ===\n\n";

try {
    try {
        $pdo = new PDO("mysql:host=127.0.0.1;port=3306;dbname={$dbName};charset={$dbCharset}", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (PDOException $e) {
        $pdo = new PDO("mysql:host={$dbHost};dbname={$dbName};charset={$dbCharset}", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
    echo "âœ“ Connected to database '{$dbName}'\n\n";

    $cols = $pdo->query('DESCRIBE password_reset_tokens')->fetchAll(PDO::FETCH_COLUMN);
    $needsFix = !in_array('token', $cols) || !in_array('used', $cols);

    if ($needsFix) {
        echo "â€¢ Found incorrect schema. Columns: " . implode(', ', $cols) . "\n";
        $pdo->exec('DROP TABLE IF EXISTS password_reset_tokens');
        echo "âœ“ Dropped old table\n";
    } else {
        echo "â€¢ Schema already correct. Columns: " . implode(', ', $cols) . "\n";
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT UNSIGNED NOT NULL,
            `token` VARCHAR(255) NOT NULL COMMENT 'SHA-256 hashed token',
            `expires_at` DATETIME NOT NULL,
            `used` TINYINT(1) NOT NULL DEFAULT 0,
            `used_at` DATETIME NULL DEFAULT NULL,
            `created_at` DATETIME NOT NULL,
            INDEX `idx_user_id` (`user_id`),
            INDEX `idx_token` (`token`(64)),
            INDEX `idx_expires_at` (`expires_at`),
            INDEX `idx_used` (`used`),
            FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        COMMENT='Password reset tokens for forgot-password flow'
    ");
    echo "âœ“ Created password_reset_tokens with correct schema\n";

    $finalCols = $pdo->query('DESCRIBE password_reset_tokens')->fetchAll(PDO::FETCH_COLUMN);
    echo "âœ“ Final columns: " . implode(', ', $finalCols) . "\n\n";
    echo str_repeat('=', 50) . "\n";
    echo "MIGRATION 037 COMPLETE\n";
    echo str_repeat('=', 50) . "\n";

} catch (PDOException $e) {
    echo "\nâœ— ERROR: " . $e->getMessage() . "\n";
    exit(1);
}



