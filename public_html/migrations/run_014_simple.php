<?php
/**
 * Simple Migration 014 Runner - Direct Index Creation
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/database.php';

echo "=== Migration 014: Additional Performance Indexes (Simplified) ===\n\n";

try {
    // Connect to database
    $dsn = sprintf("mysql:host=%s;dbname=%s;charset=%s", DB_HOST, DB_NAME, DB_CHARSET);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    echo "✓ Connected to database: " . DB_NAME . "\n\n";

    // List of indexes to create (in order)
    $indexes = [
        'notifications' => [
            'idx_notifications_user_read_archived' => '(user_id, is_read, is_archived, deleted_at, created_at)'
        ],
        'departments' => [
            'idx_departments_deleted_name' => '(deleted_at, name)',
            'idx_departments_deleted_status' => '(deleted_at, status)'
        ],
        'vehicle_types' => [
            'idx_vehicle_types_deleted_name' => '(deleted_at, name)'
        ],
        'users' => [
            'idx_users_deleted_status' => '(deleted_at, status)',
            'idx_users_dept_deleted_status' => '(department_id, deleted_at, status)'
        ],
        'vehicles' => [
            'idx_vehicles_deleted_status' => '(deleted_at, status)'
        ],
        'drivers' => [
            'idx_drivers_user_deleted' => '(user_id, deleted_at)',
            'idx_drivers_deleted_status' => '(deleted_at, status)'
        ],
        'requests' => [
            'idx_requests_deleted_status_dates' => '(deleted_at, status, start_datetime, end_datetime)'
        ],
        'approval_workflow' => [
            'idx_approval_workflow_request_status_step' => '(request_id, status, step)'
        ],
        'saved_workflows' => [
            'idx_saved_workflows_user_id' => '(user_id)',
            'idx_saved_workflows_user_default' => '(user_id, is_default)'
        ],
        'rate_limits' => [
            'idx_rate_limits_action_identifier_created' => '(action, identifier, created_at)',
            'idx_rate_limits_created' => '(created_at)'
        ],
        'maintenance_requests' => [
            'idx_maintenance_vehicle_deleted_created' => '(vehicle_id, deleted_at, created_at)',
            'idx_maintenance_deleted_status' => '(deleted_at, status)'
        ],
        'security_logs' => [
            'idx_security_logs_event_created' => '(event, created_at)',
            'idx_security_logs_ip_created' => '(ip_address, created_at)'
        ],
        'audit_logs' => [
            'idx_audit_logs_action_created' => '(action, created_at)'
        ]
    ];

    echo "Creating " . countTotalIndexes($indexes) . " indexes...\n";
    echo str_repeat('-', 60) . "\n\n";

    $successCount = 0;
    $skipCount = 0;
    $errorCount = 0;

    foreach ($indexes as $table => $tableIndexes) {
        foreach ($tableIndexes as $indexName => $indexColumns) {
            try {
                $sql = "ALTER TABLE `$table` ADD INDEX `$indexName` $indexColumns";
                $pdo->exec($sql);
                $successCount++;
                echo "✓ Created: $indexName on $table\n";
            } catch (PDOException $e) {
                $errorMsg = $e->getMessage();
                if (strpos($errorMsg, 'Duplicate key name') !== false) {
                    // Index already exists - that's OK
                    $skipCount++;
                    echo "⊘ Skipped: $indexName on $table (already exists)\n";
                } else {
                    $errorCount++;
                    echo "✗ Error: $indexName on $table - " . substr($errorMsg, 0, 100) . "\n";
                }
            }
        }
    }

    echo "\n" . str_repeat('=', 60) . "\n";
    echo "MIGRATION SUMMARY\n";
    echo str_repeat('=', 60) . "\n\n";

    echo "✓ Created: $successCount indexes\n";
    echo "⊘ Skipped: $skipCount indexes (already exist)\n";
    if ($errorCount > 0) {
        echo "✗ Errors:  $errorCount indexes\n";
    }

    // Show current index count
    echo "\nVerifying indexes...\n\n";

    $allTables = array_keys($indexes);
    foreach ($allTables as $table) {
        try {
            $count = $pdo->query(
                "SELECT COUNT(DISTINCT INDEX_NAME) FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table'
                 AND INDEX_NAME LIKE 'idx_%'"
            )->fetchColumn();

            if ($count > 0) {
                echo "📋 $table: $count performance indexes\n";
            }
        } catch (Exception $e) {
            // Table might not exist
        }
    }

    echo "\n" . str_repeat('=', 60) . "\n";
    echo "✓ Migration 014 completed!\n";
    echo str_repeat('=', 60) . "\n";

} catch (Exception $e) {
    echo "\n✗ Fatal Error: " . $e->getMessage() . "\n";
    exit(1);
}

function countTotalIndexes($indexes) {
    $count = 0;
    foreach ($indexes as $tableIndexes) {
        $count += count($tableIndexes);
    }
    return $count;
}
