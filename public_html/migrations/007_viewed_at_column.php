<?php
/**
 * Migration: Add viewed_at to requests table
 * Purpose: Track when approvers have viewed pending requests
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../includes/functions.php';

try {
    $pdo = db()->getConnection();

    // Check if column already exists
    $stmt = $pdo->prepare("SHOW COLUMNS FROM requests LIKE 'viewed_at'");
    $stmt->execute();
    $columnExists = $stmt->fetch();

    if (!$columnExists) {
        $pdo->exec("ALTER TABLE requests ADD COLUMN viewed_at datetime DEFAULT NULL AFTER updated_at");
        echo "Added viewed_at column to requests table.\n";
    } else {
        echo "viewed_at column already exists.\n";
    }

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
