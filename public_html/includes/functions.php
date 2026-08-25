<?php
/**
 * LOKA - Helper Functions
 */

require_once __DIR__ . '/table_sort.php';
require_once __DIR__ . '/list_pagination.php';
require_once __DIR__ . '/list_table.php';
require_once __DIR__ . '/view_as.php';
require_once __DIR__ . '/badge_counts.php';
require_once __DIR__ . '/dashboard_stats.php';
require_once __DIR__ . '/availability.php';
require_once __DIR__ . '/image_optimize.php';
require_once __DIR__ . '/vehicle_observations.php';
require_once __DIR__ . '/vehicle_care.php';
require_once __DIR__ . '/report_helpers.php';

/**
 * Get database instance
 */
function db(): Database
{
    return Database::getInstance();
}

/**
 * Check if currently in a database transaction
 */
function dbInTransaction(): bool
{
    return db()->inTransaction();
}

/**
 * Escape HTML output
 */
function e(?string $string): string
{
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Redirect to URL
 */
function redirect(string $url): void
{
    session_write_close();
    header("Location: " . APP_URL . $url);
    exit;
}

/**
 * Set flash message
 */
function setFlashMessage(string $message, string $type = 'info'): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

/**
 * Redirect with message
 */
function redirectWith(string $url, string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    redirect($url);
}

/**
 * Get and clear flash message
 */
function getFlash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

/**
 * Check if user is logged in
 */
function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']);
}

/**
 * Get current user ID
 */
function userId(): ?int
{
    return $_SESSION['user_id'] ?? null;
}

/**
 * Get current user role
 */
function userRole(): ?string
{
    return $_SESSION['user_role'] ?? null;
}

/**
 * Get current user data
 */
function currentUser(): ?object
{
    // First try to get from session
    if (isset($_SESSION['user']) && is_object($_SESSION['user'])) {
        return $_SESSION['user'];
    }
    
    // If not in session but user_id exists, fetch from database
    if (isset($_SESSION['user_id'])) {
        $auth = new Auth();
        $user = $auth->getUser($_SESSION['user_id']);
        if ($user) {
            // Store in session for next time
            $_SESSION['user'] = $user;
            return $user;
        }
    }
    
    return null;
}

/**
 * Check if user has minimum role level
 */
function hasRole(string $minRole): bool
{
    $userLevel = ROLE_LEVELS[userRole()] ?? 0;
    $requiredLevel = ROLE_LEVELS[$minRole] ?? 999;
    return $userLevel >= $requiredLevel;
}

/**
 * Check if user is admin (All Father only when not View-as another role)
 */
function isAdmin(): bool
{
    if (isViewingAs()) {
        return userRole() === ROLE_ADMIN;
    }
    return userRole() === ROLE_ADMIN || isRealAllFather();
}

/**
 * Check if user is All Father (real account — not cleared by View-as)
 */
function isAllFather(): bool
{
    return isRealAllFather();
}

/**
 * Check if user is motorpool head or higher
 */
function isMotorpool(): bool
{
    return hasRole(ROLE_MOTORPOOL);
}

/**
 * Check if user is guard
 */
function isGuard(): bool
{
    return userRole() === ROLE_GUARD;
}

/**
 * Current user's drivers.id, or null if not tagged as a driver
 */
function currentDriverId(): ?int
{
    if (!isLoggedIn()) {
        return null;
    }

    // View-as Driver uses a test driver profile
    if (getViewAsRole() === 'driver') {
        return viewAsTestDriverId();
    }

    static $loaded = false;
    static $driverId = null;

    if (!$loaded) {
        $result = db()->fetch(
            "SELECT id FROM drivers WHERE user_id = ? AND deleted_at IS NULL LIMIT 1",
            [userId()]
        );
        $driverId = ($result !== false && $result !== null) ? (int) $result->id : null;
        $loaded = true;
    }

    return $driverId;
}

/**
 * Check if user is a driver (has a driver record)
 */
function isDriver(): bool
{
    return currentDriverId() !== null;
}

/**
 * Approver+ or any user tagged as a driver may open Reports
 */
function canAccessReports(): bool
{
    return isApprover() || isDriver();
}

/**
 * Driver-tagged user who is not approver+ (own Driver Report only)
 */
function isSelfScopedDriverReporter(): bool
{
    return isDriver() && !isApprover();
}

/**
 * Whether the current user is the assigned or requested driver on a request/trip
 */
function isAssignedOrRequestedDriver(object $request): bool
{
    $driverId = currentDriverId();
    if ($driverId === null) {
        return false;
    }

    return (int) ($request->driver_id ?? 0) === $driverId
        || (int) ($request->requested_driver_id ?? 0) === $driverId;
}

/**
 * Check if user is approver or higher
 */
function isApprover(): bool
{
    return hasRole(ROLE_APPROVER);
}

/**
 * Generate CSRF token
 */
function csrfToken(): string
{
    return Security::getInstance()->getCsrfToken();
}

/**
 * CSRF token input field
 */
function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . csrfToken() . '">';
}

/**
 * Verify CSRF token
 */
function verifyCsrf(): bool
{
    $token = $_POST['csrf_token'] ?? '';
    return Security::getInstance()->verifyCsrfToken($token);
}

/**
 * Require CSRF validation
 */
function requireCsrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCsrf()) {
        $security = Security::getInstance();
        $security->logSecurityEvent('csrf_validation_failed', 'Request rejected due to invalid CSRF token', userId());
        http_response_code(403);
        die('Invalid or expired security token. Please refresh the page and try again.');
    }
}

