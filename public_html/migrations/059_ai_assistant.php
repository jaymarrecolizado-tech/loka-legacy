<?php
/**
 * MIGRATION 059: AI assistant chatbot settings (Plan #40, experimental)
 *
 * Experimental, ships DISABLED (`ai_assistant_enabled = '0'`) and stays disabled
 * until All Father stores an OpenRouter API key in System Control → AI Assistant.
 *
 * No new tables: the provider key, model and the cached model catalogue
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

    // settings.value is TEXT (65,535 bytes). The cached OpenRouter catalogue is
    // ~67 KB as named-key JSON, so widen it rather than mangling the payload or
    // silently dropping models. Widening is additive and non-destructive.
    $col = $pdo->query("SHOW COLUMNS FROM settings LIKE 'value'")->fetch(PDO::FETCH_ASSOC);
    if ($col && stripos((string) $col['Type'], 'mediumtext') === false && stripos((string) $col['Type'], 'longtext') === false) {
        $pdo->exec("ALTER TABLE settings MODIFY value MEDIUMTEXT NULL DEFAULT NULL");
        echo "OK settings.value widened to MEDIUMTEXT\n";
    } else {
        echo "OK settings.value already large enough\n";
    }

    $defaults = [
        ['ai_assistant_enabled', '0', 'boolean', 'AI assistant chatbot (experimental, Plan #40) — OFF by default'],
        ['ai_api_key', '', 'string', 'OpenRouter API key (server-side only; never sent to the browser)'],
        ['ai_base_url', 'https://openrouter.ai/api/v1', 'string', 'OpenAI-compatible API base URL (OpenRouter by default)'],
        ['ai_model', 'qwen/qwen3.8-27b:free', 'string', 'Model id used for chat + tool proposals (must carry the OpenRouter :free tag)'],
        ['ai_rate_limit_per_hour', '30', 'integer', 'Maximum AI prompts per user per hour'],
        ['ai_max_prompt_chars', '2000', 'integer', 'Maximum prompt length accepted from the chat box'],
        ['ai_models', '', 'string', 'Cached OpenRouter model catalogue (JSON, unfiltered)'],
        ['ai_models_at', '0', 'integer', 'Unix time the model catalogue was last fetched'],
    ];
    // ON DUPLICATE KEY UPDATE touches `description` ONLY — an operator's stored
    // value (including a real API key) is never overwritten by re-running this.
    $ins = $pdo->prepare(
        "INSERT INTO settings (`key`, value, description, type, category, created_at, updated_at)
         VALUES (?, ?, ?, ?, 'experimental', NOW(), NOW())
         ON DUPLICATE KEY UPDATE description = VALUES(description)"
    );
    foreach ($defaults as [$key, $value, $type, $desc]) {
        $ins->execute([$key, $value, $desc, $type]);
        // Report what is ACTUALLY stored, not the default we just tried to set,
        // so a re-run never looks like it wiped an operator's configuration.
        $stored = $pdo->query("SELECT value FROM settings WHERE `key` = " . $pdo->quote($key))->fetchColumn();
        $isDefault = ((string) $stored === (string) $value);
        echo "OK settings.{$key} = "
            . ($key === 'ai_api_key' || (string) $stored === ''
                ? ($stored === '' ? '(empty)' : '(set, ' . strlen((string) $stored) . ' chars, preserved)')
                : (string) $stored)
            . ($isDefault ? '' : '  [preserved existing value]')
            . "\n";
    }

    echo "\nMIGRATION 059 complete.\n";
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
} catch (RuntimeException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}