<?php
/**
 * Plan #43 — Central SSO client configuration (LOKA side).
 *
 * Values come from .env with conservative defaults. SSO stays OFF until
 * SSO_ENABLED=1 is set in the environment, which keeps the classic login
 * form as the fallback during rollout (Plan #43 step 5).
 */

$ssoEnv = function (string $key, string $default = ''): string {
    $value = getenv($key);
    if ($value !== false && $value !== '') {
        return trim($value);
    }
    return $default;
};

define('SSO_ENABLED', in_array(strtolower($ssoEnv('SSO_ENABLED', '0')), ['1', 'true', 'yes', 'on'], true));
define('SSO_BASE_URL', rtrim($ssoEnv('SSO_BASE_URL', 'https://sso.dictr2.cloud'), '/'));
define('SSO_CLIENT_ID', $ssoEnv('SSO_CLIENT_ID', ''));
define('SSO_CLIENT_SECRET', $ssoEnv('SSO_CLIENT_SECRET', ''));
// Defaults to the app's own sso-callback route.
define('SSO_REDIRECT_URI', $ssoEnv('SSO_REDIRECT_URI', rtrim(APP_URL, '/') . '/?page=sso-callback'));
define('SSO_SCOPE', 'openid profile email');
unset($ssoEnv);

function ssoEnabled(): bool
{
    return SSO_ENABLED && SSO_BASE_URL !== '' && SSO_CLIENT_ID !== '' && SSO_CLIENT_SECRET !== '';
}