/**
 * Format date for display
 */
function formatDate(?string $date): string
{
    if (!$date)
        return '-';
    // Ensure timezone is set to Manila (should be set in index.php, but ensure here too)
    $timezone = date_default_timezone_get();
    if ($timezone !== 'Asia/Manila') {
        date_default_timezone_set('Asia/Manila');
    }
    return date(DISPLAY_DATE, strtotime($date));
}

/**
 * Format datetime for display (Manila timezone)
 */
function formatDateTime(?string $datetime): string
{
    if (!$datetime)
        return '-';
    // Ensure timezone is set to Manila (should be set in index.php, but ensure here too)
    $timezone = date_default_timezone_get();
    if ($timezone !== 'Asia/Manila') {
        date_default_timezone_set('Asia/Manila');
    }
    return date(DISPLAY_DATETIME, strtotime($datetime));
}

/**
 * Get status badge HTML
 */
function statusBadge(string $status, array $labels): string
{
    $info = $labels[$status] ?? ['label' => ucfirst($status), 'color' => 'secondary'];
    return sprintf(
        '<span class="badge bg-%s">%s</span>',
        e($info['color']),
        e($info['label'])
    );
}

/**
 * Get request status badge
 */
function requestStatusBadge(string $status): string
{
    return statusBadge($status, STATUS_LABELS);
}

/**
 * Get vehicle status badge
 */
function vehicleStatusBadge(string $status): string
{
    return statusBadge($status, VEHICLE_STATUS_LABELS);
}

/**
 * Get driver status badge
 */
function driverStatusBadge(string $status): string
{
    return statusBadge($status, DRIVER_STATUS_LABELS);
}

/**
 * Get role badge
 */
function roleBadge(string $role): string
{
    return statusBadge($role, ROLE_LABELS);
}

/**
 * Truncate text
 */
function truncate(string $text, int $length = 50): string
{
    if (strlen($text) <= $length)
        return e($text);
    return e(substr($text, 0, $length)) . '...';
}

/**
 * Get pagination HTML
 */
function pagination(int $total, int $current, int $perPage, string $baseUrl): string
{
    $totalPages = ceil($total / $perPage);
    if ($totalPages <= 1)
        return '';

    $html = '<nav><ul class="pagination pagination-sm justify-content-center">';

    // Previous
    $prevDisabled = $current <= 1 ? 'disabled' : '';
    $html .= sprintf(
        '<li class="page-item %s"><a class="page-link" href="%s&p=%d">&laquo;</a></li>',
        $prevDisabled,
        $baseUrl,
        $current - 1
    );

    // Page numbers
    $start = max(1, $current - 2);
    $end = min($totalPages, $current + 2);

    for ($i = $start; $i <= $end; $i++) {
        $active = $i === $current ? 'active' : '';
        $html .= sprintf(
            '<li class="page-item %s"><a class="page-link" href="%s&p=%d">%d</a></li>',
            $active,
            $baseUrl,
            $i,
            $i
        );
    }

    // Next
    $nextDisabled = $current >= $totalPages ? 'disabled' : '';
    $html .= sprintf(
        '<li class="page-item %s"><a class="page-link" href="%s&p=%d">&raquo;</a></li>',
        $nextDisabled,
        $baseUrl,
        $current + 1
    );

    $html .= '</ul></nav>';
    return $html;
}

/**
 * Check if current request is POST
 */
