<?php
/**
 * LOKA - MIGRATION 036: Add Signatory Selection Fields to Gas Vouchers
 *
 * Allows requesters to select their preferred Motorpool Head and Chief Admin & Finance
 * during gas voucher creation.
 */

$envFile = __DIR__ . '/../.env';
$dbHost = 'localhost';
$dbName = 'loka_fleet';
$dbUser = 'root';
$dbPass = '';
$dbCharset = 'utf8mb4';

if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $name = trim($parts[0]);
            $value = trim($parts[1]);
            switch ($name) {
                case 'DB_HOST': $dbHost = $value; break;
                case 'DB_NAME': case 'DB_DATABASE': $dbName = $value; break;
                case 'DB_USER': case 'DB_USERNAME': $dbUser = $value; break;
                case 'DB_PASSWORD': $dbPass = $value; break;
                case 'DB_CHARSET': $dbCharset = $value; break;
            }
        }
    }
}

echo "=== MIGRATION 036: Add Signatory Selection Fields to Gas Vouchers ===\n\n";

try {
    $pdo = new PDO(
        sprintf("mysql:host=%s;dbname=%s;charset=%s", $dbHost, $dbName, $dbCharset),
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    echo "Connected to database: " . $dbName . "\n\n";

    // Check if columns already exist
    $stmt = $pdo->query("DESCRIBE gas_vouchers");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $changes = [];

    // Add requested_reviewer_id column
    if (!in_array('requested_reviewer_id', $columns)) {
        $pdo->exec("ALTER TABLE gas_vouchers ADD COLUMN requested_reviewer_id INT UNSIGNED DEFAULT NULL COMMENT 'Preferred OIC Motorpool reviewer' AFTER chargeable_against");
        $pdo->exec("ALTER TABLE gas_vouchers ADD CONSTRAINT fk_gv_requested_reviewer FOREIGN KEY (requested_reviewer_id) REFERENCES users(id) ON DELETE SET NULL");
        $changes[] = "âœ“ Added 'requested_reviewer_id' column";
    } else {
        echo "â€¢ Column 'requested_reviewer_id' already exists, skipping.\n";
    }

    // Add requested_approver_id column
    if (!in_array('requested_approver_id', $columns)) {
        $pdo->exec("ALTER TABLE gas_vouchers ADD COLUMN requested_approver_id INT UNSIGNED DEFAULT NULL COMMENT 'Preferred Chief Admin & Finance approver' AFTER requested_reviewer_id");
        $pdo->exec("ALTER TABLE gas_vouchers ADD CONSTRAINT fk_gv_requested_approver FOREIGN KEY (requested_approver_id) REFERENCES users(id) ON DELETE SET NULL");
        $changes[] = "âœ“ Added 'requested_approver_id' column";
    } else {
        echo "â€¢ Column 'requested_approver_id' already exists, skipping.\n";
    }

    // Display changes
    foreach ($changes as $change) {
        echo $change . "\n";
    }

    echo "\n" . str_repeat('=', 50) . "\n";
    echo "MIGRATION 036 COMPLETE\n";
    echo str_repeat('=', 50) . "\n\n";

    if (!empty($changes)) {
        echo "Changes made:\n";
        foreach ($changes as $change) {
            echo "  $change\n";
        }
        echo "\nNext steps:\n";
        echo "1. Update pages/gas-vouchers/create.php to add reviewer/approver selection\n";
        echo "2. Update pages/gas-vouchers/approve.php to pre-select requested approvers\n";
    }

} catch (PDOException $e) {
    echo "\nâœ— ERROR: " . $e->getMessage() . "\n";
    exit(1);
}


