<?php
/**
 * LOKA - Edit Vehicle Page
 */

requireRole(ROLE_APPROVER);

$vehicleId = (int) get('id');
$errors = [];

$vehicle = db()->fetch("SELECT * FROM vehicles WHERE id = ? AND deleted_at IS NULL FOR UPDATE", [$vehicleId]);
if (!$vehicle) redirectWith('/?page=vehicles', 'danger', 'Vehicle not found.');

$vehicleTypes = getVehicleTypes(); // Use cached vehicle types

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    
    $plateNumber = postSafe('plate_number', '', 50);
    $make = postSafe('make', '', 100);
    $model = postSafe('model', '', 100);
    $year = postInt('year');
    $vehicleTypeId = postInt('vehicle_type_id');
    $color = postSafe('color', '', 50);
    $fuelType = postSafe('fuel_type', '', 20);
    $transmission = postSafe('transmission', '', 20);
    $mileage = postInt('mileage', 0);
    $status = postSafe('status', '', 20);
    $notes = postSafe('notes', '', 500);
    
    if (empty($plateNumber)) $errors[] = 'Plate number is required';
    if (empty($make)) $errors[] = 'Make is required';
    if (empty($model)) $errors[] = 'Model is required';
    
    // Validate status transitions (only if status is actually changing)
    if ($status !== $vehicle->status) {
        $validTransitions = [
            'available' => ['in_use', 'maintenance'],
            'in_use' => ['available', 'completed'],
            'maintenance' => ['available'],
            'completed' => ['available']
        ];

        if (isset($validTransitions[$vehicle->status]) && !in_array($status, $validTransitions[$vehicle->status])) {
            $errors[] = "Cannot change vehicle status from {$vehicle->status} to {$status}";
        }
    }
    
    if (empty($errors)) {
        db()->beginTransaction();
        
        try {
            // Re-fetch with lock to ensure atomicity
            $vehicle = db()->fetch("SELECT * FROM vehicles WHERE id = ? AND deleted_at IS NULL FOR UPDATE", [$vehicleId]);
            
            // Check unique plate (exclude current)
            if ($plateNumber && $plateNumber !== $vehicle->plate_number) {
                $existing = db()->fetch("SELECT id FROM vehicles WHERE plate_number = ? AND id != ? AND deleted_at IS NULL", [$plateNumber, $vehicleId]);
                if ($existing) {
                    db()->rollback();
                    $errors[] = 'Plate number already exists';
                }
            }
            
            if (empty($errors)) {
                $oldData = (array) $vehicle;
                
                db()->update('vehicles', [
                    'plate_number' => $plateNumber,
                    'make' => $make,
                    'model' => $model,
                    'year' => $year,
                    'vehicle_type_id' => $vehicleTypeId,
                    'color' => $color,
                    'fuel_type' => $fuelType,
                    'transmission' => $transmission,
                    'mileage' => $mileage,
                    'status' => $status,
                    'notes' => $notes,
                    'updated_at' => date(DATETIME_FORMAT)
                ], 'id = ?', [$vehicleId]);
                
                auditLog('vehicle_updated', 'vehicle', $vehicleId, $oldData);
                db()->commit();
                clearVehicleCache(); // Clear vehicle cache after updating vehicle
                redirectWith('/?page=vehicles', 'success', 'Vehicle updated successfully.');
            }
        } catch (Exception $e) {
            db()->rollback();
            $errors[] = 'Failed to update vehicle';
        }
    }
}

$pageTitle = 'Edit Vehicle';
require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <div class="mb-4">
        <h4 class="mb-1">Edit Vehicle</h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= APP_URL ?>/?page=vehicles">Vehicles</a></li>
                <li class="breadcrumb-item active">Edit</li>
            </ol>
        </nav>
    </div>
    
    <div class="row">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header"><h5 class="mb-0"><i class="bi bi-pencil me-2"></i>Edit Vehicle</h5></div>
                <div class="card-body">
                    <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?></ul></div>
                    <?php endif; ?>
                    
                    <form method="POST">
                        <?= csrfField() ?>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Plate Number <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="plate_number" value="<?= e(post('plate_number', $vehicle->plate_number)) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Vehicle Type</label>
                                <select class="form-select" name="vehicle_type_id">
                                    <?php foreach ($vehicleTypes as $type): ?>
                                    <option value="<?= $type->id ?>" <?= (post('vehicle_type_id', $vehicle->vehicle_type_id) == $type->id) ? 'selected' : '' ?>><?= e($type->name) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Make <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="make" value="<?= e(post('make', $vehicle->make)) ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Model <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="model" value="<?= e(post('model', $vehicle->model)) ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Year</label>
                                <input type="text" class="form-control" name="year" value="<?= e(post('year', $vehicle->year)) ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Color</label>
                                <input type="text" class="form-control" name="color" value="<?= e(post('color', $vehicle->color)) ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Fuel Type</label>
                                <select class="form-select" name="fuel_type">
                                    <option value="gasoline" <?= post('fuel_type', $vehicle->fuel_type) === 'gasoline' ? 'selected' : '' ?>>Gasoline</option>
                                    <option value="diesel" <?= post('fuel_type', $vehicle->fuel_type) === 'diesel' ? 'selected' : '' ?>>Diesel</option>
                                    <option value="electric" <?= post('fuel_type', $vehicle->fuel_type) === 'electric' ? 'selected' : '' ?>>Electric</option>
                                    <option value="hybrid" <?= post('fuel_type', $vehicle->fuel_type) === 'hybrid' ? 'selected' : '' ?>>Hybrid</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Transmission</label>
                                <select class="form-select" name="transmission">
                                    <option value="automatic" <?= post('transmission', $vehicle->transmission) === 'automatic' ? 'selected' : '' ?>>Automatic</option>
                                    <option value="manual" <?= post('transmission', $vehicle->transmission) === 'manual' ? 'selected' : '' ?>>Manual</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status">
                                    <?php foreach (VEHICLE_STATUS_LABELS as $key => $info): ?>
                                    <option value="<?= $key ?>" <?= post('status', $vehicle->status) === $key ? 'selected' : '' ?>><?= e($info['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Mileage (km)</label>
                                <input type="number" class="form-control" name="mileage" value="<?= e(post('mileage', $vehicle->mileage)) ?>" min="0">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Notes</label>
                                <textarea class="form-control" name="notes" rows="2"><?= e(post('notes', $vehicle->notes)) ?></textarea>
                            </div>
                        </div>
                        <hr class="my-4">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save Changes</button>
                        <a href="<?= APP_URL ?>/?page=vehicles" class="btn btn-outline-secondary">Cancel</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
