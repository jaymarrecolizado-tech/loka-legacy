<?php
/**
 * LOKA - Toggle email delivery mode (CLI helper)
 * Usage:
 *   php cron/set-email-mode.php immediate   # localhost direct (no cron)
 *   php cron/set-email-mode.php queued      # VPS production (cron every 2 min)
 *   php cron/set-email-mode.php hybrid      # critical sync, rest queued
 * Also available via System Control → Email (All Father UI).
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }
chdir(dirname(__DIR__));
require_once __DIR__ . '/../config/bootstrap.php';

$mode = strtolower(trim($argv[1] ?? ''));
$allowed = ['immediate','queued','hybrid'];
if (!in_array($mode, $allowed, true)) {
    echo "Usage: php cron/set-email-mode.php <immediate|queued|hybrid>\n";
    echo "Current: ".emailDeliveryMode()."\n";
    exit(1);
}
emailSaveSetting('email_delivery_mode', $mode);
echo "OK email_delivery_mode=".emailDeliveryMode()."\n";
echo ($mode==='immediate' ? "Localhost direct: MAIL_ENABLED must be true, no cron needed.\n" : "VPS queued: ensure cron runs every 2 min (Tasks: LOKA Cron + crontab).\n");
