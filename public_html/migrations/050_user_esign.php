<?php
/**
 * MIGRATION 050: OB saved staff e-sign specimen (Plan #23)
 *
 * Admin uploads a specimen signature PNG/JPG onto the user record;
 * approve / guard-departure copies it onto the slip instead of a canvas.
 * File lives at uploads/user_signatures/{userId}.{ext}.
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

echo "=== MIGRATION 050: user e-sign specimen ===\n\n";

try {
    $pdo = new PDO(
        sprintf("mysql:host=%s;dbname=%s;charset=%s", $dbHost, $dbName, $dbCharset),
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $has = $pdo->query("SHOW COLUMNS FROM users LIKE 'signature_path'")->fetch();
    if (!$has) {
        $pdo->exec(
            "ALTER TABLE users
             ADD COLUMN signature_path VARCHAR(255) NULL COMMENT 'Specimen e-sign (admin-uploaded or first-time canvas save)' AFTER is_ob_approver"
        );
        echo "OK users.signature_path\n";
    } else {
        echo "OK users.signature_path already exists\n";
    }

    // Storage dir (best effort — CLI may run as a different user than the web server)
    $dir = __DIR__ . '/../uploads/user_signatures';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
        echo is_dir($dir) ? "OK uploads/user_signatures/\n" : "NOTE uploads/user_signatures/ will be created on first upload\n";
    } else {
        echo "OK uploads/user_signatures/ exists\n";
    }

    echo "\nMIGRATION 050 complete.\n";
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
