<?php
/**
 * LOKA - Edit Maintenance Request
 */

requireRole(ROLE_MOTORPOOL);

$maintenanceId = (int)get('id');
$errors = [];

$maintenance = db()->fetch(
    "SELECT * FROM maintenance_requests WHERE id = ? AND deleted_at IS NULL",
    [$maintenanceId]
);

if (!$maintenance) {
    redirectWith('/?page=maintenance', 'danger', 'Maintenance request not found.');
}

if ($maintenance->status === MAINTENANCE_STATUS_COMPLETED || $maintenance->status === MAINTENANCE_STATUS_CANCELLED) {
    redirectWith('/?page=maintenance&action=view&id=' . $maintenanceId, 'danger', 'Cannot edit completed or cancelled requests.');
}

$vehicles = db()->fetchAll(
    "SELECT v.*, vt.name as type_name 
     FROM vehicles v 
     JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
     WHERE v.deleted_at IS NULL 
     ORDER BY v.plate_number"
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    
    $action = postSafe('action', '', 20);
    
    if ($action === 'update_status') {
        $newStatus = postSafe('status', '', 20);
        $resolutionNotes = postSafe('resolution_notes', '', 2000);
        $actualCost = post('actual_cost') ? (float)post('actual_cost') : null;
        $completedDate = postSafe('completed_date', '', 20);
        $mileageAtCompletion = postInt('mileage_at_completion') ?: null;

        if (!in_array($newStatus, [MAINTENANCE_STATUS_PENDING, MAINTENANCE_STATUS_SCHEDULED, MAINTENANCE_STATUS_IN_PROGRESS, MAINTENANCE_STATUS_COMPLETED, MAINTENANCE_STATUS_CANCELLED])) {
            $errors[] = 'Invalid status';
        }

        // Validate mileage at completion when completing maintenance
        if ($newStatus === MAINTENANCE_STATUS_COMPLETED && $mileageAtCompletion === null) {
            $errors[] = 'Odometer reading at completion is required when marking as completed';
        }
        
        // Validate numeric fields
        if ($actualCost !== null && $actualCost < 0) $errors[] = 'Actual cost cannot be negative';
        if ($actualCost !== null && $actualCost > 9999999.99) $errors[] = 'Actual cost exceeds maximum allowed';
        
        if (empty($errors)) {
            try {
                db()->beginTransaction();
                
                $updateData = [
                    'status' => $newStatus,
                    'updated_at' => date(DATETIME_FORMAT)
                ];
                
                if ($resolutionNotes) {
                    $updateData['resolution_notes'] = $resolutionNotes;
                }
                
                if ($actualCost) {
                    $updateData['actual_cost'] = $actualCost;
                }
                
                if ($newStatus === MAINTENANCE_STATUS_IN_PROGRESS) {
                    db()->update('vehicles', [
                        'status' => VEHICLE_MAINTENANCE,
                        'updated_at' => date(DATETIME_FORMAT)
                    ], 'id = ?', [$maintenance->vehicle_id]);
                }
                
                if ($newStatus === MAINTENANCE_STATUS_COMPLETED) {
                    $updateData['completed_date'] = $completedDate ?: date('Y-m-d');
                    $updateData['mileage_at_completion'] = $mileageAtCompletion;
                    $updateData['completed_at'] = date(DATETIME_FORMAT);
                    $updateData['completed_by'] = userId();

                    $vehicle = db()->fetch(
                        "SELECT mileage FROM vehicles WHERE id = ?",
                        [$maintenance->vehicle_id]
                    );

                    // Update vehicle mileage to the completed mileage if it's higher
                    $newMileage = max($vehicle->mileage, $mileageAtCompletion);
                    db()->update('vehicles', [
                        'status' => VEHICLE_AVAILABLE,
                        'mileage' => $newMileage,
                        'last_maintenance_date' => date('Y-m-d'),
                        'last_maintenance_odometer' => $mileageAtCompletion,
                        'updated_at' => date(DATETIME_FORMAT)
                    ], 'id = ?', [$maintenance->vehicle_id]);
                }
                
                if ($newStatus === MAINTENANCE_STATUS_CANCELLED) {
                    if ($maintenance->priority === MAINTENANCE_PRIORITY_CRITICAL || $maintenance->priority === MAINTENANCE_PRIORITY_HIGH) {
                        db()->update('vehicles', [
                            'status' => VEHICLE_AVAILABLE,
                            'updated_at' => date(DATETIME_FORMAT)
                        ], 'id = ?', [$maintenance->vehicle_id]);
                    }
                }
                
                db()->update('maintenance_requests', $updateData, 'id = ?', [$maintenanceId]);
                
                auditLog('maintenance_updated', 'maintenance_request', $maintenanceId, ['status' => $maintenance->status], $updateData);
                
                db()->commit();
                
                redirectWith('/?page=maintenance&action=view&id=' . $maintenanceId, 'success', 'Maintenance request updated successfully.');
                
            } catch (Exception $e) {
                db()->rollback();
                $errors[] = 'Failed to update maintenance request.';
                error_log("Maintenance update error: " . $e->getMessage());
            }
        }
    } else {
        // Regular edit
        $vehicleId = postInt('vehicle_id');
        $type = postSafe('type', '', 20);
        $priority = postSafe('priority', '', 20);
        $title = postSafe('title', '', 255);
        $description = postSafe('description', '', 2000);
        $scheduledDate = postSafe('scheduled_date', '', 20);
        $estimatedCost = post('estimated_cost') ? (float)post('estimated_cost') : null;
        $odometer = postInt('odometer') ?: null;
        
        if (!$vehicleId) $errors[] = 'Please select a vehicle';

        // Validate maintenance type (including recurring types)
        $allTypes = array_merge(
            [MAINTENANCE_TYPE_PREVENTIVE, MAINTENANCE_TYPE_CORRECTIVE, MAINTENANCE_TYPE_EMERGENCY],
            array_keys(RECURRING_MAINTENANCE_TYPES)
        );
        if (!in_array($type, $allTypes)) $errors[] = 'Invalid maintenance type';

        if (!in_array($priority, [MAINTENANCE_PRIORITY_LOW, MAINTENANCE_PRIORITY_MEDIUM, MAINTENANCE_PRIORITY_HIGH, MAINTENANCE_PRIORITY_CRITICAL])) $errors[] = 'Invalid priority';
        if (empty($title)) $errors[] = 'Title is required';
        if (empty($description)) $errors[] = 'Description is required';
        
        // Validate numeric fields
        if ($estimatedCost !== null && $estimatedCost < 0) $errors[] = 'Estimated cost cannot be negative';
        if ($odometer !== null && $odometer < 0) $errors[] = 'Odometer reading cannot be negative';
        if ($estimatedCost !== null && $estimatedCost > 9999999.99) $errors[] = 'Estimated cost exceeds maximum allowed';
        if ($odometer !== null && $odometer > 9999999) $errors[] = 'Odometer reading exceeds maximum allowed';
        
        if (empty($errors)) {
            try {
                $oldData = (array)$maintenance;
                
                db()->update('maintenance_requests', [
                    'vehicle_id' => $vehicleId,
                    'type' => $type,
                    'priority' => $priority,
                    'title' => $title,
                    'description' => $description,
                    'scheduled_date' => $scheduledDate ?: null,
                    'estimated_cost' => $estimatedCost,
                    'odometer_reading' => $odometer,
                    'updated_at' => date(DATETIME_FORMAT)
                ], 'id = ?', [$maintenanceId]);
                
                auditLog('maintenance_edited', 'maintenance_request', $maintenanceId, $oldData, [
                    'vehicle_id' => $vehicleId,
                    'type' => $type,
                    'priority' => $priority,
                    'title' => $title
                ]);
                
                redirectWith('/?page=maintenance&action=view&id=' . $maintenanceId, 'success', 'Maintenance request updated successfully.');
                
            } catch (Exception $e) {
                $errors[] = 'Failed to update maintenance request.';
                error_log("Maintenance edit error: " . $e->getMessage());
            }
        }
    }
}

