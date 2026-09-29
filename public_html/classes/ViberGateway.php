<?php
/**
 * LOKA - Viber Bot / PA gateway (Plan #35 Phase B)
 *
 * Ships disabled until a Viber auth token is stored in System Control.
 */

class ViberGateway
{
    private string $authToken;
    private string $senderName;
    private int $timeout;

    public function __construct(string $authToken, string $senderName = 'LOKA Fleet', int $timeout = 15)
    {
        $this->authToken = $authToken;
        $this->senderName = $senderName !== '' ? $senderName : 'LOKA Fleet';
        $this->timeout = max(5, min(60, $timeout));
    }

    public static function fromConfig(): ?self
    {
        if (!function_exists('channelEnabled') || !channelEnabled('viber')) {
            return null;
        }
        $token = trim(channelConfig('viber', 'viber_auth_token'));
        if ($token === '') {
            return null;
        }
        return new self(
            $token,
            (string) channelConfig('viber', 'viber_sender_name', 'LOKA Fleet'),
            (int) channelConfig('viber', 'viber_timeout', '15')
        );
    }

    private function api(string $method): string
    {
        return 'https://chatapi.viber.com/pa/' . ltrim($method, '/');
    }

    /**
     * POST a Viber PA call. Viber returns {status: 0, ...} on success.
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
                'X-Viber-Auth-Token: ' . $this->authToken,
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
        $viberStatus = is_object($decoded) ? (int) ($decoded->status ?? 1) : 1;
        $ok = $viberStatus === 0 && $code >= 200 && $code < 300;

        $err = null;
        if (!$ok) {
            $err = 'Viber status ' . $viberStatus;
            if (is_object($decoded) && isset($decoded->status_message) && $decoded->status_message !== '') {
                $err .= ': ' . $decoded->status_message;
            }
            $err .= ' (HTTP ' . $code . ')';
        }

        return [
            'ok' => $ok,
            'response' => is_string($body) ? substr($body, 0, 4000) : null,
            'result' => is_object($decoded) ? ($decoded->message_token ?? null) : null,
            'error' => $err,
            'http_code' => $code,
        ];
    }

    /**
     * Send a text message to a Viber member id (the binding's chat id).
     *
     * @return array{ok:bool,message_id:?string,response:?string,error:?string,http_code:int}
     */
    public function send(string $receiverId, string $text): array
    {
        $r = $this->call('send_message', [
            'receiver' => $receiverId,
            'sender' => ['name' => $this->senderName],
            'type' => 'text',
            'text' => $text,
        ]);

        $messageId = null;
        if ($r['ok'] && $r['result'] !== null) {
            $messageId = substr((string) $r['result'], 0, 100);
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
     * Bot account probe for the System Control health button.
     *
     * @return array{ok:bool,error:?string,http_code:int,username:?string}
     */
    public function health(): array
    {
        $r = $this->call('get_account_info', []);
        $username = null;
        if ($r['ok']) {
            $decoded = json_decode((string) ($r['response'] ?? '{}'));
            if (is_object($decoded) && isset($decoded->uri) && $decoded->uri !== '') {
                $username = (string) $decoded->uri;
            }
        }
        return [
            'ok' => $r['ok'],
            'error' => $r['error'],
            'http_code' => $r['http_code'],
            'username' => $username,
        ];
    }
}
