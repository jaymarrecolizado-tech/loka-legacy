<?php
/**
 * Quick Migration 014 Runner
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/database.php';

echo "=== Migration 014: Additional Performance Indexes ===\n\n";

try {
    // Connect to database with buffered queries enabled
    $dsn = sprintf("mysql:host=%s;dbname=%s;charset=%s", DB_HOST, DB_NAME, DB_CHARSET);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true  // Fix unbuffered query issue
    ]);

    echo "✓ Connected to database: " . DB_NAME . "\n\n";

    // Read migration file
    $migrationFile = __DIR__ . '/014_additional_performance_indexes.sql';
    if (!file_exists($migrationFile)) {
        die("✗ Migration file not found: $migrationFile\n");
    }

    $sql = file_get_contents($migrationFile);

    // Split into statements
    $statements = [];
    $buffer = '';
    $lines = explode("\n", $sql);

    foreach ($lines as $line) {
        if (preg_match('/^(--|#|\/\*|\s*$)/', $line)) continue;
        $buffer .= $line . "\n";
        if (preg_match('/;\s*$/', trim($line))) {
            $statements[] = $buffer;
            $buffer = '';
        }
    }

    echo "Executing " . count($statements) . " statements...\n";
    echo str_repeat('-', 50) . "\n\n";

    $successCount = 0;
    $errorCount = 0;
    $errors = [];

    foreach ($statements as $index => $statement) {
        $trimmed = trim($statement);
        if (empty($trimmed)) continue;

        try {
            $pdo->exec($trimmed);
            $successCount++;

            // Show progress for every few statements
            if (($index + 1) % 5 == 0) {
                echo "✓ Processed " . ($index + 1) . " statements...\n";
            }
        } catch (PDOException $e) {
            $errorMsg = $e->getMessage();
            if (strpos($errorMsg, 'Duplicate key name') !== false) {
                // Index already exists - that's OK
                $successCount++;
            } else {
                $errorCount++;
                $errors[] = "Statement #" . ($index + 1) . ": " . $errorMsg;
            }
        }
    }

    echo "\n" . str_repeat('=', 50) . "\n";
    echo "MIGRATION COMPLETE\n";
    echo str_repeat('=', 50) . "\n\n";

    echo "Results:\n";
    echo "  ✅ Successful: $successCount statements\n";
    if ($errorCount > 0) {
        echo "  ⚠️  Errors: $errorCount\n\n";
        foreach ($errors as $error) {
            echo "  - $error\n";
        }
    } else {
        echo "  ✅ No errors!\n\n";
    }

    // Show created indexes
    echo "\nVerifying indexes...\n\n";

    $tables = [
        'notifications', 'departments', 'vehicle_types', 'users',
        'vehicles', 'drivers', 'requests', 'rate_limits',
        'security_logs', 'maintenance_requests'
    ];

    foreach ($tables as $table) {
        try {
            $indexes = $pdo->query(
                "SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) as columns
                 FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table'
                 AND INDEX_NAME LIKE 'idx_%'
                 GROUP BY INDEX_NAME
                 ORDER BY INDEX_NAME"
            )->fetchAll();

            if (!empty($indexes)) {
                echo "📋 $table:\n";
                foreach ($indexes as $idx) {
                    echo "   - {$idx->INDEX_NAME}({$idx->columns})\n";
                }
                echo "\n";
            }
        } catch (Exception $e) {
            // Table might not exist
        }
    }

    echo "\n" . str_repeat('=', 50) . "\n";
    echo "✓ Migration 014 completed successfully!\n";
    echo str_repeat('=', 50) . "\n";

} catch (Exception $e) {
    echo "\n✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}
