<?php
/**
 * LOKA - View Request Page
 */

$requestId = (int) get('id');

// Get request with all related data
$request = db()->fetch(
    "SELECT r.*,
            u.name as requester_name, u.email as requester_email, u.phone as requester_phone,
            d.name as department_name,
            v.plate_number, v.make, v.model as vehicle_model, v.color as vehicle_color,
            vt.name as vehicle_type,
            dr.license_number as driver_license,
            (SELECT name FROM users WHERE id = dr.user_id) as driver_name,
            (SELECT phone FROM users WHERE id = dr.user_id) as driver_phone,
            appr.name as approver_name,
            mph.name as motorpool_head_name,
            dg.name as dispatch_guard_name,
            ag.name as arrival_guard_name
     FROM requests r
     JOIN users u ON r.user_id = u.id
     JOIN departments d ON r.department_id = d.id
     LEFT JOIN vehicles v ON r.vehicle_id = v.id AND v.deleted_at IS NULL
     LEFT JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
     LEFT JOIN drivers dr ON r.driver_id = dr.id AND dr.deleted_at IS NULL
     LEFT JOIN users appr ON r.approver_id = appr.id
     LEFT JOIN users mph ON r.motorpool_head_id = mph.id
     LEFT JOIN users dg ON r.dispatch_guard_id = dg.id
     LEFT JOIN users ag ON r.arrival_guard_id = ag.id
     WHERE r.id = ? AND r.deleted_at IS NULL",
    [$requestId]
);

if (!$request) {
    redirectWith('/?page=requests', 'danger', 'Request not found.');
}

// Check access - owner, approver+, or assigned/requested driver
$isDriverOnTrip = isAssignedOrRequestedDriver($request);
if ($request->user_id !== userId() && !isApprover() && !$isDriverOnTrip) {
    redirectWith('/?page=requests', 'danger', 'You do not have permission to view this request.');
}

// Mark notifications as read when requester or assigned driver views the request
if ($request->user_id === userId() || $isDriverOnTrip) {
    db()->update('notifications', 
        ['is_read' => 1], 
        'user_id = ? AND link LIKE ? AND is_read = 0',
        [userId(), '%page=requests%action=view%id=' . $requestId . '%']
    );
}

// Drivers (non-approver) may print only completed trips; others keep full print access
$canPrintForm = isApprover()
    || $request->user_id === userId()
    || ($isDriverOnTrip && $request->status === STATUS_COMPLETED);

// Get approval history
$approvals = db()->fetchAll(
    "SELECT a.*, u.name as approver_name
     FROM approvals a
     JOIN users u ON a.approver_id = u.id
     WHERE a.request_id = ?
     ORDER BY a.created_at ASC",
    [$requestId]
);

// Get workflow status
$workflow = db()->fetch(
    "SELECT * FROM approval_workflow WHERE request_id = ?",
    [$requestId]
);

// Get passengers (users and guests)
$passengers = db()->fetchAll(
    "SELECT rp.user_id, u.id as user_table_id, u.name, u.email, d.name as department_name, rp.guest_name
     FROM request_passengers rp
     LEFT JOIN users u ON rp.user_id = u.id
     LEFT JOIN departments d ON u.department_id = d.id
     WHERE rp.request_id = ?
     ORDER BY u.name, rp.guest_name",
    [$requestId]
);

// Get assignment history
$assignmentHistory = db()->fetchAll(
    "SELECT ah.*, 
            u.name as assigned_by_name,
            v.plate_number, v.make, v.model as vehicle_model,
            pv.plate_number as prev_plate_number, pv.make as prev_make, pv.model as prev_vehicle_model,
            d_user.name as driver_name,
            pd_user.name as prev_driver_name
     FROM assignment_history ah
     JOIN users u ON ah.assigned_by = u.id
     LEFT JOIN vehicles v ON ah.vehicle_id = v.id
     LEFT JOIN vehicles pv ON ah.previous_vehicle_id = pv.id
     LEFT JOIN drivers d ON ah.driver_id = d.id
     LEFT JOIN users d_user ON d.user_id = d_user.id
     LEFT JOIN drivers pd ON ah.previous_driver_id = pd.id
     LEFT JOIN users pd_user ON pd.user_id = pd_user.id
     WHERE ah.request_id = ?
     ORDER BY ah.created_at ASC",
    [$requestId]
);