function isPost(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

/**
 * Get and sanitize POST value
 */
function post(string $key, $default = null): mixed
{
    if (!isset($_POST[$key]))
        return $default;
    $value = $_POST[$key];

    // Don't sanitize arrays, return as-is for further processing
    if (is_array($value))
        return $value;

    return trim($value);
}

/**
 * Get sanitized POST string (with HTML stripping)
 */
function postSafe(string $key, $default = null, int $maxLength = 0): string
{
    $value = post($key, $default);
    if ($value === null)
        return '';
    return Security::getInstance()->sanitizeString($value, $maxLength ?: MAX_INPUT_LENGTH);
}

/**
 * Get and sanitize GET value
 */
function get(string $key, $default = null): mixed
{
    if (!isset($_GET[$key]))
        return $default;
    $value = $_GET[$key];

    // Don't sanitize arrays
    if (is_array($value))
        return $value;

    return trim($value);
}

/**
 * Get sanitized GET string (with HTML stripping)
 */
function getSafe(string $key, $default = null, int $maxLength = 0): string
{
    $value = get($key, $default);
    if ($value === null)
        return '';
    return Security::getInstance()->sanitizeString($value, $maxLength ?: MAX_INPUT_LENGTH);
}

/**
 * Get sanitized integer from POST
 */
function postInt(string $key, int $default = 0): int
{
    return Security::getInstance()->sanitizeInt(post($key, $default));
}

/**
 * Get sanitized integer from GET
 */
function getInt(string $key, int $default = 0): int
{
    return Security::getInstance()->sanitizeInt(get($key, $default));
}

/**
 * Create audit log entry
 */
function auditLog(string $action, string $entityType, ?int $entityId = null, ?array $oldData = null, ?array $newData = null): void
{
    db()->insert('audit_logs', [
        'user_id' => userId(),
        'action' => $action,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'old_data' => $oldData ? json_encode($oldData) : null,
        'new_data' => $newData ? json_encode($newData) : null,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        'created_at' => date(DATETIME_FORMAT)
    ]);
}

/**
 * Create notification and send email
 * 
 * @param int $userId User ID to notify
 * @param string $type Notification type
 * @param string $title Notification title
 * @param string $message Notification message
 * @param string|null $link Optional link
 * @param int|null $requestId Optional request ID for Control No. in email subject
 */
function notify(int $userId, string $type, string $title, string $message, ?string $link = null, ?int $requestId = null): void
{
    // FIX: Validate notification type against known templates
    $validTypes = array_keys(MAIL_TEMPLATES);
    if (!in_array($type, $validTypes)) {
        error_log("NOTIFY WARN: Unknown notification type '{$type}' for user #{$userId} - using 'default' template");
        $type = 'default';
    }
    
    // FIX: Rate limit - max 20 notifications of same type per user per hour
    $rateLimitCheck = db()->fetchColumn(
        "SELECT COUNT(*) FROM notifications 
         WHERE user_id = ? AND type = ? 
         AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
         AND deleted_at IS NULL",
        [$userId, $type]
    );
    
    if ($rateLimitCheck >= 20) {
        error_log("NOTIFY RATE LIMIT: User #{$userId} exceeded 20 notifications/hour for type '{$type}' - skipping");
        return;
    }
    
    // FIX: Check for duplicate notification in last 5 minutes
    $duplicate = db()->fetch(
        "SELECT id FROM notifications 
         WHERE user_id = ? AND type = ? AND title = ? AND message = ?
         AND created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE) 
         AND deleted_at IS NULL 
         LIMIT 1",
        [$userId, $type, $title, $message]
    );
    
    if ($duplicate) {
        error_log("NOTIFY SKIP: Duplicate notification for user #{$userId} (type: {$type}) - skipping");
        return;
    }
    
    // Save to database (in-app notification)
    $notifId = db()->insert('notifications', [
        'user_id' => $userId,
        'type' => $type,
        'title' => $title,
        'message' => $message,
        'link' => $link,
        'is_read' => 0,
        'created_at' => date(DATETIME_FORMAT)
    ]);

    // FIX: Extract request ID from link if not provided
    // Link format typically: /?page=requests&action=view&id=123
    if ($requestId === null && $link) {
        parse_str(parse_url($link, PHP_URL_QUERY), $query);
        $requestId = isset($query['id']) ? (int) $query['id'] : null;
    }

    // Queue email notification ONLY - never process during request to prevent lag
    try {
        $user = db()->fetch("SELECT email, name FROM users WHERE id = ? AND deleted_at IS NULL", [$userId]);
        if ($user && $user->email) {
            // Check if template exists, if not use a default one
            $templateKey = isset(MAIL_TEMPLATES[$type]) ? $type : 'default';
            
            $queue = new EmailQueue();
            $emailId = $queue->queueTemplate($user->email, $templateKey, [
                'message' => $message,
                'link' => $link,
                'link_text' => 'View Details'
            ], $user->name, 5, $requestId);
            
            error_log("NOTIFY: User #{$userId} ({$user->email}) - Notification #{$notifId} created, Email #{$emailId} queued (type: {$type}" . ($requestId ? ", Control No.: {$requestId}" : "") . ")");
        } else {
            error_log("NOTIFY WARN: User #{$userId} has no email - Notification #{$notifId} created but email NOT queued");
        }
    } catch (Exception $e) {
        error_log("NOTIFY ERROR: Email queue failed for user #{$userId}: " . $e->getMessage());
    }

    // Soft-fail SMS enqueue (does not affect in-app/email)
    try {
        if (function_exists('smsNotifyUser')) {
            smsNotifyUser($userId, $type, $title, $message, $link, $requestId);
        }
    } catch (Throwable $e) {
        error_log("NOTIFY ERROR: SMS queue failed for user #{$userId}: " . $e->getMessage());
    }
}

/**
 * Notify all passengers of a request about status changes
 * Only notifies users (not guests) since guests don't have email addresses
 * 
 * ATOMIC OPERATION: Wrapped in transaction to ensure all notifications succeed or none
 */
