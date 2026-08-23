<?php
/**
 * LOKA - Delete Maintenance Request
 */

requireRole(ROLE_ADMIN);
requireCsrf();

$maintenanceId = (int) post('id');

try {
    $maintenance = db()->fetch(
        "SELECT * FROM maintenance_requests WHERE id = ? AND deleted_at IS NULL",
        [$maintenanceId]
    );

    if (!$maintenance) {
        redirectWith('/?page=maintenance', 'danger', 'Maintenance request not found.');
    }

    // Prevent deletion if maintenance is in progress
    if ($maintenance->status === MAINTENANCE_STATUS_IN_PROGRESS) {
        redirectWith('/?page=maintenance&action=view&id=' . $maintenanceId, 'danger', 'Cannot delete maintenance request that is currently in progress. Cancel it first.');
    }

    db()->softDelete('maintenance_requests', 'id = ?', [$maintenanceId]);
    auditLog('maintenance_deleted', 'maintenance_request', $maintenanceId, (array) $maintenance);

    redirectWith('/?page=maintenance', 'success', 'Maintenance request deleted successfully.');

} catch (Exception $e) {
    error_log("Maintenance delete error: " . $e->getMessage());
    redirectWith('/?page=maintenance', 'danger', 'An error occurred while deleting the maintenance request.');
}
