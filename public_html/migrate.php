<?php
/**
 * LOKA - Migration Runner
 *
 * Usage:
 *   php migrate.php                    # Run all pending migrations
 *   php migrate.php status             # Show migration status
 *   php migrate.php run 001_initial   # Run specific migration
 */

require_once __DIR__ . '/index.php';

$migration = new Migration();

if ($argc > 1) {
    $command = $argv[1];

    switch ($command) {
        case 'status':
            echo "\n=== Migration Status ===\n\n";
            $status = $migration->getStatus();
            if (empty($status)) {
                echo "No migrations have been executed yet.\n";
            } else {
                foreach ($status as $m) {
                    echo "✓ {$m->migration} - " . formatDate($m->executed_at) . "\n";
                }
            }
            break;

        case 'run':
            if ($argc > 2) {
                $file = $argv[2];
                echo "Running migration: {$file}\n";
                $migration->run($file);
            } else {
                echo "\n=== Running All Pending Migrations ===\n\n";
                $migration->runAll();
            }
            break;

        case 'init':
            echo "\n=== Initializing Migration Tracker ===\n\n";
            $migration->createTracker();
            break;

        default:
            echo "Unknown command: {$command}\n";
            echo "Usage: php migrate.php [status|run|init] [migration_file]\n";
            break;
    }
} else {
    echo "\n=== LOKA Migration System ===\n\n";
    echo "Usage:\n";
    echo "  php migrate.php                    # Run all pending migrations\n";
    echo "  php migrate.php status             # Show migration status\n";
    echo "  php migrate.php init               # Create migration tracker table\n";
    echo "  php migrate.php run <file>         # Run specific migration\n";
}
