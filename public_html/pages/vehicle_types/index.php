<?php
/**
 * LOKA - Vehicle Types Management
 *
 * Admin-only CRUD module for managing vehicle types
 */

requireRole('approver');

$pageTitle = 'Vehicle Types';

// Get all vehicle types with vehicle count
$vehicleTypes = db()->fetchAll(
    "SELECT vt.*,
            (SELECT COUNT(*) FROM vehicles v WHERE v.vehicle_type_id = vt.id AND v.deleted_at IS NULL) as vehicle_count
     FROM vehicle_types vt
     WHERE vt.deleted_at IS NULL
     ORDER BY vt.name"
);

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-car-front me-2"></i>Vehicle Types</h4>
            <p class="text-muted mb-0">Manage vehicle types for the fleet</p>
        </div>
        <div>
            <a href="<?= APP_URL ?>/?page=vehicle_types&action=create" class="btn btn-primary">
                <i class="bi bi-plus-lg me-1"></i>Add Vehicle Type
            </a>
        </div>
    </div>

    <!-- Vehicle Types Table -->
    <div class="card">
        <div class="card-body">
            <?php if (empty($vehicleTypes)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-car-front fs-1 text-muted"></i>
                    <p class="text-muted mt-3">No vehicle types found. Add your first vehicle type to get started.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Description</th>
                                <th>Passenger Capacity</th>
                                <th>Vehicles Using</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($vehicleTypes as $type): ?>
                                <tr>
                                    <td>
                                        <strong><?= e($type->name) ?></strong>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?= e($type->description ?: 'No description') ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary">
                                            <i class="bi bi-people me-1"></i><?= $type->passenger_capacity ?> seats
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= $type->vehicle_count > 0 ? 'success' : 'secondary' ?>">
                                            <?= $type->vehicle_count ?> vehicle<?= $type->vehicle_count != 1 ? 's' : '' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?= formatDate($type->created_at) ?></small>
                                    </td>
                                    <td>
                                        <div class="btn-group">
                                            <a href="<?= APP_URL ?>/?page=vehicle_types&action=edit&id=<?= $type->id ?>"
                                               class="btn btn-sm btn-outline-primary">
                                                <i class="bi bi-pencil"></i> Edit
                                            </a>
                                            <?php if ($type->vehicle_count == 0): ?>
                                                <a href="<?= APP_URL ?>/?page=vehicle_types&action=delete&id=<?= $type->id ?>"
                                                   class="btn btn-sm btn-outline-danger"
                                                   data-confirm="Delete this vehicle type? This action cannot be undone.">
                                                    <i class="bi bi-trash"></i>
                                                </a>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-sm btn-outline-secondary" disabled
                                                        title="Cannot delete: vehicles are using this type">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
