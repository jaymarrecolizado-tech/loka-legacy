<?php
/**
 * Migration: Add request_id column to email_queue
 * 
 * Purpose: Track which request generated each email for audit trail
 * 
 * Date: January 23, 2026
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';

echo "Adding request_id column to email_queue...\n";

try {
    $db = Database::getInstance();
    
    // Check if column already exists
    $columns = $db->fetchAll("DESCRIBE email_queue");
    $columnNames = array_map(fn($c) => $c->Field, $columns);
    
    if (in_array('request_id', $columnNames)) {
        echo "  request_id column already exists. Skipping.\n";
    } else {
        // Add request_id column
        $db->query("ALTER TABLE email_queue ADD COLUMN request_id INT UNSIGNED NULL COMMENT 'Related request ID for Control No. tracking' AFTER template");
        echo "  Added request_id column.\n";
        
        // Add index for request_id lookups
        $db->query("CREATE INDEX idx_email_queue_request_id ON email_queue(request_id)");
        echo "  Created index on request_id.\n";
    }
    
    echo "Migration completed successfully.\n";
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
