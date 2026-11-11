<?php
/**
 * LOKA - Telegram Bot API gateway (Plan #35 Phase A)
 */

class TelegramGateway
{
    private string $token;
    private int $timeout;

    public function __construct(string $token, int $timeout = 15)
    {
        $this->token = $token;
        $this->timeout = max(5, min(60, $timeout));
    }

    public static function fromConfig(): ?self
    {
        if (!function_exists('channelEnabled') || !channelEnabled('telegram')) {
            return null;
        }
        $token = trim(channelConfig('telegram', 'telegram_bot_token'));
        if ($token === '') {
            return null;
        }
        return new self($token, (int) channelConfig('telegram', 'telegram_timeout', '15'));
    }

    private function api(string $method): string
    {
        return 'https://api.telegram.org/bot' . rawurlencode($this->token) . '/' . ltrim($method, '/');
    }

    /**
     * POST a Telegram Bot API call, returning the decoded result.
     *
     * @return array{ok:bool,response:?string,result:mixed,error:?string,http_code:int}
     */
    private function call(string $method, array $payload): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return ['ok' => false, 'response' => null, 'result' => null, 'error' => 'Failed to encode payload', 'http_code' => 0];
        }

        $ch = curl_init($this->api($method));
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(5, $this->timeout),
            CURLOPT_NOSIGNAL => true,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            return [
                'ok' => false,
                'response' => is_string($body) ? $body : null,
                'result' => null,
                'error' => $error !== '' ? $error : 'cURL error ' . $errno,
                'http_code' => $code,
            ];
        }

        $decoded = is_string($body) ? json_decode($body) : null;
        $ok = is_object($decoded) && ($decoded->ok ?? false) === true
            && $code >= 200 && $code < 300;

        $err = null;
        if (!$ok) {
            $err = 'HTTP ' . $code;
            if (is_object($decoded) && isset($decoded->description)) {
                $err .= ': ' . $decoded->description;
            } elseif (is_string($body) && $body !== '') {
                $err .= ': ' . substr($body, 0, 200);
            }
        }

        return [
            'ok' => $ok,
            'response' => is_string($body) ? substr($body, 0, 4000) : null,
            'result' => is_object($decoded) ? ($decoded->result ?? null) : null,
            'error' => $err,
            'http_code' => $code,
        ];
    }

    /**
     * Send a text message to a chat.
     *
     * @return array{ok:bool,message_id:?string,response:?string,error:?string,http_code:int}
     */
    public function send(string $chatId, string $text): array
    {
        $r = $this->call('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'disable_web_page_preview' => true,
        ]);

        $messageId = null;
        if ($r['ok'] && is_object($r['result']) && isset($r['result']->message_id)) {
            $messageId = substr((string) $r['result']->message_id, 0, 100);
        }

        return [
            'ok' => $r['ok'],
            'message_id' => $messageId,
            'response' => $r['response'],
            'error' => $r['error'],
            'http_code' => $r['http_code'],
        ];
    }

    /**
     * Fetch recent updates (used to redeem /start {token} link requests when
     * no public webhook is configured). Pass the offset from the last
     * processed update to acknowledge them.
     *
     * @return array{ok:bool,updates:array<object>,error:?string,http_code:int}
     */
    public function getUpdates(int $offset = 0): array
    {
        $payload = ['timeout' => 0, 'allowed_updates' => ['message', 'edited_message']];
        if ($offset > 0) {
            $payload['offset'] = $offset;
        }
        $r = $this->call('getUpdates', $payload);
        $updates = is_array($r['result']) ? $r['result'] : [];
        return [
            'ok' => $r['ok'],
            'updates' => $updates,
            'error' => $r['error'],
            'http_code' => $r['http_code'],
        ];
    }

    /**
     * Bot identity probe for the System Control health button.
     *
     * @return array{ok:bool,error:?string,http_code:int,username:?string}
     */
    public function health(): array
    {
        $r = $this->call('getMe', []);
        $username = null;
        if ($r['ok'] && is_object($r['result']) && isset($r['result']->username)) {
            $username = (string) $r['result']->username;
        }
        return [
            'ok' => $r['ok'],
            'error' => $r['error'],
            'http_code' => $r['http_code'],
            'username' => $username,
        ];
    }
}
