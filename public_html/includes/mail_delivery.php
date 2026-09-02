<?php
/**
 * LOKA - Email delivery mode helpers (All Father controlled)
 */

define('EMAIL_MODE_IMMEDIATE', 'immediate');
define('EMAIL_MODE_QUEUED', 'queued');
define('EMAIL_MODE_HYBRID', 'hybrid');

/**
 * @return string immediate|queued|hybrid
 */
function emailDeliveryMode(): string
{
    $allowed = [EMAIL_MODE_IMMEDIATE, EMAIL_MODE_QUEUED, EMAIL_MODE_HYBRID];
    try {
        $row = db()->fetch("SELECT value FROM settings WHERE `key` = 'email_delivery_mode' LIMIT 1");
        if ($row && in_array((string) $row->value, $allowed, true)) {
            return (string) $row->value;
        }
    } catch (Throwable $e) {
        // fall through
    }

    $env = strtolower(trim((string) (getenv('EMAIL_DELIVERY_MODE') ?: '')));
    if (in_array($env, $allowed, true)) {
        return $env;
    }

    // Default depends on env: production → queued (cron required, fast pages);
    // development/localhost → immediate (direct send, no cron needed).
    $appEnv = strtolower(trim((string) (getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? ''))));
    if ($appEnv === 'production') {
        return EMAIL_MODE_QUEUED;
    }
    return EMAIL_MODE_IMMEDIATE;
}

function emailCronSecret(): string
{
    try {
        $row = db()->fetch("SELECT value FROM settings WHERE `key` = 'cron_secret' LIMIT 1");
        if ($row && trim((string) $row->value) !== '') {
            return trim((string) $row->value);
        }
    } catch (Throwable $e) {
        // fall through
    }
    $env = trim((string) (getenv('CRON_SECRET') ?: ''));
    return $env;
}

function emailSaveSetting(string $key, string $value, string $type = 'string'): void
{
    $existing = db()->fetch("SELECT id FROM settings WHERE `key` = ?", [$key]);
    $now = date(DATETIME_FORMAT);
    if ($existing) {
        db()->query(
            "UPDATE settings SET value = ?, updated_at = ? WHERE `key` = ?",
            [$value, $now, $key]
        );
    } else {
        db()->query(
            "INSERT INTO settings (`key`, value, type, category, created_at, updated_at) VALUES (?, ?, ?, 'email', ?, ?)",
            [$key, $value, $type, $now, $now]
        );
    }
}

/** Templates that sync immediately in hybrid mode */
function emailHybridCriticalTemplates(): array
{
    return [
        'request_confirmation',
        'request_approved',
        'request_rejected',
        'request_revision',
        'request_cancelled',
        'department_approved',
        'request_fully_approved',
        'driver_assigned',
    ];
}
