<?php
/**
 * Plan #43 — OIDC client helpers for LOKA (no composer deps).
 *
 * Authorization-code flow with PKCE S256 + state + nonce against the central
 * SSO service. The id_token signature is verified against the SSO JWKS with
 * openssl — RS256 only, exactly like the service signs it (joserfc).
 *
 * This file must only be loaded when config/sso.php is already in scope.
 */

if (!defined('SSO_ENABLED')) {
    throw new RuntimeException('config/sso.php must be loaded before includes/sso_client.php');
}

/**
 * Build the /authorize URL and stash PKCE/state/nonce in the session.
 */
function ssoAuthorizeUrl(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    $_SESSION['sso_verifier'] = $verifier;
    $_SESSION['sso_state'] = bin2hex(random_bytes(16));
    $_SESSION['sso_nonce'] = bin2hex(random_bytes(16));
    $_SESSION['sso_started_at'] = time();

    $params = http_build_query([
        'response_type' => 'code',
        'client_id' => SSO_CLIENT_ID,
        'redirect_uri' => SSO_REDIRECT_URI,
        'scope' => SSO_SCOPE,
        'state' => $_SESSION['sso_state'],
        'nonce' => $_SESSION['sso_nonce'],
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ]);
    return SSO_BASE_URL . '/authorize?' . $params;
}

/**
 * Exchange the authorization code for tokens and return verified claims,
 * or an error array. Validates: client auth result, PKCE (server side),
 * id_token signature (JWKS), iss, aud, exp, and the nonce we sent.
 *
 * @return array{ok:bool, error:string, claims:array}
 */
function ssoExchangeCode(string $code): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $state = (string) ($_GET['state'] ?? '');
    if ($state === '' || !hash_equals((string) ($_SESSION['sso_state'] ?? ''), $state)) {
        return ['ok' => false, 'error' => 'SSO state mismatch — restart the login.', 'claims' => []];
    }
    if (time() - (int) ($_SESSION['sso_started_at'] ?? 0) > 600) {
        return ['ok' => false, 'error' => 'SSO login took too long — start again.', 'claims' => []];
    }

    $tokenResponse = ssoHttpPost(SSO_BASE_URL . '/token', [
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => SSO_REDIRECT_URI,
        'code_verifier' => (string) ($_SESSION['sso_verifier'] ?? ''),
    ], SSO_CLIENT_ID . ':' . SSO_CLIENT_SECRET);
    if (!$tokenResponse['ok']) {
        return ['ok' => false, 'error' => 'SSO token exchange failed (' . $tokenResponse['http'] . ').', 'claims' => []];
    }
    $tokens = json_decode($tokenResponse['body'], true);
    $idToken = (string) ($tokens['id_token'] ?? '');
    if ($idToken === '') {
        return ['ok' => false, 'error' => 'SSO did not return an id_token.', 'claims' => []];
    }

    $claims = ssoVerifyIdToken($idToken);
    if ($claims === null) {
        return ['ok' => false, 'error' => 'SSO id_token failed verification.', 'claims' => []];
    }
    if (!hash_equals((string) ($_SESSION['sso_nonce'] ?? ''), (string) ($claims['nonce'] ?? ''))) {
        return ['ok' => false, 'error' => 'SSO nonce mismatch.', 'claims' => []];
    }

    // One-time use: burn the flow values so a replayed callback can't re-bind.
    unset($_SESSION['sso_verifier'], $_SESSION['sso_state'], $_SESSION['sso_nonce'], $_SESSION['sso_started_at']);
    $_SESSION['sso_login_at'] = date(DATETIME_FORMAT);

    return ['ok' => true, 'error' => '', 'claims' => $claims];
}

/**
 * Fetch the active, non-deleted local user for an SSO email.
 */
function ssoFindUserByEmail(string $email): ?object
{
    return db()->fetch(
        "SELECT * FROM users WHERE email = ? AND status = 'active' AND deleted_at IS NULL LIMIT 1",
        [trim(strtolower($email))]
    );
}

