<?php
/**
 * LOKA - SMS helpers (notify-only outbound)
 */

/**
 * Read SMS setting: DB settings first, then .env, then default.
 */
function smsConfig(string $key, string $default = ''): string
{
    if (!isset($GLOBALS['__loka_sms_config_cache']) || !is_array($GLOBALS['__loka_sms_config_cache'])) {
        $GLOBALS['__loka_sms_config_cache'] = [];
        try {
            $rows = db()->fetchAll(
                "SELECT `key`, value FROM settings WHERE category = 'sms' OR `key` LIKE 'sms_%'"
            );
            foreach ($rows as $row) {
                $GLOBALS['__loka_sms_config_cache'][(string) $row->key] = (string) $row->value;
            }
        } catch (Throwable $e) {
            error_log('smsConfig settings load: ' . $e->getMessage());
        }
    }

    $cache = $GLOBALS['__loka_sms_config_cache'];
    if (array_key_exists($key, $cache) && $cache[$key] !== '') {
        return $cache[$key];
    }

    $envMap = [
        'sms_enabled' => 'SMS_ENABLED',
        'sms_gateway_url' => 'SMS_GATEWAY_URL',
        'sms_gateway_username' => 'SMS_GATEWAY_USERNAME',
        'sms_gateway_password' => 'SMS_GATEWAY_PASSWORD',
        'sms_api_path' => 'SMS_API_PATH',
        'sms_country_code' => 'SMS_DEFAULT_COUNTRY_CODE',
        'sms_timeout_seconds' => 'SMS_TIMEOUT_SECONDS',
        'sms_max_length' => 'SMS_MAX_MESSAGE_LENGTH',
        'sms_event_allowlist' => 'SMS_EVENT_ALLOWLIST',
    ];
    if (isset($envMap[$key])) {
        $env = getenv($envMap[$key]);
        if ($env !== false && $env !== '') {
            return (string) $env;
        }
    }

    return $default;
}

/** Clear settings cache after All Father saves. */
function smsConfigClearCache(): void
{
    $GLOBALS['__loka_sms_config_cache'] = null;
}

function smsEnabled(): bool
{
    // Prefer live DB read for enable flag so toggle applies immediately
    try {
        $row = db()->fetch("SELECT value FROM settings WHERE `key` = 'sms_enabled' LIMIT 1");
        if ($row !== null) {
            return in_array(strtolower(trim((string) $row->value)), ['1', 'true', 'yes', 'on'], true);
        }
    } catch (Throwable $e) {
        // fall through
    }
    $env = strtolower(trim((string) (getenv('SMS_ENABLED') ?: 'false')));
    return in_array($env, ['1', 'true', 'yes', 'on'], true);
}

function smsEventAllowed(string $type): bool
{
    if ($type === 'test') {
        return true;
    }

    // Default "*": same events as email (MAIL_TEMPLATES). Custom CSV can restrict further.
    $raw = trim(smsConfig('sms_event_allowlist', '*'));
    if ($raw === '' || $raw === '*') {
        if (defined('MAIL_TEMPLATES') && is_array(MAIL_TEMPLATES)) {
            return array_key_exists($type, MAIL_TEMPLATES);
        }
        return in_array($type, SMS_DEFAULT_ALLOWLIST, true);
    }

    $list = array_filter(array_map('trim', explode(',', $raw)));
    if (empty($list)) {
        return defined('MAIL_TEMPLATES') && is_array(MAIL_TEMPLATES)
            ? array_key_exists($type, MAIL_TEMPLATES)
            : in_array($type, SMS_DEFAULT_ALLOWLIST, true);
    }
    return in_array($type, $list, true);
}

/** Event keys shown in All Father SMS UI (email templates + legacy defaults). */
function smsSelectableEvents(): array
{
    $events = SMS_DEFAULT_ALLOWLIST;
    if (defined('MAIL_TEMPLATES') && is_array(MAIL_TEMPLATES)) {
        $events = array_keys(MAIL_TEMPLATES);
    }
    sort($events);
    return $events;
}

/**
 * Normalize PH / E.164 phone. Returns +63… or null.
 */
