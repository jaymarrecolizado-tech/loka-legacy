<?php
/**
 * LOKA - Archive All Notifications (AJAX)
 */

requireAuth();

// Check CSRF - return JSON error for AJAX requests
if (!verifyCsrf()) {
    $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';
    
    if ($isAjax) {
        jsonResponse(false, [], 'CSRF token validation failed. Please refresh page.', 403);
    } else {
        $security = Security::getInstance();
        $security->logSecurityEvent('csrf_validation_failed', 'Request rejected due to invalid CSRF token', userId());
        http_response_code(403);
        die('Invalid or expired security token. Please refresh page and try again.');
    }
}

$view = get('view', 'inbox');

$where = 'user_id = ? AND deleted_at IS NULL AND is_archived = 0 AND is_read = 0';
$params = [userId()];

$count = db()->update('notifications', ['is_archived' => 1], $where, $params);

if ($count > 0) {
    auditLog('notifications_archived', 'notification', null, null, [
        'view' => 'inbox',
        'count' => $count
    ]);
    
    jsonResponse(true, ['count' => $count], "{$count} notification(s) archived.");
} else {
    jsonResponse(false, [], 'No notifications to archive.');
}
