<?php
/**
 * MIGRATION 054: Gas voucher Budget Officer step (Plan #31)
 *
 * - users.is_budget_officer / users.is_oic_budget_officer — flags (no new
 *   ROLE_* ENUM, same pattern as is_ob_approver). Flags define who may act
 *   on the new pending_budget step.
 * - gas_vouchers.status ENUM gains 'pending_budget' between pending_review
 *   and pending_approval. Existing pending_approval rows keep their value
 *   (grandfathered — they stay CAF-ready).
 * - gas_vouchers budget-step columns: requested_budget_officer_id (preferred
 *   officer picked at create), budget_reviewed_by/at, budget_officer_notes.
 */

require __DIR__ . '/_load_env.php';
$dbHost = 'localhost';
$dbName = 'old_loka_db';
$dbUser = 'root';
$dbPass = '';
$dbCharset = 'utf8mb4';

if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) continue;
        $name = trim($parts[0]);
        $value = trim($parts[1], " \t\"'");
        if ($name === 'DB_HOST') $dbHost = $value;
        elseif ($name === 'DB_DATABASE' || $name === 'DB_NAME') $dbName = $value;
        elseif ($name === 'DB_USERNAME' || $name === 'DB_USER') $dbUser = $value;
        elseif ($name === 'DB_PASSWORD') $dbPass = $value;
        elseif ($name === 'DB_CHARSET') $dbCharset = $value;
    }
}

echo "=== MIGRATION 054: Gas voucher Budget Officer step ===\n\n";

try {
    $pdo = new PDO(
        sprintf("mysql:host=%s;dbname=%s;charset=%s", $dbHost, $dbName, $dbCharset),
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // ---- users.is_budget_officer ----
    $has = $pdo->query("SHOW COLUMNS FROM users LIKE 'is_budget_officer'")->fetch();
    if (!$has) {
        $pdo->exec(
            "ALTER TABLE users
             ADD COLUMN is_budget_officer TINYINT(1) NOT NULL DEFAULT 0
             COMMENT 'May act as Budget Officer on gas vouchers (pending_budget step)' AFTER is_ob_approver"
        );
        echo "OK users.is_budget_officer\n";
    } else {
        echo "OK users.is_budget_officer already exists\n";
    }

    // ---- users.is_oic_budget_officer ----
    $has = $pdo->query("SHOW COLUMNS FROM users LIKE 'is_oic_budget_officer'")->fetch();
    if (!$has) {
        $pdo->exec(
            "ALTER TABLE users
             ADD COLUMN is_oic_budget_officer TINYINT(1) NOT NULL DEFAULT 0
             COMMENT 'May act as OIC Budget Officer on gas vouchers (pending_budget step)' AFTER is_budget_officer"
        );
        echo "OK users.is_oic_budget_officer\n";
    } else {
        echo "OK users.is_oic_budget_officer already exists\n";
    }

    // ---- gas_vouchers.status ENUM gains pending_budget ----
    $col = $pdo->query("SHOW COLUMNS FROM gas_vouchers LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    $type = (string) ($col['Type'] ?? '');
    if (strpos($type, 'pending_budget') === false) {
        $newType = str_replace(
            "'pending_review','pending_approval'",
            "'pending_review','pending_budget','pending_approval'",
            $type
        );
        if ($newType === $type) {
            throw new RuntimeException("gas_vouchers.status ENUM layout unexpected: {$type}");
        }
        $pdo->exec("ALTER TABLE gas_vouchers MODIFY status {$newType} NOT NULL DEFAULT 'draft'");
        echo "OK gas_vouchers.status now {$newType}\n";
    } else {
        echo "OK gas_vouchers.status already allows pending_budget\n";
    }

    // ---- gas_vouchers.requested_budget_officer_id ----
    $has = $pdo->query("SHOW COLUMNS FROM gas_vouchers LIKE 'requested_budget_officer_id'")->fetch();
    if (!$has) {
        $pdo->exec(
            "ALTER TABLE gas_vouchers
             ADD COLUMN requested_budget_officer_id INT(10) UNSIGNED NULL DEFAULT NULL
             COMMENT 'Preferred Budget Officer picked at create' AFTER requested_approver_id,
             ADD INDEX idx_gv_requested_budget_officer (requested_budget_officer_id)"
        );
        echo "OK gas_vouchers.requested_budget_officer_id\n";
    } else {
        echo "OK gas_vouchers.requested_budget_officer_id already exists\n";
    }

    // ---- gas_vouchers.budget_reviewed_by / _at / budget_officer_notes ----
    $has = $pdo->query("SHOW COLUMNS FROM gas_vouchers LIKE 'budget_reviewed_by'")->fetch();
    if (!$has) {
        $pdo->exec(
            "ALTER TABLE gas_vouchers
             ADD COLUMN budget_reviewed_by INT(10) UNSIGNED NULL DEFAULT NULL
             COMMENT 'Budget Officer who certified the budget step' AFTER requested_budget_officer_id,
             ADD INDEX idx_gv_budget_reviewed_by (budget_reviewed_by),
             ADD COLUMN budget_reviewed_at DATETIME NULL DEFAULT NULL AFTER budget_reviewed_by,
             ADD COLUMN budget_officer_notes TEXT NULL DEFAULT NULL AFTER budget_reviewed_at"
        );
        echo "OK gas_vouchers.budget_reviewed_by / budget_reviewed_at / budget_officer_notes\n";
    } else {
        echo "OK gas_vouchers budget review columns already exist\n";
    }

    echo "\nMIGRATION 054 complete.\n";
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
} catch (RuntimeException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
