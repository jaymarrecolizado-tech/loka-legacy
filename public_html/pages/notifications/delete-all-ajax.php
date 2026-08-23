<?php
/**
 * LOKA - Clear All Notifications (AJAX)
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
$where = 'user_id = ? AND deleted_at IS NULL';
$params = [userId()];

if ($view === 'archive') {
    $where .= ' AND is_archived = 1';
} else {
    $where .= ' AND is_archived = 0';
}

$count = db()->fetchColumn("SELECT COUNT(*) FROM notifications WHERE {$where}", $params);

if ($count > 0) {
    db()->update('notifications', ['deleted_at' => date(DATETIME_FORMAT)], $where, $params);
    
    auditLog('notifications_cleared', 'notification', null, null, [
        'view' => $view,
        'count' => $count
    ]);
    
    jsonResponse(true, ['count' => $count], "{$count} notification(s) cleared.");
} else {
    jsonResponse(false, [], 'No notifications to clear.');
}
