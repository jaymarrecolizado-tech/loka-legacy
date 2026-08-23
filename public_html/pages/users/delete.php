<?php
/**
 * LOKA - Delete User Page
 */

requireRole(ROLE_ADMIN);

$userId = (int) post('id');

// Validate CSRF token
requireCsrf();

// Fetch user with FOR UPDATE lock
$user = db()->fetch("SELECT * FROM users WHERE id = ? AND deleted_at IS NULL FOR UPDATE", [$userId]);

if (!$user) {
    redirectWith('/?page=users', 'danger', 'User not found.');
}

// Prevent self-deletion
if ($user->id === userId()) {
    redirectWith('/?page=users', 'danger', 'You cannot delete your own account.');
}

db()->beginTransaction();

try {
    // Soft delete user
    db()->update('users', [
        'deleted_at' => date(DATETIME_FORMAT),
        'updated_at' => date(DATETIME_FORMAT)
    ], 'id = ?', [$userId]);

    // Log the deletion
    auditLog('user_deleted', 'user', $userId, ['name' => $user->name, 'email' => $user->email]);

    db()->commit();
    redirectWith('/?page=users', 'success', 'User deleted successfully.');
} catch (Exception $e) {
    db()->rollback();
    error_log("User deletion error: " . $e->getMessage());
    redirectWith('/?page=users', 'danger', 'Failed to delete user.');
}
