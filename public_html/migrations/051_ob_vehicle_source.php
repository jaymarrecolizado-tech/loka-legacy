<?php
/**
 * MIGRATION 051: OB private vs official vehicle (Plan #24)
 *
 * ob_requests.uses_official_vehicle TINYINT(1) NOT NULL DEFAULT 1 —
 * existing slips are treated as official so Motorpool routing is unchanged.
 * Private slips keep plate_number / motorpool_head_id NULL and skip the
 * Motorpool step entirely.
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

echo "=== MIGRATION 051: OB private vs official vehicle ===\n\n";

try {
    $pdo = new PDO(
        sprintf("mysql:host=%s;dbname=%s;charset=%s", $dbHost, $dbName, $dbCharset),
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $has = $pdo->query("SHOW COLUMNS FROM ob_requests LIKE 'uses_official_vehicle'")->fetch();
    if (!$has) {
        $pdo->exec(
            "ALTER TABLE ob_requests
             ADD COLUMN uses_official_vehicle TINYINT(1) NOT NULL DEFAULT 1
             COMMENT '1 = official DICT vehicle (Motorpool approves), 0 = private vehicle (skips Motorpool)' AFTER plate_number"
        );
        echo "OK ob_requests.uses_official_vehicle\n";
    } else {
        echo "OK ob_requests.uses_official_vehicle already exists\n";
    }

    echo "\nMIGRATION 051 complete.\n";
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
