<?php
/**
 * Migration: Add 'revision' status to workflow tables
 * 
 * This migration adds the 'revision' status to:
 * - requests table
 * - approvals table
 * - approval_workflow table
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../classes/Database.php';

try {
    $pdo = db()->getConnection();

    // Update requests table - add 'revision' to status enum
    $pdo->exec("
        ALTER TABLE requests 
        MODIFY COLUMN status ENUM('draft', 'pending', 'pending_motorpool', 'approved', 'rejected', 'cancelled', 'completed', 'modified', 'revision') 
        NOT NULL DEFAULT 'draft'
    ");
    echo "Updated 'requests' table status enum.\n";

    // Update approvals table - add 'revision' to status enum
    $pdo->exec("
        ALTER TABLE approvals 
        MODIFY COLUMN status ENUM('approved', 'rejected', 'revision') 
        NOT NULL
    ");
    echo "Updated 'approvals' table status enum.\n";

    // Update approval_workflow table - add 'revision' to status enum
    $pdo->exec("
        ALTER TABLE approval_workflow 
        MODIFY COLUMN status ENUM('pending', 'approved', 'rejected', 'revision') 
        NOT NULL DEFAULT 'pending'
    ");
    echo "Updated 'approval_workflow' table status enum.\n";

    echo "\nMigration completed successfully!\n";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
