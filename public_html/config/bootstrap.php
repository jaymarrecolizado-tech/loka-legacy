<?php
/**
 * LOKA - Application Bootstrap
 *
 * This file loads all necessary configuration without running routing logic.
 * Use this for standalone scripts that need the full application context.
 */

// Load environment variables
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $name = trim($parts[0]);
            $value = trim($parts[1]);
            if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
                putenv(sprintf('%s=%s', $name, $value));
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }
}

// Set timezone to Manila, Philippines
date_default_timezone_set('Asia/Manila');

// Load configuration (order matters)
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/mail.php';

// Load classes (before session.php which needs Security class)
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Security.php';
require_once __DIR__ . '/../classes/Cache.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Mailer.php';
require_once __DIR__ . '/../classes/EmailQueue.php';
require_once __DIR__ . '/sms.php';
require_once __DIR__ . '/../classes/SmsGateway.php';
require_once __DIR__ . '/../classes/SmsQueue.php';

// Load session (needs Security class)
require_once __DIR__ . '/session.php';

// Load helpers
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/booking-rules.php';
require_once __DIR__ . '/../includes/view_as.php';
require_once __DIR__ . '/../includes/sms.php';
require_once __DIR__ . '/../includes/mail_delivery.php';
require_once __DIR__ . '/../includes/badge_counts.php';
require_once __DIR__ . '/../includes/vehicle_care.php';

// Initialize Security
$security = Security::getInstance();
$security->sendSecurityHeaders();

// Initialize Auth
$auth = new Auth();
$auth->checkRememberMe();
