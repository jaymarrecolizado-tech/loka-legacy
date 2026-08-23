<?php
/**
 * LOKA - Fix Trip Tickets Schema Migration
 *
 * Adds trip_type_other column and updates trip_type ENUM to include 'travel_order'
 */

$envFile = __DIR__ . '/../.env';
$dbHost = 'localhost';
$dbName = 'loka_fleet';
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
            $value = trim($parts[1]);
            switch ($name) {
                case 'DB_HOST':
                    $dbHost = $value;
                    break;
                case 'DB_NAME':
                    $dbName = $value;
                    break;
                case 'DB_USER':
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

echo "=== Migration: Fix Trip Tickets Schema ===\n\n";

try {
    $pdo = new PDO(
        sprintf("mysql:host=%s;dbname=%s;charset=%s", $dbHost, $dbName, $dbCharset),
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    echo "Connected to database: " . $dbName . "\n\n";

    // Modify trip_type ENUM to add 'travel_order'
    $sql = "ALTER TABLE trip_tickets
    MODIFY COLUMN trip_type ENUM('official', 'personal', 'maintenance', 'travel_order', 'other') NOT NULL DEFAULT 'official'";

    $pdo->exec($sql);
    echo "✓ Updated trip_type ENUM to include 'travel_order'\n";

    // Add trip_type_other column if it doesn't exist
    try {
        $sql = "ALTER TABLE trip_tickets
        ADD COLUMN trip_type_other VARCHAR(100) DEFAULT NULL
        COMMENT 'Description when trip_type is other'
        AFTER trip_type";

        $pdo->exec($sql);
        echo "✓ Added trip_type_other column\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "⚠ Column trip_type_other already exists\n";
        } else {
            throw $e;
        }
    }

    echo "\n" . str_repeat('=', 50) . "\n";
    echo "MIGRATION COMPLETE\n";
    echo str_repeat('=', 50) . "\n\n";

} catch (PDOException $e) {
    echo "\n✗ ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
