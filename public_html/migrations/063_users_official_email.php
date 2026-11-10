<?php
/**
 * MIGRATION 063: users.official_email (shared office mailbox)
 *
 * Individual login email (users.email) stays unique.
 * Official / office email may be reused across staff in the same unit.
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

echo "=== MIGRATION 063: users.official_email ===\n\n";

try {
    $pdo = new PDO(
        sprintf("mysql:host=%s;dbname=%s;charset=%s", $dbHost, $dbName, $dbCharset),
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $has = $pdo->query("SHOW COLUMNS FROM users LIKE 'official_email'")->fetch();
    if (!$has) {
        $pdo->exec(
            "ALTER TABLE users
             ADD COLUMN official_email VARCHAR(150) NULL DEFAULT NULL
             COMMENT 'Shared office/official mailbox; not unique'
             AFTER email"
        );
        echo "OK users.official_email\n";
    } else {
        echo "OK users.official_email already exists\n";
    }

    echo "\nMIGRATION 063 complete.\n";
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
