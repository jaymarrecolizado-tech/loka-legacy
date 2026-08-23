<?php
/**
 * LOKA - View Maintenance Request
 */

requireRole(ROLE_APPROVER);

$maintenanceId = (int)get('id');

$maintenance = db()->fetch(
    "SELECT mr.*, 
            v.plate_number, v.make, v.model, v.mileage,
            vt.name as vehicle_type,
            reporter.name as reporter_name, reporter.email as reporter_email,
            assigned.name as assigned_name, assigned.email as assigned_email
     FROM maintenance_requests mr
     JOIN vehicles v ON mr.vehicle_id = v.id
     LEFT JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
     JOIN users reporter ON mr.reported_by = reporter.id
     LEFT JOIN users assigned ON mr.assigned_to = assigned.id
     WHERE mr.id = ? AND mr.deleted_at IS NULL",
    [$maintenanceId]
);

if (!$maintenance) {
    redirectWith('/?page=maintenance', 'danger', 'Maintenance request not found.');
}

$pageTitle = 'Maintenance Request #' . $maintenanceId;

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1">Maintenance Request #<?= $maintenanceId ?></h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/?page=maintenance">Maintenance</a></li>
                    <li class="breadcrumb-item active">View</li>
                </ol>
            </nav>
        </div>
        <div>
            <?php if ($maintenance->status !== MAINTENANCE_STATUS_COMPLETED && $maintenance->status !== MAINTENANCE_STATUS_CANCELLED): ?>
            <a href="<?= APP_URL ?>/?page=maintenance&action=edit&id=<?= $maintenanceId ?>"
               class="btn btn-outline-primary">
                <i class="bi bi-pencil me-1"></i>Edit
            </a>
            <?php endif; ?>
            <?php if (isAdmin() && $maintenance->status !== MAINTENANCE_STATUS_IN_PROGRESS): ?>
            <form method="POST" action="<?= APP_URL ?>/?page=maintenance&action=delete" class="d-inline"
                  onsubmit="return confirm('Are you sure you want to delete this maintenance request?')">
                <?= csrfField() ?>
                <input type="hidden" name="id" value="<?= $maintenanceId ?>">
                <button type="submit" class="btn btn-outline-danger">
                    <i class="bi bi-trash me-1"></i>Delete
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>Request Details</h5>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <h6 class="text-muted">Title</h6>
                            <p class="fw-medium"><?= e($maintenance->title) ?></p>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-muted">Status</h6>
                            <?php $statusInfo = MAINTENANCE_STATUSES[$maintenance->status] ?? ['label' => ucfirst(str_replace('_', ' ', $maintenance->status)), 'color' => 'secondary']; ?>
                            <span class="badge bg-<?= $statusInfo['color'] ?> fs-6">
                                <?= $statusInfo['label'] ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <h6 class="text-muted">Type</h6>
                            <?php $typeInfo = MAINTENANCE_TYPES[$maintenance->type] ?? ['label' => ucfirst($maintenance->type)]; ?>
                            <p class="mb-0"><?= $typeInfo['label'] ?></p>
                        </div>
                        <div class="col-md-4">
                            <h6 class="text-muted">Priority</h6>
                            <?php $priorityInfo = MAINTENANCE_PRIORITIES[$maintenance->priority] ?? ['label' => ucfirst($maintenance->priority), 'color' => 'secondary']; ?>
                            <span class="badge bg-<?= $priorityInfo['color'] ?>">
                                <?= $priorityInfo['label'] ?>
                            </span>
                        </div>
                        <div class="col-md-4">
                            <h6 class="text-muted">Reported</h6>
                            <p class="mb-0"><?= formatDateTime($maintenance->reported_at) ?></p>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <h6 class="text-muted">Description</h6>
                        <p><?= nl2br(e($maintenance->description)) ?></p>
                    </div>
                    
                    <?php if ($maintenance->resolution_notes): ?>
                    <div class="mb-3">
                        <h6 class="text-muted">Resolution Notes</h6>
                        <p><?= nl2br(e($maintenance->resolution_notes)) ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if ($maintenance->status === MAINTENANCE_STATUS_COMPLETED): ?>
            <div class="card mb-4 border-success">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="bi bi-check-circle me-2"></i>Completion Details</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <h6 class="text-muted">Completed Date</h6>
                            <p class="mb-0"><?= formatDate($maintenance->completed_date) ?></p>
                        </div>
                        <div class="col-md-4">
                            <h6 class="text-muted">Actual Cost</h6>
                            <p class="mb-0">
                                <?php if ($maintenance->actual_cost): ?>
                                    ₱<?= number_format($maintenance->actual_cost, 2) ?>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="col-md-4">
                            <h6 class="text-muted">Odometer at Completion</h6>
                            <p class="mb-0">
                                <?php if ($maintenance->odometer_reading): ?>
                                    <?= number_format($maintenance->odometer_reading) ?> km
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="col-lg-4">
            <div class="card mb-4">
                <div class="card-header">
                    <h6 class="mb-0"><i class="bi bi-car-front me-2"></i>Vehicle</h6>
                </div>
                <div class="card-body">
                    <p class="mb-1"><strong><?= e($maintenance->plate_number) ?></strong></p>
                    <p class="text-muted mb-0"><?= e($maintenance->make . ' ' . $maintenance->model) ?></p>
                    <small class="text-muted"><?= e($maintenance->vehicle_type) ?></small>
                </div>
            </div>
            
            <div class="card mb-4">
                <div class="card-header">
                    <h6 class="mb-0"><i class="bi bi-person me-2"></i>Reported By</h6>
                </div>
                <div class="card-body">
                    <p class="mb-1"><strong><?= e($maintenance->reporter_name) ?></strong></p>
                    <small class="text-muted"><?= e($maintenance->reporter_email) ?></small>
                </div>
            </div>
            
            <?php if ($maintenance->scheduled_date): ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h6 class="mb-0"><i class="bi bi-calendar me-2"></i>Scheduled</h6>
                </div>
                <div class="card-body">
                    <p class="mb-0 fw-medium"><?= formatDate($maintenance->scheduled_date) ?></p>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($maintenance->estimated_cost): ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h6 class="mb-0"><i class="bi bi-cash me-2"></i>Cost Estimate</h6>
                </div>
                <div class="card-body">
                    <p class="mb-0 fw-medium">₱<?= number_format($maintenance->estimated_cost, 2) ?></p>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0"><i class="bi bi-clock-history me-2"></i>Timeline</h6>
                </div>
                <ul class="list-group list-group-flush">
                    <li class="list-group-item">
                        <small class="text-muted">Created</small><br>
                        <?= formatDateTime($maintenance->created_at) ?>
                    </li>
                    <li class="list-group-item">
                        <small class="text-muted">Last Updated</small><br>
                        <?= formatDateTime($maintenance->updated_at) ?>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