$pageTitle = 'Request #' . $requestId;

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div>
            <h4 class="mb-1">Request #<?= $requestId ?></h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
                    <?php if ($isDriverOnTrip && $request->user_id !== userId()): ?>
                    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/?page=my-trips">My Trips</a></li>
                    <?php else: ?>
                    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/?page=requests">Requests</a></li>
                    <?php endif; ?>
                    <li class="breadcrumb-item active">View</li>
                </ol>
            </nav>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <?php if ($canPrintForm): ?>
            <a href="<?= APP_URL ?>/?page=requests&action=print&id=<?= $requestId ?>"
                class="btn btn-outline-secondary me-2" target="_blank">
                <i class="bi bi-printer me-1"></i>Print Form
            </a>
            <?php endif; ?>

            <?php if ($request->user_id === userId() && in_array($request->status, [STATUS_PENDING, STATUS_DRAFT])): ?>
                <a href="<?= APP_URL ?>/?page=requests&action=edit&id=<?= $requestId ?>"
                    class="btn btn-outline-primary me-2">
                    <i class="bi bi-pencil me-1"></i>Edit
                </a>
            <?php endif; ?>

            <?php if ($request->user_id === userId() && !in_array($request->status, [STATUS_COMPLETED, STATUS_CANCELLED])): ?>
                <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cancelRequestModal">
                    <i class="bi bi-x-circle me-1"></i>Cancel Request
                </button>
            <?php endif; ?>

            <?php if (isMotorpool() && $request->status === STATUS_APPROVED): ?>
                <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#overrideModal">
                    <i class="bi bi-pencil-square me-1"></i>Override Vehicle/Driver
                </button>
                <?php $guardReturnRequired = !$request->actual_arrival_datetime && requireReturnConfirmation(); ?>
                <?php if ($guardReturnRequired): ?>
                <button type="button" class="btn btn-secondary" disabled
                        title="The guard must record the vehicle's return before this trip can be completed">
                    <i class="bi bi-check-circle me-1"></i>Complete Trip
                </button>
                <?php else: ?>
                <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#completeModal">
                    <i class="bi bi-check-circle me-1"></i>Complete Trip
                </button>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (isAdmin() && in_array($request->status, [STATUS_PENDING_MOTORPOOL, STATUS_APPROVED, STATUS_COMPLETED, STATUS_REVISION, STATUS_REJECTED])): ?>
                <a href="<?= APP_URL ?>/?page=rollback&action=process&id=<?= $requestId ?>"
                   class="btn btn-outline-warning" title="Roll this request back to an earlier workflow phase">
                    <i class="bi bi-arrow-counterclockwise me-1"></i>Rollback
                </a>
            <?php endif; ?>

            <?php
            // Manage Passengers - motorpool head (or admin / all father) can add/remove
            // passengers while the request is approved or still on routing (before dispatch)
            $canManagePassengers = false;
            if ($request->actual_dispatch_datetime === null
                && in_array($request->status, [STATUS_PENDING, STATUS_PENDING_MOTORPOOL, STATUS_APPROVED], true)) {

                if (isAdmin() || isAllFather()) {
                    $canManagePassengers = true;
                } elseif (isMotorpool()) {
                    if ($request->motorpool_head_id && $request->motorpool_head_id == userId()) {
                        $canManagePassengers = true;
                    } elseif (!$request->motorpool_head_id) {
                        $canManagePassengers = true;
                    }
                }
            }
            ?>
            <?php if ($canManagePassengers): ?>
                <a href="<?= APP_URL ?>/?page=requests&action=manage-passengers&id=<?= $requestId ?>"
                   class="btn btn-outline-info me-2">
                    <i class="bi bi-people me-1"></i>Manage Passengers
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-4">
        <!-- Main Details -->
        <div class="col-lg-8">
            <!-- Status Card -->
            <div class="card mb-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <span class="text-muted">Status</span>
                            <h3 class="mb-0 mt-1"><?= requestStatusBadge($request->status) ?></h3>
                        </div>
                        <div class="text-end">
                            <span class="text-muted">Created</span>
                            <div class="fw-medium"><?= formatDateTime($request->created_at) ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Cancellation Details (shown only for admins/approvers/motorpool when cancelled) -->
            <?php if ($request->status === STATUS_CANCELLED && (isAdmin() || isApprover() || isMotorpool())): ?>
                <?php
                // Get cancellation details from audit log
                $cancellationAudit = db()->fetch(
                    "SELECT * FROM audit_logs
                     WHERE entity_type = 'request' AND entity_id = ? AND action = 'request_cancelled'
                     ORDER BY created_at DESC LIMIT 1",
                    [$requestId]
                );
                ?>
                <div class="card mb-4 border-danger">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0"><i class="bi bi-x-circle me-2"></i>Cancellation Details</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($cancellationAudit): ?>
                            <?php
                            $auditData = json_decode($cancellationAudit->new_values, true);
                            $cancelledByUserId = $auditData['cancelled_by'] ?? null;
                            $cancellationReason = $auditData['reason'] ?? 'No reason provided';
                            $isAdminOverride = $auditData['is_admin_override'] ?? false;

                            // Get canceller details
                            $canceller = null;
                            if ($cancelledByUserId) {
                                $canceller = db()->fetch("SELECT name, email FROM users WHERE id = ?", [$cancelledByUserId]);
                            }
                            ?>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="text-muted small">Cancelled By</label>
                                    <div class="fw-bold">
                                        <?php if ($canceller): ?>
                                            <?= e($canceller->name) ?>
                                            <?php if ($isAdminOverride): ?>
                                            <span class="badge bg-warning text-dark ms-2">Admin Override</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            System
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="text-muted small">Cancelled On</label>
                                    <div class="fw-bold"><?= formatDateTime($cancellationAudit->created_at) ?></div>
                                </div>
                                <div class="col-12">
                                    <label class="text-muted small">Reason for Cancellation</label>
                                    <div class="alert alert-warning mb-0">
                                        <i class="bi bi-chat-left-quote me-2"></i>
                                        <?= nl2br(e($cancellationReason)) ?>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <p class="text-muted mb-0">Cancellation details not found in audit log.</p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Trip Details -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-geo-alt me-2"></i>Trip Details</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="text-muted small">Start Date/Time</label>
                            <div class="fw-medium"><?= formatDateTime($request->start_datetime) ?></div>
                        </div>
                        <div class="col-md-6">
                            <label class="text-muted small">End Date/Time</label>
                            <div class="fw-medium"><?= formatDateTime($request->end_datetime) ?></div>
                        </div>
                        <div class="col-12">
                            <label class="text-muted small">Purpose</label>
                            <div class="fw-medium"><?= nl2br(e($request->purpose)) ?></div>
                        </div>
                        <div class="col-12">
                            <label class="text-muted small">Destination</label>
                            <div class="fw-medium"><?= e($request->destination) ?></div>
                        </div>
                        <div class="col-12">
                            <?php 
                            // Calculate actual passenger count: requester (1) + passengers in table
                            $actualPassengerCount = count($passengers) + 1;
                            ?>
                            <label class="text-muted small">Passengers (<?= $actualPassengerCount ?>)</label>
                            <div class="mt-1">
                                <span class="badge bg-primary me-1 mb-1">
                                    <i class="bi bi-person-fill me-1"></i><?= e($request->requester_name) ?> (Requester)
                                </span>
                                <?php foreach ($passengers as $passenger): ?>
                                    <span class="badge bg-<?= $passenger->user_id ? 'secondary' : 'info' ?> me-1 mb-1">
                                        <i class="bi bi-person<?= $passenger->user_id ? '' : '-plus' ?> me-1"></i>
                                        <?= e($passenger->name ?: $passenger->guest_name) ?>
                                        <?= $passenger->user_id ? '' : ' <small>(Guest)</small>' ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php if ($request->notes): ?>
                            <div class="col-12">
                                <label class="text-muted small">Notes</label>
                                <div><?= nl2br(e($request->notes)) ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Mileage Information -->
            <?php if ($request->mileage_start || $request->mileage_end || $request->mileage_actual): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-speedometer2 me-2"></i>Mileage Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-4">
                            <?php if ($request->mileage_start): ?>
                                <div class="col-md-4">
                                    <h6 class="text-muted mb-2">Starting Mileage</h6>
                                    <div class="fs-4 fw-bold text-primary"><?= number_format($request->mileage_start) ?> km</div>
                                    <small class="text-muted">Recorded at dispatch</small>
                                </div>
                            <?php endif; ?>
                            <?php if ($request->mileage_end): ?>
                                <div class="col-md-4">
                                    <h6 class="text-muted mb-2">Ending Mileage</h6>
                                    <div class="fs-4 fw-bold text-success"><?= number_format($request->mileage_end) ?> km</div>
                                    <small class="text-muted">Recorded at arrival</small>
                                </div>
                            <?php endif; ?>
                            <?php if ($request->mileage_actual): ?>
                                <div class="col-md-4">
                                    <h6 class="text-muted mb-2">Actual Distance</h6>
                                    <div class="fs-4 fw-bold text-info"><?= number_format($request->mileage_actual) ?> km</div>
                                    <small class="text-muted">Total trip distance</small>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php require __DIR__ . '/partials/vehicle_observations.php'; ?>

            <!-- Travel Documents -->
            <?php if ($request->has_travel_order || $request->has_official_business_slip): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-file-earmark-text me-2"></i>Travel Documents</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <?php if ($request->has_travel_order): ?>
                                <div class="col-md-6">
                                    <div class="d-flex align-items-center p-3 bg-success bg-opacity-10 rounded">
                                        <i class="bi bi-check-circle-fill text-success fs-4 me-3"></i>
                                        <div>
                                            <div class="fw-bold">Travel Order</div>
                                            <div class="text-muted">Number: <strong><?= e($request->travel_order_number) ?></strong></div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <?php if ($request->has_official_business_slip): ?>
                                <div class="col-md-6">
                                    <div class="d-flex align-items-center p-3 bg-primary bg-opacity-10 rounded">
                                        <i class="bi bi-check-circle-fill text-primary fs-4 me-3"></i>
                                        <div>
                                            <div class="fw-bold">Official Business Slip</div>
                                            <div class="text-muted">Number: <strong><?= e($request->ob_slip_number) ?></strong></div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Uploaded Travel Order / OB Slip File -->
            <?php if (!empty($request->travel_order_file)): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-paperclip me-2"></i>Uploaded Travel Order / OB Slip</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-flex align-items-center justify-content-between p-3 bg-light rounded">
                            <div class="me-3">
                                <div class="fw-bold"><i class="bi bi-file-earmark-text me-1"></i>
                                    <?= e($request->travel_order_original_name ?? basename($request->travel_order_file)) ?>
                                </div>
                                <small class="text-muted">
                                    Uploaded <?= $request->travel_order_uploaded_at ? e(formatDateTime($request->travel_order_uploaded_at)) : '—' ?>
                                </small>
                            </div>
                            <?= fileDownloadLink($request->travel_order_file, 'Download') ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Vehicle & Driver Assignment -->
            <?php if ($request->vehicle_id || $request->driver_id): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-car-front me-2"></i>Assignment</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-4">
                            <?php if ($request->vehicle_id): ?>
                                <div class="col-md-6">
                                    <h6 class="text-muted mb-2">Vehicle</h6>
                                    <div class="d-flex align-items-center">
                                        <div class="bg-primary bg-opacity-10 rounded p-3 me-3">
                                            <i class="bi bi-car-front text-primary fs-4"></i>
                                        </div>
                                        <div>
                                            <div class="fw-bold"><?= e($request->plate_number) ?></div>
                                            <div class="text-muted"><?= e($request->make . ' ' . $request->vehicle_model) ?>
                                            </div>
                                            <small class="text-muted"><?= e($request->vehicle_type) ?> •
                                                <?= e($request->vehicle_color) ?></small>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if ($request->driver_id): ?>
                                <div class="col-md-6">
                                    <h6 class="text-muted mb-2">Driver</h6>
                                    <div class="d-flex align-items-center">
                                        <div class="bg-success bg-opacity-10 rounded p-3 me-3">
                                            <i class="bi bi-person-badge text-success fs-4"></i>
                                        </div>
                                        <div>
                                            <div class="fw-bold"><?= e($request->driver_name) ?></div>
                                            <div class="text-muted"><?= e($request->driver_phone) ?></div>
                                            <small class="text-muted">License: <?= e($request->driver_license) ?></small>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Assignment History -->
            <?php if (!empty($assignmentHistory)): ?>
                <div class="card mb-4 border-warning">
                    <div class="card-header bg-warning bg-opacity-25">
                        <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Assignment History</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Date/Time</th>
                                        <th>Action</th>
                                        <th>Vehicle</th>
                                        <th>Driver</th>
                                        <th>By</th>
                                        <th>Reason</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($assignmentHistory as $i => $ah): ?>
                                        <tr>
                                            <td>
                                                <small><?= formatDateTime($ah->created_at) ?></small>
                                            </td>
                                            <td>
                                                <?php if ($ah->action === 'assigned'): ?>
                                                    <span class="badge bg-success">Assigned</span>
                                                <?php elseif ($ah->action === 'overridden'): ?>
                                                    <span class="badge bg-warning text-dark">Overridden</span>
                                                <?php elseif ($ah->action === 'released'): ?>
                                                    <span class="badge bg-secondary">Released</span>
                                                <?php elseif ($ah->action === 'completed'): ?>
                                                    <span class="badge bg-primary">Completed</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($ah->vehicle_id): ?>
                                                    <div>
                                                        <strong><?= e($ah->plate_number) ?></strong>
                                                        <br><small class="text-muted"><?= e($ah->make . ' ' . $ah->vehicle_model) ?></small>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                                <?php if ($ah->previous_vehicle_id && $ah->action === 'overridden'): ?>
                                                    <div class="mt-1">
                                                        <small class="text-danger">
                                                            <i class="bi bi-arrow-up"></i> Was: <?= e($ah->prev_plate_number) ?> <?= e($ah->prev_make ?? '') ?>
                                                        </small>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($ah->driver_id): ?>
                                                    <strong><?= e($ah->driver_name) ?></strong>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                                <?php if ($ah->previous_driver_id && $ah->action === 'overridden'): ?>
                                                    <div class="mt-1">
                                                        <small class="text-danger">
                                                            <i class="bi bi-arrow-up"></i> Was: <?= e($ah->prev_driver_name) ?>
                                                        </small>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <small><?= e($ah->assigned_by_name) ?></small>
                                            </td>
                                            <td>
                                                <?php if ($ah->reason): ?>
                                                    <small class="text-muted fst-italic"><?= e($ah->reason) ?></small>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Guard Tracking - Actual Dispatch/Arrival Times -->
            <?php if ($request->actual_dispatch_datetime || $request->actual_arrival_datetime): ?>
                <div class="card mb-4 border-success">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="bi bi-shield-check me-2"></i>Guard Tracking</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-4">
                            <?php if ($request->actual_dispatch_datetime): ?>
                                <div class="col-md-6">
                                    <h6 class="text-muted mb-2">
                                        <i class="bi bi-box-arrow-right text-success me-1"></i>Actual Dispatch
                                    </h6>
                                    <div class="d-flex align-items-center">
                                        <div class="bg-success bg-opacity-10 rounded p-3 me-3">
                                            <i class="bi bi-clock text-success fs-4"></i>
                                        </div>
                                        <div>
                                            <div class="fw-bold"><?= formatDateTime($request->actual_dispatch_datetime) ?></div>
                                            <?php if ($request->dispatch_guard_name): ?>
                                                <small class="text-muted">Recorded by: <?= e($request->dispatch_guard_name) ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if ($request->actual_arrival_datetime): ?>
                                <div class="col-md-6">
                                    <h6 class="text-muted mb-2">
                                        <i class="bi bi-box-arrow-in-left text-primary me-1"></i>Actual Arrival
                                    </h6>
                                    <div class="d-flex align-items-center">
                                        <div class="bg-primary bg-opacity-10 rounded p-3 me-3">
                                            <i class="bi bi-clock-history text-primary fs-4"></i>
                                        </div>
                                        <div>
                                            <div class="fw-bold"><?= formatDateTime($request->actual_arrival_datetime) ?></div>
                                            <?php if ($request->arrival_guard_name): ?>
                                                <small class="text-muted">Recorded by: <?= e($request->arrival_guard_name) ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($request->guard_notes): ?>
                            <div class="mt-3 pt-3 border-top">
                                <h6 class="text-muted mb-2"><i class="bi bi-sticky me-1"></i>Guard Notes</h6>
                                <div class="bg-light p-3 rounded">
                                    <?= nl2br(e($request->guard_notes)) ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Approval History -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Approval History</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($approvals)): ?>
                        <p class="text-muted mb-0">No approval actions yet.</p>
                    <?php else: ?>
                        <div class="timeline">
                            <?php foreach ($approvals as $approval): ?>
                                <div class="d-flex mb-3">
                                    <div class="me-3">
                                        <?php if ($approval->status === 'approved'): ?>
                                            <span class="badge bg-success rounded-circle p-2"><i class="bi bi-check-lg"></i></span>
                                        <?php elseif ($approval->status === 'rollback'): ?>
                                            <span class="badge bg-warning text-dark rounded-circle p-2"><i class="bi bi-arrow-counterclockwise"></i></span>
                                        <?php else: ?>
                                            <span class="badge bg-danger rounded-circle p-2"><i class="bi bi-x-lg"></i></span>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <div class="fw-medium">
                                            <?= e($approval->approver_name) ?>
                                            <?php if ($approval->status === 'rollback'): ?>
                                                <span class="text-warning">Rolled Back</span>
                                                <small class="text-muted">(to <?= ucfirst($approval->approval_type) ?> phase)</small>
                                            <?php else: ?>
                                                <span class="text-<?= $approval->status === 'approved' ? 'success' : 'danger' ?>">
                                                    <?= ucfirst($approval->status) ?>
                                                </span>
                                                (<?= ucfirst($approval->approval_type) ?> Level)
                                            <?php endif; ?>
                                        </div>
                                        <small class="text-muted"><?= formatDateTime($approval->created_at) ?></small>
                                        <?php if ($approval->comments): ?>
                                            <div class="mt-1 fst-italic">"<?= e($approval->comments) ?>"</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="col-lg-4">
            <!-- Requester Info -->
            <div class="card mb-4">
                <div class="card-header">
                    <h6 class="mb-0"><i class="bi bi-person me-2"></i>Requester</h6>
                </div>
                <div class="card-body">
                    <div class="fw-bold"><?= e($request->requester_name) ?></div>
                    <div class="text-muted small"><?= e($request->requester_email) ?></div>
                    <?php if ($request->requester_phone): ?>
                        <div class="text-muted small"><?= e($request->requester_phone) ?></div>
                    <?php endif; ?>
                    <div class="mt-2">
                        <span class="badge bg-light text-dark"><?= e($request->department_name) ?></span>
                    </div>
                </div>
            </div>

            <!-- Workflow Status -->
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0"><i class="bi bi-diagram-3 me-2"></i>Approval Workflow</h6>
                </div>
                <div class="card-body">
                    <div class="d-flex align-items-center mb-3">
                        <div class="me-3">
                            <?php if (in_array($request->status, [STATUS_PENDING])): ?>
                                <span class="badge bg-warning rounded-circle p-2"><i class="bi bi-hourglass"></i></span>
                            <?php elseif (in_array($request->status, [STATUS_REJECTED, STATUS_CANCELLED])): ?>
                                <span class="badge bg-danger rounded-circle p-2"><i class="bi bi-x"></i></span>
                            <?php else: ?>
                                <span class="badge bg-success rounded-circle p-2"><i class="bi bi-check"></i></span>
                            <?php endif; ?>
                        </div>
                        <div class="flex-grow-1">
                            <div class="fw-medium">Department Approval</div>
                            <?php if ($request->approver_name): ?>
                                <small class="text-primary d-block"><i
                                        class="bi bi-person-check me-1"></i><?= e($request->approver_name) ?></small>
                            <?php endif; ?>
                            <small class="text-muted">
                                <?php
                                if ($request->status === STATUS_PENDING)
                                    echo 'Pending';
                                elseif ($request->status === STATUS_REJECTED)
                                    echo 'Rejected';
                                else
                                    echo 'Approved';
                                ?>
                            </small>
                        </div>
                    </div>

                    <div class="d-flex align-items-center">
                        <div class="me-3">
                            <?php if (in_array($request->status, [STATUS_PENDING_MOTORPOOL])): ?>
                                <span class="badge bg-warning rounded-circle p-2"><i class="bi bi-hourglass"></i></span>
                            <?php elseif (in_array($request->status, [STATUS_APPROVED, STATUS_COMPLETED])): ?>
                                <span class="badge bg-success rounded-circle p-2"><i class="bi bi-check"></i></span>
                            <?php elseif (in_array($request->status, [STATUS_REJECTED])): ?>
                                <span class="badge bg-danger rounded-circle p-2"><i class="bi bi-x"></i></span>
                            <?php else: ?>
                                <span class="badge bg-secondary rounded-circle p-2"><i class="bi bi-dash"></i></span>
                            <?php endif; ?>
                        </div>
                        <div class="flex-grow-1">
                            <div class="fw-medium">Motorpool Approval</div>
                            <?php if ($request->motorpool_head_name): ?>
                                <small class="text-primary d-block"><i
                                        class="bi bi-person-check me-1"></i><?= e($request->motorpool_head_name) ?></small>
                            <?php endif; ?>
                            <small class="text-muted">
                                <?php
                                if ($request->status === STATUS_PENDING_MOTORPOOL)
                                    echo 'Pending';
                                elseif (in_array($request->status, [STATUS_APPROVED, STATUS_COMPLETED]))
                                    echo 'Approved';
                                elseif ($request->status === STATUS_REJECTED)
                                    echo 'Rejected';
                                else
                                    echo 'Waiting';
                                ?>
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (isMotorpool() && $request->status === STATUS_APPROVED): ?>
    <!-- Override Vehicle/Driver Modal -->
    <div class="modal fade" id="overrideModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" action="<?= APP_URL ?>/?page=requests&action=override">
                    <?= csrfField() ?>
                    <input type="hidden" name="request_id" value="<?= $requestId ?>">
                    
                    <div class="modal-header bg-warning">
                        <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Override Vehicle/Driver Assignment</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            <strong>Warning:</strong> Overriding will reassign the vehicle and/or driver for this approved trip. 
                            This may create conflicts with other scheduled trips. Use with caution.
                        </div>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Current Vehicle</label>
                                <div class="fw-bold text-muted">
                                    <?= $request->plate_number ? e($request->plate_number . ' - ' . $request->make . ' ' . $request->vehicle_model) : 'Not assigned' ?>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">New Vehicle</label>
                                <select class="form-select" name="vehicle_id" required>
                                    <option value="">Select vehicle...</option>
                                    <?php 
                                    $availableVehicles = db()->fetchAll(
                                        "SELECT v.*, vt.name as type_name 
                                         FROM vehicles v 
                                         JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
                                         WHERE v.deleted_at IS NULL 
                                         ORDER BY v.plate_number"
                                    );
                                    foreach ($availableVehicles as $v): 
                                    ?>
                                    <option value="<?= $v->id ?>" <?= $request->vehicle_id == $v->id ? 'selected' : '' ?>>
                                        <?= e($v->plate_number) ?> - <?= e($v->make . ' ' . $v->model) ?>
                                        (<?= e($v->type_name) ?>) - <?= ucfirst($v->status) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Current Driver</label>
                                <div class="fw-bold text-muted">
                                    <?= $request->driver_name ? e($request->driver_name) : 'Not assigned' ?>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">New Driver</label>
                                <select class="form-select" name="driver_id" required>
                                    <option value="">Select driver...</option>
                                    <?php 
                                    $availableDrivers = db()->fetchAll(
                                        "SELECT d.*, u.name, u.phone 
                                         FROM drivers d 
                                         JOIN users u ON d.user_id = u.id 
                                         WHERE d.deleted_at IS NULL AND u.status = 'active'
                                         ORDER BY u.name"
                                    );
                                    foreach ($availableDrivers as $d): 
                                    ?>
                                    <option value="<?= $d->id ?>" <?= $request->driver_id == $d->id ? 'selected' : '' ?>>
                                        <?= e($d->name) ?> - <?= e($d->license_number) ?>
                                        (<?= ucfirst(str_replace('_', ' ', $d->status)) ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label">Override Reason <span class="text-danger">*</span></label>
                                <textarea class="form-control" name="override_reason" rows="2" 
                                          placeholder="Explain why you are overriding the vehicle/driver assignment..." required></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning">
                            <i class="bi bi-pencil-square me-1"></i>Confirm Override
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Complete Trip Modal -->
    <div class="modal fade" id="completeModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="<?= APP_URL ?>/?page=requests&action=complete">
                    <?= csrfField() ?>
                    <input type="hidden" name="request_id" value="<?= $requestId ?>">

                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-check-circle me-2"></i>Complete Trip</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted">Mark this trip as completed. This will release the vehicle and driver back to
                            available status.</p>

                        <div class="mb-3">
                            <label class="form-label">Vehicle</label>
                            <div class="fw-bold"><?= e($request->plate_number) ?> -
                                <?= e($request->make . ' ' . $request->vehicle_model) ?></div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Driver</label>
                            <div class="fw-bold"><?= e($request->driver_name) ?></div>
                        </div>

                        <div class="mb-3">
                            <label for="ending_mileage" class="form-label">Ending Mileage (Optional)</label>
                            <input type="number" class="form-control" id="ending_mileage" name="ending_mileage"
                                placeholder="Current odometer reading">
                        </div>

                        <div class="mb-3">
                            <label for="completion_notes" class="form-label">Completion Notes (Optional)</label>
                            <textarea class="form-control" id="completion_notes" name="completion_notes" rows="2"
                                placeholder="Any notes about the trip..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check-lg me-1"></i>Mark Complete
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Cancel Request Modal -->
<?php if ($request->user_id === userId() && !in_array($request->status, [STATUS_COMPLETED, STATUS_CANCELLED])): ?>
<div class="modal fade" id="cancelRequestModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-danger">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>Cancel Request?
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="<?= APP_URL ?>/?page=requests&action=cancel&id=<?= $requestId ?>">
                <?= csrfField() ?>
                <input type="hidden" name="confirm_cancel" value="1">
                <div class="modal-body">
                    <div class="text-center mb-4">
                        <i class="bi bi-x-circle-fill text-danger" style="font-size: 4rem;"></i>
                    </div>

                    <h5 class="text-center mb-3">Are you sure you want to cancel this request?</h5>

                    <div class="card bg-light mb-3">
                        <div class="card-body">
                            <div class="row g-2">
                                <div class="col-sm-4"><strong class="text-muted">Request #:</strong></div>
                                <div class="col-sm-8"><?= $requestId ?></div>
                                <div class="col-sm-4"><strong class="text-muted">Destination:</strong></div>
                                <div class="col-sm-8"><?= e($request->destination) ?></div>
                                <div class="col-sm-4"><strong class="text-muted">Date/Time:</strong></div>
                                <div class="col-sm-8"><?= formatDateTime($request->start_datetime) ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-danger d-flex align-items-start mb-3" role="alert">
                        <i class="bi bi-exclamation-triangle-fill flex-shrink-0 me-2 mt-1"></i>
                        <div>
                            <strong>This action cannot be undone!</strong>
                            <ul class="mb-0 mt-2">
                                <li>The request will be marked as cancelled</li>
                                <li>Assigned vehicle and driver will be released</li>
                                <li>All approvers and passengers will be notified</li>
                            </ul>
                        </div>
                    </div>

                    <?php if ($request->status === STATUS_APPROVED): ?>
                    <div class="alert alert-warning mb-3">
                        <i class="bi bi-info-circle-fill me-2"></i>
                        <strong>Attention:</strong> This request has already been approved.
                        <?php if ($request->vehicle_plate): ?>
                        <div class="mt-2"><strong>Vehicle:</strong> <?= e($request->vehicle_number) ?> - <?= e($request->vehicle_make) ?> <?= e($request->vehicle_model) ?></div>
                        <?php endif; ?>
                        <?php if ($request->driver_name): ?>
                        <div><strong>Driver:</strong> <?= e($request->driver_name) ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <div class="mb-0">
                        <label for="cancel_reason" class="form-label fw-bold">
                            <i class="bi bi-chat-left-text me-1"></i>Reason for cancellation
                            <span class="text-danger">*</span>
                        </label>
                        <textarea class="form-control" id="cancel_reason" name="reason" rows="2" required
                            placeholder="Please provide a reason for cancelling this request..."></textarea>
                        <small class="text-muted">This field is required</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-lg" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg me-1"></i>No, Keep Request
                    </button>
                    <button type="submit" class="btn btn-danger btn-lg">
                        <i class="bi bi-check-lg me-1"></i>Yes, Cancel Request
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>