function normalizePhoneE164(string $phone): ?string
{
    $digits = preg_replace('/\D+/', '', $phone);
    if ($digits === null || $digits === '') {
        return null;
    }

    $cc = preg_replace('/\D+/', '', smsConfig('sms_country_code', '63')) ?: '63';

    if (str_starts_with($digits, '0') && strlen($digits) === 11) {
        $digits = $cc . substr($digits, 1);
    } elseif (strlen($digits) === 10 && str_starts_with($digits, '9')) {
        $digits = $cc . $digits;
    } elseif (str_starts_with($digits, $cc)) {
        // already
    } elseif (strlen($digits) < 10) {
        return null;
    }

    if (strlen($digits) < 11 || strlen($digits) > 15) {
        return null;
    }

    return '+' . $digits;
}

function buildSmsMessage(
    string $eventType,
    string $title,
    string $message,
    ?string $link = null,
    ?int $requestId = null
): string {
    $max = (int) smsConfig('sms_max_length', '320');
    if ($max < 80) {
        $max = 80;
    }

    $parts = ['LOKA'];
    if ($requestId) {
        $parts[] = '#' . $requestId;
    }
    $header = implode(' ', $parts);

    $body = trim($title);
    if ($body === '') {
        $body = trim($message);
    } else {
        $shortMsg = trim($message);
        if ($shortMsg !== '' && !str_contains($body, $shortMsg)) {
            // keep title; append a short slice of message if room
            $body .= ': ' . $shortMsg;
        }
    }

    $text = $header . ' - ' . $body;

    if ($link) {
        $abs = $link;
        if (str_starts_with($link, '/')) {
            $abs = rtrim(APP_URL, '/') . $link;
        } elseif (!preg_match('#^https?://#i', $link)) {
            $abs = rtrim(APP_URL, '/') . '/' . ltrim($link, '/');
        }
        $text .= ' ' . $abs;
    }

    $text = preg_replace('/\s+/', ' ', $text) ?? $text;
    if (mb_strlen($text) > $max) {
        $text = mb_substr($text, 0, $max - 1) . '…';
    }

    return $text;
}

/**
 * Soft-fail enqueue from notify().
 */
function smsNotifyUser(
    int $userId,
    string $type,
    string $title,
    string $message,
    ?string $link = null,
    ?int $requestId = null
): void {
    try {
        if (!class_exists('SmsQueue')) {
            return;
        }
        (new SmsQueue())->queueForUser($userId, $type, $title, $message, $link, $requestId);
    } catch (Throwable $e) {
        error_log('smsNotifyUser: ' . $e->getMessage());
    }
}

/**
 * Tell the trip requester that a participant could not get SMS (no/invalid phone).
 * Does not block SMS to anyone else. Deduped per requester+participant+request (1 hour).
 */
function smsNotifyRequesterMissingPhone(int $requestId, int $missingUserId, string $missingName): void
{
    try {
        if (!smsEnabled()) {
            return;
        }

        $request = db()->fetch(
            "SELECT id, user_id FROM requests WHERE id = ? AND deleted_at IS NULL",
            [$requestId]
        );
        if (!$request || !(int) $request->user_id) {
            return;
        }

        $requesterId = (int) $request->user_id;
        if ($requesterId === $missingUserId) {
            return;
        }

        $title = 'SMS not sent — missing phone number';
        $message = trim($missingName) !== ''
            ? "{$missingName} has no valid phone number on file, so they did not receive an SMS for request #{$requestId}. Other participants with phone numbers were still texted."
            : "A participant has no valid phone number on file, so they did not receive an SMS for request #{$requestId}. Other participants with phone numbers were still texted.";
        $link = '/?page=requests&action=view&id=' . $requestId;

        $dup = db()->fetch(
            "SELECT id FROM notifications
             WHERE user_id = ? AND title = ? AND link = ?
               AND message = ?
               AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
               AND deleted_at IS NULL
             LIMIT 1",
            [$requesterId, $title, $link, $message]
        );
        if ($dup) {
            return;
        }

        // In-app + email (+ SMS if enabled); type matches email templates
        notify($requesterId, 'default', $title, $message, $link, $requestId);
    } catch (Throwable $e) {
        error_log('smsNotifyRequesterMissingPhone: ' . $e->getMessage());
    }
}

/**
 * Upsert a settings row.
 */
function smsSaveSetting(string $key, string $value, string $type = 'string'): void
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
            "INSERT INTO settings (`key`, value, type, category, created_at, updated_at) VALUES (?, ?, ?, 'sms', ?, ?)",
            [$key, $value, $type, $now, $now]
        );
    }
}
