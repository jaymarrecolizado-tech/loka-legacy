<?php
/**
 * LOKA - Messenger channel notification defaults (Plan #35)
 *
 * Telegram: soft-fail extra beside in-app / email / SMS. The bot token lives
 * in System Control settings (DB `settings`, category telegram) with optional
 * .env overrides — never committed.
 *
 * (Viber Phase B was removed 2026-09-29: Viber only issues bots on paid
 * commercial terms, so LOKA ships Telegram-only.)
 */

/** Supported messenger channels. */
define('LOKA_CHANNELS', ['telegram']);

define('CHANNEL_DEFAULTS', [
    'telegram' => [
        'label'          => 'Telegram',
        'icon'           => 'bi-telegram',
        'max_length'     => 3500,
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
]);

/** Public webhook route (no login; Telegram calls this). */
define('TELEGRAM_WEBHOOK_PATH', '/?page=channels&action=telegram-webhook');

/** Link tokens: one-time, short TTL for the Profile "Connect" deep link. */
define('CHANNEL_LINK_TOKEN_TTL_MINUTES', 30);
