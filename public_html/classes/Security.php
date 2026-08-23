<?php
/**
 * LOKA - Security Class
 * 
 * Handles rate limiting, input validation, and security utilities
 */

class Security
{
    private static ?Security $instance = null;
    private Database $db;

    private function __construct()
    {
        $this->db = Database::getInstance();
    }

    public static function getInstance(): Security
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // =========================================================================
    // RATE LIMITING
    // =========================================================================

    /**
     * Check if action is rate limited
     */
    public function isRateLimited(string $action, string $identifier, int $maxAttempts, int $windowSeconds): bool
    {
        $this->cleanupRateLimits();
        
        $count = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM rate_limits 
             WHERE action = ? AND identifier = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)",
            [$action, $identifier, $windowSeconds]
        );
        
        return $count >= $maxAttempts;
    }

    /**
     * Record a rate limit attempt (atomic - reduces race condition window)
     */
    public function recordAttempt(string $action, string $identifier): void
    {
        $ip = $this->getClientIp();
        $now = date('Y-m-d H:i:s');
        
        // Use INSERT IGNORE to silently skip duplicate concurrent attempts
        // This reduces race condition window without requiring schema changes
        $this->db->query(
            "INSERT IGNORE INTO rate_limits (action, identifier, ip_address, created_at) 
             VALUES (?, ?, ?, ?)",
            [$action, $identifier, $ip, $now]
        );
    }

    /**
     * Clear rate limits for identifier
     */
    public function clearRateLimits(string $action, string $identifier): void
    {
        $this->db->delete('rate_limits', 'action = ? AND identifier = ?', [$action, $identifier]);
    }

    /**
     * Get remaining lockout time in seconds
     */
    public function getLockoutRemaining(string $action, string $identifier, int $windowSeconds): int
    {
        $oldest = $this->db->fetchColumn(
            "SELECT MIN(created_at) FROM rate_limits 
             WHERE action = ? AND identifier = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)",
            [$action, $identifier, $windowSeconds]
        );
        
        if (!$oldest) return 0;
        
        $unlockTime = strtotime($oldest) + $windowSeconds;
        return max(0, $unlockTime - time());
    }

    /**
     * Cleanup old rate limit records
     */
    private function cleanupRateLimits(): void
    {
        // Run cleanup randomly (1% chance) to avoid doing it every request
        if (mt_rand(1, 100) === 1) {
            $this->db->query(
                "DELETE FROM rate_limits WHERE created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)"
            );
        }
    }

    // =========================================================================
    // INPUT SANITIZATION
    // =========================================================================

    /**
     * Sanitize string input
     */
    public function sanitizeString(?string $input, int $maxLength = 0): string
    {
        if ($input === null) return '';
        
        $input = trim($input);
        $input = strip_tags($input);
        
        // Remove null bytes and other control characters
        $input = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $input);
        
        if ($maxLength > 0) {
            $input = mb_substr($input, 0, $maxLength, 'UTF-8');
        }
        
        return $input;
    }

    /**
     * Sanitize email
     */
    public function sanitizeEmail(?string $email): string
    {
        if ($email === null) return '';
        return filter_var(trim($email), FILTER_SANITIZE_EMAIL);
    }

    /**
     * Sanitize integer
     */
    public function sanitizeInt($input): int
    {
        return (int) filter_var($input, FILTER_SANITIZE_NUMBER_INT);
    }

    /**
     * Sanitize URL
     */
    public function sanitizeUrl(?string $url): string
    {
        if ($url === null) return '';
        return filter_var(trim($url), FILTER_SANITIZE_URL);
    }

    /**
     * Sanitize filename
     */
    public function sanitizeFilename(?string $filename): string
    {
        if ($filename === null) return '';
        
        // Remove path components
        $filename = basename($filename);
        
        // Remove special characters
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
        
        // Prevent double extensions
        $filename = preg_replace('/\.{2,}/', '.', $filename);
        
        return $filename;
    }

    // =========================================================================
    // PASSWORD VALIDATION
    // =========================================================================

    /**
     * Validate password against policy
     * Returns array of errors, empty if valid
     */
    public function validatePassword(string $password): array
    {
        $errors = [];
        
        if (strlen($password) < PASSWORD_MIN_LENGTH) {
            $errors[] = 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters';
        }
        
        if (PASSWORD_REQUIRE_UPPERCASE && !preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain at least one uppercase letter';
        }
        
        if (PASSWORD_REQUIRE_LOWERCASE && !preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must contain at least one lowercase letter';
        }
        
        if (PASSWORD_REQUIRE_NUMBER && !preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must contain at least one number';
        }
        
        if (PASSWORD_REQUIRE_SPECIAL && !preg_match('/[' . preg_quote(PASSWORD_SPECIAL_CHARS, '/') . ']/', $password)) {
            $errors[] = 'Password must contain at least one special character';
        }
        
        // Check for common weak passwords
        $weakPasswords = ['password', '12345678', 'qwerty123', 'admin123', 'letmein1'];
        if (in_array(strtolower($password), $weakPasswords)) {
            $errors[] = 'This password is too common. Please choose a stronger password';
        }
        
        return $errors;
    }

    /**
     * Get password requirements message
     */
    public function getPasswordRequirements(): string
    {
        $reqs = ['At least ' . PASSWORD_MIN_LENGTH . ' characters'];
        
        if (PASSWORD_REQUIRE_UPPERCASE) $reqs[] = 'one uppercase letter';
        if (PASSWORD_REQUIRE_LOWERCASE) $reqs[] = 'one lowercase letter';
        if (PASSWORD_REQUIRE_NUMBER) $reqs[] = 'one number';
        if (PASSWORD_REQUIRE_SPECIAL) $reqs[] = 'one special character';
        
        return implode(', ', $reqs);
    }

    // =========================================================================
    // SESSION SECURITY
    // =========================================================================

    /**
     * Generate session fingerprint
     */
    public function generateFingerprint(): string
    {
        $data = [
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''
        ];
        
        return hash('sha256', implode('|', $data));
    }

    /**
     * Validate session fingerprint
     */
    public function validateFingerprint(): bool
    {
        if (!SESSION_FINGERPRINT_ENABLED) return true;
        
        $currentFingerprint = $this->generateFingerprint();
        $storedFingerprint = $_SESSION['_fingerprint'] ?? null;
        
        if ($storedFingerprint === null) {
            $_SESSION['_fingerprint'] = $currentFingerprint;
            return true;
        }
        
        return hash_equals($storedFingerprint, $currentFingerprint);
    }

    /**
     * Store session fingerprint
     */
    public function storeFingerprint(): void
    {
        $_SESSION['_fingerprint'] = $this->generateFingerprint();
    }

    // =========================================================================
    // CSRF PROTECTION
    // =========================================================================

    /**
     * Generate new CSRF token
     */
    public function generateCsrfToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
        $_SESSION['csrf_token_time'] = time();
        return $token;
    }

    /**
     * Get current CSRF token (generate if needed)
     */
    public function getCsrfToken(): string
    {
        // Check if token exists and is not expired
        if (isset($_SESSION['csrf_token']) && isset($_SESSION['csrf_token_time'])) {
            if (time() - $_SESSION['csrf_token_time'] < CSRF_TOKEN_LIFETIME) {
                return $_SESSION['csrf_token'];
            }
        }
        
        return $this->generateCsrfToken();
    }

    /**
     * Verify CSRF token
     */
    public function verifyCsrfToken(?string $token): bool
    {
        if (!isset($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_time'])) {
            return false;
        }
        
        // Check expiration
        if (time() - $_SESSION['csrf_token_time'] > CSRF_TOKEN_LIFETIME) {
            return false;
        }
        
        $valid = hash_equals($_SESSION['csrf_token'], $token ?? '');
        
        // Rotate token after use if configured
        if ($valid && CSRF_ROTATE_ON_USE) {
            $this->generateCsrfToken();
        }
        
        return $valid;
    }

    // =========================================================================
    // SECURITY HEADERS
    // =========================================================================

    /**
     * Send all security headers
     */
    public function sendSecurityHeaders(): void
    {
        // Don't send headers if already sent
        if (headers_sent()) return;
        
        foreach (SECURITY_HEADERS as $header => $value) {
            header("$header: $value");
        }
        
        // Content Security Policy
        header("Content-Security-Policy: " . CSP_POLICY);
        
        // HSTS (only in production with HTTPS)
        if (HSTS_ENABLED) {
            $hsts = "max-age=" . HSTS_MAX_AGE;
            if (HSTS_INCLUDE_SUBDOMAINS) {
                $hsts .= "; includeSubDomains";
            }
            header("Strict-Transport-Security: $hsts");
        }
    }

    // =========================================================================
    // IP & REQUEST UTILITIES
    // =========================================================================

    /**
     * Get client IP address
     */
    public function getClientIp(): string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR'
        ];
        
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                // Handle comma-separated list (X-Forwarded-For)
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        
        return '0.0.0.0';
    }

    /**
     * Check if IP is in whitelist/blacklist
     */
    public function checkIpAccess(): bool
    {
        $ip = $this->getClientIp();
        
        // Check blacklist first
        if (IP_BLACKLIST_ENABLED && in_array($ip, IP_BLACKLIST)) {
            return false;
        }
        
        // Check whitelist
        if (IP_WHITELIST_ENABLED && !in_array($ip, IP_WHITELIST)) {
            return false;
        }
        
        return true;
    }

    /**
     * Log security event
     */
    public function logSecurityEvent(string $event, ?string $details = null, ?int $userId = null): void
    {
        $this->db->insert('security_logs', [
            'event' => $event,
            'user_id' => $userId,
            'ip_address' => $this->getClientIp(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'details' => $details,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    // =========================================================================
    // DDOS PROTECTION
    // =========================================================================

    /**
     * Check if IP is temporarily banned
     * Uses file-based storage for performance (no DB query needed)
     */
    public function isIpBanned(): bool
    {
        $ip = $this->getClientIp();
        $banFile = $this->getBanFilePath($ip);

        if (!file_exists($banFile)) {
            return false;
        }

        $data = json_decode(file_get_contents($banFile), true);

        // Check if ban has expired
        if ($data['expires_at'] < time()) {
            @unlink($banFile);
            return false;
        }

        return true;
    }

    /**
     * Get remaining ban time in seconds
     */
    public function getBanRemainingTime(): int
    {
        $ip = $this->getClientIp();
        $banFile = $this->getBanFilePath($ip);

        if (!file_exists($banFile)) {
            return 0;
        }

        $data = json_decode(file_get_contents($banFile), true);
        $remaining = $data['expires_at'] - time();

        return max(0, $remaining);
    }

    /**
     * Temporarily ban an IP
     * @param int $duration Ban duration in seconds (default: 1 hour)
     */
    public function banIp(int $duration = 3600): void
    {
        $ip = $this->getClientIp();
        $banFile = $this->getBanFilePath($ip);

        $data = [
            'ip' => $ip,
            'banned_at' => time(),
            'expires_at' => time() + $duration,
            'reason' => 'Exceeded rate limits'
        ];

        // Create ban directory if it doesn't exist
        $banDir = dirname($banFile);
        if (!is_dir($banDir)) {
            @mkdir($banDir, 0755, true);
        }

        file_put_contents($banFile, json_encode($data));

        // Log the ban
        $this->logSecurityEvent('ip_banned', "IP banned for {$duration} seconds");
    }

    /**
     * Get ban file path for an IP
     */
    private function getBanFilePath(string $ip): string
    {
        // Create a hash of the IP to avoid filename issues
        $hash = md5($ip);
        return __DIR__ . '/../logs/bans/' . $hash . '.json';
    }

    /**
     * Check global request rate (per IP, all requests)
     * Uses file-based counter for better performance during DDoS
     *
     * @param int $maxRequests Max requests per window
     * @param int $windowSeconds Time window in seconds
     * @return bool True if rate limited
     */
    public function checkGlobalRateLimit(int $maxRequests = 60, int $windowSeconds = 60): bool
    {
        $ip = $this->getClientIp();
        $rateFile = $this->getRateFilePath($ip);

        $now = time();
        $windowStart = $now - $windowSeconds;

        // Get existing data
        if (file_exists($rateFile)) {
            $data = json_decode(file_get_contents($rateFile), true);

            // Filter out old requests
            $data['requests'] = array_values(array_filter($data['requests'], function($timestamp) use ($windowStart) {
                return $timestamp > $windowStart;
            }));

            // Check if over limit
            if (count($data['requests']) >= $maxRequests) {
                // Check if should ban
                if (count($data['requests']) >= $maxRequests * 2) {
                    $this->banIp(7200); // 2 hour ban for severe abuse
                }
                return true;
            }
        } else {
            $data = ['requests' => []];

            // Create directory if needed
            $rateDir = dirname($rateFile);
            if (!is_dir($rateDir)) {
                @mkdir($rateDir, 0755, true);
            }
        }

        // Add current request
        $data['requests'][] = $now;
        file_put_contents($rateFile, json_encode($data));

        return false;
    }

    /**
     * Get rate limit file path for an IP
     */
    private function getRateFilePath(string $ip): string
    {
        $hash = md5($ip);
        return __DIR__ . '/../logs/rates/' . $hash . '.json';
    }

    /**
     * Detect suspicious activity patterns
     * Returns true if activity looks automated/attack-like
     */
    public function detectSuspiciousActivity(): bool
    {
        $suspicious = false;
        $reasons = [];

        // Check for missing User-Agent (automated tools often don't send one)
        if (empty($_SERVER['HTTP_USER_AGENT'])) {
            $suspicious = true;
            $reasons[] = 'no_user_agent';
        }

        // Check for suspicious User-Agent patterns
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $suspiciousPatterns = [
            '/bot/i', '/crawl/i', '/spider/i', '/scan/i',
            '/curl/i', '/wget/i', '/python/i', '/perl/i',
            '/java/i', '/go-http-client/i'
        ];

        foreach ($suspiciousPatterns as $pattern) {
            if (preg_match($pattern, $ua)) {
                $suspicious = true;
                $reasons[] = 'suspicious_ua';
                break;
            }
        }

        // Check for request without proper headers (browser always sends these)
        if (empty($_SERVER['HTTP_ACCEPT']) || empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            $suspicious = true;
            $reasons[] = 'missing_browser_headers';
        }

        // Check if request is from localhost but not local dev
        $ip = $this->getClientIp();
        if ($ip === '127.0.0.1' || $ip === '::1' || $ip === 'localhost') {
            // Allow localhost in development
            if (!IS_PRODUCTION) {
                $suspicious = false;
                $reasons = [];
            }
        }

        // Log if suspicious
        if ($suspicious && !empty($reasons)) {
            $this->logSecurityEvent('suspicious_activity', implode(', ', $reasons));
        }

        return $suspicious;
    }

    /**
     * Clean up old rate and ban files
     * Should be called periodically (e.g., via cron)
     */
    public function cleanupSecurityFiles(): void
    {
        $now = time();

        // Clean up old rate files
        $rateDir = __DIR__ . '/../logs/rates/';
        if (is_dir($rateDir)) {
            foreach (glob($rateDir . '*.json') as $file) {
                if ($now - filemtime($file) > 3600) { // 1 hour old
                    @unlink($file);
                }
            }
        }

        // Clean up expired ban files
        $banDir = __DIR__ . '/../logs/bans/';
        if (is_dir($banDir)) {
            foreach (glob($banDir . '*.json') as $file) {
                $data = json_decode(file_get_contents($file), true);
                if ($data['expires_at'] < $now) {
                    @unlink($file);
                }
            }
        }
    }

    /**
     * Get current security status for monitoring
     */
    public function getSecurityStatus(): array
    {
        $banDir = __DIR__ . '/../logs/bans/';
        $rateDir = __DIR__ . '/../logs/rates/';

        $activeBans = 0;
        if (is_dir($banDir)) {
            $activeBans = count(glob($banDir . '*.json'));
        }

        $activeTracking = 0;
        if (is_dir($rateDir)) {
            $activeTracking = count(glob($rateDir . '*.json'));
        }

        return [
            'active_bans' => $activeBans,
            'tracked_ips' => $activeTracking,
            'current_ip_banned' => $this->isIpBanned(),
            'ban_remaining' => $this->getBanRemainingTime()
        ];
    }

    // Prevent cloning
    private function __clone() {}

    public function __wakeup()
    {
        throw new Exception("Cannot unserialize singleton");
    }
}
