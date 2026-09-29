<?php
/**
 * LOKA - Channel Queue Processor (Telegram + Viber, Plan #35)
 *
 * CLI only. Schedule every 2 minutes (same pattern as email/SMS queues).
 *
 * Windows Task Scheduler:
 *   Program: C:\xampp\php\php.exe
 *   Arguments: C:\xampp\htdocs\Projects\pred-loka-old-boots\public_html\cron\process_channel_queue.php
 *
 * Linux / Hostinger (every 2 minutes):
 *   php /path/to/public_html/cron/process_channel_queue.php >> /var/log/loka_channels.log 2>&1
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('CLI access only');
}

chdir(dirname(__DIR__));

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../config/sms.php';
require_once __DIR__ . '/../config/channels.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/ChannelQueue.php';
require_once __DIR__ . '/../classes/TelegramGateway.php';
require_once __DIR__ . '/../classes/ViberGateway.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/sms.php';
require_once __DIR__ . '/../includes/channels.php';

$lockFile = __DIR__ . '/channel_queue.lock';
$lockFileResource = fopen($lockFile, 'w');
if (!$lockFileResource || !flock($lockFileResource, LOCK_EX | LOCK_NB)) {
    echo date('[Y-m-d H:i:s]') . " Channel queue processor already running. Exiting.\n";
    exit(0);
}

try {
    $queue = new ChannelQueue();

    foreach (LOKA_CHANNELS as $channel) {
        if (!channelEnabled($channel)) {
            echo date('[Y-m-d H:i:s]') . " " . ucfirst($channel) . " disabled — nothing to do.\n";
            continue;
        }
        $stats = $queue->getStats($channel);
        echo date('[Y-m-d H:i:s]') . " " . ucfirst($channel) . " start (pending={$stats['pending']})\n";
        $results = $queue->process($channel, 30);
        echo date('[Y-m-d H:i:s]') . " " . ucfirst($channel) . " done: sent={$results['sent']} failed={$results['failed']} skipped={$results['skipped']}\n";
    }
} catch (Throwable $e) {
    echo date('[Y-m-d H:i:s]') . ' ERROR: ' . $e->getMessage() . "\n";
    exit(1);
} finally {
    flock($lockFileResource, LOCK_UN);
    fclose($lockFileResource);
}
