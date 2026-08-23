<?php
/**
 * LOKA - Delete Vehicle
 */

requireRole(ROLE_MOTORPOOL);
requireCsrf();

$vehicleId = (int) post('id');

try {
    $vehicle = db()->fetch("SELECT * FROM vehicles WHERE id = ? AND deleted_at IS NULL", [$vehicleId]);

    if (!$vehicle) {
        redirectWith('/?page=vehicles', 'danger', 'Vehicle not found.');
    }

    if ($vehicle->status === VEHICLE_IN_USE) {
        redirectWith('/?page=vehicles', 'danger', 'Cannot delete vehicle that is currently in use.');
    }

    db()->softDelete('vehicles', 'id = ?', [$vehicleId]);
    auditLog('vehicle_deleted', 'vehicle', $vehicleId, (array) $vehicle);

    redirectWith('/?page=vehicles', 'success', 'Vehicle deleted successfully.');
} catch (Exception $e) {
    error_log("Vehicle delete error: " . $e->getMessage());
    redirectWith('/?page=vehicles', 'danger', 'An error occurred while deleting the vehicle.');
}
