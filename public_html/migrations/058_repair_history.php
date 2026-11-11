<?php
/**
 * MIGRATION 058: Vehicle Repair History + costing + maintenance reminder stamps (Plan #38)
 *
 * Experimental feature, ships DISABLED (`settings.repair_history_enabled = '0'`).
 *
 * - vehicle_repair_entries — one dated repair event per vehicle. Mirrors the
 *   "Motor Vehicle Repair History" workbook shape: Date | Nature of Repair |
 *   purchased items (one entry, many items).
 * - vehicle_repair_items — line items (Description / Unit / Quantity / Price).
 *   NOTE: the workbook's "Price" column is the LINE AMOUNT, not a unit price
 *   (verified against Reference/Repair History/*.xlsx — e.g. SHS 987 row
 *   "assorted orring, pc, 12, 360" totals ₱360 for twelve pieces). The column
 *   is therefore named `amount`, not `unit_price`.
 * - maintenance_requests reminder stamps — the care schedule already had
 *   7d/1d/due/overdue stamps; repair tickets had none (Plan #38 decision 8).
 * - settings: repair_history_enabled (category `experimental`).
 *
 * vehicles.engine_number already exists (shipped long before Plan #38), so
 * decision 9 is a no-op here.
 */

require __DIR__ . '/_load_env.php';
$dbHost = 'localhost';
$dbName = 'old_loka_db';
$dbUser = 'root';
$dbPass = '';
$dbCharset = 'utf8mb4';

if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) continue;
        $name = trim($parts[0]);
        $value = trim($parts[1], " \t\"'");
        if ($name === 'DB_HOST') $dbHost = $value;
        elseif ($name === 'DB_DATABASE' || $name === 'DB_NAME') $dbName = $value;
        elseif ($name === 'DB_USERNAME' || $name === 'DB_USER') $dbUser = $value;
        elseif ($name === 'DB_PASSWORD') $dbPass = $value;
        elseif ($name === 'DB_CHARSET') $dbCharset = $value;
    }
}

echo "=== MIGRATION 058: Vehicle repair history + costing ===\n\n";

try {
    $pdo = new PDO(
        sprintf("mysql:host=%s;dbname=%s;charset=%s", $dbHost, $dbName, $dbCharset),
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // ---- vehicle_repair_entries ----
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS vehicle_repair_entries (
            id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            vehicle_id INT(10) UNSIGNED NOT NULL,
            repair_date DATE NOT NULL,
            nature_of_repair VARCHAR(255) NOT NULL,
            maintenance_request_id INT(10) UNSIGNED NULL DEFAULT NULL COMMENT 'auto source: repair ticket',
            care_schedule_id INT(10) UNSIGNED NULL DEFAULT NULL COMMENT 'auto source: care schedule',
            source ENUM('maintenance','care','import','manual') NOT NULL DEFAULT 'manual',
            total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Roll-up of vehicle_repair_items.amount',
            created_by INT(10) UNSIGNED NULL DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL DEFAULT NULL,
            deleted_at DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_vre_vehicle (vehicle_id, deleted_at, repair_date),
            KEY idx_vre_maint (maintenance_request_id),
            KEY idx_vre_care (care_schedule_id),
            KEY idx_vre_source (source)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        COMMENT='Dated vehicle repair events (Plan #38 repair history)'"
    );
    echo "OK vehicle_repair_entries\n";

    // ---- vehicle_repair_items ----
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS vehicle_repair_items (
            id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            entry_id INT(10) UNSIGNED NOT NULL,
            description VARCHAR(255) NOT NULL,
            unit VARCHAR(30) NULL DEFAULT NULL,
            quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00,
            amount DECIMAL(12,2) NULL DEFAULT NULL COMMENT 'Price as printed = LINE amount',
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_vri_entry (entry_id, sort_order),
            CONSTRAINT fk_vri_entry FOREIGN KEY (entry_id)
                REFERENCES vehicle_repair_entries (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        COMMENT='Repair history line items (Plan #38)'"
    );
    echo "OK vehicle_repair_items\n";

    // ---- maintenance_requests reminder stamps (repair tickets had none) ----
    foreach ([
        'reminded_7d_at'     => "ALTER TABLE maintenance_requests ADD COLUMN reminded_7d_at DATETIME NULL DEFAULT NULL COMMENT 'Repair reminder 7d' AFTER deleted_at",
        'reminded_1d_at'     => "ALTER TABLE maintenance_requests ADD COLUMN reminded_1d_at DATETIME NULL DEFAULT NULL COMMENT 'Repair reminder 1d' AFTER reminded_7d_at",
        'reminded_due_at'    => "ALTER TABLE maintenance_requests ADD COLUMN reminded_due_at DATETIME NULL DEFAULT NULL COMMENT 'Repair reminder due day' AFTER reminded_1d_at",
        'reminded_overdue_on'=> "ALTER TABLE maintenance_requests ADD COLUMN reminded_overdue_on DATE NULL DEFAULT NULL COMMENT 'Last date an overdue repair reminder went out' AFTER reminded_due_at",
    ] as $col => $sql) {
        $has = $pdo->query("SHOW COLUMNS FROM maintenance_requests LIKE '{$col}'")->fetch();
        if (!$has) {
            $pdo->exec($sql);
            echo "OK maintenance_requests.{$col}\n";
        } else {
            echo "OK maintenance_requests.{$col} already exists\n";
        }
    }

    // ---- settings: experimental feature flag, OFF by default ----
    $ins = $pdo->prepare(
        "INSERT INTO settings (`key`, value, description, type, category, created_at, updated_at)
         VALUES ('repair_history_enabled', '0', 'Vehicle Repair History + costing (experimental, Plan #38)', 'boolean', 'experimental', NOW(), NOW())
         ON DUPLICATE KEY UPDATE description = VALUES(description)"
    );
    $ins->execute();
    echo "OK settings.repair_history_enabled = 0 (OFF)\n";

    echo "\nMIGRATION 058 complete.\n";
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
} catch (RuntimeException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}