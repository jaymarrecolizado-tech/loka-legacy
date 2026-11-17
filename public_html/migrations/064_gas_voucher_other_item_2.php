<?php
/**
 * MIGRATION 064: second other-article line on gas vouchers
 * (e.g. Engine oil + Brake fluid with separate qty/unit).
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

echo "=== MIGRATION 064: gas_vouchers other article 2 ===\n\n";

try {
    $pdo = new PDO(
        sprintf("mysql:host=%s;dbname=%s;charset=%s", $dbHost, $dbName, $dbCharset),
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $cols = [
        'other_items_2' => "TEXT NULL COMMENT 'Second extra article (e.g. Brake fluid)'",
        'other_qty_2' => "DECIMAL(10,2) NULL COMMENT 'Qty for second extra article'",
        'other_unit_2' => "VARCHAR(20) NULL COMMENT 'Unit for second extra article'",
    ];
    $after = 'other_unit';
    foreach ($cols as $name => $def) {
        $has = $pdo->query("SHOW COLUMNS FROM gas_vouchers LIKE " . $pdo->quote($name))->fetch();
        if (!$has) {
            $pdo->exec("ALTER TABLE gas_vouchers ADD COLUMN {$name} {$def} AFTER {$after}");
            echo "OK {$name}\n";
        } else {
            echo "OK {$name} already exists\n";
        }
        $after = $name;
    }

    echo "\nMIGRATION 064 complete.\n";
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
