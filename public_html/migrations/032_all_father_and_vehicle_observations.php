<?php
/**
 * MIGRATION 032: All Father role + vehicle observation tables
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

echo "MIGRATION 032: All Father role + vehicle_observations...\n";

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=%s', $dbHost, $dbName, $dbCharset),
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $pdo->exec("
        ALTER TABLE users
        MODIFY COLUMN role ENUM(
            'requester',
            'guard',
            'approver',
            'motorpool_head',
            'chief_admin_finance',
            'admin',
            'all_father'
        ) NOT NULL DEFAULT 'requester'
    ");
    echo "OK users.role includes all_father\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS vehicle_observations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            request_id INT UNSIGNED NOT NULL,
            vehicle_id INT UNSIGNED NULL,
            phase ENUM('dispatch','arrival') NOT NULL,
            guard_id INT UNSIGNED NOT NULL,
            overall_condition ENUM('good','fair','poor','damaged') NOT NULL,
            flags_json JSON NULL,
            notes VARCHAR(1000) NULL,
            mileage_reading INT UNSIGNED NULL,
            observed_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uq_request_phase (request_id, phase),
            KEY idx_vehicle (vehicle_id),
            KEY idx_guard (guard_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "OK vehicle_observations\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS vehicle_observation_photos (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            observation_id INT UNSIGNED NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            thumb_path VARCHAR(500) NULL,
            full_path VARCHAR(500) NULL,
            file_name VARCHAR(255) NOT NULL,
            mime_type VARCHAR(100) NOT NULL,
            file_size INT UNSIGNED NOT NULL DEFAULT 0,
            width INT UNSIGNED NULL,
            height INT UNSIGNED NULL,
            caption VARCHAR(200) NULL,
            sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            KEY idx_observation (observation_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "OK vehicle_observation_photos\n";
    echo "MIGRATION 032 complete.\n";
    echo "Bootstrap All Father (optional):\n";
    echo "  UPDATE users SET role='all_father' WHERE email='YOUR_EMAIL' AND deleted_at IS NULL LIMIT 1;\n";
} catch (Exception $e) {
    echo 'FAILED: ' . $e->getMessage() . "\n";
    exit(1);
}



