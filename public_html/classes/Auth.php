<?php
/**
 * LOKA - Authentication Class
 * 
 * Handles user authentication with rate limiting and security logging
 */

class Auth
{
    private Database $db;
    private Security $security;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->security = Security::getInstance();
    }

    /**
     * Check if login is rate limited
     */
    public function isLoginRateLimited(string $email): bool
    {
        // Check by email
        if ($this->security->isRateLimited('login', $email, RATE_LIMIT_LOGIN_ATTEMPTS, RATE_LIMIT_LOGIN_WINDOW)) {
            return true;
        }
        
        // Also check by IP
        $ip = $this->security->getClientIp();
        if ($this->security->isRateLimited('login_ip', $ip, RATE_LIMIT_LOGIN_ATTEMPTS * 2, RATE_LIMIT_LOGIN_WINDOW)) {
            return true;
        }
        
        return false;
    }

    /**
     * Get remaining lockout time
     */
    public function getLockoutTime(string $email): int
    {
        $emailLockout = $this->security->getLockoutRemaining('login', $email, RATE_LIMIT_LOGIN_WINDOW);
        $ipLockout = $this->security->getLockoutRemaining('login_ip', $this->security->getClientIp(), RATE_LIMIT_LOGIN_WINDOW);
        
        return max($emailLockout, $ipLockout);
    }

    /**
     * Attempt login with email and password
     */
    public function attempt(string $email, string $password, bool $remember = false): array
    {
        // Check rate limiting
        if ($this->isLoginRateLimited($email)) {
            $remaining = $this->getLockoutTime($email);
            $minutes = ceil($remaining / 60);
            
            if (LOG_RATE_LIMIT_HITS) {
                $this->security->logSecurityEvent('login_rate_limited', "Email: $email", null);
            }
            
            return [
                'success' => false,
                'error' => "Too many failed attempts. Please try again in $minutes minute(s).",
                'locked' => true
            ];
        }
        
        $user = $this->db->fetch(
            "SELECT * FROM users WHERE email = ? AND deleted_at IS NULL",
            [$email]
        );

        // Check if user exists
        if (!$user) {
            $this->recordFailedLogin($email);
            return ['success' => false, 'error' => 'Invalid email or password'];
        }
        
        // Check if account is locked
        if ($user->locked_until && strtotime($user->locked_until) > time()) {
            $remaining = ceil((strtotime($user->locked_until) - time()) / 60);
            return [
                'success' => false,
                'error' => "Account locked. Try again in $remaining minute(s).",
                'locked' => true
            ];
        }
        
        // Check if account is active
        if ($user->status !== USER_ACTIVE) {
            return ['success' => false, 'error' => 'Invalid email or password'];
        }
        
        // Verify password
        if (!password_verify($password, $user->password)) {
            $this->recordFailedLogin($email, $user->id);
            return ['success' => false, 'error' => 'Invalid email or password'];
        }

        // Success - clear rate limits and login
        $this->security->clearRateLimits('login', $email);
        $this->clearFailedAttempts($user->id);
        $this->login($user, $remember);
        
        if (LOG_SUCCESSFUL_LOGINS) {
            $this->security->logSecurityEvent('login_success', "Email: $email", $user->id);
        }
        
        return ['success' => true, 'user' => $user];
    }

    /**
     * Record failed login attempt
     */
    private function recordFailedLogin(string $email, ?int $userId = null): void
    {
        // Record in rate limits
        $this->security->recordAttempt('login', $email);
        $this->security->recordAttempt('login_ip', $this->security->getClientIp());
        
        // Log the event
        if (LOG_FAILED_LOGINS) {
            $this->security->logSecurityEvent('login_failed', "Email: $email", $userId);
        }
        
        // Update user's failed attempt counter if user exists (atomic - prevents bypass)
        if ($userId) {
            $lockUntil = date('Y-m-d H:i:s', time() + RATE_LIMIT_LOGIN_LOCKOUT);
            
            // Single atomic UPDATE with CASE WHEN - prevents race condition
            $result = $this->db->query(
                "UPDATE users SET 
                    failed_login_attempts = failed_login_attempts + 1,
                    last_failed_login = NOW(),
                    locked_until = CASE 
                        WHEN (failed_login_attempts + 1) >= ? THEN ?
                        ELSE locked_until 
                    END
                 WHERE id = ?",
                [RATE_LIMIT_LOGIN_ATTEMPTS, $lockUntil, $userId]
            );
            
            // Check if account was just locked
            $user = $this->db->fetch("SELECT failed_login_attempts, locked_until FROM users WHERE id = ?", [$userId]);
            if ($user && $user->failed_login_attempts >= RATE_LIMIT_LOGIN_ATTEMPTS && $user->locked_until) {
                $this->security->logSecurityEvent('account_locked', "Email: $email, locked until: {$user->locked_until}", $userId);
            }
        }
    }

    /**
     * Clear failed login attempts
     */
    private function clearFailedAttempts(int $userId): void
    {
        $this->db->update('users', [
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_failed_login' => null
        ], 'id = ?', [$userId]);
    }

    /**
     * Login user (create session)
     */
    public function login(object $user, bool $remember = false): void
    {
        // Regenerate session ID to prevent fixation
        if (!session_regenerate_id(true)) {
            error_log("Auth::login() - session_regenerate_id() failed");
        }

        // Set session data
        $_SESSION['user_id'] = $user->id;
        $_SESSION['user_role'] = $user->role;
        $_SESSION['user_email'] = $user->email;
        $_SESSION['user_name'] = $user->name;
        $_SESSION['user_department_id'] = $user->department_id;
        $_SESSION['user'] = $user;
        $_SESSION['logged_in_at'] = time();
        
        // Reset session timers for fresh login
        $_SESSION['_created'] = time();
        $_SESSION['_absolute_start'] = time();
        $_SESSION['_last_activity'] = time();
        
        // Store fingerprint immediately after login to prevent validation issues
        $this->security->storeFingerprint();

        // Update last login
        $this->db->update('users', ['last_login_at' => date(DATETIME_FORMAT)], 'id = ?', [$user->id]);

        // Handle remember me
        if ($remember) {
            $this->setRememberToken($user->id);
        }

        // Audit log
        auditLog('login', 'user', $user->id);
    }

    /**
     * Logout user
     */
    public function logout(): void
    {
        $userId = userId();

        // Clear remember token
        $this->clearRememberToken();

        // Audit log before destroying session
        if ($userId) {
            auditLog('logout', 'user', $userId);
        }

        // Destroy session
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        session_destroy();
    }

    /**
     * Set remember me token (atomic - prevents race conditions)
     */
    private function setRememberToken(int $userId): void
    {
        $selector = bin2hex(random_bytes(16));
        $token = bin2hex(random_bytes(32));
        $hashedToken = hash('sha256', $token);
        $expires = date(DATETIME_FORMAT, strtotime('+' . REMEMBER_ME_DAYS . ' days'));

        // Atomic operation: Wrap in transaction to prevent token hoarding
        $this->db->beginTransaction();
        try {
            // Delete old tokens for this user (row-level lock via transaction)
            $this->db->delete('remember_tokens', 'user_id = ?', [$userId]);

            // Insert new token
            $this->db->insert('remember_tokens', [
                'user_id' => $userId,
                'selector' => $selector,
                'hashed_token' => $hashedToken,
                'expires' => $expires,
                'created_at' => date(DATETIME_FORMAT)
            ]);

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Auth::setRememberToken() failed for user #{$userId}: " . $e->getMessage());
            throw $e;
        }

        // Set secure cookie with proper options
        $cookieValue = $selector . ':' . $token;
        setcookie(
            'remember_token',
            $cookieValue,
            [
                'expires' => time() + (REMEMBER_ME_DAYS * 24 * 60 * 60),
                'path' => COOKIE_PATH,
                'domain' => COOKIE_DOMAIN,
                'secure' => COOKIE_SECURE,
                'httponly' => COOKIE_HTTPONLY,
                'samesite' => COOKIE_SAMESITE
            ]
        );
    }

    /**
     * Check and process remember me token
     */
    public function checkRememberMe(): bool
    {
        if (isLoggedIn()) {
            return true;
        }

        if (!isset($_COOKIE['remember_token'])) {
            return false;
        }

        $parts = explode(':', $_COOKIE['remember_token']);
        if (count($parts) !== 2) {
            $this->clearRememberToken();
            return false;
        }

        [$selector, $token] = $parts;

        $record = $this->db->fetch(
            "SELECT * FROM remember_tokens WHERE selector = ? AND expires > NOW()",
            [$selector]
        );

        if (!$record) {
            $this->clearRememberToken();
            return false;
        }

        if (!hash_equals($record->hashed_token, hash('sha256', $token))) {
            $this->clearRememberToken();
            return false;
        }

        // Get user
        $user = $this->db->fetch(
            "SELECT * FROM users WHERE id = ? AND status = ? AND deleted_at IS NULL",
            [$record->user_id, USER_ACTIVE]
        );

        if (!$user) {
            $this->clearRememberToken();
            return false;
        }

        // Login user
        $this->login($user, true);
        return true;
    }

    /**
     * Clear remember me token
     */
    private function clearRememberToken(): void
    {
        if (isset($_COOKIE['remember_token'])) {
            $parts = explode(':', $_COOKIE['remember_token']);
            if (count($parts) === 2) {
                $this->db->delete('remember_tokens', 'selector = ?', [$parts[0]]);
            }
        }

        // Clear cookie with proper options
        setcookie('remember_token', '', [
            'expires' => time() - 3600,
            'path' => COOKIE_PATH,
            'domain' => COOKIE_DOMAIN,
            'secure' => COOKIE_SECURE,
            'httponly' => COOKIE_HTTPONLY,
            'samesite' => COOKIE_SAMESITE
        ]);
    }

    /**
     * Get user by ID
     */
    public function getUser(int $id): ?object
    {
        return $this->db->fetch(
            "SELECT u.*, d.name as department_name 
             FROM users u 
             LEFT JOIN departments d ON u.department_id = d.id 
             WHERE u.id = ? AND u.deleted_at IS NULL",
            [$id]
        );
    }

    /**
     * Get user by email
     */
    public function getUserByEmail(string $email): ?object
    {
        return $this->db->fetch(
            "SELECT * FROM users WHERE email = ? AND deleted_at IS NULL",
            [$email]
        );
    }

    /**
     * Hash password
     */
    public function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /**
     * Update password
     */
    public function updatePassword(int $userId, string $newPassword): bool
    {
        $hash = $this->hashPassword($newPassword);
        $result = $this->db->update('users', ['password' => $hash], 'id = ?', [$userId]);
        
        if ($result) {
            auditLog('password_changed', 'user', $userId);
        }
        
        return $result > 0;
    }

    // =========================================================================
    // PASSWORD RESET FUNCTIONALITY
    // =========================================================================

    /**
     * Request password reset
     * Generates a secure token and queues reset email
     * 
     * @param string $email User email address
     * @return array Result with success status and message
     */
    public function requestPasswordReset(string $email): array
    {
        $email = $this->security->sanitizeEmail($email);
        
        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'error' => 'Invalid email format'
            ];
        }
        
        // Check rate limiting (max 3 requests per hour per email)
        if ($this->security->isRateLimited('password_reset', $email, 3, 3600)) {
            $remaining = $this->security->getLockoutRemaining('password_reset', $email, 3600);
            $minutes = ceil($remaining / 60);
            
            return [
                'success' => false,
                'error' => "Too many reset requests. Please try again in {$minutes} minute(s).",
                'rate_limited' => true
            ];
        }
        
        // Record the attempt for rate limiting (even if email doesn't exist)
        $this->security->recordAttempt('password_reset', $email);
        
        // Find user by email
        $user = $this->getUserByEmail($email);
        
        // Security: Don't reveal if email exists
        // Return success message even if user not found
        if (!$user) {
            return [
                'success' => true,
                'message' => 'If an account exists with this email, you will receive a password reset link shortly.'
            ];
        }
        
        // Check if user is active
        if ($user->status !== USER_ACTIVE) {
            return [
                'success' => true,
                'message' => 'If an account exists with this email, you will receive a password reset link shortly.'
            ];
        }
        
        // Generate secure token (32 bytes = 64 hex chars)
        $token = bin2hex(random_bytes(32));
        $hashedToken = hash('sha256', $token);
        
        // Token expires in 1 hour
        $expiresAt = date(DATETIME_FORMAT, strtotime('+1 hour'));
        
        // Store hashed token in database
        try {
            $this->db->insert('password_reset_tokens', [
                'user_id' => $user->id,
                'token' => $hashedToken,
                'expires_at' => $expiresAt,
                'used' => 0,
                'created_at' => date(DATETIME_FORMAT)
            ]);
        } catch (Exception $e) {
            error_log("Password reset token creation failed for user {$user->id}: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Unable to process reset request. Please try again later.'
            ];
        }
        
        // Queue password reset email
        try {
            $resetLink = '/?page=reset-password&token=' . $token . '&email=' . urlencode($email);
            
            $queue = new EmailQueue();
            $queue->queue(
                $user->email,
                'Password Reset Request - ' . APP_NAME,
                $this->buildPasswordResetEmail($user->name, $resetLink, $expiresAt),
                $user->name,
                'password_reset',
                1 // High priority
            );
            
            // Log the event
            $this->security->logSecurityEvent('password_reset_requested', "Email: {$email}", $user->id);
            
        } catch (Exception $e) {
            error_log("Password reset email queue failed for {$email}: " . $e->getMessage());
            // Don't fail the request if email fails - user can request again
        }
        
        return [
            'success' => true,
            'message' => 'If an account exists with this email, you will receive a password reset link shortly.'
        ];
    }
    
    /**
     * Build password reset email HTML
     */
    private function buildPasswordResetEmail(string $userName, string $resetLink, string $expiresAt): string
    {
        $fullLink = SITE_URL . $resetLink;
        $expiryTime = formatDateTime($expiresAt);
        
        return '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Password Reset Request</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background: #f4f4f4; }
                .container { max-width: 600px; margin: 20px auto; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
                .header { background: #0d6efd; color: #fff; padding: 20px; text-align: center; }
                .header h1 { margin: 0; font-size: 24px; }
                .content { padding: 30px; }
                .message { margin-bottom: 20px; }
                .btn { display: inline-block; padding: 12px 24px; background: #0d6efd; color: #fff; text-decoration: none; border-radius: 5px; font-weight: bold; }
                .btn:hover { background: #0b5ed7; }
                .expiry { background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 5px; margin: 20px 0; color: #856404; }
                .footer { background: #f8f9fa; padding: 20px; text-align: center; font-size: 12px; color: #666; }
                .warning { color: #dc3545; font-size: 13px; margin-top: 20px; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>' . APP_NAME . '</h1>
                </div>
                <div class="content">
                    <div class="message">
                        <p>Hello ' . htmlspecialchars($userName) . ',</p>
                        <p>We received a request to reset your password. Click the button below to create a new password:</p>
                    </div>
                    <p><a href="' . htmlspecialchars($fullLink) . '" class="btn">Reset Password</a></p>
                    <div class="expiry">
                        <strong>⏰ This link expires at:</strong> ' . $expiryTime . '<br>
                        <small>(1 hour from request time)</small>
                    </div>
                    <p class="warning">
                        <strong>Security Notice:</strong> If you did not request this password reset, please ignore this email. 
                        Your account remains secure and no changes have been made.
                    </p>
                    <p style="margin-top: 20px; font-size: 13px; color: #666;">
                        If the button doesn\'t work, copy and paste this link into your browser:<br>
                        <code style="word-break: break-all;">' . htmlspecialchars($fullLink) . '</code>
                    </p>
                </div>
                <div class="footer">
                    <p>This is an automated message from ' . APP_NAME . '</p>
                    <p>Please do not reply to this email.</p>
                </div>
            </div>
        </body>
        </html>';
    }
    
    /**
     * Validate reset token
     * 
     * @param string $token The reset token from URL
     * @param string $email The email from URL
     * @return array Result with success status and user data if valid
     */
    public function validateResetToken(string $token, string $email): array
    {
        // Validate inputs
        if (empty($token) || strlen($token) !== 64 || !ctype_xdigit($token)) {
            return [
                'success' => false,
                'error' => 'Invalid reset token format'
            ];
        }
        
        $email = $this->security->sanitizeEmail($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'error' => 'Invalid email format'
            ];
        }
        
        // Hash the token for database lookup
        $hashedToken = hash('sha256', $token);
        
        // Find the token record
        $tokenRecord = $this->db->fetch(
            "SELECT prt.*, u.email as user_email, u.status as user_status, u.id as user_id, u.name as user_name
             FROM password_reset_tokens prt
             JOIN users u ON prt.user_id = u.id
             WHERE prt.token = ? AND u.email = ? AND prt.used = 0 AND prt.expires_at > NOW()
             ORDER BY prt.created_at DESC
             LIMIT 1",
            [$hashedToken, $email]
        );
        
        if (!$tokenRecord) {
            return [
                'success' => false,
                'error' => 'Invalid or expired reset link. Please request a new password reset.'
            ];
        }
        
        // Check if user is still active
        if ($tokenRecord->user_status !== USER_ACTIVE) {
            return [
                'success' => false,
                'error' => 'Your account is not active. Please contact an administrator.'
            ];
        }
        
        return [
            'success' => true,
            'user_id' => $tokenRecord->user_id,
            'user_email' => $tokenRecord->user_email,
            'user_name' => $tokenRecord->user_name,
            'token_id' => $tokenRecord->id
        ];
    }
    
    /**
     * Reset password using token
     * 
     * @param string $token The reset token
     * @param string $email The email address
     * @param string $newPassword The new password
     * @return array Result with success status
     */
    public function resetPassword(string $token, string $email, string $newPassword): array
    {
        // First validate the token
        $validation = $this->validateResetToken($token, $email);
        
        if (!$validation['success']) {
            return $validation;
        }
        
        // Validate password strength
        $passwordErrors = $this->security->validatePassword($newPassword);
        if (!empty($passwordErrors)) {
            return [
                'success' => false,
                'error' => implode(' ', $passwordErrors)
            ];
        }
        
        $userId = $validation['user_id'];
        $tokenId = $validation['token_id'];
        
        // Begin transaction
        $this->db->beginTransaction();
        
        try {
            // Update password
            $hash = $this->hashPassword($newPassword);
            $this->db->update('users', ['password' => $hash], 'id = ?', [$userId]);
            
            // Mark token as used
            $this->db->update('password_reset_tokens', [
                'used' => 1,
                'used_at' => date(DATETIME_FORMAT)
            ], 'id = ?', [$tokenId]);
            
            // Clear any existing sessions for this user (force re-login)
            // Note: This is a security measure - in a multi-server setup, 
            // you'd use a session invalidation mechanism
            
            // Log the event
            $this->security->logSecurityEvent('password_reset_completed', "Email: {$email}", $userId);
            auditLog('password_reset', 'user', $userId);
            
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => 'Your password has been reset successfully. You can now log in with your new password.'
            ];
            
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Password reset failed for user {$userId}: " . $e->getMessage());
            
            return [
                'success' => false,
                'error' => 'Unable to reset password. Please try again later.'
            ];
        }
    }
}
