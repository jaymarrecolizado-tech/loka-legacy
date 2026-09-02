<?php
/**
 * LOKA - Add Driver Assignment & Signatory columns to trip_tickets
 *
 * Adds assigned_driver_id (reuses existing driver_id), and signatory_motorpool_id,
 * signatory_chief_finance_id columns.
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
                case 'DB_HOST':    $dbHost = $value; break;
                case 'DB_NAME':    $dbName = $value; break;
                case 'DB_USER':    $dbUser = $value; break;
                case 'DB_PASSWORD':$dbPass = $value; break;
                case 'DB_CHARSET': $dbCharset = $value; break;
            }
        }
    }
}

echo "=== Migration: Add signatory columns to trip_tickets ===\n\n";

try {
    $pdo = new PDO(
        sprintf("mysql:host=%s;dbname=%s;charset=%s", $dbHost, $dbName, $dbCharset),
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    echo "Connected to database: " . $dbName . "\n\n";

    $columns = [
        "signatory_motorpool_id INT UNSIGNED DEFAULT NULL COMMENT 'Motorpool Head signatory user ID' AFTER driver_id",
        "signatory_chief_finance_id INT UNSIGNED DEFAULT NULL COMMENT 'Chief Admin & Finance signatory user ID' AFTER signatory_motorpool_id",
    ];

    foreach ($columns as $col) {
        $colName = preg_match('/^([a-zA-Z_]+)/', $col, $m) ? $m[1] : $col;
        try {
            $pdo->exec("ALTER TABLE trip_tickets ADD COLUMN $col");
            echo "✓ Added column $colName\n";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
                echo "⚠ Column $colName already exists\n";
            } else {
                throw $e;
            }
        }
    }

    echo "\n" . str_repeat('=', 50) . "\n";
    echo "MIGRATION COMPLETE\n";
    echo str_repeat('=', 50) . "\n\n";
} catch (PDOException $e) {
    echo "\n✗ ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
