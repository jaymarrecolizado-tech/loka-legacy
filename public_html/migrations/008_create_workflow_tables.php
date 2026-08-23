<?php
/**
 * Migration: Create missing tables for approval workflow
 * Creates: approvals, approval_workflow, audit_logs tables
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../includes/functions.php';

try {
    $pdo = db()->getConnection();

    // Check if approvals table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'approvals'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `approvals` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `request_id` INT UNSIGNED NOT NULL,
                `approver_id` INT UNSIGNED NOT NULL,
                `approval_type` ENUM('department', 'motorpool') NOT NULL,
                `status` ENUM('approved', 'rejected') NOT NULL,
                `comments` TEXT NULL,
                `created_at` DATETIME NOT NULL,
                INDEX `idx_request` (`request_id`),
                INDEX `idx_approver` (`approver_id`),
                INDEX `idx_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "Created 'approvals' table.\n";
    } else {
        echo "'approvals' table already exists.\n";
    }

    // Check if approval_workflow table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'approval_workflow'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `approval_workflow` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `request_id` INT UNSIGNED NOT NULL UNIQUE,
                `department_id` INT UNSIGNED NOT NULL,
                `step` ENUM('department', 'motorpool') NOT NULL DEFAULT 'department',
                `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
                `approver_id` INT UNSIGNED NULL,
                `motorpool_head_id` INT UNSIGNED NULL,
                `comments` TEXT NULL,
                `action_at` DATETIME NULL,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                INDEX `idx_request` (`request_id`),
                INDEX `idx_status` (`status`),
                INDEX `idx_step` (`step`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "Created 'approval_workflow' table.\n";
    } else {
        echo "'approval_workflow' table already exists.\n";
    }

    // Check if audit_logs table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'audit_logs'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `audit_logs` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT UNSIGNED NULL,
                `action` VARCHAR(100) NOT NULL,
                `entity_type` VARCHAR(50) NOT NULL,
                `entity_id` INT UNSIGNED NOT NULL,
                `old_values` JSON NULL,
                `new_values` JSON NULL,
                `ip_address` VARCHAR(45) NULL,
                `created_at` DATETIME NOT NULL,
                INDEX `idx_user` (`user_id`),
                INDEX `idx_entity` (`entity_type`, `entity_id`),
                INDEX `idx_action` (`action`),
                INDEX `idx_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "Created 'audit_logs' table.\n";
    } else {
        echo "'audit_logs' table already exists.\n";
    }

    echo "\nAll migration tables created successfully!\n";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
