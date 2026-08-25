<?php
/**
 * MIGRATION 039: vehicles.odometer_broken + seed known broken plates
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
        } elseif ($name === 'DB_PASSWORD' || $name === 'DB_PASS') {
            $dbPass = $value;
        } elseif ($name === 'DB_CHARSET') {
            $dbCharset = $value;
        }
    }
}

echo "MIGRATION 039: vehicle odometer_broken...\n";

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=%s', $dbHost, $dbName, $dbCharset),
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $cols = $pdo->query("SHOW COLUMNS FROM vehicles LIKE 'odometer_broken'")->fetch();
    if (!$cols) {
        $pdo->exec(
            "ALTER TABLE vehicles
             ADD COLUMN odometer_broken TINYINT(1) NOT NULL DEFAULT 0
             COMMENT '1 = odometer broken/unreadable; skip required readings'
             AFTER mileage"
        );
        echo "OK added vehicles.odometer_broken\n";
    } else {
        echo "SKIP column already exists\n";
    }

    $plates = ['SDF 424', 'SBY 225', 'SJN 940', 'SDF424', 'SBY225', 'SJN940'];
    $stmt = $pdo->prepare(
        "UPDATE vehicles SET odometer_broken = 1, updated_at = NOW()
         WHERE deleted_at IS NULL
           AND REPLACE(UPPER(plate_number), ' ', '') = REPLACE(UPPER(?), ' ', '')"
    );
    foreach ($plates as $p) {
        $stmt->execute([$p]);
        if ($stmt->rowCount() > 0) {
            echo "OK marked broken: {$p}\n";
        }
    }

    $now = date('Y-m-d H:i:s');
    try {
        $setting = $pdo->prepare(
            "INSERT INTO settings (`key`, value, type, category, created_at, updated_at)
             SELECT 'odometer_broken_plates', 'SDF424,SBY225,SJN940', 'string', 'fleet', ?, ?
             FROM DUAL
             WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = 'odometer_broken_plates')"
        );
        $setting->execute([$now, $now]);
        echo "OK setting odometer_broken_plates\n";
    } catch (Throwable $e) {
        echo "SKIP settings seed: " . $e->getMessage() . "\n";
    }

    echo "MIGRATION 039 complete.\n";
} catch (Exception $e) {
    echo 'FAILED: ' . $e->getMessage() . "\n";
    exit(1);
}




