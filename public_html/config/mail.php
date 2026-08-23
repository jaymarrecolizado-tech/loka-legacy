<?php
/**
 * LOKA - Mail Configuration
 *
 * SMTP Settings - Read from environment variables
 * Supports both Apache SetEnv and .env file approaches
 *
 * Environment Variables Required:
 * - SMTP_HOST: SMTP server hostname (e.g., smtp.gmail.com)
 * - SMTP_PORT: SMTP port (e.g., 587 for TLS, 465 for SSL)
 * - SMTP_ENCRYPTION: Encryption type (tls or ssl)
 * - SMTP_USER: SMTP username/email address
 * - SMTP_PASSWORD: SMTP password or app-specific password
 * - SMTP_FROM_EMAIL: From email address
 * - SMTP_FROM_NAME: From name (optional, defaults to 'LOKA Fleet Management')
 * - APP_ENV: Application environment (development/production)
 *
 * NOTE: getEnvVar() function is defined in database.php which should be
 * loaded before this file. If loading this file independently, ensure
 * database.php is loaded first.
 */

// Determine environment
$isProduction = (getEnvVar('APP_ENV') === 'production');

// SMTP Settings - All values read from environment variables
// No hardcoded credentials - all must be set via environment variables

// Basic SMTP Configuration
define('MAIL_HOST', getEnvVar('SMTP_HOST', ''));
define('MAIL_PORT', (int) getEnvVar('SMTP_PORT', 587));
define('MAIL_ENCRYPTION', getEnvVar('SMTP_ENCRYPTION', 'tls'));

// Authentication - CRITICAL: These must be set via environment variables
define('MAIL_USERNAME', getEnvVar('SMTP_USER', ''));
define('MAIL_PASSWORD', getEnvVar('SMTP_PASSWORD', ''));

// From Address Configuration
define('MAIL_FROM_ADDRESS', getEnvVar('SMTP_FROM_EMAIL', ''));
define('MAIL_FROM_NAME', getEnvVar('SMTP_FROM_NAME', 'LOKA Fleet Management'));

// Enable/disable mail functionality based on configuration
define('MAIL_ENABLED', getEnvVar('MAIL_ENABLED', 'true') === 'true');

// Configuration validation - check if email is properly configured
function isEmailConfigured(): bool {
    return MAIL_ENABLED 
        && !empty(MAIL_HOST) 
        && !empty(MAIL_USERNAME) 
        && !empty(MAIL_PASSWORD) 
        && !empty(MAIL_FROM_ADDRESS);
}

// Helper to check if running in production
function isProduction(): bool {
    return (getEnvVar('APP_ENV') === 'production');
}

// Validate configuration in production
if ($isProduction) {
    $missing = [];
    if (empty(MAIL_HOST)) $missing[] = 'SMTP_HOST';
    if (empty(MAIL_USERNAME)) $missing[] = 'SMTP_USER';
    if (empty(MAIL_PASSWORD)) $missing[] = 'SMTP_PASSWORD';
    if (empty(MAIL_FROM_ADDRESS)) $missing[] = 'SMTP_FROM_EMAIL';
    
    if (!empty($missing)) {
        error_log('CRITICAL: Missing required email environment variables in production: ' . implode(', ', $missing));
        // Don't die() here to allow the app to run, but email functionality will be disabled
    }
}

