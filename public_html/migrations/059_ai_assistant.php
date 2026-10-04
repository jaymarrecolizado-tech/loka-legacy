<?php
/**
 * MIGRATION 059: AI assistant chatbot settings (Plan #40, experimental)
 *
 * Experimental, ships DISABLED (`ai_assistant_enabled = '0'`) and stays disabled
 * until All Father stores an OpenRouter API key in System Control → AI Assistant.
 *
 * No new tables: the provider key, model and the cached free-model catalogue
 * live in `settings` (same soft-fail pattern as the Telegram bot token from
 * Plan #35) and are never exposed to the browser.
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

echo "=== MIGRATION 059: AI assistant settings ===\n\n";

try {
    $pdo = new PDO(
        sprintf("mysql:host=%s;dbname=%s;charset=%s", $dbHost, $dbName, $dbCharset),
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $defaults = [
        ['ai_assistant_enabled', '0', 'boolean', 'AI assistant chatbot (experimental, Plan #40) — OFF by default'],
        ['ai_api_key', '', 'string', 'OpenRouter API key (server-side only; never sent to the browser)'],
        ['ai_base_url', 'https://openrouter.ai/api/v1', 'string', 'OpenAI-compatible API base URL (OpenRouter by default)'],
        ['ai_model', 'openrouter/free', 'string', 'Model id used for chat + tool proposals'],
        ['ai_rate_limit_per_hour', '30', 'integer', 'Maximum AI prompts per user per hour'],
        ['ai_max_prompt_chars', '2000', 'integer', 'Maximum prompt length accepted from the chat box'],
        ['ai_free_models', '', 'string', 'Cached provider catalogue of free chat models (JSON)'],
        ['ai_free_models_at', '0', 'integer', 'Unix time the free-model catalogue was last fetched'],
    ];
    $ins = $pdo->prepare(
        "INSERT INTO settings (`key`, value, type, category, created_at, updated_at)
         VALUES (?, ?, ?, 'experimental', NOW(), NOW())
         ON DUPLICATE KEY UPDATE description = VALUES(description)"
    );
    foreach ($defaults as [$key, $value, $type, $desc]) {
        $ins->execute([$key, $value, $type]);
        $pdo->exec(
            "UPDATE settings SET description = '" . addslashes($desc) . "'
             WHERE `key` = '" . addslashes($key) . "' AND (description IS NULL OR description = '')"
        );
        echo "OK settings.{$key} = " . ($value === '' ? '(empty)' : $value) . "\n";
    }

    echo "\nMIGRATION 059 complete.\n";
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
} catch (RuntimeException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}