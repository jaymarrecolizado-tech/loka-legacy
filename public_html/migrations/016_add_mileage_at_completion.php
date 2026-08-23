<?php
/**
 * Migration 016: Add mileage_at_completion to maintenance_requests
 *
 * This column is needed to track the vehicle's mileage when maintenance
 * was completed, which is essential for calculating recurring maintenance
 * intervals based on mileage (e.g., oil change every 5000 km).
 */

// Load required files
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../classes/Database.php';

// Get database connection
$db = Database::getInstance();

try {
    // Check if column exists
    $columnExists = false;
    $columns = $db->fetchAll("SHOW COLUMNS FROM maintenance_requests LIKE 'mileage_at_completion'");
    if (!empty($columns)) {
        $columnExists = true;
    }

    if (!$columnExists) {
        // Add the column
        $db->query(
            "ALTER TABLE maintenance_requests
            ADD COLUMN mileage_at_completion INT UNSIGNED NULL
            AFTER odometer_reading"
        );
        echo "SUCCESS: Added mileage_at_completion column to maintenance_requests table.\n";
    } else {
        echo "INFO: mileage_at_completion column already exists in maintenance_requests table.\n";
    }

    // Check if completed_at column exists (for tracking completion datetime)
    $completedAtExists = false;
    $columns = $db->fetchAll("SHOW COLUMNS FROM maintenance_requests LIKE 'completed_at'");
    if (!empty($columns)) {
        $completedAtExists = true;
    }

    if (!$completedAtExists) {
        // Add the column at the end of the table
        $db->query(
            "ALTER TABLE maintenance_requests
            ADD COLUMN completed_at DATETIME NULL"
        );
        echo "SUCCESS: Added completed_at column to maintenance_requests table.\n";
    } else {
        echo "INFO: completed_at column already exists in maintenance_requests table.\n";
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