// Email Templates
define('MAIL_TEMPLATES', [
    // Approver notifications
    'request_submitted' => [
        'subject' => 'New Vehicle Request Submitted',
        'template' => 'A new vehicle request has been submitted and requires your approval.'
    ],
    'request_submitted_motorpool' => [
        'subject' => 'New Vehicle Request Awaiting Review',
        'template' => 'A new vehicle request has been submitted. Please review for awareness. Approval will be required after department approval.'
    ],
    'request_pending_motorpool' => [
        'subject' => 'Request Awaiting Vehicle Assignment',
        'template' => 'A request has been approved by department and needs vehicle/driver assignment.'
    ],
    
    // Requester notifications
    'request_confirmation' => [
        'subject' => 'Your Vehicle Request Has Been Submitted',
        'template' => 'Your vehicle request has been submitted successfully and is now awaiting approval.'
    ],
    'request_approved' => [
        'subject' => 'Your Request Has Been Approved',
        'template' => 'Great news! Your vehicle request has been approved.'
    ],
    'request_rejected' => [
        'subject' => 'Your Request Has Been Rejected',
        'template' => 'Unfortunately, your vehicle request has been rejected.'
    ],
    'vehicle_assigned' => [
        'subject' => 'Vehicle and Driver Assigned',
        'template' => 'A vehicle and driver have been assigned to your request.'
    ],
    'trip_completed_requester' => [
        'subject' => 'Trip Completed',
        'template' => 'Your trip has been marked as completed.'
    ],
    
    // Passenger notifications
    'added_to_request' => [
        'subject' => 'You Have Been Added to a Vehicle Request',
        'template' => 'You have been added as a passenger to a vehicle request.'
    ],
    'removed_from_request' => [
        'subject' => 'Removed from Vehicle Request',
        'template' => 'You have been removed from a vehicle request.'
    ],
    'request_modified' => [
        'subject' => 'Trip Details Updated',
        'template' => 'A trip you are part of has been modified.'
    ],
    'request_cancelled' => [
        'subject' => 'Trip Cancelled',
        'template' => 'A trip you were part of has been cancelled.'
    ],
    
    // Driver notifications
    'driver_requested' => [
        'subject' => 'You Have Been Requested as Driver',
        'template' => 'You have been requested as the driver for a vehicle request. The request is pending approval.'
    ],
    'driver_assigned' => [
        'subject' => 'You Have Been Assigned as Driver',
        'template' => 'You have been assigned as the driver for an approved vehicle request.'
    ],
    'driver_status_update' => [
        'subject' => 'Trip Status Update',
        'template' => 'There has been a status update for your assigned trip.'
    ],
    'trip_started' => [
        'subject' => 'Trip Has Started',
        'template' => 'Your assigned trip has officially started. Safe travels!'
    ],
    'trip_completed' => [
        'subject' => 'Trip Completed',
        'template' => 'Your assigned trip has been completed. Thank you for your service.'
    ],
    
    // Guard notifications
    'vehicle_dispatched' => [
        'subject' => 'Vehicle Dispatched',
        'template' => 'Your vehicle has departed as scheduled. Trip is now in progress.'
    ],
    'vehicle_arrived' => [
        'subject' => 'Vehicle Returned',
        'template' => 'Your vehicle has returned from the trip. The trip is now complete.'
    ],
    
    // Password reset
    'password_reset' => [
        'subject' => 'Password Reset Request',
        'template' => 'A password reset has been requested for your account.'
    ],
    
    // System notifications
    'system_notification' => [
        'subject' => 'System Notification',
        'template' => 'You have a new system notification.'
    ],
    
    // Override notifications
    'vehicle_driver_override' => [
        'subject' => 'Vehicle/Driver Assignment Changed',
        'template' => 'The vehicle or driver assignment for a trip has been changed.'
    ],
    'driver_unassigned' => [
        'subject' => 'Driver Assignment Removed',
        'template' => 'You have been removed from a trip assignment.'
    ],
    'trip_vehicle_changed' => [
        'subject' => 'Trip Vehicle/Driver Changed',
        'template' => 'The vehicle or driver for your trip has been changed.'
    ],
    
    // Revision notifications
    'request_revision' => [
        'subject' => 'Request Sent Back for Revision',
        'template' => 'Your vehicle request has been sent back for revision. Please review the comments and update your request.'
    ],
    'trip_revision' => [
        'subject' => 'Trip Sent Back for Revision',
        'template' => 'A trip you are part of has been sent back for revision.'
    ],
    
    // Department approval stage
    'department_approved' => [
        'subject' => 'Request Approved by Department',
        'template' => 'Your vehicle request has been approved by the department approver and is now awaiting motorpool assignment.'
    ],
    'pending_motorpool_approval' => [
        'subject' => 'Request Awaiting Your Approval',
        'template' => 'A vehicle request has been approved by the department and requires your motorpool approval.'
    ],
    
    // Final approval
    'request_fully_approved' => [
        'subject' => 'Request Fully Approved!',
        'template' => 'Great news! Your vehicle request has been fully approved.'
    ],
    'trip_fully_approved' => [
        'subject' => 'Trip Fully Approved',
        'template' => 'The trip you are part of has been fully approved!'
    ],
    'trip_rejected' => [
        'subject' => 'Trip Rejected',
        'template' => 'A trip you were part of has been rejected.'
    ],
    
    // Driver notifications
    'driver_not_selected' => [
        'subject' => 'Driver Assignment Update',
        'template' => 'A trip you were requested to drive has been assigned to another driver.'
    ],
    
    // Override/conflict notifications
    'request_override_notice' => [
        'subject' => 'Your Trip May Be Affected by Override',
        'template' => 'Your trip may be affected by a vehicle/driver override. Please review your trip details.'
    ],
    'passenger_override_notice' => [
        'subject' => 'Trip May Be Affected by Override',
        'template' => 'A trip you are part of may be affected by a vehicle/driver override.'
    ],
    
    // Default fallback template
    'default' => [
        'subject' => 'LOKA Notification',
        'template' => 'You have a new notification from LOKA Fleet Management.'
    ]
]);

// Additional mail settings
define('MAIL_CHARSET', 'UTF-8');
define('MAIL_DEBUG', getEnvVar('MAIL_DEBUG', 'false') === 'true');
define('MAIL_TIMEOUT', 30);
