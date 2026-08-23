<?php
/**
 * LOKA - Delete Driver
 */

requireRole(ROLE_ADMIN);
requireCsrf();

$driverId = (int) post('id');

try {
    $driver = db()->fetch("SELECT * FROM drivers WHERE id = ? AND deleted_at IS NULL", [$driverId]);

    if (!$driver) {
        redirectWith('/?page=drivers', 'danger', 'Driver not found.');
    }

    if ($driver->status === DRIVER_ON_TRIP) {
        redirectWith('/?page=drivers', 'danger', 'Cannot delete driver who is currently on a trip.');
    }

    db()->softDelete('drivers', 'id = ?', [$driverId]);
    auditLog('driver_deleted', 'driver', $driverId, (array) $driver);

    redirectWith('/?page=drivers', 'success', 'Driver deleted successfully.');
} catch (Exception $e) {
    error_log("Driver delete error: " . $e->getMessage());
    redirectWith('/?page=drivers', 'danger', 'An error occurred while deleting the driver.');
}
