<?php
/**
 * MIGRATION 055: Telegram + Viber notification channels (Plan #35)
 *
 * - user_channel_bindings — one chat per user per channel (unique user_id+channel).
 *   linked_via: 'self' (Profile deep link) or 'admin' (User create/edit).
 * - channel_link_tokens — one-time, short-TTL tokens for the Profile
 *   "Connect" deep link; redeemed by the Telegram/Viber webhook or the
 *   System Control "poll updates" tool.
 * - channel_logs — outbound queue/delivery log mirroring sms_logs.
 * - Settings defaults (category telegram / viber): channels ship disabled
 *   until All Father stores a bot token in System Control.
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

echo "=== MIGRATION 055: Telegram + Viber notification channels ===\n\n";

try {
    $pdo = new PDO(
        sprintf("mysql:host=%s;dbname=%s;charset=%s", $dbHost, $dbName, $dbCharset),
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // ---- user_channel_bindings ----
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS user_channel_bindings (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NOT NULL,
            channel VARCHAR(20) NOT NULL,
            chat_id VARCHAR(100) NOT NULL,
            display_name VARCHAR(120) NULL DEFAULT NULL,
            linked_via VARCHAR(10) NOT NULL DEFAULT 'self',
            linked_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_ucb_user_channel (user_id, channel),
            KEY idx_ucb_chat (channel, chat_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "OK user_channel_bindings\n";

    // ---- channel_link_tokens ----
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS channel_link_tokens (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            token VARCHAR(64) NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            channel VARCHAR(20) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL DEFAULT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_clt_token (token),
            KEY idx_clt_user_channel (user_id, channel),
            KEY idx_clt_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "OK channel_link_tokens\n";

    // ---- channel_logs (mirror sms_logs) ----
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS channel_logs (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NULL DEFAULT NULL,
            channel VARCHAR(20) NOT NULL,
            chat_id VARCHAR(100) NOT NULL,
            request_id INT UNSIGNED NULL DEFAULT NULL,
            event_type VARCHAR(64) NULL DEFAULT NULL,
            message TEXT NOT NULL,
            status ENUM('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
            gateway_message_id VARCHAR(100) NULL DEFAULT NULL,
            gateway_response TEXT NULL DEFAULT NULL,
            error_message TEXT NULL DEFAULT NULL,
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            sent_at DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_cl_channel_status (channel, status),
            KEY idx_cl_user (user_id),
            KEY idx_cl_request (request_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "OK channel_logs\n";

    // ---- settings defaults (channels ship disabled until a token is stored) ----
    $defaults = [
        ['telegram_enabled', '0', 'boolean', 'telegram', 'Telegram notifications enabled'],
        ['telegram_bot_token', '', 'string', 'telegram', 'Bot token from @BotFather'],
        ['telegram_bot_username', '', 'string', 'telegram', 'Bot username (without @) — used for Profile deep links'],
        ['telegram_webhook_secret', '', 'string', 'telegram', 'Optional webhook secret token (X-Telegram-Bot-Api-Secret-Token)'],
        ['telegram_event_allowlist', '*', 'string', 'telegram', 'Events that may send over Telegram (* = mirror email)'],
        ['telegram_max_length', '3500', 'integer', 'telegram', 'Max message length'],
        ['telegram_timeout', '15', 'integer', 'telegram', 'Gateway timeout seconds'],
        ['viber_enabled', '0', 'boolean', 'viber', 'Viber notifications enabled'],
        ['viber_auth_token', '', 'string', 'viber', 'Viber bot / PA auth token'],
        ['viber_sender_name', 'LOKA Fleet', 'string', 'viber', 'Sender name shown in Viber'],
        ['viber_webhook_secret', '', 'string', 'viber', 'Optional secret key required on webhook calls'],
        ['viber_event_allowlist', '*', 'string', 'viber', 'Events that may send over Viber (* = mirror email)'],
        ['viber_max_length', '900', 'integer', 'viber', 'Max message length'],
        ['viber_timeout', '15', 'integer', 'viber', 'Gateway timeout seconds'],
    ];
    $ins = $pdo->prepare(
        "INSERT INTO settings (`key`, value, type, category, created_at, updated_at)
         VALUES (?, ?, ?, ?, NOW(), NOW())
         ON DUPLICATE KEY UPDATE updated_at = updated_at"
    );
    foreach ($defaults as [$key, $value, $type, $category, $desc]) {
        $ins->execute([$key, $value, $type, $category]);
        $pdo->exec("UPDATE settings SET description = '" . addslashes($desc) . "' WHERE `key` = '" . addslashes($key) . "' AND (description IS NULL OR description = '')");
    }
    echo "OK settings defaults (telegram/viber)\n";

    echo "\nMIGRATION 055 complete.\n";
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
