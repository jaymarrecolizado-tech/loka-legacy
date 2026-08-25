<?php
/**
 * LOKA - SMS notification defaults (overridden by settings / .env)
 */

/**
 * Fallback when MAIL_TEMPLATES is not loaded. Prefer "*" (mirror email) in settings.
 * Kept for UI/custom-mode checkboxes and CLI cron without mail.php.
 */
define('SMS_DEFAULT_ALLOWLIST', [
    'request_confirmation',
    'request_resubmitted',
    'request_submitted',
    'request_submitted_motorpool',
    'request_pending_motorpool',
    'added_to_request',
    'removed_from_request',
    'request_modified',
    'request_approved',
    'request_rejected',
    'request_revision',
    'request_cancelled',
    'department_approved',
    'pending_motorpool_approval',
    'request_fully_approved',
    'trip_fully_approved',
    'vehicle_assigned',
    'driver_requested',
    'driver_assigned',
    'driver_status_update',
    'driver_unassigned',
    'driver_not_selected',
    'vehicle_dispatched',
    'trip_started',
    'vehicle_arrived',
    'trip_completed',
    'trip_completed_driver',
    'trip_rejected',
    'trip_revision',
    'trip_cancelled_driver',
    'trip_vehicle_changed',
    'vehicle_driver_override',
    'request_override_notice',
    'passenger_override_notice',
    'gas_voucher_submitted',
    'gas_voucher_reviewed',
    'gas_voucher_approved',
    'gas_voucher_rejected',
    'gas_voucher_cancelled',
    'gas_voucher_payment_updated',
    'trip_ticket_approved',
    'trip_ticket_rejected',
    'trip_cancelled_driver',
    'maintenance_created',
    'maintenance_status_updated',
    'maintenance_completed',
    'maintenance_cancelled',
    'care_schedule_proposed',
    'care_schedule_scheduled',
    'care_schedule_reminder',
    'care_schedule_completed',
    'care_schedule_cancelled',
    'password_reset',
    'system_notification',
    'default',
]);

/** API path presets for All Father UI */
define('SMS_API_PATH_LOCAL', '/message');
/** Self-hosted private SMSGate server */
define('SMS_API_PATH_PRIVATE', '/api/3rdparty/v1/messages');
/** Public cloud: https://api.sms-gate.app */
define('SMS_API_PATH_CLOUD', '/3rdparty/v1/messages');
