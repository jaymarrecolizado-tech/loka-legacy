<?php
/**
 * MIGRATION 046: Driver evaluation — 4-category rubric
 *
 * Adds 4 new TINYINT rating columns (cleanliness/behavior/appearance/safety)
 * + optional details_json for future per-sub scores. Keeps old 5 columns for
 * historical reports. Overall for new rows = AVG(4).
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
            $value = trim($parts[1], " \t\"'");
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

echo "MIGRATION 046: Driver evaluation 4-category rubric...\n";

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=%s', $dbHost, $dbName, $dbCharset),
        $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Add 4 new rating columns + details_json if not already present
    $cols = $pdo->query("SHOW COLUMNS FROM driver_evaluations")->fetchAll(PDO::FETCH_COLUMN, 0);
    $adds = [];
    if (!in_array('rating_cleanliness', $cols, true)) $adds[] = "ADD COLUMN rating_cleanliness TINYINT UNSIGNED NULL AFTER rating_vehicle";
    if (!in_array('rating_behavior', $cols, true))    $adds[] = "ADD COLUMN rating_behavior TINYINT UNSIGNED NULL AFTER rating_cleanliness";
    if (!in_array('rating_appearance', $cols, true))  $adds[] = "ADD COLUMN rating_appearance TINYINT UNSIGNED NULL AFTER rating_behavior";
    if (!in_array('rating_safety', $cols, true))      $adds[] = "ADD COLUMN rating_safety TINYINT UNSIGNED NULL AFTER rating_appearance";
    if (!in_array('details_json', $cols, true))       $adds[] = "ADD COLUMN details_json JSON NULL AFTER remarks";

    if ($adds) {
        $sql = "ALTER TABLE driver_evaluations " . implode(", ", $adds);
        $pdo->exec($sql);
        echo "OK added columns: " . implode(", ", array_map(fn($s)=>trim(explode(' ', $s)[2]), $adds)) . "\n";
    } else {
        echo "OK columns already exist\n";
    }

    echo "MIGRATION 046 complete.\n";
} catch (Exception $e) {
    echo 'FAILED: ' . $e->getMessage() . "\n";
    exit(1);
}
