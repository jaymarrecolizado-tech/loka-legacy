<?php
/**
 * MIGRATION 053: Sticky trip-create block after evaluation threshold (Plan #28)
 *
 * users.eval_create_blocked — set when pending evals hit driver_evaluation_block_at;
 * cleared only when pending count returns to 0. Survives logout/new session.
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

echo "=== MIGRATION 053: users.eval_create_blocked ===\n\n";

try {
    $pdo = new PDO(
        sprintf("mysql:host=%s;dbname=%s;charset=%s", $dbHost, $dbName, $dbCharset),
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $has = $pdo->query("SHOW COLUMNS FROM users LIKE 'eval_create_blocked'")->fetch();
    if (!$has) {
        $pdo->exec(
            "ALTER TABLE users
             ADD COLUMN eval_create_blocked TINYINT(1) NOT NULL DEFAULT 0
             COMMENT 'Plan #28: sticky block new trip create until all pending driver evals submitted'
             AFTER status"
        );
        echo "OK users.eval_create_blocked\n";
    } else {
        echo "OK users.eval_create_blocked already exists\n";
    }

    // Break-glass accounts must never stay sticky-blocked
    $cleared = $pdo->exec(
        "UPDATE users SET eval_create_blocked = 0
         WHERE role IN ('admin', 'all_father') AND eval_create_blocked <> 0"
    );
    echo "OK cleared sticky on admin/all_father (" . (int) $cleared . " row(s))\n";

    echo "\nMIGRATION 053 complete.\n";
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
