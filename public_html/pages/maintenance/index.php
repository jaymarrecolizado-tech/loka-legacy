<?php
/**
 * LOKA - Maintenance Requests Page
 * 
 * Manage vehicle maintenance requests and records
 */

requireRole(ROLE_APPROVER);

$pageTitle = 'Maintenance';

$filter = get('filter', 'all');
$status = get('status', '');

$sql = "SELECT mr.*, 
            v.plate_number, v.make, v.model,
            reporter.name as reporter_name,
            assigned.name as assigned_name
        FROM maintenance_requests mr
        JOIN vehicles v ON mr.vehicle_id = v.id
        JOIN users reporter ON mr.reported_by = reporter.id
        LEFT JOIN users assigned ON mr.assigned_to = assigned.id
        WHERE mr.deleted_at IS NULL";

$params = [];

if ($status && in_array($status, [MAINTENANCE_STATUS_PENDING, MAINTENANCE_STATUS_SCHEDULED, MAINTENANCE_STATUS_IN_PROGRESS, MAINTENANCE_STATUS_COMPLETED, MAINTENANCE_STATUS_CANCELLED])) {
    $sql .= " AND mr.status = ?";
    $params[] = $status;
}

switch ($filter) {
    case 'overdue':
        $sql .= " AND mr.status IN (?, ?) AND mr.scheduled_date < CURDATE()";
        $params[] = MAINTENANCE_STATUS_PENDING;
        $params[] = MAINTENANCE_STATUS_SCHEDULED;
        break;
    case 'upcoming':
        $sql .= " AND mr.status IN (?, ?) AND mr.scheduled_date >= CURDATE() AND mr.scheduled_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
        $params[] = MAINTENANCE_STATUS_PENDING;
        $params[] = MAINTENANCE_STATUS_SCHEDULED;
        break;
}

$sql .= " ORDER BY 
    FIELD(mr.priority, 'critical', 'high', 'medium', 'low'),
    FIELD(mr.status, 'pending', 'scheduled', 'in_progress', 'completed', 'cancelled'),
    mr.scheduled_date ASC,
    mr.created_at DESC";

$maintenanceRequests = db()->fetchAll($sql, $params);