function notifyPassengers(int $requestId, string $type, string $title, string $message, ?string $link = null): void
{
    $alreadyInTransaction = dbInTransaction();
    
    if (!$alreadyInTransaction) {
        db()->beginTransaction();
    }
    
    try {
        $passengers = db()->fetchAll(
            "SELECT rp.user_id, u.name, u.email 
             FROM request_passengers rp
             LEFT JOIN users u ON rp.user_id = u.id
             WHERE rp.request_id = ? 
             AND rp.user_id IS NOT NULL 
             AND u.status = 'active' 
             AND u.deleted_at IS NULL",
            [$requestId]
        );

        if (empty($passengers)) {
            error_log("NOTIFY PASSENGERS: Request #{$requestId} - No passenger users to notify");
            if (!$alreadyInTransaction) {
                db()->commit();
            }
            return;
        }

        $notified = 0;
        $errors = [];
        
        foreach ($passengers as $passenger) {
            if ($passenger->user_id && $passenger->email) {
                try {
                    notify($passenger->user_id, $type, $title, $message, $link);
                    $notified++;
                } catch (Exception $e) {
                    $errors[] = "Passenger {$passenger->user_id}: " . $e->getMessage();
                }
            }
        }
        
        error_log("NOTIFY PASSENGERS: Request #{$requestId} - Notified {$notified} passengers (type: {$type})" . ($errors ? " - Errors: " . implode("; ", $errors) : ""));
        
        if (!$alreadyInTransaction) {
            db()->commit();
        }
    } catch (Exception $e) {
        if (!$alreadyInTransaction) {
            db()->rollback();
        }
        error_log("NOTIFY PASSENGERS ERROR: Request #{$requestId} - Transaction failed: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Batch notify all passengers - More efficient for large groups
 * Creates notifications and queues emails in optimized batches
 * 
 * @param int $requestId The request ID
 * @param string $type Notification type key
 * @param string $title Notification title
 * @param string $message Notification message
 * @param string|null $link Optional link
 * @return int Number of passengers notified
 */
function notifyPassengersBatch(int $requestId, string $type, string $title, string $message, ?string $link = null): int
{
    // Single query to get all passengers
    $passengers = db()->fetchAll(
        "SELECT rp.user_id, u.email, u.name 
         FROM request_passengers rp
         LEFT JOIN users u ON rp.user_id = u.id
         WHERE rp.request_id = ? AND rp.user_id IS NOT NULL 
         AND u.status = 'active' AND u.deleted_at IS NULL",
        [$requestId]
    );
    
    if (empty($passengers)) {
        error_log("NOTIFY PASSENGERS BATCH: Request #{$requestId} - No passengers to notify");
        return 0;
    }
    
    // Batch insert notifications in a transaction
    db()->beginTransaction();
    $notified = 0;
    $now = date(DATETIME_FORMAT);
    
    foreach ($passengers as $passenger) {
        try {
            db()->insert('notifications', [
                'user_id' => $passenger->user_id,
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'link' => $link,
                'is_read' => 0,
                'created_at' => $now
            ]);
            $notified++;
        } catch (Exception $e) {
            error_log("NOTIFY PASSENGERS BATCH ERROR: Failed to create notification for user {$passenger->user_id}: " . $e->getMessage());
        }
    }
    
    db()->commit();
    
    // Queue emails in batch AFTER transaction commits
    $queue = new EmailQueue();
    $emailsQueued = 0;
    
    foreach ($passengers as $passenger) {
        if ($passenger->email) {
            try {
                $templateKey = isset(MAIL_TEMPLATES[$type]) ? $type : 'default';
                
                $queue->queueTemplate($passenger->email, $templateKey, [
                    'message' => $message,
                    'link' => $link,
                    'link_text' => 'View Details'
                ], $passenger->name, 5, $requestId);
                
                $emailsQueued++;
            } catch (Exception $e) {
                error_log("NOTIFY PASSENGERS BATCH ERROR: Failed to queue email for {$passenger->email}: " . $e->getMessage());
            }
        }
    }
    
    error_log("NOTIFY PASSENGERS BATCH: Request #{$requestId} - Notified {$notified} passengers, queued {$emailsQueued} emails (type: {$type})");
    return $notified;
}

/**
 * Notify driver about request status changes
 * Notifies both requested driver (if specified) and assigned driver (if assigned)
 */
function notifyDriver(?int $driverId, string $type, string $title, string $message, ?string $link = null): void
{
    if (!$driverId) {
        error_log("NOTIFY DRIVER: No driver ID provided");
        return;
    }
    
    $driver = db()->fetch(
        "SELECT d.user_id, d.status as driver_status, d.deleted_at as driver_deleted,
                u.name, u.email, u.status as user_status, u.deleted_at as user_deleted
         FROM drivers d
         LEFT JOIN users u ON d.user_id = u.id
         WHERE d.id = ?",
        [$driverId]
    );
    
    if (!$driver) {
        error_log("NOTIFY DRIVER: Driver #{$driverId} not found");
        return;
    }
    
    if (!$driver->user_id || !$driver->email || $driver->user_status !== 'active' || $driver->user_deleted || $driver->driver_deleted) {
        error_log("NOTIFY DRIVER: Driver #{$driverId} is inactive or deleted - skipping notification");
        return;
    }
    
    try {
        notify($driver->user_id, $type, $title, $message, $link);
        error_log("NOTIFY DRIVER: Driver #{$driverId} ({$driver->email}) notified (type: {$type})");
    } catch (Exception $e) {
        error_log("NOTIFY DRIVER ERROR: Failed to notify driver #{$driverId}: " . $e->getMessage());
    }
}

/**
 * Notify all parties (requester, passengers, driver) about request status changes
 */
function notifyAllParties(int $requestId, string $type, string $title, string $message, ?string $link = null): void
{
    $request = db()->fetch(
        "SELECT user_id, requested_driver_id, driver_id 
         FROM requests 
         WHERE id = ? AND deleted_at IS NULL",
        [$requestId]
    );
    
    if (!$request) {
        return;
    }
    
    notify($request->user_id, $type, $title, $message, $link);
    notifyPassengers($requestId, $type, $title, $message, $link);
    
    $driverId = $request->driver_id ?: $request->requested_driver_id;
    if ($driverId) {
        notifyDriver($driverId, $type, $title, $message, $link);
    }
}

/**
 * Get unread notification count
 */
function unreadNotificationCount(): int
{
    if (!isLoggedIn())
        return 0;
    return db()->fetchColumn("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0 AND is_archived = 0 AND deleted_at IS NULL", [userId()]);
}

/**
 * JSON response
 *
 * @param bool $success Whether the operation was successful
 * @param array $data Data to return (optional)
 * @param string $message Message to return (optional)
 * @param int $httpCode HTTP status code (optional, default 200)
 * @return void
 */
function jsonResponse(bool $success, array $data = [], string $message = '', int $httpCode = 200): void
{
    // Set HTTP response code
    http_response_code($httpCode);
    
    // Set Content-Type header if not already sent
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    
    // Build response array
    $response = [
        'success' => $success
    ];
    
    // Add data if provided
    if (!empty($data)) {
        $response = array_merge($response, $data);
    }
    
    // Add message if provided
    if (!empty($message)) {
        $response['message'] = $message;
    }
    
    // Output JSON and exit
    echo json_encode($response);
    exit;
}

/**
 * Require authentication
 */
function requireAuth(): void
{
    if (!isLoggedIn()) {
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            jsonResponse(false, ['error' => 'Unauthorized'], 'Unauthorized', 401);
        }
        redirectWith('/?page=login', 'warning', 'Please log in to continue.');
    }
}

/**
 * Require minimum role
 */
function requireRole(string $role): void
{
    requireAuth();
    if (!hasRole($role)) {
        redirectWith('/?page=dashboard', 'danger', 'You do not have permission to access this page.');
    }
}

/**
 * Require Reports access (approver+ or tagged driver)
 */
function requireReportsAccess(): void
{
    requireAuth();
    if (!canAccessReports()) {
        redirectWith('/?page=dashboard', 'danger', 'You do not have permission to access this page.');
    }
}

/**
 * Check for driver schedule conflicts
 * 
 * @return array|null Conflict details if found, null otherwise
 */
function checkDriverConflict(int $driverId, string $start, string $end, ?int $excludeRequestId = null): ?array
{
    $sql = "SELECT r.*, u.name as requester_name 
            FROM requests r
            JOIN users u ON r.user_id = u.id
            WHERE r.driver_id = ? 
            AND r.status IN ('approved', 'pending_motorpool')
            AND r.start_datetime < ? 
            AND r.end_datetime > ?
            AND r.deleted_at IS NULL";

    $params = [$driverId, $end, $start];

    if ($excludeRequestId) {
        $sql .= " AND r.id != ?";
        $params[] = $excludeRequestId;
    }

    $conflict = db()->fetch($sql, $params);
    return $conflict ? (array) $conflict : null;
}

/**
 * Check for vehicle schedule conflicts
 * 
 * @return array|null Conflict details if found, null otherwise
 */
function checkVehicleConflict(int $vehicleId, string $start, string $end, ?int $excludeRequestId = null): ?array
{
    $sql = "SELECT r.*, u.name as requester_name 
            FROM requests r
            JOIN users u ON r.user_id = u.id
            WHERE r.vehicle_id = ? 
            AND r.status IN ('approved', 'pending_motorpool')
            AND r.start_datetime < ? 
            AND r.end_datetime > ?
            AND r.deleted_at IS NULL";

    $params = [$vehicleId, $end, $start];

    if ($excludeRequestId) {
        $sql .= " AND r.id != ?";
        $params[] = $excludeRequestId;
    }

    $conflict = db()->fetch($sql, $params);
    return $conflict ? (array) $conflict : null;
}

/**
 * Calculate overlap duration in minutes between conflict and request
 * 
 * @param array $conflict Conflict details from checkDriverConflict/checkVehicleConflict
 * @param string $requestStart Request start datetime
 * @param string $requestEnd Request end datetime
 * @return int Overlap in minutes
 */
function calculateOverlapMinutes(array $conflict, string $requestStart, string $requestEnd): int
{
    $conflictStart = strtotime($conflict['start_datetime']);
    $conflictEnd = strtotime($conflict['end_datetime']);
    $reqStart = strtotime($requestStart);
    $reqEnd = strtotime($requestEnd);
    
    // Calculate overlap
    $overlapStart = max($conflictStart, $reqStart);
    $overlapEnd = min($conflictEnd, $reqEnd);
    $overlap = $overlapEnd - $overlapStart;
    
    return $overlap > 0 ? round($overlap / 60) : 0;
}

/**
 * Get vehicle name from ID
 * 
 * @param int $vehicleId Vehicle ID
 * @return string Vehicle plate - make model
 */
function getVehicleName(int $vehicleId): string
{
    $vehicle = db()->fetch(
        "SELECT plate_number, make, model 
         FROM vehicles WHERE id = ? AND deleted_at IS NULL",
        [$vehicleId]
    );
    return $vehicle ? "{$vehicle->plate_number} - {$vehicle->make} {$vehicle->model}" : 'Unknown Vehicle';
}

/**
 * Get driver name from ID
 * 
 * @param int $driverId Driver ID
 * @return string Driver name
 */
function getDriverName(int $driverId): string
{
    $driver = db()->fetch(
        "SELECT u.name FROM drivers d JOIN users u ON d.user_id = u.id 
         WHERE d.id = ? AND d.deleted_at IS NULL",
        [$driverId]
    );
    return $driver ? $driver->name : 'Unknown Driver';
}

/**
 * Active menu class
 */
function activeMenu(string $page): string
{
    return (get('page', 'dashboard') === $page) ? 'active' : '';
}

// =============================================================================
// CACHE HELPER FUNCTIONS
// =============================================================================

/**
 * Get cache instance
 */
function cache(): Cache
{
    return Cache::getInstance();
}

/**
 * Get all active departments (cached)
 *
 * @param bool $refresh Force refresh from database
 * @return array Array of department objects
 */
function getDepartments(bool $refresh = false): array
{
    $cache = cache();
    $key = Cache::KEY_DEPARTMENTS . 'all';

    if ($refresh) {
        $cache->delete($key);
    }

    return $cache->remember($key, function() {
        return db()->fetchAll(
            "SELECT * FROM departments WHERE deleted_at IS NULL ORDER BY name"
        );
    }, 3600); // Cache for 1 hour
}

/**
 * Get all available vehicles with types (cached)
 *
 * @param bool $refresh Force refresh from database
 * @return array Array of vehicle objects with type_name
 */
function getAvailableVehicles(bool $refresh = false): array
{
    $cache = cache();
    $key = Cache::KEY_VEHICLES . 'available';

    if ($refresh) {
        $cache->delete($key);
    }

    return $cache->remember($key, function() {
        return db()->fetchAll(
            "SELECT v.*, vt.name as type_name, vt.passenger_capacity
             FROM vehicles v
             JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
             WHERE v.deleted_at IS NULL
             AND v.status IN ('available', 'in_use')
             ORDER BY vt.name, v.plate_number"
        );
    }, 300); // Cache for 5 minutes (vehicles change more often)
}

/**
 * Get all active drivers (cached)
 *
 * @param bool $refresh Force refresh from database
 * @return array Array of driver objects with user info
 */
function getActiveDrivers(bool $refresh = false): array
{
    $cache = cache();
    $key = Cache::KEY_DRIVERS . 'active';

    if ($refresh) {
        $cache->delete($key);
    }

    return $cache->remember($key, function() {
        return db()->fetchAll(
            "SELECT d.*, u.name as driver_name, u.phone as driver_phone, u.email as driver_email
             FROM drivers d
             JOIN users u ON d.user_id = u.id
             WHERE d.deleted_at IS NULL AND u.status = 'active' AND u.deleted_at IS NULL
             ORDER BY u.name"
        );
    }, 600); // Cache for 10 minutes
}

/**
 * Get employees (active users, excluding current) (cached)
 *
 * @param int|null $excludeUserId User ID to exclude (default: current user)
 * @param bool $refresh Force refresh from database
 * @return array Array of user objects with department
 */
function getEmployees(?int $excludeUserId = null, bool $refresh = false): array
{
    $excludeUserId = $excludeUserId ?? userId();
    $cache = cache();
    $key = Cache::KEY_USERS . "employees:{$excludeUserId}";

    if ($refresh) {
        $cache->delete($key);
    }

    return $cache->remember($key, function() use ($excludeUserId) {
        return db()->fetchAll(
            "SELECT u.id, u.name, u.email, d.name as department_name
             FROM users u
             LEFT JOIN departments d ON u.department_id = d.id
             WHERE u.status = 'active' AND u.deleted_at IS NULL AND u.id != ?
             ORDER BY u.name",
            [$excludeUserId]
        );
    }, 600); // Cache for 10 minutes
}

/**
 * Get approvers (users with approver/admin role) (cached)
 *
 * @param bool $refresh Force refresh from database
 * @return array Array of user objects with department
 */
function getApprovers(bool $refresh = false): array
{
    $cache = cache();
    $key = Cache::KEY_USERS . 'approvers';

    if ($refresh) {
        $cache->delete($key);
    }

    return $cache->remember($key, function() {
        return db()->fetchAll(
            "SELECT u.id, u.name, u.email, d.name as department_name
             FROM users u
             LEFT JOIN departments d ON u.department_id = d.id
             WHERE u.role IN ('approver', 'admin') AND u.status = 'active' AND u.deleted_at IS NULL
             ORDER BY u.name"
        );
    }, 600); // Cache for 10 minutes
}

/**
 * Get motorpool heads (cached)
 *
 * @param bool $refresh Force refresh from database
 * @return array Array of user objects
 */
function getMotorpoolHeads(bool $refresh = false): array
{
    $cache = cache();
    $key = Cache::KEY_USERS . 'motorpool_heads';

    if ($refresh) {
        $cache->delete($key);
    }

    return $cache->remember($key, function() {
        return db()->fetchAll(
            "SELECT u.id, u.name, u.email
             FROM users u
             WHERE u.role IN (?, ?) AND u.status = 'active' AND u.deleted_at IS NULL
             ORDER BY u.name",
            [ROLE_MOTORPOOL, ROLE_ADMIN]
        );
    }, 600); // Cache for 10 minutes
}

/**
 * Get vehicle types (cached)
 *
 * @param bool $refresh Force refresh from database
 * @return array Array of vehicle type objects
 */
function getVehicleTypes(bool $refresh = false): array
{
    $cache = cache();
    $key = Cache::KEY_VEHICLE_TYPES . 'all';

    if ($refresh) {
        $cache->delete($key);
    }

    return $cache->remember($key, function() {
        return db()->fetchAll(
            "SELECT * FROM vehicle_types WHERE deleted_at IS NULL ORDER BY name"
        );
    }, 3600); // Cache for 1 hour
}

/**
 * Clear user-related cache
 * Call this when user data changes
 */
function clearUserCache(): void
{
    $cache = cache();
    $cache->deletePattern(Cache::KEY_USERS);
    $cache->deletePattern(Cache::KEY_DRIVERS);
}

/**
 * Clear vehicle-related cache
 * Call this when vehicle data changes
 */
function clearVehicleCache(): void
{
    $cache = cache();
    $cache->deletePattern(Cache::KEY_VEHICLES);
}

/**
 * Clear department cache
 * Call this when department data changes
 */
function clearDepartmentCache(): void
{
    $cache = cache();
    $cache->deletePattern(Cache::KEY_DEPARTMENTS);
}

/**
 * Clear all application cache
 * Use this for major changes
 */
function clearAppCache(): void
{
    cache()->clear();
}

/**
 * Generate URL to view an uploaded file
 *
 * @param string $filePath Relative file path from uploads directory
 * @param string|null $label Optional label to use as link text
 * @param string|null $class CSS class for the link
 * @return string HTML anchor tag or plain URL if label is null
 */
function fileUrl(string $filePath, ?string $label = null, ?string $class = null): string
{
    if (empty($filePath)) {
        return $label ? '<span class="text-muted">N/A</span>' : '';
    }

    $url = '?page=file-view&file=' . urlencode($filePath);

    if ($label === null) {
        return $url;
    }

    $classAttr = $class ? ' class="' . e($class) . '"' : '';
    return '<a href="' . e($url) . '" target="_blank"' . $classAttr . '>' . e($label) . '</a>';
}

/**
 * Generate download link for an uploaded file
 *
 * @param string $filePath Relative file path from uploads directory
 * @param string|null $fileName Optional filename to display
 * @return string HTML anchor tag with download icon
 */
function fileDownloadLink(string $filePath, ?string $fileName = null): string
{
    if (empty($filePath)) {
        return '<span class="text-muted">No file</span>';
    }

    $displayFileName = $fileName ?? basename($filePath);
    $url = '?page=file-view&file=' . urlencode($filePath);

    return '<a href="' . e($url) . '" target="_blank" class="btn btn-sm btn-outline-primary me-1">
        <i class="bi bi-download me-1"></i>' . e($displayFileName) . '
    </a>';
}

/**
 * Display file icon based on extension
 *
 * @param string $filePath File path or extension
 * @return string HTML with Bootstrap icon
 */
function fileIcon(string $filePath): string
{
    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

    $icons = [
        'pdf' => 'bi-file-earmark-pdf text-danger',
        'jpg' => 'bi-file-earmark-image text-primary',
        'jpeg' => 'bi-file-earmark-image text-primary',
        'png' => 'bi-file-earmark-image text-primary',
        'gif' => 'bi-file-earmark-image text-primary',
        'doc' => 'bi-file-earmark-word text-primary',
        'docx' => 'bi-file-earmark-word text-primary',
        'xls' => 'bi-file-earmark-excel text-success',
        'xlsx' => 'bi-file-earmark-excel text-success',
        'csv' => 'bi-file-earmark-spreadsheet text-success',
        'zip' => 'bi-file-earmark-zip text-warning',
        'txt' => 'bi-file-earmark-text text-secondary',
    ];

    $iconClass = $icons[$extension] ?? 'bi-file-earmark text-secondary';

    return '<i class="bi ' . $iconClass . '"></i>';
}

// =============================================================================
// PORTED FROM PROD-LOKA (Plan #4 Phase 0): roles / access layer / gas vouchers
// =============================================================================

/**
 * Only All Father may clear rate limits / unlock accounts in-app.
 */
function canClearRateLimits(): bool
{
    return isRealAllFather();
}

/**
 * Roles assignable in user create/edit. All Father is hidden unless actor is All Father.
 *
 * @return array<string, array{label: string, color: string}>
 */
function assignableRoles(): array
{
    $roles = ROLE_LABELS;
    if (!isRealAllFather()) {
        unset($roles[ROLE_ALL_FATHER]);
    }
    return $roles;
}

/**
 * Require All Father role or redirect (real role, not View-as).
 */
function requireAllFather(): void
{
    if (!isRealAllFather() || isViewingAs()) {
        redirectWith('/?page=dashboard', 'danger', 'All Father access required.');
    }
}

/**
 * System Control: All Father only (unavailable while View-as is active).
 */
function canAccessSystemControl(): bool
{
    return isRealAllFather() && !isViewingAs();
}

/**
 * Require System Control access (All Father only).
 */
function requireSystemControl(): void
{
    requireAuth();
    if (!canAccessSystemControl()) {
        redirectWith('/?page=dashboard', 'danger', 'All Father access required.');
    }
}

/**
 * Check if user is Chief Admin & Finance (or OIC — same powers)
 */
function isChiefAdminFinance(): bool
{
    return userRole() === ROLE_CHIEF_ADMIN_FINANCE
        || userRole() === ROLE_OIC_CHIEF_ADMIN_FINANCE;
}

/**
 * Drivers may use gas vouchers (own) and my trips.
 */
function canAccessGasVouchers(): bool
{
    if (isGuard() && !isDriver()) {
        return false;
    }
    return isDriver()
        || hasRole(ROLE_REQUESTER)
        || isApprover()
        || isMotorpool()
        || isAdmin()
        || isChiefAdminFinance();
}

/**
 * Full ops reports — Administrator or All Father only.
 */
function canAccessOpsReports(): bool
{
    if (isGuard()) {
        return false;
    }
    return isAdmin();
}

/**
 * Reports menu/pages — Administrator or All Father only.
 */
function canAccessDriverReports(): bool
{
    return canAccessOpsReports();
}

/**
 * Notify active users holding any of the given roles (in-app + email via notify()).
 *
 * @param array<string> $roles
 */
function notifyRoleUsers(
    array $roles,
    string $type,
    string $title,
    string $message,
    ?string $link = null,
    ?int $excludeUserId = null,
    ?int $requestId = null
): void {
    $roles = array_values(array_unique(array_filter($roles)));
    if ($roles === []) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($roles), '?'));
    $params = $roles;
    $sql = "SELECT id FROM users
            WHERE role IN ({$placeholders})
              AND status = 'active'
              AND deleted_at IS NULL";
    if ($excludeUserId !== null && $excludeUserId > 0) {
        $sql .= ' AND id != ?';
        $params[] = $excludeUserId;
    }

    $users = db()->fetchAll($sql, $params);
    foreach ($users as $user) {
        notify((int) $user->id, $type, $title, $message, $link, $requestId);
    }
}

