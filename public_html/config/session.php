<?php
/**
 * LOKA - Session Configuration
 * 
 * Hardened session security settings
 */

// Ensure session save path exists and is writable
$sessionSavePath = ini_get('session.save_path') ?: sys_get_temp_dir();
if (!is_dir($sessionSavePath) || !is_writable($sessionSavePath)) {
    $sessionSavePath = dirname(__DIR__) . '/logs/sessions';
    if (!is_dir($sessionSavePath)) {
        @mkdir($sessionSavePath, 0755, true);
    }
    ini_set('session.save_path', $sessionSavePath);
}

// Session cookie settings
$cookieParams = [
    'lifetime' => 0,
    'path' => COOKIE_PATH,
    'domain' => COOKIE_DOMAIN,
    'secure' => COOKIE_SECURE,
    'httponly' => COOKIE_HTTPONLY,
    'samesite' => COOKIE_SAMESITE
];

if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params($cookieParams);
} else {
    session_set_cookie_params(
        $cookieParams['lifetime'],
        $cookieParams['path'],
        $cookieParams['domain'],
        $cookieParams['secure'],
        $cookieParams['httponly']
    );
}

ini_set('session.use_only_cookies', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.gc_maxlifetime', SESSION_TIMEOUT);
ini_set('session.sid_length', 48);
ini_set('session.sid_bits_per_character', 6);

// Prevent session ID from being passed in URL
ini_set('session.use_trans_sid', 0);

// Set session name (obscure default)
session_name('LOKA_SID');

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Session security checks
$security = Security::getInstance();

// Validate session fingerprint (browser signature)
// Only validate if user is logged in AND fingerprint exists (skip during login process)
if (isset($_SESSION['user_id']) && isset($_SESSION['_fingerprint']) && !$security->validateFingerprint()) {
    // Possible session hijacking - destroy session
    if (LOG_PERMISSION_DENIALS) {
        $security->logSecurityEvent('session_fingerprint_mismatch', 'Session destroyed due to fingerprint mismatch', $_SESSION['user_id'] ?? null);
    }
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    session_id(bin2hex(random_bytes(32)));
    session_start();
    session_regenerate_id(true);
    $_SESSION['_created'] = time();
    $_SESSION['_absolute_start'] = time();
}

// Store fingerprint for new sessions
if (!isset($_SESSION['_fingerprint'])) {
    $security->storeFingerprint();
}

// Regenerate session ID periodically to prevent fixation
if (!isset($_SESSION['_created'])) {
    $_SESSION['_created'] = time();
    $_SESSION['_absolute_start'] = time();
} elseif (time() - $_SESSION['_created'] > SESSION_REGENERATE_INTERVAL) {
    // Regenerate session ID
    session_regenerate_id(true);
    $_SESSION['_created'] = time();
}

// Check absolute session timeout (force re-login after max time)
if (isset($_SESSION['_absolute_start']) && (time() - $_SESSION['_absolute_start'] > SESSION_ABSOLUTE_TIMEOUT)) {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    session_id(bin2hex(random_bytes(32)));
    session_start();
    session_regenerate_id(true);
    $_SESSION['_flash'] = ['type' => 'warning', 'message' => 'Your session has expired. Please log in again.'];
}

// Check idle session timeout
if (isset($_SESSION['_last_activity']) && (time() - $_SESSION['_last_activity'] > SESSION_TIMEOUT)) {
    // Session expired due to inactivity
    $expiredUserId = $_SESSION['user_id'] ?? null;
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    session_id(bin2hex(random_bytes(32)));
    session_start();
    session_regenerate_id(true);
    
    if ($expiredUserId && LOG_SUCCESSFUL_LOGINS) {
        $security->logSecurityEvent('session_timeout', 'Session expired due to inactivity', $expiredUserId);
    }
}

$_SESSION['_last_activity'] = time();
