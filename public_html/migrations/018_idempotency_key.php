<?php
/**
 * Migration: Add idempotency key to requests
 * Purpose: Prevent duplicate request submissions. Each submission form carries a
 * unique token; the UNIQUE index makes a duplicate INSERT fail atomically.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../includes/functions.php';

try {
    $pdo = db()->getConnection();

    $stmt = $pdo->prepare("SHOW COLUMNS FROM requests LIKE 'idempotency_key'");
    $stmt->execute();
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE requests ADD COLUMN idempotency_key VARCHAR(64) DEFAULT NULL AFTER rollback_count");
        echo "Added requests.idempotency_key column.\n";
    } else {
        echo "requests.idempotency_key already exists.\n";
    }

    // Unique index (check by name to be idempotent)
    $stmt = $pdo->prepare("SHOW INDEX FROM requests WHERE Key_name = 'uq_requests_idempotency'");
    $stmt->execute();
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE requests ADD UNIQUE INDEX uq_requests_idempotency (idempotency_key)");
        echo "Added unique index uq_requests_idempotency.\n";
    } else {
        echo "Unique index already exists.\n";
    }

    echo "Migration complete.\n";
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
