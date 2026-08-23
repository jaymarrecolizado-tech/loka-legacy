<?php
/**
 * LOKA - Edit Vehicle Type
 */

requireRole('admin');

$id = getInt('id');

$vehicleType = db()->fetch(
    "SELECT * FROM vehicle_types WHERE id = ? AND deleted_at IS NULL",
    [$id]
);

if (!$vehicleType) {
    redirectWith('/?page=vehicle_types', 'danger', 'Vehicle type not found.');
}

$pageTitle = 'Edit Vehicle Type - ' . e($vehicleType->name);

if (isPost()) {
    requireCsrf();

    $name = postSafe('name', '', 50);
    $description = postSafe('description', '', 500);
    $passengerCapacity = postInt('passenger_capacity');

    // Validate
    if (empty($name)) {
        redirectWith('/?page=vehicle_types&action=edit&id=' . $id, 'danger', 'Vehicle type name is required.');
    }

    if ($passengerCapacity < 1 || $passengerCapacity > 50) {
        redirectWith('/?page=vehicle_types&action=edit&id=' . $id, 'danger', 'Passenger capacity must be between 1 and 50.');
    }

    // Check if name already exists (excluding current record)
    $existing = db()->fetch(
        "SELECT id FROM vehicle_types WHERE name = ? AND id != ? AND deleted_at IS NULL",
        [$name, $id]
    );

    if ($existing) {
        redirectWith('/?page=vehicle_types&action=edit&id=' . $id, 'danger', 'A vehicle type with this name already exists.');
    }

    // Update vehicle type
    db()->update('vehicle_types', [
        'name' => $name,
        'description' => $description ?: null,
        'passenger_capacity' => $passengerCapacity,
        'updated_at' => date('Y-m-d H:i:s')
    ], 'id = ?', [$id]);

    setFlashMessage('Vehicle type updated successfully.', 'success');
    redirect('/?page=vehicle_types');
}

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-car-front me-2"></i>Edit Vehicle Type</h4>
            <p class="text-muted mb-0">Update vehicle type information</p>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-body">
                    <form method="POST">
                        <?= csrfField() ?>

                        <div class="mb-3">
                            <label for="name" class="form-label">Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="name" name="name"
                                   required maxlength="50"
                                   value="<?= e($vehicleType->name) ?>"
                                   placeholder="e.g., Sedan, SUV, Van, Bus">
                            <small class="text-muted">A unique name for this vehicle type</small>
                        </div>

                        <div class="mb-3">
                            <label for="passenger_capacity" class="form-label">Passenger Capacity <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="passenger_capacity" name="passenger_capacity"
                                   required min="1" max="50" value="<?= $vehicleType->passenger_capacity ?>">
                            <small class="text-muted">Number of passengers this vehicle type can accommodate (including driver)</small>
                        </div>

                        <div class="mb-4">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description"
                                      rows="3" maxlength="500"
                                      placeholder="Optional description of this vehicle type..."><?= e($vehicleType->description) ?></textarea>
                            <small class="text-muted">Additional details about this vehicle type</small>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg me-1"></i>Update Vehicle Type
                            </button>
                            <a href="<?= APP_URL ?>/?page=vehicle_types" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0"><i class="bi bi-info-circle me-1"></i>Help</h6>
                </div>
                <div class="card-body">
                    <p class="small mb-2">Editing vehicle types will affect how vehicles are displayed and assigned.</p>
                    <ul class="small mb-0">
                        <li><strong>Name:</strong> Must be unique across all vehicle types</li>
                        <li><strong>Capacity:</strong> Used for trip validation</li>
                        <li><strong>Vehicles:</strong> <?= db()->fetchColumn("SELECT COUNT(*) FROM vehicles WHERE vehicle_type_id = ? AND deleted_at IS NULL", [$id]) ?> vehicle(s) currently using this type</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
