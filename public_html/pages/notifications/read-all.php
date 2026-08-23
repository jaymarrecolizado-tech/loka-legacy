<?php
/**
 * LOKA - Mark All Notifications as Read
 */

db()->update('notifications', ['is_read' => 1], 'user_id = ? AND is_read = 0', [userId()]);
redirectWith('/?page=notifications', 'success', 'All notifications marked as read.');
