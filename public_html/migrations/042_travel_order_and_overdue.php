<?php
/**
 * MIGRATION 042: Travel Order / OB Slip enforcement + overdue-trip tracking
 *
 * Adds:
 *  - requests.travel_order_file / travel_order_original_name / travel_order_uploaded_at
 *  - requests.overdue_notified_at
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

echo "MIGRATION 042: Travel Order / OB Slip enforcement + overdue-trip tracking...\n";

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=%s', $dbHost, $dbName, $dbCharset),
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Helper: add a column only when it does not exist yet (idempotent)
    $addColumn = function (string $table, string $column, string $definition) use ($pdo) {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
        );
        $stmt->execute([$table, $column]);
        if ((int) $stmt->fetchColumn() > 0) {
            echo "SKIP {$table}.{$column} (already exists)\n";
            return;
        }
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        echo "OK {$table}.{$column}\n";
    };

    // --- Travel Order / OB Slip columns ---
    $addColumn('requests', 'travel_order_file', "VARCHAR(500) NULL AFTER `notes`");
    $addColumn('requests', 'travel_order_original_name', "VARCHAR(255) NULL AFTER `travel_order_file`");
    $addColumn('requests', 'travel_order_uploaded_at', "DATETIME NULL AFTER `travel_order_original_name`");

    // --- Overdue trip tracking ---
    $addColumn('requests', 'overdue_notified_at', "DATETIME NULL AFTER `travel_order_uploaded_at`");

    // --- Settings defaults (idempotent) ---
    $now = date('Y-m-d H:i:s');
    $defaults = [
        // Admin / All Father toggle: enforce Travel Order / OB Slip upload on submission
        ['require_travel_order_upload', '0', 'bool', 'booking'],
        // Overdue trip re-reminder cadence (hours) while a trip remains overdue
        ['trip_overdue_renotify_hours', '24', 'int', 'trips'],
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

    echo "MIGRATION 042 complete.\n";
} catch (Exception $e) {
    echo 'FAILED: ' . $e->getMessage() . "\n";
    exit(1);
}
