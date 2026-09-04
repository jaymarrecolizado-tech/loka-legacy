<?php
/**
 * Health check endpoint for Docker container and load balancers
 */

header('Content-Type: application/json');

try {
    // Load .env if not already (like config/database.php)
    if (!isset($_ENV['DB_HOST']) || !isset($_ENV['DB_DATABASE'])) {
        $envFile = __DIR__ . '/.env';
        if (!file_exists($envFile)) {
            $envFile = __DIR__ . '/../.env.lokastage';
        }
        if (file_exists($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line=trim($line); if($line===''||$line[0]==='#') continue;
                if(strpos($line,'=')!==false){ [$k,$v]=explode('=',$line,2); $k=trim($k); $v=trim(trim($v)," \t\"'"); if(!isset($_ENV[$k])) $_ENV[$k]=$v; }
            }
        }
    }
    // Check database connection
    $dbCheck = false;
    try {
        $dbName = $_ENV['DB_DATABASE'] ?? $_ENV['DB_NAME'] ?? 'loka_fleet';
        $dbUser = $_ENV['DB_USERNAME'] ?? $_ENV['DB_USER'] ?? 'root';
        $dbPass = $_ENV['DB_PASSWORD'] ?? $_ENV['DB_PASS'] ?? '';
        $pdo = new PDO(
            'mysql:host=' . ($_ENV['DB_HOST'] ?? 'localhost') . ';dbname=' . $dbName,
            $dbUser,
            $dbPass
        );
        $dbCheck = $pdo->query('SELECT 1')->fetch();
    } catch (PDOException $e) {
        // Database connection failed
    }

    // Check Redis connection (if available)
    $redisCheck = false;
    if (extension_loaded('redis')) {
        try {
            $redis = new Redis();
            $redis->connect($_ENV['REDIS_HOST'] ?? '127.0.0.1', $_ENV['REDIS_PORT'] ?? 6379);
            $redisCheck = $redis->ping();
        } catch (Exception $e) {
            // Redis connection failed
        }
    }

    $healthy = $dbCheck !== false;

    http_response_code($healthy ? 200 : 503);
    echo json_encode([
        'status' => $healthy ? 'healthy' : 'unhealthy',
        'timestamp' => date('c'),
        'checks' => [
            'database' => $dbCheck !== false ? 'ok' : 'failed',
            'redis' => $redisCheck ? 'ok' : 'skipped',
        ],
    ]);
} catch (Exception $e) {
    http_response_code(503);
    echo json_encode([
        'status' => 'unhealthy',
        'timestamp' => date('c'),
        'error' => $e->getMessage(),
    ]);
}
