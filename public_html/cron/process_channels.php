<?php
/**
 * LOKA - Messenger Channel Queue Processor (Telegram)
 *
 * The web app ONLY queues channel messages (channel_logs.status='pending') —
 * this cron does the actual Telegram API sends, and also redeems pending
 * /start {token} connect codes so new users can link their Telegram without
 * anyone clicking "Poll updates" in System Control.
 *
 * Polling is skipped automatically when a public webhook is registered
 * (Telegram then pushes updates itself, and getUpdates would 409-conflict).
 *
 * Schedule: every 2 minutes, alongside cron/process_queue.php:
 *   0-59/2 * * * * /usr/bin/php /home/.../cron/process_channels.php >> /home/.../logs/cron_channels.log 2>&1
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('CLI access only');
}

echo date('[Y-m-d H:i:s]') . " [PID:" . getmypid() . "] process_channels.php started\n";

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        echo date('[Y-m-d H:i:s]') . " FATAL: {$error['message']} in {$error['file']}:{$error['line']}\n";
    }
});

chdir(dirname(__DIR__));

// PRE-LOAD .env explicitly — cron does not inherit panel-injected DB/SMTP vars.
$envFile = __DIR__ . '/../.env';
if (!file_exists($envFile)) {
    $envFile = __DIR__ . '/../../.env.lokastage';
}
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (preg_match('/^(["\'])(.*)\1$/', $value, $m)) {
            $value = $m[2];
        }
        putenv("$key=$value");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
    echo date('[Y-m-d H:i:s]') . " .env loaded from: {$envFile}\n";
} else {
    echo date('[Y-m-d H:i:s]') . " WARNING: .env file not found at: {$envFile}\n";
}

$configFiles = ['database.php', 'constants.php', 'security.php', 'mail.php', 'sms.php', 'channels.php'];
foreach ($configFiles as $cf) {
    $path = __DIR__ . '/../config/' . $cf;
    if (!file_exists($path)) {
        echo date('[Y-m-d H:i:s]') . " FATAL: Config file missing: {$path}\n";
        exit(1);
    }
    require_once $path;
}

$classFiles = ['Database.php', 'Security.php', 'SmsGateway.php', 'SmsQueue.php', 'ChannelQueue.php', 'TelegramGateway.php'];
foreach ($classFiles as $cf) {
    $path = __DIR__ . '/../classes/' . $cf;
    if (!file_exists($path)) {
        echo date('[Y-m-d H:i:s]') . " FATAL: Class file missing: {$path}\n";
        exit(1);
    }
    require_once $path;
}

require_once __DIR__ . '/../includes/trip-enhancements.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/channels.php';

// Lock file to prevent concurrent runs (atomic flock)
$lockFile = __DIR__ . '/channels.lock';
$lockResource = fopen($lockFile, 'w');
if (!flock($lockResource, LOCK_EX | LOCK_NB)) {
    echo date('[Y-m-d H:i:s]') . " Channel processor already running. Exiting.\n";
    exit(0);
}

try {
    $queue = new ChannelQueue();

    // ---- 1. Flush queued messages per enabled channel ----
    foreach (LOKA_CHANNELS as $channel) {
        if (!function_exists('channelEnabled') || !channelEnabled($channel)) {
            echo date('[Y-m-d H:i:s]') . " " . ucfirst($channel) . " disabled — skipping queue\n";
            continue;
        }
        $before = $queue->getStats($channel);
        echo date('[Y-m-d H:i:s]') . " " . ucfirst($channel) . " queue: pending={$before['pending']}\n";
        $r = $queue->process($channel, 20);
        echo date('[Y-m-d H:i:s]') . " " . ucfirst($channel) . " processed: sent={$r['sent']}, failed={$r['failed']}, skipped={$r['skipped']}\n";
    }

    // ---- 2. Redeem pending /start {token} link codes (no-webhook mode) ----
    if (in_array('telegram', LOKA_CHANNELS, true) && function_exists('channelEnabled') && channelEnabled('telegram')) {
        $gw = TelegramGateway::fromConfig();
        if (!$gw) {
            echo date('[Y-m-d H:i:s]') . " Telegram gateway unavailable (token not configured)\n";
        } else {
            // If a webhook is registered, Telegram pushes updates itself and
            // getUpdates would conflict — leave linking to the webhook.
            // (TelegramGateway::call() is private, so query the API directly.)
            $webhookUrl = '';
            $token = channelConfig('telegram', 'telegram_bot_token', '');
            if ($token !== '') {
                $ch = curl_init('https://api.telegram.org/bot' . $token . '/getWebhookInfo');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 10,
                ]);
                $raw = curl_exec($ch);
                curl_close($ch);
                $decoded = $raw ? json_decode((string) $raw, true) : null;
                $webhookUrl = trim((string) ($decoded['result']['url'] ?? ''));
            }
            if ($webhookUrl !== '') {
                echo date('[Y-m-d H:i:s]') . " Webhook registered ({$webhookUrl}) — skipping getUpdates poll\n";
            } else {
                $offset = (int) channelConfig('telegram', 'telegram_last_update_id', '0');
                $r = $gw->getUpdates($offset);
                if (!$r['ok']) {
                    echo date('[Y-m-d H:i:s]') . " getUpdates failed: " . ($r['error'] ?: 'unknown') . "\n";
                } else {
                    $n = channelRedeemTelegramUpdates($r['updates']);
                    $maxId = 0;
                    foreach ($r['updates'] as $u) {
                        $maxId = max($maxId, (int) ($u->update_id ?? 0));
                    }
                    if ($maxId > 0) {
                        channelSaveSetting('telegram', 'telegram_last_update_id', (string) ($maxId + 1), 'integer');
                        channelConfigClearCache('telegram');
                    }
                    echo date('[Y-m-d H:i:s]') . " Polled " . count($r['updates']) . " update(s); processed {$n} link command(s)\n";
                }
            }
        }
    }
} catch (Throwable $e) {
    echo date('[Y-m-d H:i:s]') . " ERROR: " . $e->getMessage() . "\n";
    error_log("Channel queue error: " . $e->getMessage());
} finally {
    flock($lockResource, LOCK_UN);
    fclose($lockResource);
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
}

echo date('[Y-m-d H:i:s]') . " Channel processor finished\n";