/**
 * Require the user to have at least ONE of the given roles.
 * Accepts either a single role string or an array of role strings.
 *
 * @param string|array $roles
 */
function requireAnyRole($roles): void
{
    requireAuth();
    $roles = (array) $roles;
    foreach ($roles as $role) {
        if (hasRole($role)) {
            return; // Passes — at least one role matches
        }
    }
    redirectWith('/?page=dashboard', 'danger', 'You do not have permission to access this page.');
}

/**
 * Block guard accounts from non-ops modules (requests, vouchers, tickets, etc.).
 */
function denyGuardAccess(): void
{
    if (isGuard()) {
        redirectWith('/?page=guard', 'danger', 'You do not have permission to access this page.');
    }
}

/**
 * Guards may view trip details for approved/completed fleet trips
 * (pending dispatch, on trip, or already arrived).
 */
function canGuardViewActiveTrip(object $request): bool
{
    if (!isGuard()) {
        return false;
    }

    return in_array($request->status, [STATUS_APPROVED, STATUS_COMPLETED], true);
}

/**
 * Total passengers for a request = companions in request_passengers + requester.
 */
function countRequestPassengers(int $requestId): int
{
    $companions = (int) db()->fetchColumn(
        "SELECT COUNT(*) FROM request_passengers WHERE request_id = ?",
        [$requestId]
    );
    return $companions + 1;
}

