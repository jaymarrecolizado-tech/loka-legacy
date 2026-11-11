<?php
/**
 * MIGRATION 062: Travel Order → LOKA vehicle-request link (Plan #44)
 *
 * requests.to_request_id — the travel_orders.id on tostage/prod TO that this
 *   vehicle request was created from (authority document; TO owns purpose,
 *   dates, destination, travelers). UNIQUE so one TO can only ever produce
 *   one LOKA request (idempotency at the schema level).
 * requests.to_code — human-readable control number (e.g. TO-2026-0142) for
 *   display without a cross-database join.
 *
 * Run: php migrations/062_to_link.php
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

try {
    $pdo = new PDO("mysql:host={$dbHost};dbname={$dbName};charset={$dbCharset}", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->exec(
        "ALTER TABLE requests
            ADD COLUMN to_request_id BIGINT UNSIGNED NULL DEFAULT NULL,
            ADD COLUMN to_code VARCHAR(50) NULL DEFAULT NULL,
            ADD UNIQUE INDEX uq_requests_to_request (to_request_id)"
    );
    echo "MIGRATION 062: requests.to_request_id + to_code ready.\n";
} catch (Throwable $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "MIGRATION 062: columns already exist.\n";
        exit(0);
    }
    echo "MIGRATION 062 failed: " . $e->getMessage() . "\n";
    exit(1);
}
