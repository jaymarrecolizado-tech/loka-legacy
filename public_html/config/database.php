<?php
/**
 * LOKA - Database Configuration
 * 
 * This file loads database credentials from environment variables.
 * It will first check $_ENV/getenv(), then attempt to load from .env file if needed.
 */

/**
 * Load environment variables from .env file if not already loaded
 * This ensures compatibility with both systems that have env vars pre-loaded
 * and those that need to read from the .env file directly.
 */
function loadEnvFile($envFilePath)
{
    if (!file_exists($envFilePath)) {
        return false;
    }
    
    $lines = file($envFilePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return false;
    }
    
    foreach ($lines as $line) {
        // Skip comments and empty lines
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) {
            continue;
        }
        
        // Parse KEY=VALUE format
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            // Remove quotes if present
            if ((strpos($value, '"') === 0 && strrpos($value, '"') === strlen($value) - 1) ||
                (strpos($value, "'") === 0 && strrpos($value, "'") === strlen($value) - 1)) {
                $value = substr($value, 1, -1);
            }
            
            // Always set from .env - this file is the source of truth
            // Hosting panels may pre-set env vars that conflict with .env values
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
    
    return true;
}

// Determine if we're in production mode
$isProduction = (getenv('APP_ENV') === 'production');

// ALWAYS load the .env file so the app's config is the source of truth.
// Hosting panels (CloudPanel, Hostinger, cPanel) may pre-set DB vars via the
// web server environment, but those vars are NOT injected into CLI/cron processes.
// Loading .env unconditionally guarantees SMTP, APP_URL, and all other vars are
// available in BOTH web and cron contexts, regardless of the hosting panel config.
$envFile = dirname(__DIR__) . '/.env';
// Nginx/CloudPanel may serve webroot files; keep secrets outside docroot as ../.env.lokastage when needed
if (!file_exists($envFile)) {
    $envFile = dirname(__DIR__) . '/../.env.lokastage';
}
if (file_exists($envFile)) {
    loadEnvFile($envFile);
} else {
    error_log('WARNING: .env file not found at: ' . $envFile);
}

/**
 * Helper function to get environment variable with fallback
 * Checks multiple possible variable names and provides default for development
 */
function getEnvVar($names, $default = null, $requiredInProduction = false)
{
    global $isProduction;
    
    // Support single name or array of names
    if (!is_array($names)) {
        $names = [$names];
    }
    
    foreach ($names as $name) {
        // Check $_ENV first, then getenv()
        if (isset($_ENV[$name])) {
            return $_ENV[$name];
        }
        
        $value = getenv($name);
        if ($value !== false) {
            return $value;
        }
    }
    
    // If not found and required in production, die with error
    if ($requiredInProduction && $isProduction) {
        $varNames = implode(' or ', $names);
        die("ERROR: {$varNames} environment variable required in production");
    }
    
    // Return default (or empty string if no default specified and in dev mode)
    return $default !== null ? $default : '';
}

// Define database constants using environment variables
// Supports both naming conventions: Laravel-style (DB_DATABASE, DB_USERNAME, DB_PASSWORD) 
// and traditional (DB_NAME, DB_USER, DB_PASS)
define('DB_HOST', getEnvVar('DB_HOST', 'localhost', true));
define('DB_NAME', getEnvVar(['DB_NAME', 'DB_DATABASE'], 'fleet_management', true));
define('DB_USER', getEnvVar(['DB_USER', 'DB_USERNAME'], 'root', true));
define('DB_PASS', getEnvVar(['DB_PASS', 'DB_PASSWORD'], '', true));
define('DB_CHARSET', 'utf8mb4');

define('DB_OPTIONS', [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
    PDO::ATTR_EMULATE_PREPARES   => false,
    @PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
]);