/**
 * Normalize passenger POST payload (passengers[] and/or passengers_json backup).
 *
 * @return list<string>
 */
function normalizeRequestPassengerIdsFromPost(): array
{
    $passengerIds = $_POST['passengers'] ?? [];
    if (!is_array($passengerIds)) {
        $passengerIds = [];
    }

    if ($passengerIds === [] && !empty($_POST['passengers_json'])) {
        $decoded = json_decode((string) $_POST['passengers_json'], true);
        if (is_array($decoded)) {
            $passengerIds = $decoded;
        }
    }

    $passengerIds = array_values(array_filter($passengerIds, static function ($p) {
        return trim((string) $p) !== '';
    }));

    return array_map(static fn($p) => trim((string) $p), $passengerIds);
}

/**
 * Prefix passenger-facing copy so recipients know they are on the trip.
 */
function passengerInvolvementMessage(int $requestId, string $detail): string
{
    $detail = trim($detail);
    return "You are listed as a passenger on this trip (Request #{$requestId})."
        . ($detail !== '' ? "\n\n" . $detail : '');
}

// =============================================================================
// GAS VOUCHER HELPERS
// =============================================================================

define('GAS_VOUCHER_STATUSES', [
    'draft'            => ['label' => 'Draft',              'color' => 'secondary'],
    'pending_review'   => ['label' => 'Pending Review',     'color' => 'warning'],
    'pending_approval' => ['label' => 'Pending Approval',   'color' => 'info'],
    'approved'         => ['label' => 'Approved',           'color' => 'success'],
    'rejected'         => ['label' => 'Rejected',           'color' => 'danger'],
    'cancelled'        => ['label' => 'Cancelled',          'color' => 'dark'],
]);

