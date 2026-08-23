<?php
/**
 * LOKA - Toggle User Status
 */

requireRole(ROLE_ADMIN);

$userId = (int) get('id');
$user = db()->fetch("SELECT * FROM users WHERE id = ? AND deleted_at IS NULL FOR UPDATE", [$userId]);

if (!$user)
    redirectWith('/?page=users', 'danger', 'User not found.');

// Prevent self-deactivation
if ($user->id === userId()) {
    redirectWith('/?page=users', 'danger', 'You cannot deactivate your own account.');
}

// Validate status transitions
$newStatus = $user->status === USER_ACTIVE ? USER_INACTIVE : USER_ACTIVE;
$validTransitions = [
    USER_ACTIVE => [USER_INACTIVE],
    USER_INACTIVE => [USER_ACTIVE]
];

if (!isset($validTransitions[$user->status]) || !in_array($newStatus, $validTransitions[$user->status])) {
    redirectWith('/?page=users', 'danger', 'Invalid status transition.');
}

db()->beginTransaction();

try {
    $newStatus = $user->status === USER_ACTIVE ? USER_INACTIVE : USER_ACTIVE;
    db()->update('users', ['status' => $newStatus, 'updated_at' => date(DATETIME_FORMAT)], 'id = ?', [$userId]);
    auditLog('user_status_changed', 'user', $userId, ['status' => $user->status], ['status' => $newStatus]);

    db()->commit();
    $message = $newStatus === USER_ACTIVE ? 'User activated successfully.' : 'User deactivated successfully.';
    redirectWith('/?page=users', 'success', $message);
} catch (Exception $e) {
    db()->rollback();
    redirectWith('/?page=users', 'danger', 'Failed to update user status.');
}

