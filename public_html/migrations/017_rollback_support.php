<?php
/**
 * Migration: Add rollback support
 * Purpose:
 *  1. Extend approvals.status ENUM with 'rollback' so admin rollbacks appear
 *     as distinct events in the approval history timeline.
 *  2. Add requests.rollback_count to flag chronically rolled-back requests.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../includes/functions.php';

try {
    $pdo = db()->getConnection();

    // 1. Extend approvals.status ENUM
    $stmt = $pdo->query("SHOW COLUMNS FROM approvals LIKE 'status'");
    $col = $stmt->fetch();
    if ($col && strpos($col->Type, 'rollback') === false) {
        $pdo->exec("ALTER TABLE approvals MODIFY status ENUM('approved','rejected','revision','rollback') NOT NULL");
        echo "Extended approvals.status ENUM with 'rollback'.\n";
    } else {
        echo "approvals.status already supports 'rollback'.\n";
    }

    // 2. Add requests.rollback_count
    $stmt = $pdo->prepare("SHOW COLUMNS FROM requests LIKE 'rollback_count'");
    $stmt->execute();
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE requests ADD COLUMN rollback_count TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER viewed_at");
        echo "Added requests.rollback_count column.\n";
    } else {
        echo "requests.rollback_count already exists.\n";
    }

    echo "Migration complete.\n";
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
