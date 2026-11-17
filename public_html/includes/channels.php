<?php
/**
 * LOKA - Messenger channel helpers (Plan #35: Telegram)
 *
 * Soft-fail extras beside in-app / email / SMS (notify() fan-out). Channels
 * send only when (a) the channel is enabled in System Control, (b) the event
 * passes the channel allowlist (default '*' = mirror email), and (c) the user
 * has a binding (self-linked via Profile deep link, or set by an admin).
 * Queue-only: gateway calls never happen during the HTTP page request.
 */

require_once BASE_PATH . '/classes/ChannelQueue.php';

/**
 * Read a channel setting: DB settings (category telegram) first, then
 * .env, then default. Mirrors smsConfig().
 */
function channelConfig(string $channel, string $key, string $default = ''): string
{
    $cacheKey = '__loka_channel_config_' . $channel;
    if (!isset($GLOBALS[$cacheKey]) || !is_array($GLOBALS[$cacheKey])) {
        $GLOBALS[$cacheKey] = [];
        try {
            $rows = db()->fetchAll(
                "SELECT `key`, value FROM settings WHERE category = ? OR `key` LIKE ?",
                [$channel, $channel . '\_%'
                ]
            );
            foreach ($rows as $row) {
                $GLOBALS[$cacheKey][(string) $row->key] = (string) $row->value;
            }
        } catch (Throwable $e) {
            error_log('channelConfig settings load: ' . $e->getMessage());
        }
    }

    $cache = $GLOBALS[$cacheKey];
    if (array_key_exists($key, $cache) && $cache[$key] !== '') {
        return (string) $cache[$key];
    }

    $envMap = defined('CHANNEL_ENV_MAP') ? CHANNEL_ENV_MAP : [];
    if (isset($envMap[$key])) {
        $env = getenv($envMap[$key]);
        if ($env !== false && $env !== '') {
            return (string) $env;
        }
    }

    return $default;
}

/** Clear the per-channel settings cache after All Father saves. */
function channelConfigClearCache(string $channel): void
{
    $GLOBALS['__loka_channel_config_' . $channel] = null;
}

function channelEnabled(string $channel): bool
{
    try {
        $row = db()->fetch(
            "SELECT value FROM settings WHERE `key` = ? LIMIT 1",
            [$channel . '_enabled']
        );
        if ($row !== null) {
            return in_array(strtolower(trim((string) $row->value)), ['1', 'true', 'yes', 'on'], true);
        }
    } catch (Throwable $e) {
        // fall through to env
    }
    $env = strtolower(trim((string) (getenv(strtoupper($channel) . '_ENABLED') ?: 'false')));
    return in_array($env, ['1', 'true', 'yes', 'on'], true);
}

/**
 * Allowlist per channel. Default '*': same events as email (MAIL_TEMPLATES).
 * A custom CSV restricts further.
 */
function channelEventAllowed(string $channel, string $type): bool
{
    if ($type === 'test') {
        return true;
    }

    $raw = trim(channelConfig($channel, $channel . '_event_allowlist', '*'));
    $emailHasKey = function () use ($type): bool {
        return defined('MAIL_TEMPLATES') && is_array(MAIL_TEMPLATES)
            ? array_key_exists($type, MAIL_TEMPLATES)
            : true; // no templates loaded (CLI) — allow
    };

    if ($raw === '' || $raw === '*') {
        return $emailHasKey();
    }

    $list = array_filter(array_map('trim', explode(',', $raw)));
    if (empty($list)) {
        return $emailHasKey();
    }
    return in_array($type, $list, true);
}

/** Event keys for the All Father channel UI (email templates when present). */
function channelSelectableEvents(): array
{
    $events = defined('MAIL_TEMPLATES') && is_array(MAIL_TEMPLATES)
        ? array_keys(MAIL_TEMPLATES)
        : [];
    if (empty($events) && defined('SMS_DEFAULT_ALLOWLIST')) {
        $events = SMS_DEFAULT_ALLOWLIST;
    }
    sort($events);
    return $events;
}

/** Upsert a channel settings row (category = channel name). */
function channelSaveSetting(string $channel, string $key, string $value, string $type = 'string'): void
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
            "INSERT INTO settings (`key`, value, type, category, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)",
            [$key, $value, $type, $channel, $now, $now]
        );
    }
}

// =============================================================================
// BINDINGS
// =============================================================================

