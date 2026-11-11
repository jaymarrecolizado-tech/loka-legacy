<?php
/**
 * Keep application logs out of the document root.
 *
 * CloudPanel's site nginx workers run as root, so chmod inside public_html/logs
 * cannot hide files. Cron stdout is redirected by the shell into that directory
 * before PHP starts; we swallow CLI output and write it under sibling app_logs
 * instead, then delete any leftover public *.log files.
 */
if (defined('LOKA_LOGS_HARDENED')) {
    return;
}
define('LOKA_LOGS_HARDENED', true);

@umask(0027);

$publicHtml = dirname(__DIR__);
$legacyDir = $publicHtml . '/logs';
$outsideDir = dirname($publicHtml) . '/app_logs';

if (!is_dir($outsideDir)) {
    @mkdir($outsideDir, 0750, true);
}

if (is_dir($outsideDir)) {
    define('LOKA_LOG_DIR', $outsideDir);
} else {
    define('LOKA_LOG_DIR', $legacyDir);
}

if (is_dir(LOKA_LOG_DIR)) {
    @chmod(LOKA_LOG_DIR, 0770);
}

if (PHP_SAPI === 'cli') {
    $script = strtolower((string) basename($_SERVER['argv'][0] ?? 'cron'));
    $cliLog = LOKA_LOG_DIR . '/cron.log';
    if (str_contains($script, 'sms')) {
        $cliLog = LOKA_LOG_DIR . '/cron_sms.log';
    } elseif (str_contains($script, 'trip')) {
        $cliLog = LOKA_LOG_DIR . '/cron_trips.log';
    } elseif (str_contains($script, 'care')) {
        $cliLog = LOKA_LOG_DIR . '/cron_care.log';
    } elseif (str_contains($script, 'channel')) {
        $cliLog = LOKA_LOG_DIR . '/cron_channels.log';
    }
    ob_start(static function (string $buf) use ($cliLog): string {
        if ($buf !== '') {
            @file_put_contents($cliLog, $buf, FILE_APPEND | LOCK_EX);
            @chmod($cliLog, 0660);
        }
        return '';
    });
}

if (is_dir($legacyDir)) {
    foreach (glob($legacyDir . '/*.log') ?: [] as $logFile) {
        @unlink($logFile);
    }
}
