<?php
/**
 * MIGRATION 060: Driver-phone GPS trip tracking (Plan #41, experimental)
 *
 * Experimental, ships DISABLED (`gps_tracking_enabled = '0'`).
 *
 * - trip_gps_points — one breadcrumb per ping for a dispatched DICT fleet trip.
 *   Written only by the assigned driver of that trip, only while the request is
 *   approved AND dispatched (the window opens on guard Dispatch and closes on
 *   guard Arrival / completion / cancellation).
 * - Retained ~30 days, then purged by cron/process_gps_retention.php.
 *
 * No map tiles are fetched by this app (see the ops panel), so coordinates never
 * leave the server.
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

echo "=== MIGRATION 060: GPS trip tracking ===\n\n";

try {
    $pdo = new PDO(
        sprintf("mysql:host=%s;dbname=%s;charset=%s", $dbHost, $dbName, $dbCharset),
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS trip_gps_points (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            request_id INT(10) UNSIGNED NOT NULL,
            driver_user_id INT(10) UNSIGNED NOT NULL,
            lat DECIMAL(10,7) NOT NULL,
            lng DECIMAL(10,7) NOT NULL,
            accuracy_m DECIMAL(10,2) NULL DEFAULT NULL COMMENT 'Horizontal accuracy in metres',
            speed_mps DECIMAL(8,2) NULL DEFAULT NULL,
            heading_deg DECIMAL(6,2) NULL DEFAULT NULL,
            recorded_at DATETIME NOT NULL COMMENT 'Device clock when the fix was taken',
            received_at DATETIME NOT NULL COMMENT 'When the server accepted the ping',
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_gps_request (request_id, recorded_at),
            KEY idx_gps_driver (driver_user_id, recorded_at),
            KEY idx_gps_received (received_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        COMMENT='Driver-phone GPS breadcrumb per dispatched trip (Plan #41)'"
    );
    echo "OK trip_gps_points\n";

    $ins = $pdo->prepare(
        "INSERT INTO settings (`key`, value, description, type, category, created_at, updated_at)
         VALUES ('gps_tracking_enabled', '0',
                 'Driver-phone GPS trip tracking (experimental, Plan #41) — OFF by default',
                 'boolean', 'experimental', NOW(), NOW())
         ON DUPLICATE KEY UPDATE description = VALUES(description)"
    );
    $ins->execute();
    echo "OK settings.gps_tracking_enabled = 0 (OFF)\n";

    echo "\nMIGRATION 060 complete.\n";
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
} catch (RuntimeException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}