/** Current binding for a user + channel, or null. */
function channelGetBinding(int $userId, string $channel): ?object
{
    $row = db()->fetch(
        "SELECT * FROM user_channel_bindings WHERE user_id = ? AND channel = ? LIMIT 1",
        [$userId, $channel]
    );
    return $row ?: null;
}

/** All bindings for a user. */
function channelGetBindings(int $userId): array
{
    return db()->fetchAll(
        "SELECT * FROM user_channel_bindings WHERE user_id = ? ORDER BY channel",
        [$userId]
    );
}

/** Find the user bound to a channel chat id (webhook command handling). */
function channelUserByChatId(string $channel, string $chatId): ?object
{
    $row = db()->fetch(
        "SELECT b.*, u.name AS user_name FROM user_channel_bindings b
         JOIN users u ON u.id = b.user_id
         WHERE b.channel = ? AND b.chat_id = ? LIMIT 1",
        [$channel, $chatId]
    );
    return $row ?: null;
}

/** Admin set/clear from User create/edit (Plan #35 decision 2). */
function channelSetAdminBinding(int $userId, string $channel, string $chatId, ?string $displayName = null): void
{
    $chatId = trim($chatId);
    if ($chatId === '') {
        channelClearBinding($userId, $channel);
        return;
    }
    $now = date(DATETIME_FORMAT);
    $existing = channelGetBinding($userId, $channel);
    if ($existing) {
        db()->update('user_channel_bindings', [
            'chat_id'       => $chatId,
            'linked_via'    => 'admin',
            'linked_at'     => $now,
            'updated_at'    => $now,
        ], 'id = ?', [(int) $existing->id]);
    } else {
        db()->insert('user_channel_bindings', [
            'user_id'      => $userId,
            'channel'      => $channel,
            'chat_id'      => $chatId,
            'display_name' => $displayName,
            'linked_via'   => 'admin',
            'linked_at'    => $now,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }
    auditLog('channel_binding_set', 'user', $userId, null, ['channel' => $channel, 'via' => 'admin']);
}

/** Remove a binding (Disconnect button / admin cleared the field). */
function channelClearBinding(int $userId, string $channel): bool
{
    $existing = channelGetBinding($userId, $channel);
    if (!$existing) {
        return false;
    }
    db()->delete('user_channel_bindings', 'id = ?', [(int) $existing->id]);
    auditLog('channel_binding_cleared', 'user', $userId, ['channel' => $channel], null);
    return true;
}

// =============================================================================
// SELF-LINK TOKENS
// =============================================================================

/**
 * Mint a one-time connect token for the Profile "Connect" deep link.
 * Invalidates the user's previous unused tokens for that channel.
 */
function channelMintLinkToken(int $userId, string $channel): string
{
    db()->query(
        "UPDATE channel_link_tokens SET used_at = NOW() WHERE user_id = ? AND channel = ? AND used_at IS NULL",
        [$userId, $channel]
    );

    $ttl = defined('CHANNEL_LINK_TOKEN_TTL_MINUTES') ? (int) CHANNEL_LINK_TOKEN_TTL_MINUTES : 30;
    $token = bin2hex(random_bytes(24));
    db()->insert('channel_link_tokens', [
        'token'      => $token,
        'user_id'    => $userId,
        'channel'    => $channel,
        'expires_at' => date(DATETIME_FORMAT, time() + $ttl * 60),
        'created_at' => date(DATETIME_FORMAT),
    ]);
    auditLog('channel_link_token_minted', 'user', $userId, null, ['channel' => $channel]);
    return $token;
}

/**
 * Redeem a connect token with a chat id (webhook /start payload or the
 * System Control getUpdates poller). Returns [ok, message].
 *
 * @return array{0:bool,1:string}
 */
function channelRedeemLinkToken(string $channel, string $token, string $chatId, ?string $displayName = null): array
{
    $token = trim($token);
    $chatId = trim($chatId);
    if ($token === '' || $chatId === '') {
        return [false, 'Missing token or chat id.'];
    }

    $row = db()->fetch(
        "SELECT * FROM channel_link_tokens
         WHERE token = ? AND channel = ? AND used_at IS NULL AND expires_at > NOW()
         LIMIT 1",
        [$token, $channel]
    );
    if (!$row) {
        return [false, 'This connect code is invalid or expired. Open the app and generate a new one.'];
    }

    $now = date(DATETIME_FORMAT);
    $existing = channelGetBinding((int) $row->user_id, $channel);
    if ($existing) {
        db()->update('user_channel_bindings', [
            'chat_id'       => $chatId,
            'display_name'  => $displayName ?: $existing->display_name,
            'linked_via'    => 'self',
            'linked_at'     => $now,
            'updated_at'    => $now,
        ], 'id = ?', [(int) $existing->id]);
    } else {
        db()->insert('user_channel_bindings', [
            'user_id'      => (int) $row->user_id,
            'channel'      => $channel,
            'chat_id'      => $chatId,
            'display_name' => $displayName,
            'linked_via'   => 'self',
            'linked_at'    => $now,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);
    }

    db()->update('channel_link_tokens', ['used_at' => $now], 'id = ?', [(int) $row->id]);
    auditLog('channel_linked', 'user', (int) $row->user_id, null, ['channel' => $channel, 'via' => 'self']);
    return [true, 'connected'];
}

/**
 * Redeem every pending /start {token} payload from a Telegram getUpdates
 * batch (used by the System Control poller when no public webhook exists).
 *
 * @param list<object> $updates Telegram update objects (already decoded)
 * @return int number of updates processed into outcomes
 */
function channelRedeemTelegramUpdates(array $updates): int
{
    $count = 0;
    foreach ($updates as $update) {
        $message = $update->message ?? ($update->edited_message ?? null);
        if (!$message || !isset($message->text) || !isset($message->chat->id)) {
            continue;
        }
        $text = trim((string) $message->text);
        $chatId = (string) $message->chat->id;
        $from = $message->from ?? null;
        $name = $from ? trim(($from->first_name ?? '') . ' ' . ($from->last_name ?? '')) : null;

        if (preg_match('#^/start(?:\s+([A-Za-z0-9]+))?#', $text, $m)) {
            $count++;
            if (!empty($m[1])) {
                channelRedeemLinkToken('telegram', $m[1], $chatId, $name !== '' ? $name : null);
            }
        } elseif (strcasecmp($text, '/stop') === 0 || strcasecmp($text, '/disconnect') === 0) {
            $count++;
            $binding = channelUserByChatId('telegram', $chatId);
            if ($binding) {
                channelClearBinding((int) $binding->user_id, 'telegram');
            }
        }
    }
    return $count;
}

// =============================================================================
// OUTBOUND
// =============================================================================

/**
 * Family-tagged short text for messenger channels — same spirit as
 * buildSmsMessage(), reusing the Plan #32 family tags ([Vehicle] etc.).
 */
function channelBuildMessage(
    string $channel,
    string $eventType,
    string $title,
    string $message,
    ?string $link = null,
    ?int $requestId = null
): string {
    $max = (int) channelConfig($channel, $channel . '_max_length', '900');
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
            $body .= ': ' . $shortMsg;
        }
    }

    if (function_exists('notificationFamily') && function_exists('notificationTheme')) {
        $tag = notificationTheme(notificationFamily($eventType))['smsTag'] ?? '';
        if ($tag !== '' && !str_starts_with($body, $tag)) {
            $body = $tag . ' ' . $body;
        }
    }

    $text = $header . ' — ' . $body;

    if ($link) {
        $abs = $link;
        if (str_starts_with($link, '/')) {
            $abs = rtrim(APP_URL, '/') . $link;
        } elseif (!preg_match('#^https?://#i', $link)) {
            $abs = rtrim(APP_URL, '/') . '/' . ltrim($link, '/');
        }
        $text .= "\n" . $abs;
    }

    $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
    if (mb_strlen($text) > $max) {
        $text = mb_substr($text, 0, $max - 1) . '…';
    }

    return $text;
}

/**
 * Soft-fail enqueue for one user + channel (called from notify()).
 * Skips silently — no binding, disabled channel, or non-allowed event means
 * no queue row at all (never blast unlinked users).
 */
function channelNotifyUser(
    int $userId,
    string $channel,
    string $eventType,
    string $title,
    string $message,
    ?string $link = null,
    ?int $requestId = null
): void {
    if (!in_array($channel, LOKA_CHANNELS, true)) {
        return;
    }
    if (!channelEnabled($channel) || !channelEventAllowed($channel, $eventType)) {
        return;
    }
    if (!channelGetBinding($userId, $channel)) {
        return;
    }

    try {
        (new ChannelQueue())->queueForUser($userId, $channel, $eventType, $title, $message, $link, $requestId);
    } catch (Throwable $e) {
        error_log('channelNotifyUser: ' . $e->getMessage());
    }
}
