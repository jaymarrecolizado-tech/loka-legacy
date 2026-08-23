<?php
/**
 * Health check endpoint for Docker container and load balancers
 */

header('Content-Type: application/json');

try {
    // Check database connection
    $dbCheck = false;
    try {
        $pdo = new PDO(
            'mysql:host=' . ($_ENV['DB_HOST'] ?? 'localhost') . ';dbname=' . ($_ENV['DB_NAME'] ?? 'loka_fleet'),
            $_ENV['DB_USER'] ?? 'root',
            $_ENV['DB_PASSWORD'] ?? ''
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
