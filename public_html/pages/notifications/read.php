<?php
/**
 * LOKA - Mark Notification as Read
 */

$notifId = (int) get('id');
$notif = db()->fetch("SELECT * FROM notifications WHERE id = ? AND user_id = ?", [$notifId, userId()]);

if ($notif) {
    db()->update('notifications', ['is_read' => 1], 'id = ?', [$notifId]);
    
    // Check if AJAX request
    $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';
    
    if ($isAjax) {
        $unread = db()->fetchColumn("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0 AND is_archived = 0 AND deleted_at IS NULL", [userId()]);
        
        jsonResponse(true, ['unread' => $unread]);
    }
    
    // Redirect to link if exists (non-AJAX)
    if ($notif->link) {
        redirect($notif->link);
    }
}

redirect('/?page=notifications');