$pageTitle = 'Edit Maintenance Request #' . $maintenanceId;

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <div class="mb-4">
        <h4 class="mb-1">Edit Maintenance Request #<?= $maintenanceId ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= APP_URL ?>/?page=maintenance">Maintenance</a></li>
                <li class="breadcrumb-item active">Edit</li>
            </ol>
        </nav>
    </div>
    
    <div class="row">
        <div class="col-lg-8">
            <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?></ul>
            </div>
            <?php endif; ?>
            
            <!-- Status Update Card -->
            <div class="card mb-4 border-primary">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="bi bi-arrow-repeat me-2"></i>Update Status</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="update_status">
                        
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status" id="statusSelect">
                                    <option value="pending" <?= $maintenance->status === 'pending' ? 'selected' : '' ?>>Pending</option>
                                    <option value="scheduled" <?= $maintenance->status === 'scheduled' ? 'selected' : '' ?>>Scheduled</option>
                                    <option value="in_progress" <?= $maintenance->status === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                                    <option value="completed" <?= $maintenance->status === 'completed' ? 'selected' : '' ?>>Completed</option>
                                    <option value="cancelled" <?= $maintenance->status === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">Actual Cost</label>
                                <div class="input-group">
                                    <span class="input-group-text">₱</span>
                                    <input type="number" class="form-control" name="actual_cost"
                                           value="<?= e($maintenance->actual_cost ?? '') ?>" step="0.01" min="0">
                                </div>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">Completed Date</label>
                                <input type="text" class="form-control datepicker" name="completed_date"
                                       value="<?= e($maintenance->completed_date ?? '') ?>">
                            </div>

                            <div class="col-md-6" id="mileageAtCompletionWrapper" style="display: <?= $maintenance->status === MAINTENANCE_STATUS_COMPLETED ? 'block' : 'none' ?>;">
                                <label class="form-label">Odometer at Completion <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="number" class="form-control" name="mileage_at_completion"
                                           value="<?= e($maintenance->mileage_at_completion ?? '') ?>" min="0">
                                    <span class="input-group-text">km</span>
                                </div>
                                <small class="text-muted">Vehicle's odometer reading when maintenance was completed</small>
                            </div>

                            <div class="col-12">
                                <label class="form-label">Resolution Notes</label>
                                <textarea class="form-control" name="resolution_notes" rows="2"
                                          placeholder="Notes about the repair or resolution..."><?= e($maintenance->resolution_notes ?? '') ?></textarea>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-primary mt-3">
                            <i class="bi bi-check-lg me-1"></i>Update Status
                        </button>
                    </form>
                </div>
            </div>
            
            <!-- Edit Details Card -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-pencil me-2"></i>Edit Details</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <?= csrfField() ?>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Vehicle</label>
                                <select class="form-select" name="vehicle_id">
                                    <?php foreach ($vehicles as $v): ?>
                                    <option value="<?= $v->id ?>" <?= ($maintenance->vehicle_id == $v->id || post('vehicle_id') == $v->id) ? 'selected' : '' ?>>
                                        <?= e($v->plate_number) ?> - <?= e($v->make . ' ' . $v->model) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label">Type</label>
                                <select class="form-select" name="type">
                                    <optgroup label="Maintenance Categories">
                                        <option value="corrective" <?= $maintenance->type === 'corrective' ? 'selected' : '' ?>>Corrective</option>
                                        <option value="preventive" <?= $maintenance->type === 'preventive' ? 'selected' : '' ?>>Preventive</option>
                                        <option value="emergency" <?= $maintenance->type === 'emergency' ? 'selected' : '' ?>>Emergency</option>
                                    </optgroup>
                                    <optgroup label="Recurring Maintenance">
                                        <?php foreach (RECURRING_MAINTENANCE_TYPES as $typeKey => $typeInfo): ?>
                                        <option value="<?= $typeKey ?>" <?= $maintenance->type === $typeKey ? 'selected' : '' ?>>
                                            <?= $typeInfo['label'] ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                </select>
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label">Priority</label>
                                <select class="form-select" name="priority">
                                    <option value="low" <?= $maintenance->priority === 'low' ? 'selected' : '' ?>>Low</option>
                                    <option value="medium" <?= $maintenance->priority === 'medium' ? 'selected' : '' ?>>Medium</option>
                                    <option value="high" <?= $maintenance->priority === 'high' ? 'selected' : '' ?>>High</option>
                                    <option value="critical" <?= $maintenance->priority === 'critical' ? 'selected' : '' ?>>Critical</option>
                                </select>
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label">Title</label>
                                <input type="text" class="form-control" name="title" 
                                       value="<?= e(post('title', $maintenance->title)) ?>">
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label">Description</label>
                                <textarea class="form-control" name="description" rows="3"><?= e(post('description', $maintenance->description)) ?></textarea>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Scheduled Date</label>
                                <input type="text" class="form-control datepicker" name="scheduled_date" 
                                       value="<?= e(post('scheduled_date', $maintenance->scheduled_date)) ?>">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Estimated Cost</label>
                                <div class="input-group">
                                    <span class="input-group-text">₱</span>
                                    <input type="number" class="form-control" name="estimated_cost" 
                                           value="<?= e(post('estimated_cost', $maintenance->estimated_cost)) ?>" step="0.01" min="0">
                                </div>
                            </div>
                        </div>
                        
                        <hr class="my-4">
                        
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg me-1"></i>Save Changes
                            </button>
                            <a href="<?= APP_URL ?>/?page=maintenance&action=view&id=<?= $maintenanceId ?>" 
                               class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="col-lg-4">
            <div class="card bg-light border-0">
                <div class="card-body">
                    <h6><i class="bi bi-info-circle me-2"></i>Current Status</h6>
                    <?php $statusInfo = MAINTENANCE_STATUSES[$maintenance->status] ?? ['label' => ucfirst(str_replace('_', ' ', $maintenance->status)), 'color' => 'secondary']; ?>
                    <span class="badge bg-<?= $statusInfo['color'] ?> fs-6">
                        <?= $statusInfo['label'] ?>
                    </span>
                    
                    <hr>
                    <p class="small text-muted mb-0">
                        <strong>Created:</strong> <?= formatDateTime($maintenance->created_at) ?><br>
                        <strong>Updated:</strong> <?= formatDateTime($maintenance->updated_at) ?>
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const statusSelect = document.getElementById('statusSelect');
    const mileageWrapper = document.getElementById('mileageAtCompletionWrapper');

    function toggleMileageField() {
        if (statusSelect.value === 'completed') {
            mileageWrapper.style.display = 'block';
        } else {
            mileageWrapper.style.display = 'none';
        }
    }

    statusSelect.addEventListener('change', toggleMileageField);
});
</script>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
