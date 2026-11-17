<?php
/**
 * MIGRATION 057: OB CoA acknowledgment + contact (Plan #36)
 *
 * The client Certificate of Appearance becomes an acknowledgment: a
 * proof-of-service notice tick-box + representative contact (mobile and/or
 * official email) instead of a self-signed canvas. Old coa_* signature/token
 * columns are kept for history and stop being written.
 *
 * - coa_acknowledged_at  — canonical submit timestamp (replaces coa_signed_at
 *   for new slips; old rows keep their value)
 * - coa_contact_mobile / coa_contact_email — future-validation contacts
 *   (format-validated, at least one required at submit)
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

echo "=== MIGRATION 057: OB CoA acknowledgment + contact ===\n\n";

try {
    $pdo = new PDO(
        sprintf("mysql:host=%s;dbname=%s;charset=%s", $dbHost, $dbName, $dbCharset),
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $adds = [
        'coa_acknowledged_at' => "ALTER TABLE ob_requests
             ADD COLUMN coa_acknowledged_at DATETIME NULL DEFAULT NULL
             COMMENT 'Client acknowledgment submit time (Plan #36)' AFTER coa_signed_at",
        'coa_contact_mobile' => "ALTER TABLE ob_requests
             ADD COLUMN coa_contact_mobile VARCHAR(30) NULL DEFAULT NULL
             COMMENT 'Representative mobile for spot-check validation' AFTER coa_acknowledged_at",
        'coa_contact_email' => "ALTER TABLE ob_requests
             ADD COLUMN coa_contact_email VARCHAR(150) NULL DEFAULT NULL
             COMMENT 'Representative official email for spot-check validation' AFTER coa_contact_mobile",
    ];

    foreach ($adds as $col => $sql) {
        $has = $pdo->query("SHOW COLUMNS FROM ob_requests LIKE '{$col}'")->fetch();
        if (!$has) {
            $pdo->exec($sql);
            echo "OK ob_requests.{$col}\n";
        } else {
            echo "OK ob_requests.{$col} already exists\n";
        }
    }

    echo "\nMIGRATION 057 complete.\n";
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
