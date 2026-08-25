<?php
/**
 * LOKA - MIGRATION 030: Create Gas Vouchers Table
 *
 * Gas Voucher module for fuel/maintenance supplies requests.
 * Approval workflow: Requester â†’ Reviewer (OIC Motorpool) â†’ Approver (Admin & Finance Chief)
 */

$envFile = __DIR__ . '/../.env';
$dbHost = 'localhost';
$dbName = 'loka_fleet';
$dbUser = 'root';
$dbPass = '';
$dbCharset = 'utf8mb4';

if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $name = trim($parts[0]);
            $value = trim($parts[1]);
            switch ($name) {
                case 'DB_HOST': $dbHost = $value; break;
                case 'DB_NAME': case 'DB_DATABASE': $dbName = $value; break;
                case 'DB_USER': case 'DB_USERNAME': $dbUser = $value; break;
                case 'DB_PASSWORD': $dbPass = $value; break;
                case 'DB_CHARSET': $dbCharset = $value; break;
            }
        }
    }
}

echo "=== MIGRATION 030: Create Gas Vouchers Table ===\n\n";

try {
    $pdo = new PDO(
        sprintf("mysql:host=%s;dbname=%s;charset=%s", $dbHost, $dbName, $dbCharset),
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    echo "Connected to database: " . $dbName . "\n\n";

    // Create gas_vouchers table
    $sql = "CREATE TABLE IF NOT EXISTS gas_vouchers (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

        -- Reference
        voucher_no VARCHAR(20) NOT NULL UNIQUE COMMENT 'e.g. 2026-0311',
        request_date DATE NOT NULL COMMENT 'Date of voucher request',

        -- Requester Info
        requested_by_user_id INT UNSIGNED NOT NULL COMMENT 'User who submitted the request',
        driver_name VARCHAR(100) NOT NULL COMMENT 'Name of driver (bearer)',

        -- Vehicle
        vehicle_plate VARCHAR(30) NOT NULL COMMENT 'Vehicle plate number',
        vehicle_id INT UNSIGNED DEFAULT NULL COMMENT 'FK to vehicles table if matched',

        -- Fuel/Items Details
        fuel_type ENUM('Gasoline', 'Diesel') NOT NULL DEFAULT 'Diesel',
        quantity DECIMAL(10,2) NOT NULL COMMENT 'Qty in liters (or unit)',
        unit VARCHAR(20) NOT NULL DEFAULT 'L' COMMENT 'Unit: L, liters, full tank, etc.',
        other_items TEXT DEFAULT NULL COMMENT 'e.g. engine oil, brake fluid',
        other_qty DECIMAL(10,2) DEFAULT NULL COMMENT 'Qty for other items',
        other_unit VARCHAR(20) DEFAULT NULL COMMENT 'Unit for other items',

        -- Fund & Purpose
        fund_source VARCHAR(100) NOT NULL COMMENT 'e.g. Free WiFi, GASS, ELGU',
        purpose TEXT NOT NULL COMMENT 'Purpose of the fuel request',
        chargeable_against VARCHAR(100) DEFAULT NULL COMMENT 'Budget charged to',

        -- Cost & Payment
        total_cost DECIMAL(12,2) DEFAULT NULL COMMENT 'Total cost in PHP',
        payment_status ENUM('unpaid','paid','cancelled','processed') NOT NULL DEFAULT 'unpaid',
        saro_no VARCHAR(50) DEFAULT NULL COMMENT 'SARO number if applicable',

        -- Approval Workflow
        -- Status: draft â†’ pending_review â†’ pending_approval â†’ approved â†’ rejected â†’ cancelled
        status ENUM('draft','pending_review','pending_approval','approved','rejected','cancelled') NOT NULL DEFAULT 'draft',

        -- Step 1: OIC Motorpool Review
        reviewed_by INT UNSIGNED DEFAULT NULL COMMENT 'User who reviewed (OIC Motorpool)',
        reviewed_at DATETIME DEFAULT NULL,
        reviewer_notes TEXT DEFAULT NULL,

        -- Step 2: Admin & Finance Chief Approval
        approved_by INT UNSIGNED DEFAULT NULL COMMENT 'User who approved (Chief A&F)',
        approved_at DATETIME DEFAULT NULL,
        approver_notes TEXT DEFAULT NULL,

        -- Rejection
        rejected_by INT UNSIGNED DEFAULT NULL,
        rejected_at DATETIME DEFAULT NULL,
        rejection_reason TEXT DEFAULT NULL,

        -- Date tracking
        date_withdrawn DATE DEFAULT NULL COMMENT 'Date fuel was actually withdrawn',

        -- Audit
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        deleted_at DATETIME DEFAULT NULL,

        -- Foreign Keys
        FOREIGN KEY (requested_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
        FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE SET NULL,
        FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
        FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
        FOREIGN KEY (rejected_by) REFERENCES users(id) ON DELETE SET NULL,

        -- Indexes
        INDEX idx_status (status),
        INDEX idx_voucher_no (voucher_no),
        INDEX idx_vehicle_plate (vehicle_plate),
        INDEX idx_requested_by (requested_by_user_id),
        INDEX idx_request_date (request_date),
        INDEX idx_deleted (deleted_at),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    COMMENT='Gas Voucher requests with two-step approval workflow'";

    $pdo->exec($sql);
    echo "âœ“ Created gas_vouchers table\n";

    echo "\n" . str_repeat('=', 50) . "\n";
    echo "MIGRATION 030 COMPLETE\n";
    echo str_repeat('=', 50) . "\n\n";

    echo "Next steps:\n";
    echo "1. Add 'gas_vouchers' to ALLOWED_TABLES in Database.php\n";
    echo "2. Add gas-vouchers routes to index.php\n";
    echo "3. Create pages/gas-vouchers/ directory with page files\n\n";

} catch (PDOException $e) {
    echo "\nâœ— ERROR: " . $e->getMessage() . "\n";
    exit(1);
}



