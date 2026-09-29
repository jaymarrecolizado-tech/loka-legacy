<?php
/**
 * LOKA - Messenger channel notification defaults (Plan #35)
 *
 * Telegram (Phase A) + Viber (Phase B): soft-fail extras beside in-app /
 * email / SMS. Bot tokens live in System Control settings (DB `settings`,
 * category telegram / viber) with optional .env overrides — never committed.
 */

/** Supported messenger channels. */
define('LOKA_CHANNELS', ['telegram', 'viber']);

define('CHANNEL_DEFAULTS', [
    'telegram' => [
        'label'          => 'Telegram',
        'icon'           => 'bi-telegram',
        'max_length'     => 3500,
        'default_timeout' => 15,
    ],
    'viber' => [
        'label'          => 'Viber',
        'icon'           => 'bi-chat-dots',
        'max_length'     => 900,
        'default_timeout' => 15,
    ],
]);

/** .env fallbacks read by channelConfig() when the DB setting is empty. */
define('CHANNEL_ENV_MAP', [
    'telegram_enabled'          => 'TELEGRAM_ENABLED',
    'telegram_bot_token'        => 'TELEGRAM_BOT_TOKEN',
    'telegram_bot_username'     => 'TELEGRAM_BOT_USERNAME',
    'telegram_webhook_secret'   => 'TELEGRAM_WEBHOOK_SECRET',
    'telegram_event_allowlist'  => 'TELEGRAM_EVENT_ALLOWLIST',
    'telegram_max_length'       => 'TELEGRAM_MAX_LENGTH',
    'telegram_timeout'          => 'TELEGRAM_TIMEOUT_SECONDS',
    'viber_enabled'             => 'VIBER_ENABLED',
    'viber_auth_token'          => 'VIBER_AUTH_TOKEN',
    'viber_sender_name'         => 'VIBER_SENDER_NAME',
    'viber_webhook_secret'      => 'VIBER_WEBHOOK_SECRET',
    'viber_event_allowlist'     => 'VIBER_EVENT_ALLOWLIST',
    'viber_max_length'          => 'VIBER_MAX_LENGTH',
    'viber_timeout'             => 'VIBER_TIMEOUT_SECONDS',
]);

/** Public webhook routes (no login; Telegram/Viber call these). */
define('TELEGRAM_WEBHOOK_PATH', '/?page=channels&action=telegram-webhook');
define('VIBER_WEBHOOK_PATH', '/?page=channels&action=viber-webhook');

/** Link tokens: one-time, short TTL for the Profile "Connect" deep link. */
define('CHANNEL_LINK_TOKEN_TTL_MINUTES', 30);
