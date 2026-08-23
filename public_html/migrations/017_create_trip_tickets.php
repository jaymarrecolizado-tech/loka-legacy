<?php
/**
 * LOKA - Create Trip Ticket Migration
 *
 * Adds trip_tickets table for recording trip completion details
 */

// Load environment variables from .env file
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
                case 'DB_HOST':
                    $dbHost = $value;
                    break;
                case 'DB_NAME':
                    $dbName = $value;
                    break;
                case 'DB_USER':
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

echo "=== Migration: Create Trip Tickets Table ===\n\n";

try {
    $pdo = new PDO(
        sprintf("mysql:host=%s;dbname=%s;charset=%s", $dbHost, $dbName, $dbCharset),
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    echo "Connected to database: " . $dbName . "\n\n";

    // Create trip_tickets table
    $sql = "CREATE TABLE IF NOT EXISTS trip_tickets (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        request_id INT UNSIGNED NOT NULL,
        driver_id INT UNSIGNED NOT NULL,
        trip_type ENUM('official', 'personal', 'maintenance', 'other') NOT NULL DEFAULT 'official',

        -- Trip Details
        start_date DATETIME NOT NULL COMMENT 'Actual trip start time',
        end_date DATETIME NOT NULL COMMENT 'Actual trip end time',
        destination TEXT NOT NULL COMMENT 'Trip destination',
        purpose TEXT COMMENT 'Purpose of trip',
        passengers INT UNSIGNED DEFAULT 0 COMMENT 'Number of passengers',

        -- Mileage
        start_mileage INT UNSIGNED DEFAULT NULL COMMENT 'Odometer reading at start',
        end_mileage INT UNSIGNED DEFAULT NULL COMMENT 'Odometer reading at end',
        distance_traveled INT UNSIGNED DEFAULT NULL COMMENT 'Total distance in km',

        -- Fuel
        fuel_consumed DECIMAL(10,2) UNSIGNED DEFAULT NULL COMMENT 'Fuel consumed in liters',
        fuel_cost DECIMAL(10,2) UNSIGNED DEFAULT NULL COMMENT 'Fuel cost in PHP',

        -- Documents (stored as file paths)
        travel_order_path VARCHAR(255) DEFAULT NULL COMMENT 'Path to travel order document',
        ob_slip_path VARCHAR(255) DEFAULT NULL COMMENT 'Path to OB slip document',
        other_documents_path VARCHAR(255) DEFAULT NULL COMMENT 'Path to other documents',

        -- Issues/Incidents
        has_issues BOOLEAN DEFAULT FALSE COMMENT 'Were there any issues?',
        issues_description TEXT COMMENT 'Description of issues',
        resolved BOOLEAN DEFAULT FALSE COMMENT 'Have issues been resolved?',
        resolution_notes TEXT COMMENT 'Notes on resolution',

        -- Guard Verification
        dispatch_guard_id INT UNSIGNED NOT NULL COMMENT 'Guard who dispatched',
        arrival_guard_id INT UNSIGNED NOT NULL COMMENT 'Guard who verified arrival',
        guard_notes TEXT COMMENT 'Additional notes from guard',

        -- Status
        status ENUM('draft', 'submitted', 'reviewed', 'approved') NOT NULL DEFAULT 'draft',
        reviewed_by INT UNSIGNED DEFAULT NULL COMMENT 'User ID who reviewed',
        reviewed_at DATETIME DEFAULT NULL,

        -- Audit
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        created_by INT UNSIGNED NOT NULL COMMENT 'User ID who created',

        -- Foreign Keys
        FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
        FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE CASCADE,
        FOREIGN KEY (dispatch_guard_id) REFERENCES users(id) ON DELETE SET NULL,
        FOREIGN KEY (arrival_guard_id) REFERENCES users(id) ON DELETE SET NULL,
        FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,

        -- Indexes
        INDEX idx_request (request_id),
        INDEX idx_driver (driver_id),
        INDEX idx_status (status),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    COMMENT='Stores trip completion tickets with details, documents, and issues'";

    $pdo->exec($sql);
    echo "✓ Created trip_tickets table\n";

    // Add trip_ticket_id to requests table (link to latest ticket)
    $sql = "ALTER TABLE requests
    ADD COLUMN IF NOT EXISTS trip_ticket_id INT UNSIGNED DEFAULT NULL
    COMMENT 'ID of trip completion ticket'
    AFTER arrival_guard_id";

    try {
        $pdo->exec($sql);
        echo "✓ Added trip_ticket_id column to requests table\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            throw $e;
        }
        echo "⚠ Column trip_ticket_id already exists\n";
    }

    // Add index for trip_ticket_id
    $sql = "ALTER TABLE requests
    ADD INDEX IF NOT EXISTS idx_trip_ticket (trip_ticket_id)";

    try {
        $pdo->exec($sql);
        echo "✓ Added index on trip_ticket_id\n";
    } catch (PDOException $e) {
        echo "⚠ Index may already exist\n";
    }

    echo "\n" . str_repeat('=', 50) . "\n";
    echo "MIGRATION COMPLETE\n";
    echo str_repeat('=', 50) . "\n\n";

    echo "Next steps:\n";
    echo "1. Add 'trip_tickets' to ALLOWED_TABLES in Database.php\n";
    echo "2. Restart server if needed\n";
    echo "3. Test trip ticket creation at: /?page=trip-tickets&action=create_form&request_id=X\n\n";

} catch (PDOException $e) {
    echo "\n✗ ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
