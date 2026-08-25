<?php
/**
 * LOKA - HTTP cron endpoint (no session UI)
 * Usage: /?page=cron&action=email&key=SECRET
 *         /?page=cron&action=sms&key=SECRET
 *         /?page=cron&action=care&key=SECRET
 */

header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

$action = get('action', 'email');
$key = (string) (get('key', '') ?: ($_SERVER['HTTP_X_CRON_KEY'] ?? ''));
$expected = function_exists('emailCronSecret') ? emailCronSecret() : '';

if ($expected === '' || !hash_equals($expected, $key)) {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

$batch = max(1, min(100, (int) get('limit', 40)));

try {
    if ($action === 'sms') {
        if (!class_exists('SmsQueue')) {
            http_response_code(500);
            echo "SmsQueue unavailable\n";
            exit;
        }
        $queue = new SmsQueue();
        if (!function_exists('smsEnabled') || !smsEnabled()) {
            echo date('c') . " SMS disabled\n";
            exit;
        }
        $r = $queue->process($batch);
        echo date('c') . " SMS ok sent={$r['sent']} failed={$r['failed']} skipped={$r['skipped']}\n";
        exit;
    }

    if ($action === 'care') {
        if (!is_file(BASE_PATH . '/cron/process_care_reminders.php')) {
            http_response_code(500);
            echo "process_care_reminders.php unavailable\n";
            exit;
        }
        require_once BASE_PATH . '/cron/process_care_reminders.php';
        if (!function_exists('processCareReminders')) {
            http_response_code(500);
            echo "processCareReminders() unavailable\n";
            exit;
        }
        $r = processCareReminders();
        echo date('c') . " CARE ok sent={$r['sent']} skipped={$r['skipped']}\n";
        exit;
    }

    // default: email
    if (!class_exists('EmailQueue')) {
        http_response_code(500);
        echo "EmailQueue unavailable\n";
        exit;
    }
    $queue = new EmailQueue();
    $r = $queue->process($batch);
    echo date('c') . " EMAIL ok sent={$r['sent']} failed={$r['failed']} skipped={$r['skipped']}\n";
} catch (Throwable $e) {
    http_response_code(500);
    error_log('HTTP cron error: ' . $e->getMessage());
    echo "Error: " . $e->getMessage() . "\n";
}
