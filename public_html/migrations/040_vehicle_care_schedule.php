<?php
/**
 * MIGRATION 040: Vehicle care assignments + care schedule (additive)
 * Does not alter maintenance_requests.
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

echo "MIGRATION 040: Vehicle care schedule...\n";

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=%s', $dbHost, $dbName, $dbCharset),
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS vehicle_care_assignments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            vehicle_id INT UNSIGNED NOT NULL,
            driver_id INT UNSIGNED NOT NULL,
            assigned_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            deleted_at DATETIME NULL,
            UNIQUE KEY uq_care_vehicle_driver (vehicle_id, driver_id),
            INDEX idx_care_assign_vehicle (vehicle_id),
            INDEX idx_care_assign_driver (driver_id),
            INDEX idx_care_assign_deleted (deleted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "OK vehicle_care_assignments\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS vehicle_care_schedules (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            vehicle_id INT UNSIGNED NOT NULL,
            care_type VARCHAR(32) NOT NULL,
            title VARCHAR(255) NOT NULL,
            notes TEXT NULL,
            due_date DATE NOT NULL,
            status ENUM('pending','scheduled','completed','cancelled') NOT NULL DEFAULT 'pending',
            proposed_by INT UNSIGNED NULL,
            approved_by INT UNSIGNED NULL,
            approved_at DATETIME NULL,
            completed_at DATETIME NULL,
            completed_by INT UNSIGNED NULL,
            completed_mileage INT UNSIGNED NULL,
            interval_days INT UNSIGNED NULL,
            interval_km INT UNSIGNED NULL,
            reminded_7d_at DATETIME NULL,
            reminded_1d_at DATETIME NULL,
            reminded_due_at DATETIME NULL,
            reminded_overdue_on DATE NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            deleted_at DATETIME NULL,
            INDEX idx_care_sched_vehicle (vehicle_id),
            INDEX idx_care_sched_due (due_date),
            INDEX idx_care_sched_status (status),
            INDEX idx_care_sched_deleted (deleted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "OK vehicle_care_schedules\n";
    echo "DONE\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}





