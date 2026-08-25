<?php
/**
 * LOKA - MIGRATION 038: Add gas_station column to gas_vouchers
 *
 * Adds a gas_station field so the voucher letter can be addressed
 * to the correct fuel supplier (Petromar or Queensforth).
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
            $name  = trim($parts[0]);
            $value = trim($parts[1]);
            switch ($name) {
                case 'DB_HOST':     $dbHost    = $value; break;
                case 'DB_NAME': case 'DB_DATABASE':     $dbName    = $value; break;
                case 'DB_USER': case 'DB_USERNAME':     $dbUser    = $value; break;
                case 'DB_PASSWORD': $dbPass    = $value; break;
                case 'DB_CHARSET':  $dbCharset = $value; break;
            }
        }
    }
}

echo "=== MIGRATION 038: Add gas_station to gas_vouchers ===\n\n";

try {
    $pdo = new PDO(
        sprintf("mysql:host=%s;dbname=%s;charset=%s", $dbHost, $dbName, $dbCharset),
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    echo "Connected to database: " . $dbName . "\n\n";

    // Check if column already exists
    $cols = $pdo->query("DESCRIBE gas_vouchers")->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('gas_station', $cols)) {
        echo "âœ“ Column 'gas_station' already exists. Skipping.\n";
    } else {
        $pdo->exec(
            "ALTER TABLE gas_vouchers
             ADD COLUMN gas_station VARCHAR(150) DEFAULT NULL
             COMMENT 'Name of the gas station receiving the voucher'
             AFTER voucher_no"
        );
        echo "âœ“ Added column 'gas_station' to gas_vouchers.\n";
    }

    echo "\n" . str_repeat('=', 50) . "\n";
    echo "MIGRATION 038 COMPLETE\n";
    echo str_repeat('=', 50) . "\n";

} catch (PDOException $e) {
    echo "\nâœ— ERROR: " . $e->getMessage() . "\n";
    exit(1);
}



