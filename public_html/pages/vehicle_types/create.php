<?php
/**
 * LOKA - Create Vehicle Type
 */

requireRole('admin');

$pageTitle = 'Add Vehicle Type';

if (isPost()) {
    requireCsrf();

    $name = postSafe('name', '', 50);
    $description = postSafe('description', '', 500);
    $passengerCapacity = postInt('passenger_capacity');

    // Validate
    if (empty($name)) {
        redirectWith('/?page=vehicle_types&action=create', 'danger', 'Vehicle type name is required.');
    }

    if ($passengerCapacity < 1 || $passengerCapacity > 50) {
        redirectWith('/?page=vehicle_types&action=create', 'danger', 'Passenger capacity must be between 1 and 50.');
    }

    // Check if name already exists
    $existing = db()->fetch(
        "SELECT id FROM vehicle_types WHERE name = ? AND deleted_at IS NULL",
        [$name]
    );

    if ($existing) {
        redirectWith('/?page=vehicle_types&action=create', 'danger', 'A vehicle type with this name already exists.');
    }

    // Create vehicle type
    db()->insert('vehicle_types', [
        'name' => $name,
        'description' => $description ?: null,
        'passenger_capacity' => $passengerCapacity,
        'created_at' => date('Y-m-d H:i:s')
    ]);

    setFlashMessage('Vehicle type created successfully.', 'success');
    redirect('/?page=vehicle_types');
}

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-car-front me-2"></i>Add Vehicle Type</h4>
            <p class="text-muted mb-0">Create a new vehicle type for the fleet</p>
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
                                   placeholder="e.g., Sedan, SUV, Van, Bus">
                            <small class="text-muted">A unique name for this vehicle type</small>
                        </div>

                        <div class="mb-3">
                            <label for="passenger_capacity" class="form-label">Passenger Capacity <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="passenger_capacity" name="passenger_capacity"
                                   required min="1" max="50" value="4">
                            <small class="text-muted">Number of passengers this vehicle type can accommodate (including driver)</small>
                        </div>

                        <div class="mb-4">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description"
                                      rows="3" maxlength="500"
                                      placeholder="Optional description of this vehicle type..."></textarea>
                            <small class="text-muted">Additional details about this vehicle type</small>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg me-1"></i>Create Vehicle Type
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
                    <p class="small mb-2">Vehicle types help organize your fleet and ensure proper vehicle assignment based on passenger count.</p>
                    <ul class="small mb-0">
                        <li><strong>Name:</strong> Unique identifier for the type (e.g., Sedan, SUV)</li>
                        <li><strong>Capacity:</strong> Maximum passengers including driver</li>
                        <li><strong>Description:</strong> Optional details about the vehicle type</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
