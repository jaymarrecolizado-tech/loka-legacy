<?php
/**
 * Plan #43 — SSO OIDC callback (public page).
 *
 * Route: ?page=sso-callback&code=...&state=...
 * Exchanges the code, verifies the id_token, finds the local user by email,
 * and logs them in with Auth::login() so the existing session/fingerprint/
 * timeout machinery runs unchanged. Failures land back on the login form
 * with a plain-language flash.
 */

require_once INCLUDES_PATH . '/sso_client.php';

if (!isset($_GET['code']) && !isset($_GET['error'])) {
    redirect('/?page=login');
}
if (isset($_GET['error'])) {
    redirectWith('/?page=login', 'danger', 'SSO login was cancelled or failed: ' . e((string) ($_GET['error_description'] ?? $_GET['error'])));
}

$exchange = ssoExchangeCode((string) $_GET['code']);
if (!$exchange['ok']) {
    error_log('SSO callback failed: ' . $exchange['error']);
    redirectWith('/?page=login', 'danger', $exchange['error']);
}

$claims = $exchange['claims'];
$email = trim((string) ($claims['email'] ?? ''));
if ($email === '') {
    redirectWith('/?page=login', 'danger', 'SSO did not return an email address.');
}

$user = ssoFindUserByEmail($email);
if ($user === null) {
    auditLog('sso_login_rejected', 'user', null, null, ['email' => $email, 'reason' => 'no local account']);
    redirectWith('/?page=login', 'danger', 'This email has no LOKA account. Ask an administrator to create one.');
}

$auth = new Auth();
$auth->login($user);
auditLog('sso_login', 'user', (int) $user->id, null, ['email' => $email]);
redirectWith('/?page=dashboard', 'success', 'Welcome back, ' . e($user->name ?: $email) . '!');
