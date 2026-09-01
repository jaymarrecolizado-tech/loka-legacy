<?php
/**
 * MIGRATION 043: Pre-trip confirmation emails (GRAB-style Proceed / Don't Proceed)
 *
 * Adds:
 *  - trip_confirmations table (one row per confirmation cycle)
 *  - requests.reschedule_requested / requests.reschedule_note
 *  - settings defaults (idempotent)
 */

$envFile = __DIR__ . '/../.env';
$dbHost = 'localhost';
$dbName = 'fleetdb';
$dbUser = 'root';
$dbPass = '';
$dbCharset = 'utf8mb4';

if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $name = trim($parts[0]);
            $value = trim($parts[1], " \t\"'");
            switch ($name) {
                case 'DB_HOST':
                    $dbHost = $value;
                    break;
                case 'DB_NAME': case 'DB_DATABASE':
                    $dbName = $value;
                    break;
                case 'DB_USER': case 'DB_USERNAME':
                    $dbUser = $value;
                    break;
                case 'DB_PASSWORD':
                    $dbPass = $value;
                    break;
                case 'DB_CHARSET':
                    $dbCharset = $value;
                    break;
            }
        }
    }
}

echo "MIGRATION 043: Pre-trip confirmations...\n";

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=%s', $dbHost, $dbName, $dbCharset),
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS trip_confirmations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            request_id INT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL,
            cycle TINYINT UNSIGNED NOT NULL DEFAULT 1,
            status ENUM('pending','confirmed','declined_cancel','declined_reschedule','expired','cancelled') NOT NULL DEFAULT 'pending',
            scheduled_send_at DATETIME NULL,
            sent_at DATETIME NULL,
            deadline_at DATETIME NOT NULL,
            responded_at DATETIME NULL,
            reschedule_note VARCHAR(1000) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uq_token_hash (token_hash),
            UNIQUE KEY uq_request_cycle (request_id, cycle),
            KEY idx_status_send (status, scheduled_send_at),
            KEY idx_deadline (status, deadline_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "OK trip_confirmations\n";

    // Reschedule request flags on requests (idempotent)
    $addColumn = function (string $table, string $column, string $definition) use ($pdo) {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
        );
        $stmt->execute([$table, $column]);
        if ((int) $stmt->fetchColumn() > 0) {
            echo "SKIP {$table}.{$column} (already exists)\n";
            return;
        }
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        echo "OK {$table}.{$column}\n";
    };

    $addColumn('requests', 'reschedule_requested', "TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `overdue_notified_at`");
    $addColumn('requests', 'reschedule_note', "VARCHAR(1000) NULL AFTER `reschedule_requested`");

    // --- Settings defaults (idempotent) ---
    $now = date('Y-m-d H:i:s');
    $defaults = [
        ['trip_confirmation_enabled', '1', 'bool', 'trips'],
        ['trip_confirmation_lead_hours', '24', 'int', 'trips'],
        ['trip_confirmation_same_day_lead_minutes', '60', 'int', 'trips'],
        ['trip_confirmation_window_minutes', '60', 'int', 'trips'],
    ];

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

    echo "MIGRATION 043 complete.\n";
} catch (Exception $e) {
    echo 'FAILED: ' . $e->getMessage() . "\n";
    exit(1);
}
