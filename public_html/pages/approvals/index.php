<?php
/**
 * LOKA - Approvals Queue Page
 */

requireRole(ROLE_APPROVER);

$pageTitle = 'Approval Queue';
$tab = get('tab', 'pending');
$recordsPerPage = 10;

// Get current page for pagination
$pendingPage = max(1, getInt('p_pending', 1));
$processedPage = max(1, getInt('p_processed', 1));

// Determine which requests to show based on role
// Only the specifically assigned approver/motorpool head can see and process requests
if (isAdmin()) {
    $pendingRequestsCount = db()->fetchColumn(
        "SELECT COUNT(*)
         FROM requests r
         WHERE r.status IN ('pending', 'pending_motorpool', 'revision') AND r.deleted_at IS NULL"
    );
    $pendingOffset = ($pendingPage - 1) * $recordsPerPage;
    $pendingRequests = db()->fetchAll(
        "SELECT r.*, u.name as requester_name, d.name as department_name,
                appr.name as assigned_approver_name, mph.name as assigned_motorpool_name,
                v.plate_number as vehicle_plate, v.make as vehicle_make, v.model as vehicle_model,
                (SELECT status FROM approvals WHERE request_id = r.id AND approval_type = 'department' ORDER BY created_at DESC LIMIT 1) as dept_status,
                (SELECT status FROM approvals WHERE request_id = r.id AND approval_type = 'motorpool' ORDER BY created_at DESC LIMIT 1) as motorpool_status
         FROM requests r
         JOIN users u ON r.user_id = u.id
         JOIN departments d ON r.department_id = d.id
         LEFT JOIN users appr ON r.approver_id = appr.id
         LEFT JOIN users mph ON r.motorpool_head_id = mph.id
         LEFT JOIN vehicles v ON r.vehicle_id = v.id AND v.deleted_at IS NULL
         WHERE r.status IN ('pending', 'pending_motorpool', 'revision') AND r.deleted_at IS NULL
         ORDER BY r.viewed_at IS NULL DESC, r.created_at DESC
         LIMIT ? OFFSET ?",
        [$recordsPerPage, $pendingOffset]
    );
    $queueType = 'All';
} elseif (isMotorpool()) {
    // Motorpool sees: pending_motorpool + revision requests assigned to them
    $pendingRequestsCount = db()->fetchColumn(
        "SELECT COUNT(*)
         FROM requests r
         WHERE (r.status = 'pending_motorpool' OR r.status = 'revision')
         AND r.motorpool_head_id = ?
         AND r.deleted_at IS NULL",
        [userId()]
    );
    $pendingOffset = ($pendingPage - 1) * $recordsPerPage;
    $pendingRequests = db()->fetchAll(
        "SELECT r.*, u.name as requester_name, d.name as department_name,
                appr.name as assigned_approver_name,
                v.plate_number as vehicle_plate, v.make as vehicle_make, v.model as vehicle_model,
                (SELECT status FROM approvals WHERE request_id = r.id AND approval_type = 'department' ORDER BY created_at DESC LIMIT 1) as dept_status
         FROM requests r
         JOIN users u ON r.user_id = u.id
         JOIN departments d ON r.department_id = d.id
         LEFT JOIN users appr ON r.approver_id = appr.id
         LEFT JOIN vehicles v ON r.vehicle_id = v.id AND v.deleted_at IS NULL
         WHERE (r.status = 'pending_motorpool' OR r.status = 'revision')
         AND r.motorpool_head_id = ?
         AND r.deleted_at IS NULL
         ORDER BY r.viewed_at IS NULL DESC, r.created_at DESC
         LIMIT ? OFFSET ?",
        [userId(), $recordsPerPage, $pendingOffset]
    );
    $queueType = 'Motorpool';
} else {
    // Approvers see: pending + revision requests assigned to them
    $pendingRequestsCount = db()->fetchColumn(
        "SELECT COUNT(*)
         FROM requests r
         WHERE (r.status = 'pending' OR r.status = 'revision')
         AND r.approver_id = ?
         AND r.deleted_at IS NULL",
        [userId()]
    );
    $pendingOffset = ($pendingPage - 1) * $recordsPerPage;
    $pendingRequests = db()->fetchAll(
        "SELECT r.*, u.name as requester_name, d.name as department_name,
                mph.name as assigned_motorpool_name,
                v.plate_number as vehicle_plate, v.make as vehicle_make, v.model as vehicle_model,
                (SELECT status FROM approvals WHERE request_id = r.id AND approval_type = 'motorpool' ORDER BY created_at DESC LIMIT 1) as motorpool_status
         FROM requests r
         JOIN users u ON r.user_id = u.id
         JOIN departments d ON r.department_id = d.id
         LEFT JOIN users mph ON r.motorpool_head_id = mph.id
         LEFT JOIN vehicles v ON r.vehicle_id = v.id AND v.deleted_at IS NULL
         WHERE (r.status = 'pending' OR r.status = 'revision')
         AND r.approver_id = ?
         AND r.deleted_at IS NULL
         ORDER BY r.viewed_at IS NULL DESC, r.created_at DESC
         LIMIT ? OFFSET ?",
        [userId(), $recordsPerPage, $pendingOffset]
    );
    $queueType = 'Assigned';
}

