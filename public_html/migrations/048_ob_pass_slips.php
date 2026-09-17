<?php
/**
 * MIGRATION 048: Official Business Pass Slip (Plan #22)
 *
 * - users.is_ob_approver (Immediate Supervisor checkbox)
 * - ob_requests (pass slip + workflow + guard times + signatures + CoA)
 * - ob_approvals (separate audit trail — approvals.request_id FK untouched)
 * - requests.ob_request_id (optional 1:1 bind, unique)
 * - settings: allow_ob_attach_after_submit, ob_coa_token_days
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

echo "=== MIGRATION 048: OB Pass Slip ===\n\n";

try {
    $pdo = new PDO(
        sprintf("mysql:host=%s;dbname=%s;charset=%s", $dbHost, $dbName, $dbCharset),
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // ---- users.is_ob_approver ----
    $has = $pdo->query("SHOW COLUMNS FROM users LIKE 'is_ob_approver'")->fetch();
    if (!$has) {
        $pdo->exec(
            "ALTER TABLE users
             ADD COLUMN is_ob_approver TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'May act as Immediate Supervisor for OB Pass Slips' AFTER status"
        );
        echo "OK users.is_ob_approver\n";
    } else {
        echo "OK users.is_ob_approver already exists\n";
    }

    // ---- ob_requests ----
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ob_requests (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            pass_slip_no VARCHAR(20) NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            department_id INT UNSIGNED NULL,
            purpose VARCHAR(500) NOT NULL,
            ob_date DATE NOT NULL,
            plate_number VARCHAR(30) NULL,
            supervisor_user_id INT UNSIGNED NOT NULL,
            motorpool_head_id INT UNSIGNED NULL,
            status ENUM('pending_supervisor','pending_motorpool','approved','departed','coa_received','completed','rejected','revision','cancelled') NOT NULL DEFAULT 'pending_supervisor',
            ob_departure_datetime DATETIME NULL,
            ob_arrival_datetime DATETIME NULL,
            departure_guard_id INT UNSIGNED NULL,
            arrival_guard_id INT UNSIGNED NULL,
            employee_signature_path VARCHAR(255) NULL,
            supervisor_signature_path VARCHAR(255) NULL,
            motorpool_signature_path VARCHAR(255) NULL,
            guard_departure_signature_path VARCHAR(255) NULL,
            coa_office VARCHAR(200) NULL,
            coa_representative VARCHAR(150) NULL,
            coa_purpose VARCHAR(300) NULL,
            coa_time_from VARCHAR(20) NULL,
            coa_time_to VARCHAR(20) NULL,
            coa_signature_path VARCHAR(255) NULL,
            coa_signed_at DATETIME NULL,
            coa_token_hash CHAR(64) NULL,
            coa_token_expires_at DATETIME NULL,
            finalized_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            deleted_at DATETIME NULL,
            UNIQUE KEY uq_ob_pass_slip_no (pass_slip_no),
            UNIQUE KEY uq_ob_coa_token (coa_token_hash),
            KEY idx_ob_user (user_id),
            KEY idx_ob_status (status),
            KEY idx_ob_supervisor (supervisor_user_id),
            KEY idx_ob_motorpool (motorpool_head_id),
            KEY idx_ob_date (ob_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "OK ob_requests\n";

    // ---- ob_approvals ----
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ob_approvals (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ob_request_id INT UNSIGNED NOT NULL,
            approver_user_id INT UNSIGNED NULL,
            approval_type ENUM('supervisor','motorpool','guard','client','requester','system') NOT NULL,
            action VARCHAR(30) NOT NULL,
            comments VARCHAR(500) NULL,
            created_at DATETIME NOT NULL,
            KEY idx_ob_approval (ob_request_id),
            CONSTRAINT fk_ob_approval_request FOREIGN KEY (ob_request_id) REFERENCES ob_requests(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "OK ob_approvals\n";

    // ---- requests.ob_request_id (optional 1:1 bind) ----
    $has = $pdo->query("SHOW COLUMNS FROM requests LIKE 'ob_request_id'")->fetch();
    if (!$has) {
        $pdo->exec(
            "ALTER TABLE requests
             ADD COLUMN ob_request_id INT UNSIGNED NULL COMMENT 'Bound OB Pass Slip (1:1)' AFTER travel_order_uploaded_at,
             ADD UNIQUE KEY uq_requests_ob (ob_request_id),
             ADD CONSTRAINT fk_requests_ob FOREIGN KEY (ob_request_id) REFERENCES ob_requests(id) ON DELETE SET NULL"
        );
        echo "OK requests.ob_request_id (unique)\n";
    } else {
        echo "OK requests.ob_request_id already exists\n";
    }

    // ---- settings defaults ----
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare(
        "INSERT INTO settings (`key`, value, type, category, created_at, updated_at)
         SELECT ?, ?, ?, ?, ?, ?
         FROM DUAL
         WHERE NOT EXISTS (SELECT 1 FROM settings WHERE `key` = ?)"
    );
    foreach (
        [
            ['allow_ob_attach_after_submit', '0', 'bool', 'trips'],
            ['ob_coa_token_days', '7', 'int', 'trips'],
        ] as [$key, $value, $type, $category]
    ) {
        $stmt->execute([$key, $value, $type, $category, $now, $now, $key]);
        echo "OK setting {$key}\n";
    }

    echo "\nMIGRATION 048 complete.\n";
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
