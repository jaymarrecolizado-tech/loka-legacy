<?php
/**
 * LOKA - Fleet Management System
 *
 * Main Entry Point / Router
 */

// Load environment variables
$envFile = __DIR__ . '/.env';
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
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/mail.php';
require_once __DIR__ . '/config/sms.php';

// Environment-based error reporting
// Auto-detect production: check if not localhost and HTTPS is enabled
$isLocalhost = in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1', '::1']) 
    || strpos($_SERVER['HTTP_HOST'] ?? '', '.local') !== false;
$isProduction = !$isLocalhost && (IS_PRODUCTION || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'));

if ($isProduction) {
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    // Try to use Hostinger error log location, fallback to local
    $errorLogPath = __DIR__ . '/logs/error.log';
    if (!is_dir(__DIR__ . '/logs')) {
        @mkdir(__DIR__ . '/logs', 0755, true);
    }
    ini_set('error_log', $errorLogPath);
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

// Load classes (must be before session.php which uses Security class)
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Security.php';
require_once __DIR__ . '/classes/Cache.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Mailer.php';
require_once __DIR__ . '/classes/EmailQueue.php';
require_once __DIR__ . '/classes/SmsGateway.php';
require_once __DIR__ . '/classes/SmsQueue.php';
require_once __DIR__ . '/classes/NotificationService.php';

// Load notification templates
require_once __DIR__ . '/config/notifications.php';

// Load session after classes (session.php uses Security class)
require_once __DIR__ . '/config/session.php';

// Load helpers
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/booking-rules.php';
require_once __DIR__ . '/includes/view_as.php';
require_once __DIR__ . '/includes/sms.php';
require_once __DIR__ . '/includes/mail_delivery.php';
require_once __DIR__ . '/includes/badge_counts.php';

// Initialize Security and send headers
$security = Security::getInstance();
$security->sendSecurityHeaders();

// Check IP access
if (!$security->checkIpAccess()) {
    http_response_code(403);
    die('Access denied.');
}

// =============================================================================
// DDOS PROTECTION CHECKS
// =============================================================================

// Check if IP is currently banned (must be before expensive operations)
if ($security->isIpBanned()) {
    http_response_code(429);
    $remaining = $security->getBanRemainingTime();
    $minutes = ceil($remaining / 60);
    die("Too many requests. Please try again in {$minutes} minute" . ($minutes > 1 ? 's' : '') . ".");
}

// Apply global rate limiting (per IP, all requests)
// Skip in local development to avoid blocking legitimate testing
if ($isProduction) {
    $rateLimited = $security->checkGlobalRateLimit(
        60,  // max 60 requests
        60   // per 60 seconds
    );

    if ($rateLimited) {
        http_response_code(429);
        die("Rate limit exceeded. Please slow down.");
    }
}

// Detect suspicious activity (optional - just logs, doesn't block)
// This helps identify potential attacks without blocking legitimate users
$security->detectSuspiciousActivity();

// =============================================================================
// END DDOS PROTECTION
// =============================================================================

// Initialize Auth
$auth = new Auth();

// Check remember me on every request
$auth->checkRememberMe();

// Get requested page
$page = get('page', 'dashboard');
$action = get('action', 'index');

// Public pages (no auth required)
$publicPages = ['login', 'logout', 'forgot-password', 'reset-password', 'qr', 'verify-voucher', 'cron'];

// Route handling
if (!in_array($page, $publicPages)) {
    requireAuth();
}

// Page routing
$pageFile = PAGES_PATH . '/' . $page . '/' . $action . '.php';
$pageIndex = PAGES_PATH . '/' . $page . '/index.php';

// Handle special routes
switch ($page) {
    case 'login':
        require_once PAGES_PATH . '/auth/login.php';
        break;

    case 'logout':
        $auth->logout();
        redirectWith('/?page=login', 'success', 'You have been logged out.');
        break;

    case 'forgot-password':
        require_once PAGES_PATH . '/auth/forgot-password.php';
        break;

    case 'reset-password':
        require_once PAGES_PATH . '/auth/reset-password.php';
        break;

    case 'view-as':
        require_once PAGES_PATH . '/view-as/set.php';
        break;

    case 'qr':
        require_once PAGES_PATH . '/public/qr.php';
        break;

    case 'verify-voucher':
        require_once PAGES_PATH . '/public/verify-voucher.php';
        break;

    case 'cron':
        // Secret-key auth handled inside the page
        require_once PAGES_PATH . '/cron/index.php';
        break;

    case 'dashboard':
        require_once PAGES_PATH . '/dashboard/index.php';
        break;

    case 'requests':
        if ($action === 'create') {
            require_once PAGES_PATH . '/requests/create.php';
        } elseif ($action === 'view') {
            require_once PAGES_PATH . '/requests/view.php';
        } elseif ($action === 'edit') {
            require_once PAGES_PATH . '/requests/edit.php';
        } elseif ($action === 'cancel') {
            require_once PAGES_PATH . '/requests/cancel.php';
        } elseif ($action === 'complete') {
            require_once PAGES_PATH . '/requests/complete.php';
        } elseif ($action === 'override') {
            require_once PAGES_PATH . '/requests/override.php';
        } elseif ($action === 'rollback') {
            require_once PAGES_PATH . '/requests/rollback.php';
        } elseif ($action === 'print') {
            require_once PAGES_PATH . '/requests/print.php';
        } else {
            require_once PAGES_PATH . '/requests/index.php';
        }
        break;

    case 'approvals':
        if ($action === 'view') {
            require_once PAGES_PATH . '/approvals/view.php';
        } elseif ($action === 'process') {
            require_once PAGES_PATH . '/approvals/process.php';
        } else {
            require_once PAGES_PATH . '/approvals/index.php';
        }
        break;

    case 'schedule':
        // Calendar is available to all logged-in users
        if ($action === 'calendar') {
            require_once PAGES_PATH . '/schedule/calendar.php';
        } else {
            require_once PAGES_PATH . '/schedule/calendar.php';
        }
        break;

    case 'vehicles':
        requireRole(ROLE_APPROVER);
        if ($action === 'create') {
            require_once PAGES_PATH . '/vehicles/create.php';
        } elseif ($action === 'edit') {
            require_once PAGES_PATH . '/vehicles/edit.php';
        } elseif ($action === 'delete') {
            require_once PAGES_PATH . '/vehicles/delete.php';
        } elseif ($action === 'view') {
            require_once PAGES_PATH . '/vehicles/view.php';
        } else {
            require_once PAGES_PATH . '/vehicles/index.php';
        }
        break;

    case 'vehicle_types':
        requireRole(ROLE_APPROVER);
        if ($action === 'create') {
            require_once PAGES_PATH . '/vehicle_types/create.php';
        } elseif ($action === 'edit') {
            require_once PAGES_PATH . '/vehicle_types/edit.php';
        } elseif ($action === 'delete') {
            require_once PAGES_PATH . '/vehicle_types/delete.php';
        } else {
            require_once PAGES_PATH . '/vehicle_types/index.php';
        }
        break;

    case 'drivers':
        requireRole(ROLE_APPROVER);
        if ($action === 'create') {
            require_once PAGES_PATH . '/drivers/create.php';
        } elseif ($action === 'edit') {
            require_once PAGES_PATH . '/drivers/edit.php';
        } elseif ($action === 'delete') {
            require_once PAGES_PATH . '/drivers/delete.php';
        } else {
            require_once PAGES_PATH . '/drivers/index.php';
        }
        break;

    case 'users':
        requireRole(ROLE_MOTORPOOL);
        if ($action === 'create') {
            requireRole(ROLE_ADMIN);
            require_once PAGES_PATH . '/users/create.php';
        } elseif ($action === 'edit') {
            requireRole(ROLE_ADMIN);
            require_once PAGES_PATH . '/users/edit.php';
        } elseif ($action === 'toggle') {
            requireRole(ROLE_ADMIN);
            require_once PAGES_PATH . '/users/toggle.php';
        } else {
            require_once PAGES_PATH . '/users/index.php';
        }
        break;

    case 'departments':
        requireRole(ROLE_MOTORPOOL);
        if ($action === 'create') {
            requireRole(ROLE_ADMIN);
            require_once PAGES_PATH . '/departments/create.php';
        } elseif ($action === 'edit') {
            requireRole(ROLE_ADMIN);
            require_once PAGES_PATH . '/departments/edit.php';
        } else {
            require_once PAGES_PATH . '/departments/index.php';
        }
        break;

    case 'reports':
        requireReportsAccess();
        // Non-approver drivers may only use own Driver Report + exports
        if (isSelfScopedDriverReporter()) {
            $driverAllowed = ['index', 'driver', 'export-driver', 'export-driver-csv'];
            if (!in_array($action, $driverAllowed, true)) {
                redirectWith('/?page=reports', 'danger', 'You do not have permission to access that report.');
            }
        }
        if ($action === 'vehicle-history') {
            require_once PAGES_PATH . '/reports/vehicle-history.php';
        } elseif ($action === 'driver') {
            require_once PAGES_PATH . '/reports/driver.php';
        } elseif ($action === 'trips') {
            require_once PAGES_PATH . '/reports/trips.php';
        } elseif ($action === 'export') {
            require_once PAGES_PATH . '/reports/export.php';
        } elseif ($action === 'export-pdf') {
            require_once PAGES_PATH . '/reports/export-pdf.php';
        } elseif ($action === 'export-vehicle-history') {
            require_once PAGES_PATH . '/reports/export-vehicle-history.php';
        } elseif ($action === 'export-vehicle-csv') {
            require_once PAGES_PATH . '/reports/export-vehicle-csv.php';
        } elseif ($action === 'export-driver') {
            require_once PAGES_PATH . '/reports/export-driver.php';
        } elseif ($action === 'export-driver-csv') {
            require_once PAGES_PATH . '/reports/export-driver-csv.php';
        } elseif ($action === 'gas-vouchers') {
            require_once PAGES_PATH . '/reports/gas-vouchers.php';
        } elseif ($action === 'export-gas-vouchers-csv') {
            require_once PAGES_PATH . '/reports/export-gas-vouchers-csv.php';
        } elseif ($action === 'export-gas-vouchers-pdf') {
            require_once PAGES_PATH . '/reports/export-gas-vouchers-pdf.php';
        } else {
            require_once PAGES_PATH . '/reports/index.php';
        }
        break;

    case 'maintenance':
        // Care actions have their own access gates (drivers with assignments may propose/complete)
        $careActions = ['schedule', 'care-assign', 'care-create', 'care-edit'];
        if (in_array($action, $careActions, true)) {
            require_once INCLUDES_PATH . '/vehicle_care.php';
            if ($action === 'care-assign') {
                require_once PAGES_PATH . '/maintenance/care-assign.php';
            } elseif ($action === 'care-create') {
                require_once PAGES_PATH . '/maintenance/care-create.php';
            } elseif ($action === 'care-edit') {
                require_once PAGES_PATH . '/maintenance/care-edit.php';
            } else {
                require_once PAGES_PATH . '/maintenance/schedule.php';
            }
            break;
        }
        requireRole(ROLE_APPROVER);
        if ($action === 'create') {
            require_once PAGES_PATH . '/maintenance/create.php';
        } elseif ($action === 'view') {
            require_once PAGES_PATH . '/maintenance/view.php';
        } elseif ($action === 'edit') {
            require_once PAGES_PATH . '/maintenance/edit.php';
        } elseif ($action === 'delete') {
            require_once PAGES_PATH . '/maintenance/delete.php';
        } elseif ($action === 'schedule') {
            require_once PAGES_PATH . '/maintenance/schedule.php';
        } else {
            require_once PAGES_PATH . '/maintenance/index.php';
        }
        break;

    case 'notifications':
        if ($action === 'read') {
            require_once PAGES_PATH . '/notifications/read.php';
        } elseif ($action === 'read-all') {
            require_once PAGES_PATH . '/notifications/read-all.php';
        } elseif ($action === 'read-all-ajax') {
            require_once PAGES_PATH . '/notifications/read-all-ajax.php';
        } elseif ($action === 'archive') {
            require_once PAGES_PATH . '/notifications/archive.php';
        } elseif ($action === 'archive-all') {
            require_once PAGES_PATH . '/notifications/archive-all.php';
        } elseif ($action === 'archive-all-ajax') {
            require_once PAGES_PATH . '/notifications/archive-all-ajax.php';
        } elseif ($action === 'delete') {
            require_once PAGES_PATH . '/notifications/delete.php';
        } elseif ($action === 'delete-all') {
            require_once PAGES_PATH . '/notifications/delete-all.php';
        } elseif ($action === 'delete-all-ajax') {
            require_once PAGES_PATH . '/notifications/delete-all-ajax.php';
        } elseif ($action === 'refresh-ajax') {
            require_once PAGES_PATH . '/notifications/refresh-ajax.php';
        } else {
            require_once PAGES_PATH . '/notifications/index.php';
        }
        break;

    case 'audit':
        requireRole(ROLE_ADMIN);
        require_once PAGES_PATH . '/audit/index.php';
        break;

    case 'rollback':
        requireRole(ROLE_ADMIN);
        if ($action === 'process') {
            require_once PAGES_PATH . '/requests/rollback.php';
        } else {
            require_once PAGES_PATH . '/rollback/index.php';
        }
        break;

    case 'admin-reports':
        requireRole(ROLE_ADMIN);
        require_once PAGES_PATH . '/admin/reports.php';
        break;

    case 'settings':
        requireRole(ROLE_ADMIN);
        if ($action === 'email-queue') {
            require_once PAGES_PATH . '/settings/email-queue.php';
        } else {
            require_once PAGES_PATH . '/settings/index.php';
        }
        break;

    case 'security':
        requireSystemControl();
        if ($action === 'summary') {
            require_once PAGES_PATH . '/security/summary.php';
        } elseif ($action === 'sms') {
            require_once PAGES_PATH . '/security/sms.php';
        } elseif ($action === 'email') {
            require_once PAGES_PATH . '/security/email.php';
        } elseif ($action === 'odometer') {
            require_once INCLUDES_PATH . '/odometer.php';
            require_once PAGES_PATH . '/security/odometer.php';
        } else {
            require_once PAGES_PATH . '/security/rate-limits.php';
        }
        break;

    case 'gas-vouchers':
        denyGuardAccess();
        if ($action === 'create') {
            require_once PAGES_PATH . '/gas-vouchers/create.php';
        } elseif ($action === 'view') {
            require_once PAGES_PATH . '/gas-vouchers/view.php';
        } elseif ($action === 'approve') {
            require_once PAGES_PATH . '/gas-vouchers/approve.php';
        } elseif ($action === 'print') {
            require_once PAGES_PATH . '/gas-vouchers/print.php';
        } elseif ($action === 'cancel') {
            require_once PAGES_PATH . '/gas-vouchers/cancel.php';
        } elseif ($action === 'update-payment') {
            require_once PAGES_PATH . '/gas-vouchers/update-payment.php';
        } else {
            require_once PAGES_PATH . '/gas-vouchers/index.php';
        }
        break;

    case 'profile':
        require_once PAGES_PATH . '/profile/index.php';
        break;

    case 'my-trips':
        if ($action === 'export-pdf') {
            require_once PAGES_PATH . '/my-trips/export-pdf.php';
        } else {
            require_once PAGES_PATH . '/my-trips/index.php';
        }
        break;

    case 'my-trip-tickets':
        if ($action === 'generate-summary') {
            require_once PAGES_PATH . '/my-trip-tickets/generate-summary.php';
        } elseif ($action === 'summary-print') {
            require_once PAGES_PATH . '/my-trip-tickets/summary-print.php';
        } elseif ($action === 'summary-print-travelorder') {
            require_once PAGES_PATH . '/my-trip-tickets/summary-print-travelorder.php';
        } else {
            require_once PAGES_PATH . '/my-trip-tickets/index.php';
        }
        break;

    case 'completed-trips':
        require_once PAGES_PATH . '/completed-trips/index.php';
        break;

    case 'patch-notes':
        require_once PAGES_PATH . '/patch-notes/index.php';
        break;

    case 'guard':
        requireRole(ROLE_GUARD);
        if ($action === 'completed') {
            require_once PAGES_PATH . '/guard/completed.php';
        } elseif ($action === 'record_dispatch' || $action === 'record_arrival') {
            require_once PAGES_PATH . '/guard/actions.php';
        } else {
            require_once PAGES_PATH . '/guard/index.php';
        }
        break;

    case 'trip-tickets':
        if ($action === 'view') {
            require_once PAGES_PATH . '/trip-tickets/view.php';
        } elseif ($action === 'create_form') {
            require_once PAGES_PATH . '/trip-tickets/create.php';
        } elseif ($action === 'export-pdf') {
            require_once PAGES_PATH . '/trip-tickets/export-pdf.php';
        } elseif ($action === 'export-excel') {
            require_once PAGES_PATH . '/trip-tickets/export-excel.php';
        } else {
            require_once PAGES_PATH . '/trip-tickets/index.php';
        }
        break;

    case 'api':
        $action = get('action');
        if ($action === 'check_conflict') {
            require_once PAGES_PATH . '/api/check_conflict.php';
        } elseif ($action === 'list' || $action === 'get' || $action === 'create' || $action === 'update' || $action === 'delete') {
            // Determine API type from query parameter or referer
            $apiType = get('type');
            if (!$apiType) {
                $apiType = get('endpoint', 'requests');
            }

            if ($apiType === 'vehicle_types') {
                require_once BASE_PATH . '/api/vehicle_types.php';
            } else {
                require_once BASE_PATH . '/api/requests.php';
            }
        } else {
            jsonResponse(false, ['error' => 'Invalid action'], 'Invalid action', 404);
        }
        break;

    case 'export':
        requireRole(ROLE_ADMIN);
        $format = get('format', 'csv');
        if ($format === 'csv') {
            require_once PAGES_PATH . '/admin/exports/csv.php';
        } elseif ($format === 'pdf') {
            require_once PAGES_PATH . '/admin/exports/pdf.php';
        } else {
            redirectWith('/?page=admin-reports', 'danger', 'Invalid export format.');
        }
        break;

    case 'file-view':
        // Public endpoint for viewing uploaded files
        require_once PAGES_PATH . '/file-view.php';
        break;

    default:
        // 404 page
        http_response_code(404);
        require_once INCLUDES_PATH . '/header.php';
        echo '<div class="container-fluid py-4"><div class="alert alert-danger">Page not found.</div></div>';
        require_once INCLUDES_PATH . '/footer.php';
        break;
}
