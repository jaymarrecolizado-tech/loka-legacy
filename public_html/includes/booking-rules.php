<?php
/**
 * LOKA - Booking Rules Helpers
 * Single source of truth for loading and enforcing booking rule settings.
 */

if (!defined('BOOKING_RULE_DEFAULTS')) {
    define('BOOKING_RULE_DEFAULTS', [
        'max_advance_booking_days' => 30,
        'min_advance_booking_hours' => 24,
        'max_trip_duration_hours' => 72,
    ]);
}

/**
 * Load booking rules from settings with sane clamping and defaults.
 *
 * @return array{max_advance_booking_days:int, min_advance_booking_hours:int, max_trip_duration_hours:int}
 */
function getBookingRules(): array
{
    $rules = BOOKING_RULE_DEFAULTS;

    $rows = db()->fetchAll(
        "SELECT `key`, value FROM settings WHERE `key` IN ('max_advance_booking_days', 'min_advance_booking_hours', 'max_trip_duration_hours')"
    );
    foreach ($rows as $row) {
        if (array_key_exists($row->key, $rules)) {
            $rules[$row->key] = (int) $row->value;
        }
    }

    // Clamp defensively so a bad DB value can't brick validation
    $rules['max_advance_booking_days'] = max(1, min(365, $rules['max_advance_booking_days']));
    $rules['min_advance_booking_hours'] = max(0, min(168, $rules['min_advance_booking_hours']));
    $rules['max_trip_duration_hours'] = max(1, min(720, $rules['max_trip_duration_hours']));

    return $rules;
}

/**
 * Whether a vehicle return must be confirmed by a guard before completion.
 */
function requireReturnConfirmation(): bool
{
    static $cached = null;
    if ($cached === null) {
        $row = db()->fetch("SELECT value FROM settings WHERE `key` = 'require_return_confirmation'");
        $cached = $row && $row->value === '1';
    }
    return $cached;
}

/**
 * Validate start/end datetimes against the configured booking rules.
 * Assumes $endDt > $startDt has already been checked by the caller.
 *
 * @return string[] List of validation error messages (empty when valid).
 */
function validateBookingRules(DateTime $startDt, DateTime $endDt): array
{
    $rules = getBookingRules();
    $errors = [];

    $tz = new DateTimeZone('Asia/Manila');
    $now = new DateTime('now', $tz);

    $hoursUntilStart = ($startDt->getTimestamp() - $now->getTimestamp()) / 3600;

    if ($hoursUntilStart < $rules['min_advance_booking_hours']) {
        $errors[] = "Bookings must be made at least {$rules['min_advance_booking_hours']} hours in advance. Please select a later start time.";
    }

    $maxAdvanceHours = $rules['max_advance_booking_days'] * 24;
    if ($hoursUntilStart > $maxAdvanceHours) {
        $errors[] = "Bookings cannot be made more than {$rules['max_advance_booking_days']} days in advance. Please select an earlier start time.";
    }

    $tripDurationHours = ($endDt->getTimestamp() - $startDt->getTimestamp()) / 3600;
    if ($tripDurationHours > $rules['max_trip_duration_hours']) {
        $errors[] = "Trip duration cannot exceed {$rules['max_trip_duration_hours']} hours. Please shorten your trip or split it into multiple requests.";
    }

    return $errors;
}
