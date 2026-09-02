<?php
/**
 * MIGRATION 045: Gas stations master table
 */

$envFile = __DIR__ . '/../.env';
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

echo "MIGRATION 045: gas_stations...\n";

try {
    $pdo = new PDO(
        sprintf("mysql:host=%s;dbname=%s;charset=%s", $dbHost, $dbName, $dbCharset),
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS gas_stations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            address VARCHAR(255) NULL,
            contact VARCHAR(100) NULL,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            deleted_at DATETIME NULL,
            UNIQUE KEY uq_gas_station_name (name),
            INDEX idx_gas_station_status (status),
            INDEX idx_gas_station_deleted (deleted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "OK gas_stations\n";

    // Seed the two existing stations if empty
    $cnt = (int) $pdo->query("SELECT COUNT(*) FROM gas_stations WHERE deleted_at IS NULL")->fetchColumn();
    if ($cnt === 0) {
        $stmt = $pdo->prepare("INSERT INTO gas_stations (name, status, created_at) VALUES (?, 'active', NOW())");
        $stmt->execute(['Petromar Trade and Service Center']);
        $stmt->execute(['Queensforth Corporation']);
        echo "Seeded 2 stations\n";
    } else {
        echo "Skip seed: {$cnt} existing\n";
    }

    // Copy distinct historical station names that aren't in master yet (inactive marker not needed, just ensure they exist for filter)
    // Not seeding unknowns as active — leave as-is.

    echo "DONE\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}