// ---------------------------------------------------------------------------
// internals
// ---------------------------------------------------------------------------

function ssoHttpPost(string $url, array $fields, string $basicAuth): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        CURLOPT_USERPWD => $basicAuth,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $body = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['ok' => $body !== false && $http < 400, 'http' => $http, 'body' => (string) $body, 'err' => $err];
}

/**
 * RS256 id_token verification against the SSO JWKS. Returns claims or null.
 */
function ssoVerifyIdToken(string $token): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }
    $b64 = static fn(string $s) => json_decode(base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)), true);
    $header = $b64($parts[0]);
    $payload = $b64($parts[1]);
    $sigBytes = base64_decode(strtr($parts[2], '-_', '+/') . str_repeat('=', (4 - strlen($parts[2]) % 4) % 4));
    if (!is_array($header) || !is_array($payload) || $sigBytes === false) {
        return null;
    }
    if (($header['alg'] ?? '') !== 'RS256') {
        return null;
    }
    $kid = (string) ($header['kid'] ?? '');

    $keyPem = ssoJwkToPem($kid);
    if ($keyPem === null) {
        return null;
    }
    $public = openssl_pkey_get_public($keyPem);
    $signingInput = $parts[0] . '.' . $parts[1];
    if (openssl_verify($signingInput, $sigBytes, $public, OPENSSL_ALGO_SHA256) !== 1) {
        return null;
    }
    $now = time();
    if (($payload['exp'] ?? 0) < $now || ($payload['iat'] ?? $now) > $now + 120) {
        return null;
    }
    if (($payload['iss'] ?? '') !== SSO_BASE_URL || ($payload['aud'] ?? '') !== SSO_CLIENT_ID) {
        return null;
    }
    return $payload;
}

/**
 * Fetch /jwks and convert the matching RSA key to a PEM public key.
 * Cached per request lifetime.
 */
function ssoJwkToPem(string $kid): ?string
{
    static $cache = null;
    if ($cache === null) {
        $ch = curl_init(SSO_BASE_URL . '/jwks');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4]);
        $body = curl_exec($ch);
        curl_close($ch);
        $cache = $body ? (json_decode((string) $body, true)['keys'] ?? []) : [];
    }
    foreach ($cache as $jwk) {
        if (($jwk['kid'] ?? '') !== $kid || ($jwk['kty'] ?? '') !== 'RSA') {
            continue;
        }
        $b64 = static fn(string $s) => base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
        $n = $b64((string) $jwk['n']);
        $e = $b64((string) $jwk['e']);
        if ($n === false || $e === false) {
            return null;
        }
        // DER: SPKI { AlgorithmIdentifier(rsaEncryption, NULL), BIT STRING {
        //   0x00, SEQUENCE { INTEGER modulus, INTEGER publicExponent } } }
        // Structure byte-verified against the private key's own public PEM.
        $encodeLen = static function (int $len) {
            if ($len < 0x80) {
                return chr($len);
            }
            $bytes = ltrim(pack('N', $len), chr(0));
            return chr(0x80 | strlen($bytes)) . $bytes;
        };
        $derInt = static function (string $bytes) use ($encodeLen) {
            if (ord($bytes[0]) > 0x7f) {
                $bytes = chr(0) . $bytes; // keep positive
            }
            return "\x02" . $encodeLen(strlen($bytes)) . $bytes;
        };
        $nInt = $derInt($n);
        $eInt = $derInt($e);
        $rsaKey = "\x30" . $encodeLen(strlen($nInt) + strlen($eInt)) . $nInt . $eInt;
        $algId = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
        $bitString = "\x00" . $rsaKey;
        $spki = $algId . "\x03" . $encodeLen(strlen($bitString)) . $bitString;
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode("\x30" . $encodeLen(strlen($spki)) . $spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
        return $pem;
    }
    return null;
}
