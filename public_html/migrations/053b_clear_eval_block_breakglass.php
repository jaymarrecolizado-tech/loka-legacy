<?php
/**
 * Clear sticky trip-create blocks on Admin / All Father accounts (Plan #28).
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

echo "=== Clear eval_create_blocked for admin / all_father ===\n\n";

try {
    $pdo = new PDO(
        sprintf("mysql:host=%s;dbname=%s;charset=%s", $dbHost, $dbName, $dbCharset),
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $has = $pdo->query("SHOW COLUMNS FROM users LIKE 'eval_create_blocked'")->fetch();
    if (!$has) {
        echo "SKIP: column eval_create_blocked missing (run 053 first)\n";
        exit(0);
    }

    $stmt = $pdo->prepare(
        "UPDATE users SET eval_create_blocked = 0
         WHERE role IN ('admin', 'all_father') AND eval_create_blocked <> 0"
    );
    $stmt->execute();
    echo "OK cleared sticky flags on " . $stmt->rowCount() . " admin/all_father row(s)\n";
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