/**
 * Return a badge for a gas voucher status.
 */
function gasVoucherStatusBadge(string $status): string
{
    return statusBadge($status, GAS_VOUCHER_STATUSES);
}

/**
 * Return the color key for a gas voucher status (used for alert classes).
 */
function gasVoucherStatusColor(string $status): string
{
    return GAS_VOUCHER_STATUSES[$status]['color'] ?? 'secondary';
}

/**
 * Return the human-readable label for a gas voucher status.
 */
function gasVoucherStatusLabel(string $status): string
{
    return GAS_VOUCHER_STATUSES[$status]['label'] ?? ucfirst($status);
}

/**
 * HMAC secret used to sign gas voucher verification links.
 */
function gasVoucherVerifySecret(): string
{
    $key = getenv('APP_KEY') ?: ($_ENV['APP_KEY'] ?? '');
    $key = is_string($key) ? trim($key) : '';
    return $key !== '' ? $key : 'LOKA_SECRET';
}

/**
 * Truncated HMAC for voucher QR links (keeps QR modules sparse enough to scan).
 */
function gasVoucherVerifyHash(object $voucher): string
{
    return substr(hash_hmac(
        'sha256',
        $voucher->id . '-' . $voucher->voucher_no,
        gasVoucherVerifySecret()
    ), 0, 16);
}

/**
 * Build a signed public verification URL for a gas voucher (encoded in QR).
 */
function gasVoucherVerifyUrl(object $voucher): string
{
    $hash = gasVoucherVerifyHash($voucher);

    $base = rtrim((string) SITE_URL, '/');
    if ($base === '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base = $scheme . '://' . $host . rtrim((string) APP_URL, '/');
    }

    return $base . '/?page=verify-voucher&id=' . (int) $voucher->id . '&hash=' . urlencode($hash);
}

/**
 * Validate a gas voucher verification hash from a scanned QR link.
 * Accepts the current 16-char token or a legacy full SHA-256 hex digest.
 */
function gasVoucherVerifyHashValid(object $voucher, string $hash): bool
{
    if ($hash === '') {
        return false;
    }

    $full = hash_hmac(
        'sha256',
        $voucher->id . '-' . $voucher->voucher_no,
        gasVoucherVerifySecret()
    );
    $short = substr($full, 0, 16);

    if (hash_equals($short, $hash)) {
        return true;
    }

    return hash_equals($full, $hash);
}
