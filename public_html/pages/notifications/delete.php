<?php
/**
 * LOKA - Soft Delete Notification
 */

requireAuth();

$notifId = (int) get('id');
$notif = db()->fetch("SELECT * FROM notifications WHERE id = ? AND user_id = ?", [$notifId, userId()]);

if ($notif) {
    db()->update('notifications', ['deleted_at' => date(DATETIME_FORMAT)], 'id = ?', [$notifId]);
    
    // Check if AJAX request
    $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';
    
    if ($isAjax) {
        // Get updated unread count
        $unread = db()->fetchColumn("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0 AND is_archived = 0 AND deleted_at IS NULL", [userId()]);
        
        jsonResponse(true, ['unread' => $unread], 'Notification deleted.');
    }
    
    redirectWith('/?page=notifications' . (get('view') === 'archive' ? '&view=archive' : ''), 'success', 'Notification deleted.');
}

redirect('/?page=notifications');