// Get total count for processed requests
$processedRequestsCount = db()->fetchColumn(
    "SELECT COUNT(*)
     FROM approvals a
     JOIN requests r ON a.request_id = r.id
     WHERE a.approver_id = ? AND r.deleted_at IS NULL",
    [userId()]
);

// Get processed requests with pagination
$processedOffset = ($processedPage - 1) * $recordsPerPage;
$processedRequests = db()->fetchAll(
    "SELECT r.*, u.name as requester_name, d.name as department_name,
            a.status as my_action, a.approval_type, a.created_at as action_date
     FROM approvals a
     JOIN requests r ON a.request_id = r.id
     JOIN users u ON r.user_id = u.id
     JOIN departments d ON r.department_id = d.id
     WHERE a.approver_id = ? AND r.deleted_at IS NULL
     ORDER BY a.created_at DESC
     LIMIT ? OFFSET ?",
    [userId(), $recordsPerPage, $processedOffset]
);

// Calculate pagination
$pendingTotalPages = ceil($pendingRequestsCount / $recordsPerPage);
$processedTotalPages = ceil($processedRequestsCount / $recordsPerPage);

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <!-- Page Header with Pending Count Badge -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><?= $queueType ?> Approval Queue</h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
                    <li class="breadcrumb-item active">Approvals</li>
                </ol>
            </nav>
        </div>
        <div>
            <?php
            if ($pendingRequestsCount > 0):
            ?>
            <span class="badge bg-warning fs-6">
                <i class="bi bi-hourglass-split me-1"></i><?= $pendingRequestsCount ?> Pending Action<?= $pendingRequestsCount > 1 ? 's' : '' ?>
            </span>
            <?php else: ?>
            <span class="badge bg-success fs-6">
                <i class="bi bi-check-circle me-1"></i>All Caught Up
            </span>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Tabs -->
    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link <?= $tab === 'pending' ? 'active' : '' ?>" href="<?= APP_URL ?>/?page=approvals&tab=pending&p_pending=1">
                <i class="bi bi-hourglass-split me-1"></i>Pending
                <?php if ($pendingRequestsCount > 0): ?>
                <span class="badge bg-warning ms-1"><?= $pendingRequestsCount ?></span>
                <?php endif; ?>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $tab === 'processed' ? 'active' : '' ?>" href="<?= APP_URL ?>/?page=approvals&tab=processed&p_processed=1">
                <i class="bi bi-check-circle me-1"></i>Processed
                <?php if ($processedRequestsCount > 0): ?>
                <span class="badge bg-secondary ms-1"><?= $processedRequestsCount ?></span>
                <?php endif; ?>
            </a>
        </li>
    </ul>
    
    <?php if ($tab === 'pending'): ?>
    <!-- Pending Approvals -->
    <div class="card table-card">
        <div class="card-body">
            <?php if (empty($pendingRequests)): ?>
            <div class="empty-state">
                <i class="bi bi-inbox"></i>
                <h5>No pending approvals</h5>
                <p class="text-muted">All caught up! No requests awaiting your approval.</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Requester</th>
                            <th>Department</th>
                            <th>Purpose</th>
                            <th>Date/Time</th>
                            <th>Vehicle</th>
                            <th>Stage Status</th>
                            <th>Submitted</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendingRequests as $request): ?>
                        <tr class="<?= !$request->viewed_at ? 'table-warning' : '' ?>">
                            <td>
                                <strong>#<?= $request->id ?></strong>
                                <?php if (!$request->viewed_at): ?>
                                <span class="badge bg-danger ms-1">NEW</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($request->requester_name) ?></td>
                            <td><span class="badge bg-light text-dark"><?= e($request->department_name) ?></span></td>
                            <td><?= truncate($request->purpose, 30) ?></td>
                            <td>
                                <div><?= formatDateTime($request->start_datetime) ?></div>
                                <small class="text-muted">to <?= formatDateTime($request->end_datetime) ?></small>
                            </td>
                            <td>
                                <?php if ($request->vehicle_plate): ?>
                                    <div class="fw-medium"><?= e($request->vehicle_plate) ?></div>
                                    <small class="text-muted"><?= e($request->vehicle_make . ' ' . $request->vehicle_model) ?></small>
                                <?php else: ?>
                                    <span class="text-muted">Not assigned</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($request->status === 'revision'): ?>
                                <div class="d-flex align-items-center gap-1">
                                    <span class="badge bg-warning text-dark"><i class="bi bi-pencil-square me-1"></i>Revision</span>
                                    <span class="text-warning">Needs Update</span>
                                </div>
                                <?php elseif ($request->status === 'pending'): ?>
                                <div class="d-flex align-items-center gap-1">
                                    <span class="badge bg-info" title="Department Approval">Dept</span>
                                    <span class="text-warning" title="Waiting for your action">
                                        <i class="bi bi-clock-history"></i> Pending
                                    </span>
                                </div>
                                <?php else: ?>
                                <div class="d-flex flex-column gap-1">
                                    <div class="d-flex align-items-center gap-1">
                                        <span class="badge bg-light text-dark" title="Department Approval">Dept</span>
                                        <?php if ($request->dept_status === 'approved'): ?>
                                        <span class="text-success" title="Department approved"><i class="bi bi-check-circle"></i> Done</span>
                                        <?php elseif ($request->dept_status === 'rejected'): ?>
                                        <span class="text-danger" title="Department rejected"><i class="bi bi-x-circle"></i> Rejected</span>
                                        <?php elseif ($request->dept_status === 'revision'): ?>
                                        <span class="text-warning" title="Under revision"><i class="bi bi-arrow-repeat"></i> Revision</span>
                                        <?php else: ?>
                                        <span class="text-secondary"><i class="bi bi-dash-circle"></i> -</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="d-flex align-items-center gap-1">
                                        <span class="badge bg-primary" title="Motorpool Approval">MP</span>
                                        <span class="text-warning" title="Waiting for your action">
                                            <i class="bi bi-clock-history"></i> Pending
                                        </span>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td><?= formatDateTime($request->created_at) ?></td>
                            <td>
                                <a href="<?= APP_URL ?>/?page=approvals&action=view&id=<?= $request->id ?>" 
                                   class="btn btn-sm btn-primary">
                                    <i class="bi bi-eye me-1"></i>Review
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if ($pendingTotalPages > 1): ?>
            <nav aria-label="Pending approvals pagination" class="mt-4">
                <ul class="pagination justify-content-center mb-0">
                    <li class="page-item <?= $pendingPage <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= APP_URL ?>/?page=approvals&tab=pending&p_pending=<?= $pendingPage - 1 ?>">
                            <i class="bi bi-chevron-left"></i> Previous
                        </a>
                    </li>
                    <?php for ($i = 1; $i <= $pendingTotalPages; $i++): ?>
                    <li class="page-item <?= $i === $pendingPage ? 'active' : '' ?>">
                        <a class="page-link" href="<?= APP_URL ?>/?page=approvals&tab=pending&p_pending=<?= $i ?>"><?= $i ?></a>
                    </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $pendingPage >= $pendingTotalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= APP_URL ?>/?page=approvals&tab=pending&p_pending=<?= $pendingPage + 1 ?>">
                            Next <i class="bi bi-chevron-right"></i>
                        </a>
                    </li>
                </ul>
            </nav>
            <div class="text-center text-muted small mt-2">
                Showing <?= (($pendingPage - 1) * $recordsPerPage) + 1 ?> to <?= min($pendingPage * $recordsPerPage, $pendingRequestsCount) ?> of <?= $pendingRequestsCount ?> pending requests
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <?php else: ?>
    <!-- Processed Approvals -->
    <div class="card table-card">
        <div class="card-body">
            <?php if (empty($processedRequests)): ?>
            <div class="empty-state">
                <i class="bi bi-clipboard-check"></i>
                <h5>No processed requests</h5>
                <p class="text-muted">You haven't processed any requests yet.</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Requester</th>
                            <th>Purpose</th>
                            <th>Approval Level</th>
                            <th>Your Action</th>
                            <th>Action Date</th>
                            <th>Final Status</th>
                            <th>View</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($processedRequests as $request): ?>
                        <tr>
                            <td><strong>#<?= $request->id ?></strong></td>
                            <td><?= e($request->requester_name) ?></td>
                            <td><?= truncate($request->purpose, 25) ?></td>
                            <td>
                                <span class="badge bg-<?= $request->approval_type === 'motorpool' ? 'primary' : 'info' ?>">
                                    <?= ucfirst($request->approval_type) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-<?= $request->my_action === 'approved' ? 'success' : 'danger' ?>">
                                    <?= ucfirst($request->my_action) ?>
                                </span>
                            </td>
                            <td><?= formatDateTime($request->action_date) ?></td>
                            <td><?= requestStatusBadge($request->status) ?></td>
                            <td>
                                <a href="<?= APP_URL ?>/?page=requests&action=view&id=<?= $request->id ?>" 
                                   class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-eye"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if ($processedTotalPages > 1): ?>
            <nav aria-label="Processed approvals pagination" class="mt-4">
                <ul class="pagination justify-content-center mb-0">
                    <li class="page-item <?= $processedPage <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= APP_URL ?>/?page=approvals&tab=processed&p_processed=<?= $processedPage - 1 ?>">
                            <i class="bi bi-chevron-left"></i> Previous
                        </a>
                    </li>
                    <?php for ($i = 1; $i <= $processedTotalPages; $i++): ?>
                    <li class="page-item <?= $i === $processedPage ? 'active' : '' ?>">
                        <a class="page-link" href="<?= APP_URL ?>/?page=approvals&tab=processed&p_processed=<?= $i ?>"><?= $i ?></a>
                    </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $processedPage >= $processedTotalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= APP_URL ?>/?page=approvals&tab=processed&p_processed=<?= $processedPage + 1 ?>">
                            Next <i class="bi bi-chevron-right"></i>
                        </a>
                    </li>
                </ul>
            </nav>
            <div class="text-center text-muted small mt-2">
                Showing <?= (($processedPage - 1) * $recordsPerPage) + 1 ?> to <?= min($processedPage * $recordsPerPage, $processedRequestsCount) ?> of <?= $processedRequestsCount ?> processed requests
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
