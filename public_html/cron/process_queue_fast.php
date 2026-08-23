<?php
/**
 * LOKA - Fast Email Queue Processor
 * 
 * This script processes emails every 10-15 seconds for faster delivery
 * Run this script continuously or via a task scheduler
 * 
 * Windows Task Scheduler:
 *   Program: C:\wamp64\bin\php\php8.x.x\php.exe
 *   Arguments: C:\wamp64\www\fleetManagement\LOKA\cron\process_queue_fast.php
 *   Run: Continuously or every 10 seconds
 *   
 * Linux: Run as a daemon or use systemd timer
 */

// Prevent web access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('CLI access only');
}

// Change to LOKA directory
chdir(dirname(__DIR__));

// Load configuration
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/mail.php';

// Load classes
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Security.php';
require_once __DIR__ . '/../classes/Mailer.php';
require_once __DIR__ . '/../classes/EmailQueue.php';

// Configuration
$batchSize = 10;        // Process 10 emails per run
$sleepSeconds = 15;     // Wait 15 seconds between runs
$maxRuns = null;        // null = run indefinitely, or set a number to limit runs

// Lock file to prevent concurrent runs
$lockFile = __DIR__ . '/queue_fast.lock';

// Check if already running
if (file_exists($lockFile)) {
    $lockTime = filemtime($lockFile);
    // If lock is older than 2 minutes, assume previous process died
    if (time() - $lockTime < 120) {
        echo date('[Y-m-d H:i:s]') . " Fast queue processor already running. Exiting.\n";
        exit(0);
    } else {
        // Remove stale lock
        @unlink($lockFile);
    }
}

// Create lock
file_put_contents($lockFile, getmypid());

// Signal handler for graceful shutdown
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function() use ($lockFile) {
        if (file_exists($lockFile)) {
            unlink($lockFile);
        }
        exit(0);
    });
}

$runCount = 0;

try {
    $queue = new EmailQueue();
    
    echo date('[Y-m-d H:i:s]') . " Fast email queue processor started (runs every {$sleepSeconds} seconds)\n";
    
    while (true) {
        // Check if we should stop
        if ($maxRuns !== null && $runCount >= $maxRuns) {
            break;
        }
        
        // Update lock file timestamp
        touch($lockFile);
        
        // Process queue
        $results = $queue->process($batchSize);
        
        if ($results['sent'] > 0 || $results['failed'] > 0) {
            echo date('[Y-m-d H:i:s]') . " Processed: Sent={$results['sent']}, Failed={$results['failed']}\n";
        }
        
        $runCount++;
        
        // Sleep before next run
        sleep($sleepSeconds);
        
        // Handle signals (for graceful shutdown)
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
    }
    
} catch (Exception $e) {
    echo date('[Y-m-d H:i:s]') . " ERROR: " . $e->getMessage() . "\n";
    error_log("Fast email queue error: " . $e->getMessage());
} finally {
    // Remove lock
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
    echo date('[Y-m-d H:i:s]') . " Fast queue processor stopped\n";
}
