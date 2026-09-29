<?php
/**
 * MIGRATION 056: Remove Viber channel (Telegram-only)
 *
 * Viber Phase B was dropped 2026-09-29 (Viber only issues bots on paid
 * commercial terms). Purges Viber rows seeded by migration 055 so no dead
 * settings/bindings/tokens/logs linger:
 * - settings WHERE category = 'viber'
 * - user_channel_bindings / channel_link_tokens / channel_logs WHERE channel = 'viber'
 *
 * Shared tables stay (Telegram still uses them). Idempotent.
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

echo "=== MIGRATION 056: Remove Viber channel ===\n\n";

$pdo = new PDO(
    "mysql:host={$dbHost};dbname={$dbName};charset={$dbCharset}",
    $dbUser,
    $dbPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$pMagic = $pdo->query("SELECT COUNT(*) FROM settings WHERE category = 'viber'")->fetchColumn();
$pdo->exec("DELETE FROM settings WHERE category = 'viber'");
echo "OK settings: removed {$pMagic} viber row(s)\n";

$bCount = (int) $pdo->query("SELECT COUNT(*) FROM user_channel_bindings WHERE channel = 'viber'")->fetchColumn();
$pdo->exec("DELETE FROM user_channel_bindings WHERE channel = 'viber'");
echo "OK bindings: removed {$bCount} viber row(s)\n";

$tCount = (int) $pdo->query("SELECT COUNT(*) FROM channel_link_tokens WHERE channel = 'viber'")->fetchColumn();
$pdo->exec("DELETE FROM channel_link_tokens WHERE channel = 'viber'");
echo "OK link tokens: removed {$tCount} viber row(s)\n";

$lCount = (int) $pdo->query("SELECT COUNT(*) FROM channel_logs WHERE channel = 'viber'")->fetchColumn();
$pdo->exec("DELETE FROM channel_logs WHERE channel = 'viber'");
echo "OK logs: removed {$lCount} viber row(s)\n";

echo "\nMIGRATION 056 complete.\n";
