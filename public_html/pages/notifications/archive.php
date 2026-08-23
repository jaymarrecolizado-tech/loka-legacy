<?php
/**
 * LOKA - Toggle Notification Archive Status
 */

requireAuth();

$notifId = (int) get('id');
$notif = db()->fetch("SELECT * FROM notifications WHERE id = ? AND user_id = ?", [$notifId, userId()]);

if ($notif) {
    $newStatus = $notif->is_archived ? 0 : 1;
    db()->update('notifications', ['is_archived' => $newStatus], 'id = ?', [$notifId]);
    
    // Check if AJAX request
    $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';
    
    if ($isAjax) {
        // Get updated unread count
        $unread = db()->fetchColumn("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0 AND is_archived = 0 AND deleted_at IS NULL", [userId()]);
        
        jsonResponse(true, ['unread' => $unread], $newStatus ? 'Notification archived.' : 'Notification moved to inbox.');
    }
    
    $message = $newStatus ? 'Notification archived.' : 'Notification moved to inbox.';
    redirectWith('/?page=notifications' . (get('view') === 'archive' ? '&view=archive' : ''), 'success', $message);
}

redirect('/?page=notifications');