$stats = [
    'pending' => db()->count('maintenance_requests', "status = ? AND deleted_at IS NULL", [MAINTENANCE_STATUS_PENDING]),
    'scheduled' => db()->count('maintenance_requests', "status = ? AND deleted_at IS NULL", [MAINTENANCE_STATUS_SCHEDULED]),
    'in_progress' => db()->count('maintenance_requests', "status = ? AND deleted_at IS NULL", [MAINTENANCE_STATUS_IN_PROGRESS]),
    'completed' => db()->count('maintenance_requests', "status = ? AND deleted_at IS NULL", [MAINTENANCE_STATUS_COMPLETED])
];

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-wrench me-2"></i>Maintenance Requests</h4>
            <p class="text-muted mb-0">Manage vehicle maintenance and repairs</p>
        </div>
        <a href="<?= APP_URL ?>/?page=maintenance&action=create" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i>New Request
        </a>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="bg-warning bg-opacity-10 rounded p-3">
                                <i class="bi bi-clock text-warning fs-4"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="text-muted mb-1">Pending</h6>
                            <h3 class="mb-0"><?= $stats['pending'] ?></h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="bg-info bg-opacity-10 rounded p-3">
                                <i class="bi bi-calendar-check text-info fs-4"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="text-muted mb-1">Scheduled</h6>
                            <h3 class="mb-0"><?= $stats['scheduled'] ?></h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="bg-primary bg-opacity-10 rounded p-3">
                                <i class="bi bi-tools text-primary fs-4"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="text-muted mb-1">In Progress</h6>
                            <h3 class="mb-0"><?= $stats['in_progress'] ?></h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="bg-success bg-opacity-10 rounded p-3">
                                <i class="bi bi-check-circle text-success fs-4"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="text-muted mb-1">Completed</h6>
                            <h3 class="mb-0"><?= $stats['completed'] ?></h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header bg-white">
            <ul class="nav nav-tabs card-header-tabs">
                <li class="nav-item">
                    <a class="nav-link <?= $filter === 'all' && !$status ? 'active' : '' ?>" 
                       href="<?= APP_URL ?>/?page=maintenance">
                        <i class="bi bi-list-ul me-1"></i>All
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $status === 'pending' ? 'active' : '' ?>" 
                       href="<?= APP_URL ?>/?page=maintenance&status=pending">
                        <i class="bi bi-clock me-1"></i>Pending
                        <?php if ($stats['pending'] > 0): ?>
                            <span class="badge bg-warning ms-1"><?= $stats['pending'] ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $filter === 'overdue' ? 'active' : '' ?>" 
                       href="<?= APP_URL ?>/?page=maintenance&filter=overdue">
                        <i class="bi bi-exclamation-triangle me-1"></i>Overdue
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $status === 'completed' ? 'active' : '' ?>" 
                       href="<?= APP_URL ?>/?page=maintenance&status=completed">
                        <i class="bi bi-check-circle me-1"></i>Completed
                    </a>
                </li>
            </ul>
        </div>
        <div class="card-body">
            <?php if (empty($maintenanceRequests)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-wrench-adjustable fs-1 text-muted"></i>
                    <p class="text-muted mt-3">No maintenance requests found.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Vehicle</th>
                                <th>Issue</th>
                                <th>Type</th>
                                <th>Priority</th>
                                <th>Scheduled</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($maintenanceRequests as $req): ?>
                                <tr>
                                    <td><strong>#<?= $req->id ?></strong></td>
                                    <td>
                                        <div class="fw-medium"><?= e($req->plate_number) ?></div>
                                        <small class="text-muted"><?= e($req->make . ' ' . $req->model) ?></small>
                                    </td>
                                    <td>
                                        <div class="fw-medium"><?= e($req->title) ?></div>
                                        <small class="text-muted"><?= truncate($req->description, 50) ?></small>
                                    </td>
                                    <td>
                                        <?php $typeInfo = MAINTENANCE_TYPES[$req->type] ?? ['label' => ucfirst($req->type), 'icon' => 'bi-wrench']; ?>
                                        <span class="badge bg-light text-dark">
                                            <i class="bi <?= $typeInfo['icon'] ?> me-1"></i>
                                            <?= $typeInfo['label'] ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php $priorityInfo = MAINTENANCE_PRIORITIES[$req->priority] ?? ['label' => ucfirst($req->priority), 'color' => 'secondary']; ?>
                                        <span class="badge bg-<?= $priorityInfo['color'] ?>">
                                            <?= $priorityInfo['label'] ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($req->scheduled_date): ?>
                                            <?php if ($req->status === MAINTENANCE_STATUS_PENDING && strtotime($req->scheduled_date) < time()): ?>
                                                <span class="text-danger">
                                                    <i class="bi bi-exclamation-circle me-1"></i>
                                                    <?= formatDate($req->scheduled_date) ?>
                                                </span>
                                            <?php else: ?>
                                                <?= formatDate($req->scheduled_date) ?>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php $statusInfo = MAINTENANCE_STATUSES[$req->status] ?? ['label' => ucfirst(str_replace('_', ' ', $req->status)), 'color' => 'secondary']; ?>
                                        <span class="badge bg-<?= $statusInfo['color'] ?>">
                                            <?= $statusInfo['label'] ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="btn-group">
                                            <a href="<?= APP_URL ?>/?page=maintenance&action=view&id=<?= $req->id ?>"
                                               class="btn btn-sm btn-outline-primary">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <?php if ($req->status !== MAINTENANCE_STATUS_COMPLETED && $req->status !== MAINTENANCE_STATUS_CANCELLED): ?>
                                            <a href="<?= APP_URL ?>/?page=maintenance&action=edit&id=<?= $req->id ?>"
                                               class="btn btn-sm btn-outline-secondary">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <?php endif; ?>
                                            <?php if (isAdmin()): ?>
                                            <form method="POST" action="<?= APP_URL ?>/?page=maintenance&action=delete" class="d-inline"
                                                  onsubmit="return confirm('Are you sure you want to delete this maintenance request?')">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="id" value="<?= $req->id ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
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
