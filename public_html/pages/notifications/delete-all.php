<?php
/**
 * LOKA - Clear All Notifications (Soft Delete)
 */

requireAuth();
requireCsrf();

$view = get('view', 'inbox');
$where = 'user_id = ? AND deleted_at IS NULL';
$params = [userId()];

if ($view === 'archive') {
    $where .= ' AND is_archived = 1';
} else {
    // Clear ALL inbox items (both read and unread)
    $where .= ' AND is_archived = 0';
}

// Get count before deletion for feedback
$count = db()->fetchColumn("SELECT COUNT(*) FROM notifications WHERE {$where}", $params);

// Soft delete all matching notifications
if ($count > 0) {
    db()->update('notifications', ['deleted_at' => date(DATETIME_FORMAT)], $where, $params);
    
    // Audit log
    auditLog('notifications_cleared', 'notification', null, null, [
        'view' => $view,
        'count' => $count
    ]);
}

$message = $view === 'archive' 
    ? "Archive cleared. {$count} notification(s) removed." 
    : "Inbox cleared. {$count} notification(s) removed.";

redirectWith('/?page=notifications' . ($view === 'archive' ? '&view=archive' : ''), 'success', $message);
