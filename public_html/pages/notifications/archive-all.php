<?php
/**
 * LOKA - Archive All Unread/Unarchived Notifications
 */

requireAuth();

db()->update('notifications', 
    ['is_archived' => 1], 
    'user_id = ? AND is_archived = 0 AND deleted_at IS NULL', 
    [userId()]
);

redirectWith('/?page=notifications', 'success', 'All notifications archived.');
