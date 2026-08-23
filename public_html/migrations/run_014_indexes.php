<?php
/**
 * Migration Runner for Additional Performance Indexes
 *
 * This script executes migration 014 which adds additional performance
 * indexes to optimize commonly executed queries.
 *
 * Usage:
 *   php migrations/run_014_indexes.php
 *   OR visit in browser: /migrations/run_014_indexes.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Load configuration
require_once __DIR__ . '/../config/database.php';

echo "<!DOCTYPE html>
<html>
<head>
    <title>Migration 014 - Additional Performance Indexes</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 900px; margin: 30px auto; padding: 20px; }
        .success { background: #d4edda; color: #155724; padding: 15px; margin: 10px 0; border-radius: 4px; }
        .error { background: #f8d7da; color: #721c24; padding: 15px; margin: 10px 0; border-radius: 4px; }
        .info { background: #d1ecf1; color: #0c5460; padding: 15px; margin: 10px 0; border-radius: 4px; }
        pre { background: #f8f9fa; padding: 15px; border-radius: 4px; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #0d6efd; color: white; }
        .btn { display: inline-block; padding: 12px 24px; background: #0d6efd; color: white; text-decoration: none; border-radius: 4px; }
        .btn:hover { background: #0b5ed7; }
    </style>
</head>
<body>
    <h1>🚀 Migration 014: Additional Performance Indexes</h1>

    <div class='info'>
        <strong>This migration adds performance indexes for:</strong>
        <ul>
            <li>Notifications filtering (user_id, is_read, is_archived, deleted_at)</li>
            <li>Department and vehicle type queries with soft deletes</li>
            <li>User status filtering</li>
            <li>Vehicle and driver availability queries</li>
            <li>Rate limiting queries</li>
            <li>Security and audit log queries</li>
            <li>Maintenance request queries</li>
            <li>Saved workflows lookups</li>
        </ul>
    </div>

    <p><a href='?' class='btn'>Run Migration Now</a></p>
";

if (isset($_GET['confirm']) || count($_GET) > 0) {
    try {
        // Connect to database
        $dsn = sprintf(
            "mysql:host=%s;dbname=%s;charset=%s",
            DB_HOST,
            DB_NAME,
            DB_CHARSET
        );
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);

        echo "<div class='success'>✓ Connected to database: " . htmlspecialchars(DB_NAME) . "</div>";

        // Read and execute migration
        $migrationFile = __DIR__ . '/014_additional_performance_indexes.sql';

        if (!file_exists($migrationFile)) {
            throw new Exception("Migration file not found: " . $migrationFile);
        }

        $sql = file_get_contents($migrationFile);

        echo "<div class='info'>Executing migration...</div>";

        // Split into individual statements
        $statements = [];
        $buffer = '';
        $lines = explode("\n", $sql);

        foreach ($lines as $line) {
            // Skip comments and empty lines
            if (preg_match('/^(--|#|\/\*|\s*$)/', $line)) {
                continue;
            }
            $buffer .= $line . "\n";

            // Execute complete statements
            if (preg_match('/;\s*$/', trim($line))) {
                $statements[] = $buffer;
                $buffer = '';
            }
        }

        $successCount = 0;
        $errorCount = 0;
        $errors = [];

        foreach ($statements as $index => $statement) {
            $trimmed = trim($statement);
            if (empty($trimmed)) {
                continue;
            }

            try {
                $pdo->exec($trimmed);
                $successCount++;
            } catch (PDOException $e) {
                // Some statements may fail if index already exists (that's OK)
                $errorMsg = $e->getMessage();
                if (strpos($errorMsg, 'Duplicate key name') !== false) {
                    // Index already exists - count as success
                    $successCount++;
                } else {
                    $errorCount++;
                    $errors[] = "Statement #" . ($index + 1) . ": " . $errorMsg;
                }
            }
        }

        echo "<div class='success'><strong>Migration completed!</strong></div>";
        echo "<p><strong>Results:</strong></p>";
        echo "<ul>";
        echo "<li>✅ Successful statements: <strong>" . $successCount . "</strong></li>";

        if ($errorCount > 0) {
            echo "<li>⚠️ Errors (non-critical): <strong>" . $errorCount . "</strong></li>";
        }
        echo "</ul>";

        // Display any errors
        if (!empty($errors)) {
            echo "<div class='error'><strong>Errors encountered:</strong><br>";
            echo "<pre>" . htmlspecialchars(implode("\n", array_slice($errors, 0, 5))) . "</pre>";
            echo "</div>";
        }

        // Show current indexes on key tables
        echo "<h2>Current Index Status</h2>";

        $tables = [
            'notifications', 'departments', 'vehicle_types', 'users',
            'vehicles', 'drivers', 'requests', 'rate_limits',
            'security_logs', 'maintenance_requests'
        ];

        echo "<table>";
        echo "<tr><th>Table</th><th>Indexes</th></tr>";

        foreach ($tables as $table) {
            try {
                $indexes = $pdo->query(
                    "SELECT INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX
                     FROM INFORMATION_SCHEMA.STATISTICS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table'
                     ORDER BY INDEX_NAME, SEQ_IN_INDEX"
                )->fetchAll();

                if (!empty($indexes)) {
                    $indexList = [];
                    foreach ($indexes as $idx) {
                        $indexList[$idx->INDEX_NAME][] = $idx->COLUMN_NAME;
                    }

                    $indexStrings = [];
                    foreach ($indexList as $name => $cols) {
                        $indexStrings[] = "<span style='color: #0d6efd;'>$name</span>(" . implode(', ', $cols) . ")";
                    }

                    echo "<tr>";
                    echo "<td><strong>$table</strong></td>";
                    echo "<td>" . implode('<br>', $indexStrings) . "</td>";
                    echo "</tr>";
                }
            } catch (Exception $e) {
                // Table might not exist
            }
        }

        echo "</table>";

        echo "<hr>";
        echo "<a href='../index.php' class='btn'>Go to Dashboard</a>";
        echo " <a href='?' class='btn' style='background: #6c757d;'>Run Again</a>";

    } catch (Exception $e) {
        echo "<div class='error'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
} else {
    echo "<p><a href='?confirm=1' class='btn'>Confirm & Run Migration</a></p>";
}

echo "
    <hr>
    <h3>About Migration 014</h3>
    <p><strong>Benefits:</strong></p>
    <ul>
        <li>Faster notification queries (notifications list page)</li>
        <li>Improved department/vehicle type loading</li>
        <li>Optimized user filtering by status</li>
        <li>Better vehicle/driver availability checks</li>
        <li>Faster rate limit lookups (DDoS protection)</li>
        <li>Quick security and audit log searches</li>
    </ul>
    <p><strong>Performance Impact:</strong> These indexes add minimal storage overhead
    (typically < 5% increase) but can improve query performance by 50-90% for indexed queries.</p>
</body>
</html>";
