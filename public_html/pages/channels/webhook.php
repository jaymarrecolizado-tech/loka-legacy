<?php
/**
 * LOKA - Messenger webhook receiver (Plan #35)
 *
 * Public endpoints (no login; Telegram/Viber call these):
 *   POST /?page=channels&action=telegram-webhook
 *   POST /?page=channels&action=viber-webhook[&key=SECRET]
 *
 * Redeems one-time Profile connect codes (deep-link `/start {token}`) into
 * user_channel_bindings and handles /stop / /disconnect unbinds. Queue-only
 * elsewhere — this endpoint never sends batch traffic, only single chat
 * confirmations through the channel gateway.
 */

require_once BASE_PATH . '/config/channels.php';
require_once BASE_PATH . '/includes/functions.php';
require_once BASE_PATH . '/includes/view_as.php';
require_once BASE_PATH . '/includes/sms.php';
require_once BASE_PATH . '/includes/channels.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

$action = (string) (get('action', '') ?? '');

function channelWebhookReply(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!in_array($action, ['telegram-webhook', 'viber-webhook'], true)) {
    channelWebhookReply(['ok' => false, 'description' => 'Unknown webhook'], 404);
}

$raw = file_get_contents('php://input');
$update = json_decode((string) $raw);
if (!is_object($update)) {
    channelWebhookReply(['ok' => false, 'description' => 'Invalid payload'], 400);
}

try {
    if ($action === 'telegram-webhook') {
        // Optional secret header set when creating the webhook via Bot API.
        $secret = trim(channelConfig('telegram', 'telegram_webhook_secret'));
        if ($secret !== '') {
            $given = (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
            if (!hash_equals($secret, $given)) {
                channelWebhookReply(['ok' => false, 'description' => 'Forbidden'], 403);
            }
        }

        $message = $update->message ?? ($update->edited_message ?? null);
        if (!$message || !isset($message->chat->id)) {
            channelWebhookReply(['ok' => true]);
        }

        $chatId = (string) $message->chat->id;
        $text = trim((string) ($message->text ?? ''));
        $from = $message->from ?? null;
        $name = $from ? trim(($from->first_name ?? '') . ' ' . ($from->last_name ?? '')) : null;

        $gateway = TelegramGateway::fromConfig(); // confirmation sender (may be null if token missing)

        $reply = function (string $text) use ($gateway, $chatId): void {
            if ($gateway) {
                $gateway->send($chatId, $text);
            }
        };

        if (preg_match('#^/start(?:\s+([A-Za-z0-9]+))?#i', $text, $m)) {
            if (empty($m[1])) {
                $reply("Welcome to LOKA Fleet alerts. Open your LOKA profile and press Connect Telegram, then send that link's code with /start.");
                channelWebhookReply(['ok' => true]);
            }
            [$ok, $msg] = channelRedeemLinkToken('telegram', $m[1], $chatId, $name !== '' ? $name : null);
            $reply($ok
                ? "Connected. LOKA Fleet alerts will arrive here. Send /stop to disconnect."
                : $msg);
            channelWebhookReply(['ok' => true]);
        }

        if (strcasecmp($text, '/stop') === 0 || strcasecmp($text, '/disconnect') === 0) {
            $binding = channelUserByChatId('telegram', $chatId);
            if ($binding) {
                channelClearBinding((int) $binding->user_id, 'telegram');
                $reply('Disconnected from LOKA Fleet alerts.');
            } else {
                $reply('This chat is not linked to a LOKA account.');
            }
            channelWebhookReply(['ok' => true]);
        }

        channelWebhookReply(['ok' => true]);
    }

    // ---- Viber ----
    $secret = trim(channelConfig('viber', 'viber_webhook_secret'));
    if ($secret !== '') {
        $given = (string) (get('key', '') ?? '');
        if (!hash_equals($secret, $given)) {
            channelWebhookReply(['status' => 3, 'status_message' => 'Forbidden'], 403);
        }
    }

    $event = (string) ($update->event ?? '');
    if ($event === 'conversation_started') {
        // Viber allows one welcome message here; nudge the user to /start.
        channelWebhookReply([
            'status' => 0,
            'sender' => ['name' => (string) channelConfig('viber', 'viber_sender_name', 'LOKA Fleet')],
            'message' => ['type' => 'text', 'text' => 'Welcome! Send /start to link your LOKA Fleet account.'],
        ]);
    }

    if (in_array($event, ['message', 'subscribed'], true) && isset($update->message->text, $update->sender->id)) {
        $chatId = (string) $update->sender->id;
        $text = trim((string) $update->message->text);
        $name = $update->sender->name ?? null;

        if (preg_match('#^/start(?:\s+([A-Za-z0-9]+))?#i', $text, $m)) {
            if (empty($m[1])) {
                channelWebhookReply(['status' => 0]);
            }
            [$ok, $msg] = channelRedeemLinkToken('viber', $m[1], $chatId, is_string($name) ? $name : null);
            channelWebhookReply(['status' => 0, 'message' => $ok ? 'connected' : $msg]);
        }

        if (strcasecmp($text, '/stop') === 0 || strcasecmp($text, '/disconnect') === 0) {
            $binding = channelUserByChatId('viber', $chatId);
            if ($binding) {
                channelClearBinding((int) $binding->user_id, 'viber');
            }
            channelWebhookReply(['status' => 0]);
        }
    }

    channelWebhookReply(['status' => 0]);
} catch (Throwable $e) {
    error_log('channel webhook: ' . $e->getMessage());
    channelWebhookReply(['ok' => false, 'description' => 'Webhook error'], 500);
}
