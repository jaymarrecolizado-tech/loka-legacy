<?php
/**
 * MIGRATION 034: SMS notification queue + default settings
 */

$envFile = __DIR__ . '/../.env';
$dbHost = 'localhost';
$dbName = 'fleetdb';
$dbUser = 'root';
$dbPass = '';
$dbCharset = 'utf8mb4';

if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }
        $name = trim($parts[0]);
        $value = trim($parts[1], " \t\"'");
        if ($name === 'DB_HOST') {
            $dbHost = $value;
        } elseif ($name === 'DB_NAME' || $name === 'DB_DATABASE') {
            $dbName = $value;
        } elseif ($name === 'DB_USER' || $name === 'DB_USERNAME') {
            $dbUser = $value;
        } elseif ($name === 'DB_PASSWORD') {
            $dbPass = $value;
        } elseif ($name === 'DB_CHARSET') {
            $dbCharset = $value;
        }
    }
}

echo "MIGRATION 034: SMS notifications...\n";

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=%s', $dbHost, $dbName, $dbCharset),
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sms_logs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NULL,
            request_id INT UNSIGNED NULL,
            phone VARCHAR(20) NOT NULL,
            event_type VARCHAR(64) NULL,
            message TEXT NOT NULL,
            status ENUM('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
            gateway_message_id VARCHAR(100) NULL,
            gateway_response TEXT NULL,
            error_message TEXT NULL,
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            sent_at DATETIME NULL,
            INDEX idx_sms_status_created (status, created_at),
            INDEX idx_sms_user (user_id),
            INDEX idx_sms_request (request_id),
            INDEX idx_sms_gateway_id (gateway_message_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "OK sms_logs\n";

    $defaults = [
        ['sms_enabled', '0', 'boolean', 'sms'],
        ['sms_gateway_url', '', 'string', 'sms'],
        ['sms_gateway_username', '', 'string', 'sms'],
        ['sms_gateway_password', '', 'string', 'sms'],
        ['sms_api_path', '/message', 'string', 'sms'],
        ['sms_country_code', '63', 'string', 'sms'],
        ['sms_timeout_seconds', '15', 'integer', 'sms'],
        ['sms_max_length', '320', 'integer', 'sms'],
        ['sms_event_allowlist', '*', 'string', 'sms'],
    ];

    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare(
        "INSERT INTO settings (`key`, value, type, category, created_at, updated_at)
         SELECT ?, ?, ?, ?, ?, ?
         FROM DUAL
         WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = ?)"
    );

    foreach ($defaults as [$key, $value, $type, $category]) {
        $stmt->execute([$key, $value, $type, $category, $now, $now, $key]);
        echo "OK setting {$key}\n";
    }

    echo "MIGRATION 034 complete.\n";
} catch (Exception $e) {
    echo 'FAILED: ' . $e->getMessage() . "\n";
    exit(1);
}





