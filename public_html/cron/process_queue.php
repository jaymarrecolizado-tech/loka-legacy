<?php
/**
 * LOKA - Email Queue Processor
 *
 * Run this script via cron/task scheduler to process queued emails
 *
 * Recommended schedule: Every 1-2 minutes
 *
 * HOSTINGER (cPanel Cron Jobs):
 *   Frequency: Every 2 minutes (use: every 2 minutes in cPanel)
 *   Command: /usr/bin/php /home/dictr2-lokafleet/htdocs/lokafleet.dictr2.cloud/public_html/cron/process_queue.php
 *
 * Linux Cron:
 *   Run every 2 minutes: /usr/bin/php /path/to/cron/process_queue.php >> /path/to/logs/email_queue.log 2>&1
 */

// Prevent web access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('CLI access only');
}

// Output IMMEDIATELY so we can see the script started (before any config loading)
echo date('[Y-m-d H:i:s]') . " [PID:" . getmypid() . "] process_queue.php started\n";

// Register shutdown handler to catch fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        echo date('[Y-m-d H:i:s]') . " FATAL: {$error['message']} in {$error['file']}:{$error['line']}\n";
    }
});

// Change to LOKA directory
chdir(dirname(__DIR__));

// PRE-LOAD .env EXPLICITLY before config files.
// Reason: Hosting panels (CloudPanel, Hostinger) inject DB vars into the Apache/web
// environment, but cron processes do NOT inherit those vars. Without this explicit
// pre-load, SMTP credentials stay empty and every email silently fails with:
//   "MAIL_USERNAME and MAIL_PASSWORD must be configured"
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || $line[0] === '#') continue;
        if (strpos($line, '=') !== false) {
            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);
            // Strip surrounding quotes
            if (preg_match('/^(["\'])(.*)\\1$/', $value, $m)) {
                $value = $m[2];
            }
            putenv("$key=$value");
            $_ENV[$key]    = $value;
            $_SERVER[$key] = $value;
        }
    }
    echo date('[Y-m-d H:i:s]') . " .env loaded from: {$envFile}\n";
} else {
    echo date('[Y-m-d H:i:s]') . " WARNING: .env file not found at: {$envFile}\n";
}

// Load configuration (with error trapping so we can diagnose failures)
$configFiles = [
    'database.php',
    'constants.php',
    'security.php',
    'mail.php',
];
foreach ($configFiles as $cf) {
    $path = __DIR__ . '/../config/' . $cf;
    if (!file_exists($path)) {
        echo date('[Y-m-d H:i:s]') . " FATAL: Config file missing: {$path}\n";
        exit(1);
    }
    $result = @require_once $path;
    // require_once returns true on success, or the value of die() on failure
    if ($result === false) {
        echo date('[Y-m-d H:i:s]') . " FATAL: Failed to load {$cf}\n";
        exit(1);
    }
}

// Load classes
$classFiles = ['Database.php', 'Mailer.php', 'EmailQueue.php'];
foreach ($classFiles as $cf) {
    $path = __DIR__ . '/../classes/' . $cf;
    if (!file_exists($path)) {
        echo date('[Y-m-d H:i:s]') . " FATAL: Class file missing: {$path}\n";
        exit(1);
    }
    require_once $path;
}

// Diagnostic output after config loaded
echo date('[Y-m-d H:i:s]') . " Config loaded. MAIL_ENABLED=" . (defined('MAIL_ENABLED') ? (MAIL_ENABLED ? 'true' : 'false') : 'UNDEF')
    . " MAIL_HOST=" . (defined('MAIL_HOST') ? MAIL_HOST : 'UNDEF')
    . " MAIL_USER=" . (defined('MAIL_USERNAME') ? (MAIL_USERNAME ? 'SET' : 'EMPTY') : 'UNDEF') . "\n";

// Configuration
$batchSize = 20;        // Process 20 emails per run
$cleanupDays = 30;      // Delete sent emails older than 30 days

// Lock file to prevent concurrent runs (atomic flock)
$lockFile = __DIR__ . '/queue.lock';

// Acquire exclusive lock (non-blocking) - atomic operation
$lockFileResource = fopen($lockFile, 'w');
if (!flock($lockFileResource, LOCK_EX | LOCK_NB)) {
    echo date('[Y-m-d H:i:s]') . " Queue processor already running. Exiting.\n";
    exit(0);
}

try {
    $queue = new EmailQueue();
    
    // Get stats before processing
    $statsBefore = $queue->getStats();
    echo date('[Y-m-d H:i:s]') . " Starting queue processor\n";
    echo "  Pending: {$statsBefore['pending']}, Processing: {$statsBefore['processing']}\n";
    
    // Process queue
    $results = $queue->process($batchSize);
    
    echo date('[Y-m-d H:i:s]') . " Processed: Sent={$results['sent']}, Failed={$results['failed']}\n";
    
    // FIX: Alert admins if too many recent failures
    $statsAfter = $queue->getStats();
    if ($statsAfter['recent_failures'] > 10) {
        echo date('[Y-m-d H:i:s]') . " WARNING: {$statsAfter['recent_failures']} recent failures detected!\n";
        
        // Get admin user IDs
        $adminUsers = Database::getInstance()->fetchAll(
            "SELECT id, email, name FROM users WHERE role = 'admin' AND status = 'active' AND deleted_at IS NULL"
        );
        
        // Send alert to all admins
        if (!empty($adminUsers)) {
            $alertMessage = "CRITICAL: More than 10 emails have failed in the last hour.\n\n" .
                            "Please check:\n" .
                            "- Email configuration (config/mail.php)\n" .
                            "- SMTP server status\n" .
                            "- Error logs for details";
            
            foreach ($adminUsers as $admin) {
                $queue->queueTemplate(
                    $admin->email,
                    'default',
                    [
                        'message' => $alertMessage,
                        'link' => null,
                        'link_text' => null
                    ],
                    $admin->name,
                    1 // High priority
                );
            }
            
            echo date('[Y-m-d H:i:s]') . " Alert queued for " . count($adminUsers) . " admins\n";
        }
    }
    
    // Cleanup old sent emails (run occasionally)
    if (rand(1, 10) === 1) {
        $cleaned = $queue->cleanup($cleanupDays);
        if ($cleaned > 0) {
            echo date('[Y-m-d H:i:s]') . " Cleaned up {$cleaned} old emails\n";
        }
    }
    
    // Reset stuck "processing" emails (older than 5 minutes)
    $stuck = Database::getInstance()->query(
        "UPDATE email_queue SET status = 'pending', updated_at = NOW() 
         WHERE status = 'processing' AND updated_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)"
    );
    if ($stuck->rowCount() > 0) {
        echo date('[Y-m-d H:i:s]') . " Reset {$stuck->rowCount()} stuck emails\n";
    }
    
} catch (Exception $e) {
    echo date('[Y-m-d H:i:s]') . " ERROR: " . $e->getMessage() . "\n";
    error_log("Email queue error: " . $e->getMessage());
} finally {
    // Release lock and remove lock file
    flock($lockFileResource, LOCK_UN);
    fclose($lockFileResource);
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
}

echo date('[Y-m-d H:i:s]') . " Queue processor finished\n";
