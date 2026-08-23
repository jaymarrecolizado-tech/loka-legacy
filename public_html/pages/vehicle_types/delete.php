<?php
/**
 * LOKA - Delete Vehicle Type
 */

requireRole('admin');
requireCsrf();

$id = getInt('id');

// Check if vehicle type exists
$vehicleType = db()->fetch(
    "SELECT * FROM vehicle_types WHERE id = ? AND deleted_at IS NULL",
    [$id]
);

if (!$vehicleType) {
    redirectWith('/?page=vehicle_types', 'danger', 'Vehicle type not found.');
}

// Check if vehicles are using this type
$vehicleCount = db()->fetchColumn(
    "SELECT COUNT(*) FROM vehicles WHERE vehicle_type_id = ? AND deleted_at IS NULL",
    [$id]
);

if ($vehicleCount > 0) {
    redirectWith('/?page=vehicle_types', 'danger', "Cannot delete: {$vehicleCount} vehicle(s) are using this type. Please reassign or delete the vehicles first.");
}

// Soft delete
db()->update('vehicle_types', [
    'deleted_at' => date('Y-m-d H:i:s')
], 'id = ?', [$id]);

setFlashMessage('Vehicle type deleted successfully.', 'success');
redirect('/?page=vehicle_types');
