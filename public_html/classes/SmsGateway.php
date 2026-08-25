<?php
/**
 * LOKA - SMS Gateway HTTP client (capcom6 / sms-gate.app compatible)
 */

class SmsGateway
{
    private string $baseUrl;
    private string $apiPath;
    private string $username;
    private string $password;
    private int $timeout;

    public function __construct(
        string $baseUrl,
        string $username,
        string $password,
        string $apiPath = '/message',
        int $timeout = 15
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiPath = '/' . ltrim($apiPath, '/');
        $this->username = $username;
        $this->password = $password;
        $this->timeout = max(5, $timeout);
    }

    public static function fromConfig(): ?self
    {
        $url = trim(smsConfig('sms_gateway_url'));
        $user = trim(smsConfig('sms_gateway_username'));
        $pass = smsConfig('sms_gateway_password');
        if ($url === '' || $user === '' || $pass === '') {
            return null;
        }

        $apiPath = trim(smsConfig('sms_api_path', SMS_API_PATH_LOCAL));
        $url = self::normalizeGatewayBaseUrl($url, $apiPath);

        // Public cloud always uses the cloud messages path.
        if (stripos($url, 'api.sms-gate.app') !== false) {
            $apiPath = defined('SMS_API_PATH_CLOUD') ? SMS_API_PATH_CLOUD : '/3rdparty/v1/messages';
        } elseif ($apiPath === SMS_API_PATH_PRIVATE && stripos($url, 'sms-gate.app') !== false) {
            $apiPath = defined('SMS_API_PATH_CLOUD') ? SMS_API_PATH_CLOUD : '/3rdparty/v1/messages';
        }

        return new self(
            $url,
            $user,
            $pass,
            $apiPath,
            (int) smsConfig('sms_timeout_seconds', '15')
        );
    }

    /**
     * Normalize user-entered gateway base (handles app copy "api.sms-gate.app:443").
     */
    public static function normalizeGatewayBaseUrl(string $url, string $apiPath = ''): string
    {
        $url = trim($url);
        $url = preg_replace('#/+$#', '', $url) ?? $url;

        // App home screen often shows host:port without scheme.
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }

        // http → https for sms-gate (avoids HTTP 308 Permanent Redirect on POST).
        if (stripos($url, 'sms-gate.app') !== false) {
            $url = preg_replace('#^http://#i', 'https://', $url) ?? $url;
            // Strip default HTTPS port if pasted from app (:443).
            $url = preg_replace('#^(https://[^/:]+):443(?=/|$)#i', '$1', $url) ?? $url;
        }

        // If user pasted a full messages URL as "gateway URL", peel back to origin.
        if (preg_match('#^(https://api\.sms-gate\.app)(/3rdparty/v1/messages/?)$#i', $url, $m)) {
            $url = $m[1];
        }
        if (preg_match('#^(https://api\.sms-gate\.app)/api(/3rdparty/v1/messages/?)$#i', $url, $m)) {
            $url = $m[1];
        }

        return rtrim($url, '/');
    }

    public function endpoint(): string
    {
        return $this->baseUrl . $this->apiPath;
    }

    /**
     * @return array{ok:bool,message_id:?string,response:?string,error:?string,http_code:int}
     */
    public function send(string $phoneE164, string $message): array
    {
        $payload = json_encode([
            'textMessage' => ['text' => $message],
            'phoneNumbers' => [$phoneE164],
        ], JSON_UNESCAPED_UNICODE);

        if ($payload === false) {
            return [
                'ok' => false,
                'message_id' => null,
                'response' => null,
                'error' => 'Failed to encode SMS payload',
                'http_code' => 0,
            ];
        }

        // Keep connect/read short so a dead gateway cannot stall the whole PHP request.
        $connectTimeout = min(3, $this->timeout);
        $readTimeout = min($this->timeout, 15);

        // Prefer hitting the final HTTPS URL directly (POST+308 is unreliable).
        $endpoint = $this->endpoint();
        if (stripos($endpoint, 'http://api.sms-gate.app') === 0) {
            $endpoint = 'https://' . substr($endpoint, strlen('http://'));
        }

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_USERPWD => $this->username . ':' . $this->password,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $readTimeout,
            CURLOPT_CONNECTTIMEOUT => max(5, $connectTimeout),
            CURLOPT_NOSIGNAL => true,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        if ($errno !== 0) {
            return [
                'ok' => false,
                'message_id' => null,
                'response' => is_string($body) ? $body : null,
                'error' => $error !== '' ? $error : 'cURL error ' . $errno,
                'http_code' => $code,
            ];
        }

        $decoded = is_string($body) ? json_decode($body, true) : null;
        $messageId = null;
        if (is_array($decoded)) {
            $messageId = $decoded['id'] ?? $decoded['messageId'] ?? null;
            if (is_string($messageId)) {
                $messageId = substr($messageId, 0, 100);
            } else {
                $messageId = null;
            }
        }

        $ok = $code >= 200 && $code < 300;
        $errDetail = '';
        if (!$ok) {
            $errDetail = 'HTTP ' . $code;
            if (is_string($body) && $body !== '') {
                $errDetail .= ': ' . substr($body, 0, 200);
            }
            $errDetail .= ' @ ' . $finalUrl;
        }
        return [
            'ok' => $ok,
            'message_id' => $messageId,
            'response' => is_string($body) ? substr($body, 0, 4000) : null,
            'error' => $ok ? null : $errDetail,
            'http_code' => $code,
        ];
    }

    /**
     * @return array{ok:bool,error:?string,http_code:int,body:?string}
     */
    public function health(): array
    {
        // Public cloud has no /health; probe the API host instead.
        $isCloud = stripos($this->baseUrl, 'sms-gate.app') !== false;
        $url = $isCloud ? rtrim($this->baseUrl, '/') . '/' : ($this->baseUrl . '/health');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => min(10, $this->timeout),
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_NOBODY => $isCloud,
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            return ['ok' => false, 'error' => $error ?: 'cURL error', 'http_code' => $code, 'body' => null];
        }

        if ($isCloud) {
            // Any HTTP response means the cloud host is reachable from this server.
            return [
                'ok' => $code > 0,
                'error' => $code > 0 ? null : 'No HTTP response from cloud host',
                'http_code' => $code,
                'body' => is_string($body) ? substr($body, 0, 500) : null,
            ];
        }

        return [
            'ok' => $code >= 200 && $code < 300,
            'error' => ($code >= 200 && $code < 300) ? null : ('HTTP ' . $code),
            'http_code' => $code,
            'body' => is_string($body) ? substr($body, 0, 500) : null,
        ];
    }
}
