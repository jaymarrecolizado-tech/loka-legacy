<?php
/**
 * LOKA - Security Configuration
 * 
 * Centralized security settings for the application
 */

// =============================================================================
// TIMEZONE CONFIGURATION
// =============================================================================

date_default_timezone_set('Asia/Manila');

// =============================================================================
// ENVIRONMENT DETECTION
// =============================================================================

// Set to 'production' in production environment
define('APP_ENV', getenv('APP_ENV') ?: 'development');
define('IS_PRODUCTION', APP_ENV === 'production');
define('IS_HTTPS', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443));

// =============================================================================
// RATE LIMITING
// =============================================================================

define('RATE_LIMIT_LOGIN_ATTEMPTS', 5);           // Max login attempts
define('RATE_LIMIT_LOGIN_WINDOW', 900);           // 15 minutes lockout window
define('RATE_LIMIT_LOGIN_LOCKOUT', 1800);         // 30 minutes lockout duration

define('RATE_LIMIT_PASSWORD_ATTEMPTS', 3);        // Max password change attempts
define('RATE_LIMIT_PASSWORD_WINDOW', 3600);       // 1 hour window

define('RATE_LIMIT_API_REQUESTS', 100);           // Max API requests per window
define('RATE_LIMIT_API_WINDOW', 60);              // 1 minute window

// =============================================================================
// PASSWORD POLICY
// =============================================================================

define('PASSWORD_MIN_LENGTH', 8);
define('PASSWORD_REQUIRE_UPPERCASE', true);
define('PASSWORD_REQUIRE_LOWERCASE', true);
define('PASSWORD_REQUIRE_NUMBER', true);
define('PASSWORD_REQUIRE_SPECIAL', false);        // Special chars optional
define('PASSWORD_SPECIAL_CHARS', '!@#$%^&*()_+-=[]{}|;:,.<>?');

// =============================================================================
// SESSION SECURITY
// =============================================================================

define('SESSION_REGENERATE_INTERVAL', 1800);      // Regenerate ID every 30 min
define('SESSION_ABSOLUTE_TIMEOUT', 28800);        // 8 hours absolute max
define('SESSION_FINGERPRINT_ENABLED', true);      // Track browser fingerprint
define('SESSION_IP_BINDING', false);              // Disable for mobile users

// =============================================================================
// CSRF SETTINGS
// =============================================================================

define('CSRF_TOKEN_LIFETIME', 7200);              // 2 hours token lifetime
define('CSRF_ROTATE_ON_USE', false);              // Rotate after each use (can break back button)

// =============================================================================
// COOKIE SETTINGS
// =============================================================================

define('COOKIE_SECURE', IS_HTTPS);                // Only send over HTTPS
define('COOKIE_HTTPONLY', true);                  // No JS access
define('COOKIE_SAMESITE', 'Lax');                 // CSRF protection
define('COOKIE_PATH', '/');
define('COOKIE_DOMAIN', '');                      // Current domain only

// =============================================================================
// SECURITY HEADERS
// =============================================================================

define('SECURITY_HEADERS', [
    'X-Content-Type-Options' => 'nosniff',
    'X-Frame-Options' => 'DENY',
    'X-XSS-Protection' => '1; mode=block',
    'Referrer-Policy' => 'strict-origin-when-cross-origin',
    'Permissions-Policy' => 'geolocation=(), microphone=(), camera=(self)',
    'Cache-Control' => 'no-store, no-cache, must-revalidate, proxy-revalidate',
    'Pragma' => 'no-cache',
]);

// Content Security Policy (adjust for your CDN domains)
define('CSP_POLICY', implode('; ', [
    "default-src 'self'",
    "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://code.jquery.com https://cdn.datatables.net",
    "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdn.datatables.net https://fonts.googleapis.com",
    "font-src 'self' https://cdn.jsdelivr.net https://fonts.gstatic.com",
    "img-src 'self' data: https:",
    "connect-src 'self' https://cdn.jsdelivr.net",
    "frame-ancestors 'none'",
    "form-action 'self'",
    "base-uri 'self'"
]));

// HSTS settings (enable in production with HTTPS)
define('HSTS_ENABLED', IS_PRODUCTION && IS_HTTPS);
define('HSTS_MAX_AGE', 31536000);                  // 1 year
define('HSTS_INCLUDE_SUBDOMAINS', true);

// =============================================================================
// INPUT VALIDATION
// =============================================================================

define('MAX_INPUT_LENGTH', 10000);                // Max input field length
define('MAX_FILE_SIZE', 5 * 1024 * 1024);         // 5MB max upload
define('ALLOWED_FILE_TYPES', ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx']);

// =============================================================================
// AUDIT & LOGGING
// =============================================================================

define('LOG_FAILED_LOGINS', true);
define('LOG_SUCCESSFUL_LOGINS', true);
define('LOG_PASSWORD_CHANGES', true);
define('LOG_PERMISSION_DENIALS', true);
define('LOG_RATE_LIMIT_HITS', true);

// =============================================================================
// IP WHITELIST/BLACKLIST (optional)
// =============================================================================

define('IP_WHITELIST_ENABLED', false);
define('IP_WHITELIST', [
    // Add trusted IPs here
]);

define('IP_BLACKLIST_ENABLED', false);
define('IP_BLACKLIST', [
    // Add blocked IPs here
]);
