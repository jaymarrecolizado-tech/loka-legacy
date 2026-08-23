<?php
/**
 * LOKA - Create Maintenance Request
 */

requireRole(ROLE_APPROVER);

$pageTitle = 'New Maintenance Request';
$errors = [];

$vehicles = db()->fetchAll(
    "SELECT v.*, vt.name as type_name 
     FROM vehicles v 
     JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
     WHERE v.deleted_at IS NULL 
     ORDER BY v.plate_number"
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    
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
            $maintenanceId = db()->insert('maintenance_requests', [
                'vehicle_id' => $vehicleId,
                'reported_by' => userId(),
                'type' => $type,
                'priority' => $priority,
                'title' => $title,
                'description' => $description,
                'scheduled_date' => $scheduledDate ?: null,
                'estimated_cost' => $estimatedCost,
                'odometer_reading' => $odometer,
                'reported_at' => date(DATETIME_FORMAT),
                'status' => 'pending',
                'created_at' => date(DATETIME_FORMAT)
            ]);
            
            // Update vehicle status to maintenance if emergency or high priority
            if (in_array($priority, [MAINTENANCE_PRIORITY_CRITICAL, MAINTENANCE_PRIORITY_HIGH])) {
                db()->update('vehicles', [
                    'status' => VEHICLE_MAINTENANCE,
                    'updated_at' => date(DATETIME_FORMAT)
                ], 'id = ?', [$vehicleId]);
            }
            
            auditLog('maintenance_created', 'maintenance_request', $maintenanceId, null, [
                'vehicle_id' => $vehicleId,
                'type' => $type,
                'priority' => $priority,
                'title' => $title
            ]);
            
            redirectWith('/?page=maintenance&action=view&id=' . $maintenanceId, 'success', 'Maintenance request created successfully.');
            
        } catch (Exception $e) {
            $errors[] = 'Failed to create maintenance request.';
            error_log("Maintenance creation error: " . $e->getMessage());
        }
    }
}

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <div class="mb-4">
        <h4 class="mb-1">New Maintenance Request</h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= APP_URL ?>/?page=maintenance">Maintenance</a></li>
                <li class="breadcrumb-item active">New Request</li>
            </ol>
        </nav>
    </div>
    
    <div class="row">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-wrench me-2"></i>Request Details</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?></ul>
                    </div>
                    <?php endif; ?>
                    
                    <form method="POST">
                        <?= csrfField() ?>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Vehicle <span class="text-danger">*</span></label>
                                <select class="form-select" name="vehicle_id" required>
                                    <option value="">Select vehicle...</option>
                                    <?php foreach ($vehicles as $v): ?>
                                    <option value="<?= $v->id ?>" <?= post('vehicle_id') == $v->id ? 'selected' : '' ?>>
                                        <?= e($v->plate_number) ?> - <?= e($v->make . ' ' . $v->model) ?>
                                        (<?= e($v->type_name) ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label">Type <span class="text-danger">*</span></label>
                                <select class="form-select" name="type" required>
                                    <optgroup label="Maintenance Categories">
                                        <option value="corrective" <?= post('type') === 'corrective' ? 'selected' : '' ?>>Corrective</option>
                                        <option value="preventive" <?= post('type') === 'preventive' ? 'selected' : '' ?>>Preventive</option>
                                        <option value="emergency" <?= post('type') === 'emergency' ? 'selected' : '' ?>>Emergency</option>
                                    </optgroup>
                                    <optgroup label="Recurring Maintenance">
                                        <?php foreach (RECURRING_MAINTENANCE_TYPES as $typeKey => $typeInfo): ?>
                                        <option value="<?= $typeKey ?>" <?= post('type') === $typeKey ? 'selected' : '' ?>>
                                            <?= $typeInfo['label'] ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                </select>
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label">Priority <span class="text-danger">*</span></label>
                                <select class="form-select" name="priority" required>
                                    <option value="medium" <?= post('priority') === 'medium' ? 'selected' : '' ?>>Medium</option>
                                    <option value="low" <?= post('priority') === 'low' ? 'selected' : '' ?>>Low</option>
                                    <option value="high" <?= post('priority') === 'high' ? 'selected' : '' ?>>High</option>
                                    <option value="critical" <?= post('priority') === 'critical' ? 'selected' : '' ?>>Critical</option>
                                </select>
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label">Title <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="title" 
                                       value="<?= e(post('title', '')) ?>" 
                                       placeholder="Brief description of the issue" required>
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label">Description <span class="text-danger">*</span></label>
                                <textarea class="form-control" name="description" rows="4" 
                                          placeholder="Detailed description of the maintenance needed..." required><?= e(post('description', '')) ?></textarea>
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Scheduled Date</label>
                                <input type="text" class="form-control datepicker" name="scheduled_date" 
                                       value="<?= e(post('scheduled_date', '')) ?>">
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Estimated Cost</label>
                                <div class="input-group">
                                    <span class="input-group-text">₱</span>
                                    <input type="number" class="form-control" name="estimated_cost" 
                                           value="<?= e(post('estimated_cost', '')) ?>" step="0.01" min="0">
                                </div>
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Odometer Reading</label>
                                <div class="input-group">
                                    <input type="number" class="form-control" name="odometer" 
                                           value="<?= e(post('odometer', '')) ?>" min="0">
                                    <span class="input-group-text">km</span>
                                </div>
                            </div>
                        </div>
                        
                        <hr class="my-4">
                        
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg me-1"></i>Create Request
                            </button>
                            <a href="<?= APP_URL ?>/?page=maintenance" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="col-lg-4">
            <div class="card bg-light border-0">
                <div class="card-body">
                    <h6><i class="bi bi-info-circle me-2"></i>Maintenance Types</h6>
                    <ul class="small text-muted mb-0">
                        <li class="mb-2"><strong>Preventive:</strong> Regular scheduled maintenance</li>
                        <li class="mb-2"><strong>Corrective:</strong> Repair of identified issues</li>
                        <li><strong>Emergency:</strong> Urgent repairs needed immediately</li>
                    </ul>
                </div>
            </div>
            
            <div class="card mt-3 bg-light border-0">
                <div class="card-body">
                    <h6><i class="bi bi-exclamation-triangle me-2"></i>Priority Guide</h6>
                    <ul class="small text-muted mb-0">
                        <li class="mb-2"><span class="badge bg-danger">Critical</span> Vehicle cannot operate</li>
                        <li class="mb-2"><span class="badge bg-warning">High</span> Safety concern</li>
                        <li class="mb-2"><span class="badge bg-info">Medium</span> Needs attention soon</li>
                        <li><span class="badge bg-secondary">Low</span> Minor issue</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
