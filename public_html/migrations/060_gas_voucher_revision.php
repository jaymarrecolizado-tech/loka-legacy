<?php
/**
 * MIGRATION 060: Gas voucher return-for-revision
 *
 * - gas_vouchers.status ENUM gains 'revision' so Motorpool / Budget Officer /
 *   OIC CAF can send a voucher back without a terminal reject.
 * - revision_reason, returned_for_revision_by, returned_for_revision_at.
 * Does not rewrite existing rejected rows.
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

echo "=== MIGRATION 060: Gas voucher return-for-revision ===\n\n";

try {
    $pdo = new PDO(
        sprintf("mysql:host=%s;dbname=%s;charset=%s", $dbHost, $dbName, $dbCharset),
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $col = $pdo->query("SHOW COLUMNS FROM gas_vouchers LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    $type = (string) ($col['Type'] ?? '');
    if (strpos($type, "'revision'") === false) {
        $newType = str_replace(
            "'approved','rejected','cancelled'",
            "'approved','rejected','cancelled','revision'",
            $type
        );
        if ($newType === $type) {
            throw new RuntimeException("gas_vouchers.status ENUM layout unexpected: {$type}");
        }
        $pdo->exec("ALTER TABLE gas_vouchers MODIFY status {$newType} NOT NULL DEFAULT 'draft'");
        echo "OK gas_vouchers.status now {$newType}\n";
    } else {
        echo "OK gas_vouchers.status already allows revision\n";
    }

    $has = $pdo->query("SHOW COLUMNS FROM gas_vouchers LIKE 'revision_reason'")->fetch();
    if (!$has) {
        $hasRej = $pdo->query("SHOW COLUMNS FROM gas_vouchers LIKE 'rejection_reason'")->fetch();
        $after = $hasRej ? ' AFTER rejection_reason' : '';
        $pdo->exec(
            "ALTER TABLE gas_vouchers
             ADD COLUMN revision_reason TEXT NULL DEFAULT NULL
             COMMENT 'Notes when returned for revision'{$after}"
        );
        echo "OK gas_vouchers.revision_reason\n";
    } else {
        echo "OK gas_vouchers.revision_reason already exists\n";
    }

    $has = $pdo->query("SHOW COLUMNS FROM gas_vouchers LIKE 'returned_for_revision_by'")->fetch();
    if (!$has) {
        $pdo->exec(
            "ALTER TABLE gas_vouchers
             ADD COLUMN returned_for_revision_by INT(10) UNSIGNED NULL DEFAULT NULL
             COMMENT 'User who returned the voucher for revision' AFTER revision_reason,
             ADD INDEX idx_gv_returned_for_revision_by (returned_for_revision_by),
             ADD COLUMN returned_for_revision_at DATETIME NULL DEFAULT NULL AFTER returned_for_revision_by"
        );
        echo "OK gas_vouchers.returned_for_revision_by / returned_for_revision_at\n";
    } else {
        echo "OK gas_vouchers return-for-revision actor columns already exist\n";
    }

    echo "\nMIGRATION 060 complete.\n";
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
} catch (RuntimeException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
