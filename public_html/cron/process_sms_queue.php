<?php
/**
 * LOKA - SMS Queue Processor
 *
 * CLI only. Schedule every 1–2 minutes (same pattern as email queue).
 *
 * Windows Task Scheduler:
 *   Program: C:\xampp\php\php.exe
 *   Arguments: C:\xampp\htdocs\Projects\pred-loka-old-boots\public_html\cron\process_sms_queue.php
 *
 * Linux / Hostinger (every 2 minutes):
 *   php /path/to/public_html/cron/process_sms_queue.php >> /var/log/loka_sms.log 2>&1
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
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/SmsGateway.php';
require_once __DIR__ . '/../classes/SmsQueue.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/sms.php';

$lockFile = __DIR__ . '/sms_queue.lock';
$lockFileResource = fopen($lockFile, 'w');
if (!$lockFileResource || !flock($lockFileResource, LOCK_EX | LOCK_NB)) {
    echo date('[Y-m-d H:i:s]') . " SMS queue processor already running. Exiting.\n";
    exit(0);
}

try {
    $queue = new SmsQueue();
    $stats = $queue->getStats();
    echo date('[Y-m-d H:i:s]') . " SMS processor start (pending={$stats['pending']})\n";

    if (!smsEnabled()) {
        echo date('[Y-m-d H:i:s]') . " SMS disabled — nothing to do.\n";
        exit(0);
    }

    $results = $queue->process(30);
    echo date('[Y-m-d H:i:s]') . " Done: sent={$results['sent']} failed={$results['failed']} skipped={$results['skipped']}\n";
} catch (Throwable $e) {
    echo date('[Y-m-d H:i:s]') . ' ERROR: ' . $e->getMessage() . "\n";
    exit(1);
} finally {
    flock($lockFileResource, LOCK_UN);
    fclose($lockFileResource);
}
