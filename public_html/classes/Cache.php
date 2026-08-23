<?php
/**
 * LOKA - Cache Class
 *
 * Provides caching functionality with APCu and file-based fallback
 * Reduces database load for frequently accessed static data
 */

class Cache
{
    private static ?Cache $instance = null;
    private bool $useApcu;
    private string $fileCacheDir;
    private int $defaultTtl;

    // Cache key prefixes for organization
    public const KEY_DEPARTMENTS = 'dept:';
    public const KEY_VEHICLES = 'vehicle:';
    public const KEY_DRIVERS = 'driver:';
    public const KEY_USERS = 'user:';
    public const KEY_SETTINGS = 'setting:';
    public const KEY_VEHICLE_TYPES = 'vtype:';

    private function __construct(int $defaultTtl = 3600)
    {
        $this->defaultTtl = $defaultTtl;
        $this->useApcu = extension_loaded('apcu') && apcu_enabled();
        $this->fileCacheDir = __DIR__ . '/../cache/data/';

        // Create file cache directory if needed
        if (!$this->useApcu && !is_dir($this->fileCacheDir)) {
            @mkdir($this->fileCacheDir, 0755, true);
        }
    }

    public static function getInstance(int $defaultTtl = 3600): Cache
    {
        if (self::$instance === null) {
            self::$instance = new self($defaultTtl);
        }
        return self::$instance;
    }

    /**
     * Get value from cache
     *
     * @param string $key Cache key
     * @return mixed|null Cached value or null if not found/expired
     */
    public function get(string $key)
    {
        if ($this->useApcu) {
            $success = false;
            $value = apcu_fetch($this->prefixKey($key), $success);
            return $success ? $value : null;
        }

        // File-based fallback
        return $this->getFromFile($key);
    }

    /**
     * Set value in cache
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int|null $ttl Time to live in seconds (null for default)
     * @return bool True if successful
     */
    public function set(string $key, $value, ?int $ttl = null): bool
    {
        $ttl = $ttl ?? $this->defaultTtl;

        if ($this->useApcu) {
            return apcu_store($this->prefixKey($key), $value, $ttl);
        }

        // File-based fallback
        return $this->setToFile($key, $value, $ttl);
    }

    /**
     * Delete value from cache
     *
     * @param string $key Cache key
     * @return bool True if successful
     */
    public function delete(string $key): bool
    {
        if ($this->useApcu) {
            return apcu_delete($this->prefixKey($key));
        }

        // File-based fallback
        $file = $this->getFilePath($key);
        if (file_exists($file)) {
            return @unlink($file);
        }
        return true;
    }

    /**
     * Delete all cache entries matching a pattern
     *
     * @param string $pattern Pattern to match (e.g., 'user:')
     * @return int Number of entries deleted
     */
    public function deletePattern(string $pattern): int
    {
        $count = 0;
        $fullPattern = $this->prefixKey($pattern);

        if ($this->useApcu) {
            // APCu: iterate through all keys
            $iterator = new APCUIterator('/^' . preg_quote($fullPattern, '/') . '/');
            foreach ($iterator as $item) {
                if (apcu_delete($item['key'])) {
                    $count++;
                }
            }
        } else {
            // File-based: glob matching files
            $patternHash = md5($fullPattern);
            $files = glob($this->fileCacheDir . $patternHash . '*.json');
            foreach ($files as $file) {
                if (@unlink($file)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Clear all cache
     *
     * @return bool True if successful
     */
    public function clear(): bool
    {
        if ($this->useApcu) {
            return apcu_clear_cache();
        }

        // File-based: remove all cache files
        $files = glob($this->fileCacheDir . '*.json');
        foreach ($files as $file) {
            @unlink($file);
        }
        return true;
    }

    /**
     * Check if key exists in cache
     *
     * @param string $key Cache key
     * @return bool True if exists and not expired
     */
    public function has(string $key): bool
    {
        if ($this->useApcu) {
            return apcu_exists($this->prefixKey($key));
        }

        // File-based fallback
        $file = $this->getFilePath($key);
        if (!file_exists($file)) {
            return false;
        }

        $data = json_decode(file_get_contents($file));
        return $data->expires > time();
    }

    /**
     * Get or set pattern - fetch from cache or execute callback and cache result
     *
     * @param string $key Cache key
     * @param callable $callback Function to execute if cache miss
     * @param int|null $ttl Time to live in seconds
     * @return mixed Cached or callback result
     */
    public function remember(string $key, callable $callback, ?int $ttl = null)
    {
        $value = $this->get($key);

        if ($value !== null) {
            return $value;
        }

        // Cache miss - execute callback
        $value = $callback();
        $this->set($key, $value, $ttl);
        return $value;
    }

    // =========================================================================
    // PRIVATE HELPER METHODS
    // =========================================================================

    /**
     * Add application prefix to cache key
     */
    private function prefixKey(string $key): string
    {
        return 'loka:' . $key;
    }

    /**
     * Get cache file path for key
     */
    private function getFilePath(string $key): string
    {
        $hash = md5($this->prefixKey($key));
        return $this->fileCacheDir . $hash . '.json';
    }

    /**
     * Get value from file cache
     */
    private function getFromFile(string $key)
    {
        $file = $this->getFilePath($key);

        if (!file_exists($file)) {
            return null;
        }

        $data = json_decode(file_get_contents($file));

        // Check if expired
        if ($data->expires < time()) {
            @unlink($file);
            return null;
        }

        return $data->value;
    }

    /**
     * Set value in file cache
     */
    private function setToFile(string $key, $value, int $ttl): bool
    {
        $file = $this->getFilePath($key);

        $data = [
            'value' => $value,
            'expires' => time() + $ttl,
            'created' => time()
        ];

        $bytes = file_put_contents($file, json_encode($data));
        return $bytes !== false;
    }

    /**
     * Clean up expired cache files
     * Should be called periodically via cron
     */
    public function cleanup(): int
    {
        if ($this->useApcu) {
            // APCu handles expiration automatically
            return 0;
        }

        $count = 0;
        $now = time();
        $files = glob($this->fileCacheDir . '*.json');

        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file));
            if ($data->expires < $now) {
                if (@unlink($file)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Get cache statistics
     */
    public function getStats(): array
    {
        if ($this->useApcu) {
            $info = apcu_cache_info(true);
            return [
                'type' => 'apcu',
                'enabled' => true,
                'entries' => $info['num_entries'] ?? 0,
                'size' => $info['mem_size'] ?? 0,
                'size_human' => $this->formatBytes($info['mem_size'] ?? 0)
            ];
        }

        // File-based stats
        $files = glob($this->fileCacheDir . '*.json');
        $totalSize = 0;
        $validCount = 0;

        foreach ($files as $file) {
            $totalSize += filesize($file);
            $data = json_decode(file_get_contents($file));
            if ($data->expires > time()) {
                $validCount++;
            }
        }

        return [
            'type' => 'file',
            'enabled' => true,
            'entries' => $validCount,
            'size' => $totalSize,
            'size_human' => $this->formatBytes($totalSize),
            'directory' => $this->fileCacheDir
        ];
    }

    /**
     * Format bytes to human readable
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    // Prevent cloning
    private function __clone() {}

    public function __wakeup()
    {
        throw new Exception("Cannot unserialize singleton");
    }
}
