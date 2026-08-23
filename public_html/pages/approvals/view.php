<?php
/**
 * LOKA - Approval Review Page
 */

requireRole(ROLE_APPROVER);

$requestId = (int) get('id');

// Get request with full details
$request = db()->fetch(
    "SELECT r.*,
            u.name as requester_name, u.email as requester_email, u.phone as requester_phone,
            d.name as department_name,
            v.plate_number as vehicle_plate, v.make as vehicle_make, v.model as vehicle_model,
            v.color as vehicle_color, v.year as vehicle_year, v.mileage as vehicle_mileage,
            vt.name as vehicle_type, vt.passenger_capacity as vehicle_capacity,
            drv.license_number as driver_license, drv_u.name as driver_name, drv_u.phone as driver_phone
     FROM requests r
     JOIN users u ON r.user_id = u.id
     JOIN departments d ON r.department_id = d.id
     LEFT JOIN vehicles v ON r.vehicle_id = v.id AND v.deleted_at IS NULL
     LEFT JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
     LEFT JOIN drivers drv ON r.driver_id = drv.id AND drv.deleted_at IS NULL
     LEFT JOIN users drv_u ON drv.user_id = drv_u.id
     WHERE r.id = ? AND r.deleted_at IS NULL",
    [$requestId]
);

if (!$request) {
    redirectWith('/?page=approvals', 'danger', 'Request not found.');
}

// Mark request as viewed by this approver
db()->update('requests', ['viewed_at' => date(DATETIME_FORMAT)], 'id = ?', [$requestId]);

// Check if user can approve this request
$canApprove = false;
$approvalType = '';
$userRole = userRole();
$isCurrentUserApprover = $request->approver_id == userId();
$isCurrentUserMotorpool = $request->motorpool_head_id == userId();

if ($request->status === STATUS_PENDING_MOTORPOOL) {
    if ($request->motorpool_head_id == userId() || isAdmin()) {
        $canApprove = true;
        $approvalType = 'motorpool';
    }
} elseif ($request->status === STATUS_PENDING) {
    if ($request->approver_id == userId() || isAdmin()) {
        $canApprove = true;
        $approvalType = 'department';
    }
} elseif ($request->status === STATUS_REVISION) {
    // Both approver and motorpool can process revision requests
    if ($request->approver_id == userId() || isAdmin()) {
        $canApprove = true;
        $approvalType = 'department';
    } elseif ($request->motorpool_head_id == userId()) {
        $canApprove = true;
        $approvalType = 'motorpool';
    }
}

// Get approval workflow status for each stage
$departmentApproval = db()->fetch(
    "SELECT a.*, u.name as approver_name 
     FROM approvals a 
     JOIN users u ON a.approver_id = u.id 
     WHERE a.request_id = ? AND a.approval_type = 'department' 
     ORDER BY a.created_at DESC LIMIT 1",
    [$requestId]
);

$motorpoolApproval = db()->fetch(
    "SELECT a.*, u.name as approver_name 
     FROM approvals a 
     JOIN users u ON a.approver_id = u.id 
     WHERE a.request_id = ? AND a.approval_type = 'motorpool' 
     ORDER BY a.created_at DESC LIMIT 1",
    [$requestId]
);

// Get available vehicles (for motorpool)
$availableVehicles = [];
$availableDrivers = [];

if ($approvalType === 'motorpool') {
    $availableDrivers = db()->fetchAll(
        "SELECT d.*, u.name as driver_name, u.phone as driver_phone
         FROM drivers d
         JOIN users u ON d.user_id = u.id
         WHERE d.deleted_at IS NULL
         AND u.status = 'active' AND u.deleted_at IS NULL
         ORDER BY u.name"
    );

    $availableVehicles = db()->fetchAll(
        "SELECT v.*, vt.name as type_name, vt.passenger_capacity
         FROM vehicles v
         JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
         WHERE v.deleted_at IS NULL
         ORDER BY vt.name, v.plate_number"
    );
}

// Get the requested driver name if any
$requestedDriver = null;
if ($request->requested_driver_id) {
    $requestedDriver = db()->fetch(
        "SELECT u.name FROM drivers d JOIN users u ON d.user_id = u.id WHERE d.id = ?",
        [$request->requested_driver_id]
    );
}

// Get requested/preferred vehicle name if any (user selected during request creation)
$requestedVehicle = null;
if ($request->vehicle_id) {
    $requestedVehicle = db()->fetch(
        "SELECT v.*, vt.name as type_name, vt.passenger_capacity
         FROM vehicles v
         JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
         WHERE v.id = ?",
        [$request->vehicle_id]
    );
}

// Check conflicts for requested vehicle and driver (real-time for motorpool)
$vehicleConflictsList = [];
$driverConflictsList = [];
$vehicleConflictSeverity = 'none';
$driverConflictSeverity = 'none';
$vehicleConflicts = 0;
$driverConflicts = 0;

if ($approvalType === 'motorpool') {
    // Check vehicle conflicts
    if ($request->vehicle_id) {
        $vehicleConflict = checkVehicleConflict($request->vehicle_id, $request->start_datetime, $request->end_datetime, $requestId);
        if ($vehicleConflict) {
            $vehicleConflictsList[] = $vehicleConflict;
            $vehicleConflicts = count($vehicleConflictsList);
            $overlap = calculateOverlapMinutes($vehicleConflict, $request->start_datetime, $request->end_datetime);
            $vehicleConflictSeverity = $overlap <= 60 ? 'minor' : ($overlap <= 120 ? 'moderate' : 'severe');
        }
    }

    // Check driver conflicts
    if ($request->requested_driver_id) {
        $driverConflict = checkDriverConflict($request->requested_driver_id, $request->start_datetime, $request->end_datetime, $requestId);
        if ($driverConflict) {
            $driverConflictsList[] = $driverConflict;
            $driverConflicts = count($driverConflictsList);
            $overlap = calculateOverlapMinutes($driverConflict, $request->start_datetime, $request->end_datetime);
            $driverConflictSeverity = $overlap <= 60 ? 'minor' : ($overlap <= 120 ? 'moderate' : 'severe');
        }
    }
}

$hasConflicts = ($vehicleConflictSeverity !== 'none' || $driverConflictSeverity !== 'none');
$totalConflicts = $vehicleConflicts + $driverConflicts;
$allConflictsList = array_merge($vehicleConflictsList, $driverConflictsList);

// Get approval history
$approvals = db()->fetchAll(
    "SELECT a.*, u.name as approver_name
     FROM approvals a
     JOIN users u ON a.approver_id = u.id
     WHERE a.request_id = ?
     ORDER BY a.created_at ASC",
    [$requestId]
);

$pageTitle = 'Review Request #' . $requestId;

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <!-- Page Header with Context-Aware Status -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1">Review Request #<?= $requestId ?></h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/?page=approvals">Approvals</a></li>
                    <li class="breadcrumb-item active">Review</li>
                </ol>
            </nav>
        </div>
        <div>
            <?php
            // Context-aware status display based on current user's role and the request state
            $statusHtml = '';
            
            if ($request->status === STATUS_PENDING && $isCurrentUserApprover && $canApprove) {
                $statusHtml = '<span class="badge bg-warning fs-6"><i class="bi bi-hourglass-split me-1"></i>Pending Your Approval</span>';
            } elseif ($request->status === STATUS_PENDING_MOTORPOOL && $isCurrentUserMotorpool && $canApprove) {
                $statusHtml = '<span class="badge bg-warning fs-6"><i class="bi bi-hourglass-split me-1"></i>Awaiting Your Approval</span>';
            } elseif ($request->status === STATUS_PENDING) {
                $statusHtml = '<span class="badge bg-info fs-6"><i class="bi bi-clock-history me-1"></i>Awaiting Department Approval</span>';
            } elseif ($request->status === STATUS_PENDING_MOTORPOOL) {
                $statusHtml = '<span class="badge bg-primary fs-6"><i class="bi bi-truck-front me-1"></i>Awaiting Motorpool Assignment</span>';
            } elseif ($request->status === STATUS_APPROVED) {
                $statusHtml = '<span class="badge bg-success fs-6"><i class="bi bi-check-circle me-1"></i>Fully Approved</span>';
            } elseif ($request->status === STATUS_REJECTED) {
                $statusHtml = '<span class="badge bg-danger fs-6"><i class="bi bi-x-circle me-1"></i>Rejected</span>';
            } elseif ($request->status === STATUS_REVISION) {
                $statusHtml = '<span class="badge bg-warning fs-6"><i class="bi bi-arrow-repeat me-1"></i>Under Revision</span>';
            } elseif ($request->status === STATUS_CANCELLED) {
                $statusHtml = '<span class="badge bg-secondary fs-6"><i class="bi bi-slash-circle me-1"></i>Cancelled</span>';
            } else {
                $statusHtml = requestStatusBadge($request->status);
            }
            
            echo $statusHtml;
            ?>
        </div>
    </div>

    <!-- Approval Workflow Progress Tracker -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-diagram-3 me-2"></i>Approval Workflow Status</h5>
        </div>
        <div class="card-body">
            <div class="row g-4">
                <!-- Department Approval Stage -->
                <div class="col-md-6">
                    <div class="border rounded p-3 <?= $departmentApproval && $departmentApproval->status === 'approved' ? 'bg-success bg-opacity-10 border-success' : ($departmentApproval && $departmentApproval->status === 'rejected' ? 'bg-danger bg-opacity-10 border-danger' : ($departmentApproval && $departmentApproval->status === 'revision' ? 'bg-warning bg-opacity-10 border-warning' : 'bg-light')) ?>">
                        <div class="d-flex align-items-center mb-2">
                            <span class="badge bg-<?= $departmentApproval ? ($departmentApproval->status === 'approved' ? 'success' : ($departmentApproval->status === 'rejected' ? 'danger' : 'warning')) : 'secondary' ?> me-2">
                                <i class="bi bi-<?= $departmentApproval ? ($departmentApproval->status === 'approved' ? 'check-circle' : ($departmentApproval->status === 'rejected' ? 'x-circle' : 'arrow-repeat')) : 'clock' ?>"></i>
                            </span>
                            <strong>Department Approval</strong>
                        </div>
                        <div class="small">
                            <?php if ($departmentApproval): ?>
                                <div><strong>Status:</strong> 
                                    <span class="text-<?= $departmentApproval->status === 'approved' ? 'success' : ($departmentApproval->status === 'rejected' ? 'danger' : 'warning') ?>">
                                        <?= ucfirst($departmentApproval->status) ?>
                                    </span>
                                </div>
                                <div><strong>By:</strong> <?= e($departmentApproval->approver_name) ?></div>
                                <div><strong>Date:</strong> <?= formatDateTime($departmentApproval->created_at) ?></div>
                                <?php if ($departmentApproval->comments): ?>
                                    <div class="mt-2 fst-italic">"<?= e($departmentApproval->comments) ?>"</div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">Pending - Waiting for department approval</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Motorpool Approval Stage -->
                <div class="col-md-6">
                    <div class="border rounded p-3 <?= $motorpoolApproval && $motorpoolApproval->status === 'approved' ? 'bg-success bg-opacity-10 border-success' : ($motorpoolApproval && $motorpoolApproval->status === 'rejected' ? 'bg-danger bg-opacity-10 border-danger' : ($motorpoolApproval && $motorpoolApproval->status === 'revision' ? 'bg-warning bg-opacity-10 border-warning' : 'bg-light')) ?>">
                        <div class="d-flex align-items-center mb-2">
                            <span class="badge bg-<?= $motorpoolApproval ? ($motorpoolApproval->status === 'approved' ? 'success' : ($motorpoolApproval->status === 'rejected' ? 'danger' : 'warning')) : 'secondary' ?> me-2">
                                <i class="bi bi-<?= $motorpoolApproval ? ($motorpoolApproval->status === 'approved' ? 'check-circle' : ($motorpoolApproval->status === 'rejected' ? 'x-circle' : 'arrow-repeat')) : 'clock' ?>"></i>
                            </span>
                            <strong>Motorpool Approval</strong>
                        </div>
                        <div class="small">
                            <?php if ($motorpoolApproval): ?>
                                <div><strong>Status:</strong> 
                                    <span class="text-<?= $motorpoolApproval->status === 'approved' ? 'success' : ($motorpoolApproval->status === 'rejected' ? 'danger' : 'warning') ?>">
                                        <?= ucfirst($motorpoolApproval->status) ?>
                                    </span>
                                </div>
                                <div><strong>By:</strong> <?= e($motorpoolApproval->approver_name) ?></div>
                                <div><strong>Date:</strong> <?= formatDateTime($motorpoolApproval->created_at) ?></div>
                                <?php if ($motorpoolApproval->comments): ?>
                                    <div class="mt-2 fst-italic">"<?= e($motorpoolApproval->comments) ?>"</div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted"><?= $departmentApproval ? 'Waiting for motorpool approval' : 'Waiting for department approval first' ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Vehicle/Driver Assignment (if approved) -->
            <?php if ($request->status === STATUS_APPROVED && ($request->vehicle_id || $request->driver_id)): ?>
                <div class="mt-3 p-3 bg-success bg-opacity-10 border border-success rounded">
                    <strong><i class="bi bi-check-square me-1"></i>Final Assignment:</strong>
                    <?php
                    $vehicle = $request->vehicle_id ? db()->fetch("SELECT plate_number, make, model FROM vehicles WHERE id = ?", [$request->vehicle_id]) : null;
                    $driver = $request->driver_id ? db()->fetch("SELECT d.*, u.name FROM drivers d JOIN users u ON d.user_id = u.id WHERE d.id = ?", [$request->driver_id]) : null;
                    ?>
                    <div class="small mt-1">
                        <?php if ($vehicle): ?>
                            <span class="me-3"><i class="bi bi-car-front me-1"></i>Vehicle: <strong><?= e($vehicle->plate_number) ?> - <?= e($vehicle->make) ?> <?= e($vehicle->model) ?></strong></span>
                        <?php endif; ?>
                        <?php if ($driver): ?>
                            <span><i class="bi bi-person-badge me-1"></i>Driver: <strong><?= e($driver->name) ?></strong></span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-4">
        <!-- Request Details -->
        <div class="col-lg-8">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-file-earmark-text me-2"></i>Request Details</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="text-muted small">Requester</label>
                            <div class="fw-bold"><?= e($request->requester_name) ?></div>
                            <small class="text-muted"><?= e($request->requester_email) ?></small>
                        </div>
                        <div class="col-md-6">
                            <label class="text-muted small">Department</label>
                            <div class="fw-bold"><?= e($request->department_name) ?></div>
                        </div>
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
                        <div class="col-md-8">
                            <label class="text-muted small">Destination</label>
                            <div class="fw-medium"><?= e($request->destination) ?></div>
                        </div>
                        <div class="col-md-4">
                            <label class="text-muted small">Passenger Count</label>
                            <div class="fw-medium"><?= $request->passenger_count ?></div>
                        </div>
                        <!-- Passengers List -->
                        <?php
                        $passengers = db()->fetchAll(
                            "SELECT rp.*, u.name as user_name, u.email as user_email, d.name as department_name
                             FROM request_passengers rp
                             LEFT JOIN users u ON rp.user_id = u.id
                             LEFT JOIN departments d ON u.department_id = d.id
                             WHERE rp.request_id = ?
                             ORDER BY 
                                 CASE WHEN rp.user_id IS NOT NULL THEN 0 ELSE 1 END,
                                 u.name ASC, rp.guest_name ASC",
                            [$requestId]
                        );
                        ?>
                        <?php if (!empty($passengers)): ?>
                            <div class="col-12 mt-3">
                                <label class="text-muted small mb-2 d-block">
                                    <i class="bi bi-people-fill me-1"></i>Passengers List
                                </label>
                                <div class="border rounded p-3 bg-light">
                                    <div class="row g-2">
                                        <!-- Requester (always first) -->
                                        <div class="col-12 col-md-6 mb-2">
                                            <div class="d-flex align-items-center p-2 bg-white rounded border">
                                                <div class="bg-primary bg-opacity-10 p-2 rounded-circle me-2">
                                                    <i class="bi bi-person-fill text-primary"></i>
                                                </div>
                                                <div class="flex-grow-1">
                                                    <div class="fw-bold small"><?= e($request->requester_name) ?></div>
                                                    <div class="x-small text-primary fw-medium">Requester</div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Other Passengers -->
                                        <?php foreach ($passengers as $passenger): ?>
                                            <div class="col-12 col-md-6 mb-2">
                                                <div class="d-flex align-items-center p-2 bg-white rounded border">
                                                    <?php if ($passenger->user_id): ?>
                                                        <!-- System User -->
                                                        <div class="bg-secondary bg-opacity-10 p-2 rounded-circle me-2">
                                                            <i class="bi bi-person text-secondary"></i>
                                                        </div>
                                                        <div class="flex-grow-1">
                                                            <div class="fw-bold small"><?= e($passenger->user_name) ?></div>
                                                            <div class="x-small text-muted">
                                                                <?= e($passenger->department_name ?: 'No Department') ?>
                                                            </div>
                                                            <?php if ($passenger->user_email): ?>
                                                                <div class="x-small text-muted">
                                                                    <i class="bi bi-envelope me-1"></i><?= e($passenger->user_email) ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <!-- Guest -->
                                                        <div class="bg-success bg-opacity-10 p-2 rounded-circle me-2">
                                                            <i class="bi bi-person-plus text-success"></i>
                                                        </div>
                                                        <div class="flex-grow-1">
                                                            <div class="fw-bold small"><?= e($passenger->guest_name) ?></div>
                                                            <div class="x-small text-success fw-medium">External Guest</div>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php if ($requestedDriver): ?>
                            <div class="col-12 mt-2">
                                <label class="text-muted small">Requested Driver</label>
                                <div class="d-flex align-items-center">
                                    <span class="fw-bold text-primary"><i
                                            class="bi bi-person-badge me-1"></i><?= e($requestedDriver->name) ?></span>
                                    <div id="requestedDriverConflict" class="ms-3 small text-danger d-none">
                                        <i class="bi bi-exclamation-circle me-1"></i>Conflict detected!
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php if ($request->notes): ?>
                            <div class="col-12">
                                <label class="text-muted small">Additional Notes</label>
                                <div><?= nl2br(e($request->notes)) ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Vehicle Information -->
            <?php if ($request->vehicle_plate || $approvalType === 'motorpool'): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-car-front me-2"></i>Vehicle Information</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($request->vehicle_plate): ?>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="text-muted small">Plate Number</label>
                                    <div class="fw-bold fs-5"><?= e($request->vehicle_plate) ?></div>
                                </div>
                                <div class="col-md-6">
                                    <label class="text-muted small">Make & Model</label>
                                    <div class="fw-bold">
                                        <?= e($request->vehicle_make) ?> <?= e($request->vehicle_model) ?>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label class="text-muted small">Vehicle Type</label>
                                    <div><?= e($request->vehicle_type ?: 'N/A') ?></div>
                                </div>
                                <div class="col-md-4">
                                    <label class="text-muted small">Year</label>
                                    <div><?= $request->vehicle_year ?: 'N/A' ?></div>
                                </div>
                                <div class="col-md-4">
                                    <label class="text-muted small">Color</label>
                                    <div><?= e($request->vehicle_color ?: 'N/A') ?></div>
                                </div>
                                <div class="col-md-6">
                                    <label class="text-muted small">Passenger Capacity</label>
                                    <div>
                                        <i class="bi bi-people me-1"></i>
                                        <?= $request->vehicle_capacity ?: 'N/A' ?> seats
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="text-muted small">Current Mileage</label>
                                    <div>
                                        <i class="bi bi-speedometer2 me-1"></i>
                                        <?= number_format($request->vehicle_mileage ?? 0) ?> km
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-3">
                                <i class="bi bi-car-front text-muted fs-4"></i>
                                <p class="text-muted mb-0 mt-2">
                                    Vehicle will be assigned by Motorpool Head during approval.
                                </p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Driver Information (if assigned) -->
            <?php if ($request->driver_name): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-person-badge me-2"></i>Driver Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="text-muted small">Driver Name</label>
                                <div class="fw-bold"><?= e($request->driver_name) ?></div>
                            </div>
                            <div class="col-md-6">
                                <label class="text-muted small">Phone</label>
                                <div><?= e($request->driver_phone) ?></div>
                            </div>
                            <div class="col-md-6">
                                <label class="text-muted small">License Number</label>
                                <div><?= e($request->driver_license) ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Approval History -->
            <?php if (!empty($approvals)): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Approval History</h5>
                    </div>
                    <div class="card-body">
                        <?php foreach ($approvals as $approval): ?>
                            <div class="d-flex mb-3">
                                <div class="me-3">
                                    <span
                                        class="badge bg-<?= $approval->status === 'approved' ? 'success' : 'danger' ?> rounded-circle p-2">
                                        <i class="bi bi-<?= $approval->status === 'approved' ? 'check' : 'x' ?>-lg"></i>
                                    </span>
                                </div>
                                <div>
                                    <div class="fw-medium">
                                        <?= e($approval->approver_name) ?>
                                        <span class="text-<?= $approval->status === 'approved' ? 'success' : 'danger' ?>">
                                            <?= ucfirst($approval->status) ?>
                                        </span>
                                        (<?= ucfirst($approval->approval_type) ?>)
                                    </div>
                                    <small class="text-muted"><?= formatDateTime($approval->created_at) ?></small>
                                    <?php if ($approval->comments): ?>
                                        <div class="mt-1 fst-italic">"<?= e($approval->comments) ?>"</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Approval Action Form -->
            <?php if ($canApprove): ?>
                <div class="card border-primary">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-check-circle me-2"></i>Take Action</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="approvalForm" action="<?= APP_URL ?>/?page=approvals&action=process">
                            <?= csrfField() ?>
                            <input type="hidden" name="request_id" value="<?= $requestId ?>">
                            <input type="hidden" name="approval_type" value="<?= $approvalType ?>">

                            <?php if ($approvalType === 'motorpool'): ?>
                                <!-- Vehicle/Driver Assignment (Only for Approval) -->
                                <div id="assignmentSection">
                                    <!-- Requested Preferences Section -->
                                    <?php
                                    $hasRequestedDriver = $requestedDriver && $request->requested_driver_id;
                                    $hasRequestedVehicle = $requestedVehicle && $request->vehicle_id;
                                    $recommendedVehicle = null;
                                    
                                    if ($request->passenger_count <= 4) {
                                        $recommendedVehicle = 'Sedan or Hatchback (4-seater)';
                                    } elseif ($request->passenger_count <= 7) {
                                        $recommendedVehicle = 'SUV or Van (7-seater)';
                                    } elseif ($request->passenger_count <= 15) {
                                        $recommendedVehicle = 'Mini Bus (15-seater)';
                                    } else {
                                        $recommendedVehicle = 'Bus or Large Vehicle';
                                    }
                                    ?>
                                    
                                    <?php if ($hasRequestedDriver || $hasRequestedVehicle || $recommendedVehicle): ?>
                                        <div class="alert alert-info mb-4">
                                            <h6 class="alert-heading mb-2">
                                                <i class="bi bi-info-circle me-1"></i>Requested Preferences
                                            </h6>

                                            <?php if ($hasRequestedVehicle): ?>
                                                <div class="mb-2">
                                                    <strong>Requested Vehicle:</strong>
                                                    <span class="badge bg-primary ms-1">
                                                        <i class="bi bi-car-front me-1"></i><?= e($requestedVehicle->plate_number) ?> - <?= e($requestedVehicle->make . ' ' . $requestedVehicle->model) ?>
                                                    </span>
                                                    <small class="text-muted">(will be auto-selected)</small>
                                                </div>
                                            <?php endif; ?>

                                            <?php if ($hasRequestedDriver): ?>
                                                <div class="mb-2">
                                                    <strong>Requested Driver:</strong>
                                                    <span class="badge bg-primary ms-1">
                                                        <i class="bi bi-person-badge me-1"></i><?= e($requestedDriver->name) ?>
                                                    </span>
                                                    <small class="text-muted">(will be auto-selected)</small>
                                                </div>
                                            <?php endif; ?>

                                            <?php if ($recommendedVehicle): ?>
                                                <div>
                                                    <strong>Recommended Vehicle:</strong>
                                                    <span class="badge bg-success ms-1">
                                                        <i class="bi bi-truck me-1"></i><?= $recommendedVehicle ?>
                                                    </span>
                                                    <small class="text-muted">(for <?= $request->passenger_count ?> passenger<?= $request->passenger_count > 1 ? 's' : '' ?>)</small>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Conflict Status Dashboard -->
                                    <div id="conflictDashboard" class="card border-<?= $hasConflicts ? 'warning' : 'success' ?> mb-4">
                                        <div class="card-header bg-<?= $hasConflicts ? 'warning' : 'success' ?> d-flex justify-content-between align-items-center">
                                            <h6 class="mb-0">
                                                <i class="bi bi-<?= $hasConflicts ? 'exclamation-triangle' : 'check-circle' ?> me-1"></i>
                                                Conflict Status
                                            </h6>
                                            <?php if ($hasConflicts): ?>
                                                <span class="badge bg-dark" id="conflictCountBadge">
                                                    <?= $totalConflicts ?> conflict<?= $totalConflicts > 1 ? 's' : '' ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="card-body">
                                            <!-- Status Badges Row -->
                                            <div class="row g-3 mb-3">
                                                <!-- Vehicle Status -->
                                                <div class="col-md-6">
                                                    <div class="d-flex align-items-center p-3 rounded bg-light border">
                                                        <div class="me-3">
                                                            <span class="badge bg-<?= $vehicleConflictSeverity === 'none' ? 'success' : ($vehicleConflictSeverity === 'minor' ? 'warning' : 'danger') ?> rounded-circle p-2 fs-5" id="vehicleStatusBadge">
                                                                <i class="bi bi-<?= $vehicleConflictSeverity === 'none' ? 'check-lg' : 'exclamation-lg' ?>"></i>
                                                            </span>
                                                        </div>
                                                        <div>
                                                            <div class="small text-muted">Vehicle Assignment</div>
                                                            <div class="fw-bold">
                                                                <?= $vehicleConflictSeverity === 'none' ? 'Available (no conflicts)' : ($vehicleConflictSeverity === 'minor' ? 'Minor Conflict' : 'Major Conflict') ?>
                                                            </div>
                                                            <?php if ($vehicleConflictSeverity !== 'none'): ?>
                                                                <small class="text-danger"><?= $vehicleConflicts ?> overlapping trip<?= $vehicleConflicts > 1 ? 's' : '' ?></small>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>

                                                <!-- Driver Status -->
                                                <div class="col-md-6">
                                                    <div class="d-flex align-items-center p-3 rounded bg-light border">
                                                        <div class="me-3">
                                                            <span class="badge bg-<?= $driverConflictSeverity === 'none' ? 'success' : ($driverConflictSeverity === 'minor' ? 'warning' : 'danger') ?> rounded-circle p-2 fs-5" id="driverStatusBadge">
                                                                <i class="bi bi-<?= $driverConflictSeverity === 'none' ? 'check-lg' : 'exclamation-lg' ?>"></i>
                                                            </span>
                                                        </div>
                                                        <div>
                                                            <div class="small text-muted">Driver Assignment</div>
                                                            <div class="fw-bold">
                                                                <?= $driverConflictSeverity === 'none' ? 'Available (no conflicts)' : ($driverConflictSeverity === 'minor' ? 'Minor Conflict' : 'Major Conflict') ?>
                                                            </div>
                                                            <?php if ($driverConflictSeverity !== 'none'): ?>
                                                                <small class="text-danger"><?= $driverConflicts ?> overlapping trip<?= $driverConflicts > 1 ? 's' : '' ?></small>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Conflict Details (Collapsible) -->
                                            <?php if ($hasConflicts): ?>
                                                <div class="accordion" id="conflictAccordion">
                                                    <div class="accordion-item">
                                                        <h2 class="accordion-header">
                                                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#conflictDetails">
                                                                <i class="bi bi-chevron-down me-2"></i>
                                                                View Conflict Details
                                                            </button>
                                                        </h2>
                                                        <div id="conflictDetails" class="accordion-collapse collapse">
                                                            <div class="accordion-body">
                                                                <!-- Vehicle Conflicts -->
                                                                <?php if (!empty($vehicleConflictsList)): ?>
                                                                    <h6 class="text-warning"><i class="bi bi-car-front me-1"></i>Vehicle Conflicts</h6>
                                                                    <?php foreach ($vehicleConflictsList as $conflict): ?>
                                                                        <div class="alert alert-warning mb-2">
                                                                            <strong>Request #<?= $conflict['id'] ?></strong>
                                                                            <div class="small">
                                                                                <i class="bi bi-person me-1"></i><?= e($conflict['requester_name']) ?>
                                                                                <i class="bi bi-geo-alt ms-2 me-1"></i><?= e($conflict['destination']) ?>
                                                                            </div>
                                                                            <div class="small">
                                                                                <i class="bi bi-clock me-1"></i>
                                                                                <?= formatDateTime($conflict['start_datetime']) ?> - <?= formatDateTime($conflict['end_datetime']) ?>
                                                                            </div>
                                                                            <div class="small text-danger">
                                                                                <i class="bi bi-exclamation-triangle me-1"></i>
                                                                                Overlap: <?= calculateOverlapMinutes($conflict, $request->start_datetime, $request->end_datetime) ?> minutes
                                                                            </div>
                                                                            <a href="<?= APP_URL ?>/?page=approvals&action=view&id=<?= $conflict['id'] ?>"
                                                                               class="btn btn-sm btn-outline-primary mt-2" target="_blank">
                                                                                <i class="bi bi-arrow-up-right-square me-1"></i>View Request #<?= $conflict['id'] ?>
                                                                            </a>
                                                                        </div>
                                                                    <?php endforeach; ?>
                                                                <?php endif; ?>

                                                                <!-- Driver Conflicts -->
                                                                <?php if (!empty($driverConflictsList)): ?>
                                                                    <h6 class="text-warning mt-3"><i class="bi bi-person-badge me-1"></i>Driver Conflicts</h6>
                                                                    <?php foreach ($driverConflictsList as $conflict): ?>
                                                                        <div class="alert alert-warning mb-2">
                                                                            <strong>Request #<?= $conflict['id'] ?></strong>
                                                                            <div class="small">
                                                                                <i class="bi bi-person me-1"></i><?= e($conflict['requester_name']) ?>
                                                                                <i class="bi bi-geo-alt ms-2 me-1"></i><?= e($conflict['destination']) ?>
                                                                            </div>
                                                                            <div class="small">
                                                                                <i class="bi bi-clock me-1"></i>
                                                                                <?= formatDateTime($conflict['start_datetime']) ?> - <?= formatDateTime($conflict['end_datetime']) ?>
                                                                            </div>
                                                                            <div class="small text-danger">
                                                                                <i class="bi bi-exclamation-triangle me-1"></i>
                                                                                Overlap: <?= calculateOverlapMinutes($conflict, $request->start_datetime, $request->end_datetime) ?> minutes
                                                                            </div>
                                                                            <a href="<?= APP_URL ?>/?page=approvals&action=view&id=<?= $conflict['id'] ?>"
                                                                               class="btn btn-sm btn-outline-primary mt-2" target="_blank">
                                                                                <i class="bi bi-arrow-up-right-square me-1"></i>View Request #<?= $conflict['id'] ?>
                                                                            </a>
                                                                        </div>
                                                                    <?php endforeach; ?>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>

                                                <!-- Override Confirmation -->
                                                <div id="overrideConfirmation" class="alert alert-danger mt-3">
                                                    <h6 class="alert-heading mb-2">
                                                        <i class="bi bi-exclamation-octagon me-1"></i>Override Confirmation Required
                                                    </h6>
                                                    <div class="form-check mb-2">
                                                        <input class="form-check-input" type="checkbox" id="confirmOverride" name="override_conflict" value="1">
                                                        <label class="form-check-label fw-bold" for="confirmOverride">
                                                            I want to proceed with these conflicts:
                                                        </label>
                                                    </div>
                                                    <ul class="mb-0 ms-4">
                                                        <?php foreach ($allConflictsList as $conflict): ?>
                                                            <li>
                                                                <strong>Request #<?= $conflict['id'] ?></strong>:
                                                                <?= e($conflict['requester_name']) ?> to <?= e($conflict['destination']) ?>
                                                                (<?= calculateOverlapMinutes($conflict, $request->start_datetime, $request->end_datetime) ?> min overlap)
                                                            </li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- Vehicle Selection -->
                                    <div class="mb-3">
                                        <label for="vehicle_id" class="form-label">Assign Vehicle <span
                                                class="text-danger">*</span></label>
                                        <select class="form-select" id="vehicle_id" name="vehicle_id">
                                            <option value="">Select a vehicle...</option>
                                            <?php foreach ($availableVehicles as $vehicle): ?>
                                                <option value="<?= $vehicle->id ?>"
                                                    data-mileage="<?= $vehicle->mileage ?>"
                                                    <?= $hasRequestedVehicle && $vehicle->id == $request->vehicle_id ? 'selected' : '' ?>>
                                                    <?= e($vehicle->plate_number) ?> - <?= e($vehicle->make . ' ' . $vehicle->model) ?>
                                                    (<?= e($vehicle->type_name) ?>, <?= $vehicle->passenger_capacity ?> seats)
                                                    <?= $vehicle->status !== 'available' ? '[' . strtoupper($vehicle->status) . ']' : '' ?>
                                                    <?= $hasRequestedVehicle && $vehicle->id == $request->vehicle_id ? ' <i class="bi bi-check-circle-fill text-success ms-1"></i> (Requested)' : '' ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div id="vehicleConflictAlert" class="alert alert-warning mt-2 small py-2 d-none">
                                            <i class="bi bi-exclamation-triangle me-1"></i>
                                            <span class="message"></span>
                                        </div>
                                        <?php if (empty($availableVehicles)): ?>
                                            <small class="text-danger">No vehicles available</small>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Mileage Start (Optional) -->
                                    <div class="mb-3">
                                        <label for="mileage_start" class="form-label">Starting Mileage (Optional)</label>
                                        <input type="number" class="form-control" id="mileage_start" name="mileage_start"
                                               min="0" placeholder="Current odometer reading (optional)">
                                        <small class="text-muted">
                                            <i class="bi bi-info-circle me-1"></i>
                                            Leave blank to skip mileage tracking. If entered, must be >= vehicle's current mileage.
                                            <span id="currentMileageDisplay"></span>
                                        </small>
                                    </div>

                                    <!-- Driver Selection -->
                                    <div class="mb-3">
                                        <label for="driver_id" class="form-label">Assign Driver <span
                                                class="text-danger">*</span></label>
                                        <select class="form-select" id="driver_id" name="driver_id">
                                            <option value="">Select a driver...</option>
                                            <?php foreach ($availableDrivers as $driver): ?>
                                                <option value="<?= $driver->id ?>" 
                                                    <?= $hasRequestedDriver && $driver->id == $request->requested_driver_id ? 'selected' : '' ?>>
                                                    <?= e($driver->driver_name) ?> - <?= e($driver->license_number) ?>
                                                    (<?= $driver->years_experience ?> yrs exp)
                                                    <?= $driver->status !== 'available' ? '[' . strtoupper($driver->status) . ']' : '' ?>
                                                    <?= $hasRequestedDriver && $driver->id == $request->requested_driver_id ? ' <i class="bi bi-check-circle-fill text-success ms-1"></i> (Requested)' : '' ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div id="driverConflictAlert" class="alert alert-warning mt-3 small py-2 d-none">
                                            <i class="bi bi-exclamation-triangle me-1"></i>
                                            <span class="message"></span>
                                        </div>
                                    </div>

                                    <div id="overrideConfirm" class="form-check mb-3 d-none">
                                        <input class="form-check-input border-danger" type="checkbox" id="confirmOverride"
                                            name="override_conflict" value="1">
                                        <label class="form-check-label text-danger fw-bold" for="confirmOverride">
                                            I confirm this assignment despite schedule conflicts.
                                        </label>
                                    </div>
                                    <?php if (empty($availableDrivers)): ?>
                                        <small class="text-danger">No drivers available</small>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                    <!-- Comments -->
                    <div class="mb-4">
                        <label for="comments" class="form-label">
                            Comments 
                            <span class="text-danger" id="commentsRequired">*</span>
                            <span class="text-muted small" id="commentsOptional">(Optional for approval)</span>
                        </label>
                        <textarea class="form-control" id="comments" name="comments" rows="3"
                            placeholder="Enter your comments or remarks..."></textarea>
                        <div class="invalid-feedback" id="commentsFeedback">Comments are required when rejecting or requesting revision.</div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="d-flex gap-2 flex-wrap">
                            <input type="hidden" name="approval_action" id="approvalActionInput" value="">
                            <button type="button" id="approveBtn" class="btn btn-success" data-action="approve">
                            <span class="btn-text">
                                <i class="bi bi-check-lg me-1"></i>Approve
                            </span>
                            <span class="btn-loading d-none">
                                <span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                                Processing...
                            </span>
                        </button>
                            <button type="button" id="revisionBtn" class="btn btn-warning" data-action="revision">
                            <span class="btn-text">
                                <i class="bi bi-arrow-repeat me-1"></i>Request Revision
                            </span>
                            <span class="btn-loading d-none">
                                <span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                                Processing...
                            </span>
                        </button>
                            <button type="button" id="rejectBtn" class="btn btn-danger" data-action="reject">
                            <span class="btn-text">
                                <i class="bi bi-x-lg me-1"></i>Reject
                            </span>
                            <span class="btn-loading d-none">
                                <span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>
                                Processing...
                            </span>
                        </button>
                        <a href="<?= APP_URL ?>/?page=approvals" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle me-2"></i>
                <?php if ($request->status === STATUS_APPROVED): ?>
                    This request has already been fully approved.
                <?php elseif ($request->status === STATUS_REJECTED): ?>
                    This request has been rejected.
                <?php elseif ($request->status === STATUS_CANCELLED): ?>
                    This request was cancelled by the requester.
                <?php else: ?>
                    You cannot take action on this request at this time.
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Sidebar -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <h6 class="mb-0"><i class="bi bi-info-circle me-2"></i>Quick Info</h6>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <small class="text-muted">Request ID</small>
                    <div class="fw-bold">#<?= $requestId ?></div>
                </div>
                <div class="mb-3">
                    <small class="text-muted">Submitted</small>
                    <div><?= formatDateTime($request->created_at) ?></div>
                </div>
                <div class="mb-3">
                    <small class="text-muted">Duration</small>
                    <div>
                        <?php
                        $start = new DateTime($request->start_datetime);
                        $end = new DateTime($request->end_datetime);
                        $diff = $start->diff($end);
                        if ($diff->days > 0) {
                            echo $diff->days . ' day(s) ' . $diff->h . ' hour(s)';
                        } else {
                            echo $diff->h . ' hour(s) ' . $diff->i . ' min(s)';
                        }
                        ?>
                    </div>
                </div>
                <div>
                    <small class="text-muted">Contact</small>
                    <div><?= e($request->requester_phone ?: 'N/A') ?></div>
                </div>
            </div>
        </div>
    </div>
</div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const vehicleSelect = document.getElementById('vehicle_id');
        const driverSelect = document.getElementById('driver_id');
        const vAlert = document.getElementById('vehicleConflictAlert');
        const dAlert = document.getElementById('driverConflictAlert');
        const overrideBox = document.getElementById('overrideConfirm');
        const approveBtn = document.getElementById('approveBtn');
        const rejectBtn = document.getElementById('rejectBtn');
        const revisionBtn = document.getElementById('revisionBtn');
        const requestedConflict = document.getElementById('requestedDriverConflict');
        const approvalForm = document.getElementById('approvalForm');

        const start = '<?= $request->start_datetime ?>';
        const end = '<?= $request->end_datetime ?>';
        const requestId = '<?= $request->id ?>';

        function check(type, id, alertEl) {
            if (!id) {
                alertEl.classList.add('d-none');
                updateConflictDashboardItem(type, {conflict: false});
                return;
            }

            fetch(`<?= APP_URL ?>/?page=api&action=check_conflict&type=${type}&id=${id}&start=${start}&end=${end}&exclude_id=${requestId}`)
                .then(res => res.json())
                .then(data => {
                    if (data.conflict && data.conflicts.length > 0) {
                        const conflict = data.conflicts[0];
                        const overlapBadge = `<span class="badge bg-${getSeverityColor(data.severity)} ms-2">${data.severity.toUpperCase()} (${data.overlap_minutes}min)</span>`;

                        alertEl.querySelector('.message').innerHTML = `
                            <strong>Conflict with Request #${conflict.id}</strong>${overlapBadge}<br>
                            <small>${conflict.requester_name} → ${conflict.destination}</small>
                            <br>
                            <small>${data.start_datetime} - ${data.end_datetime}</small>
                            <br>
                            <a href="<?= APP_URL ?>/?page=approvals&action=view&id=${conflict.id}"
                               target="_blank"
                               class="small text-primary">
                               <i class="bi bi-arrow-up-right-square me-1"></i>View Request
                            </a>
                        `;
                        alertEl.classList.remove('d-none');

                        // Update conflict dashboard
                        updateConflictDashboardItem(type, data);
                    } else {
                        alertEl.classList.add('d-none');
                        updateConflictDashboardItem(type, {conflict: false});
                    }
                    updateUI();
                });
        }

        function getSeverityColor(severity) {
            switch(severity) {
                case 'minor': return 'warning';
                case 'moderate': return 'warning';
                case 'severe': return 'danger';
                default: return 'secondary';
            }
        }

        function updateConflictDashboardItem(type, data) {
            const vehicleBadge = document.getElementById('vehicleStatusBadge');
            const driverBadge = document.getElementById('driverStatusBadge');

            if (type === 'vehicle' && vehicleBadge) {
                if (data.conflict) {
                    const badgeClass = getSeverityColor(data.severity);
                    vehicleBadge.className = `badge bg-${badgeClass} rounded-circle p-2 fs-5`;
                    vehicleBadge.innerHTML = '<i class="bi bi-exclamation-lg"></i>';
                    const vehicleStatusText = vehicleBadge.parentElement.nextElementSibling.querySelector('.fw-bold');
                    if (vehicleStatusText) {
                        vehicleStatusText.textContent = data.severity === 'minor' ? 'Minor Conflict' : 'Major Conflict';
                        vehicleStatusText.className = 'text-danger fw-bold';
                    }
                } else {
                    vehicleBadge.className = 'badge bg-success rounded-circle p-2 fs-5';
                    vehicleBadge.innerHTML = '<i class="bi bi-check-lg"></i>';
                    const vehicleStatusText = vehicleBadge.parentElement.nextElementSibling.querySelector('.fw-bold');
                    if (vehicleStatusText) {
                        vehicleStatusText.textContent = 'Available (no conflicts)';
                        vehicleStatusText.className = 'fw-bold';
                    }
                }
            }

            if (type === 'driver' && driverBadge) {
                if (data.conflict) {
                    const badgeClass = getSeverityColor(data.severity);
                    driverBadge.className = `badge bg-${badgeClass} rounded-circle p-2 fs-5`;
                    driverBadge.innerHTML = '<i class="bi bi-exclamation-lg"></i>';
                    const driverStatusText = driverBadge.parentElement.nextElementSibling.querySelector('.fw-bold');
                    if (driverStatusText) {
                        driverStatusText.textContent = data.severity === 'minor' ? 'Minor Conflict' : 'Major Conflict';
                        driverStatusText.className = 'text-danger fw-bold';
                    }
                } else {
                    driverBadge.className = 'badge bg-success rounded-circle p-2 fs-5';
                    driverBadge.innerHTML = '<i class="bi bi-check-lg"></i>';
                    const driverStatusText = driverBadge.parentElement.nextElementSibling.querySelector('.fw-bold');
                    if (driverStatusText) {
                        driverStatusText.textContent = 'Available (no conflicts)';
                        driverStatusText.className = 'fw-bold';
                    }
                }
            }

            // Update conflict count
            const vConflict = vehicleBadge && !vehicleBadge.classList.contains('bg-success');
            const dConflict = driverBadge && !driverBadge.classList.contains('bg-success');
            const totalConflicts = (vConflict ? 1 : 0) + (dConflict ? 1 : 0);

            const countBadge = document.getElementById('conflictCountBadge');
            if (countBadge) {
                if (totalConflicts > 0) {
                    countBadge.textContent = `${totalConflicts} conflict${totalConflicts > 1 ? 's' : ''}`;
                    countBadge.classList.remove('d-none');
                } else {
                    countBadge.classList.add('d-none');
                }
            }

            // Toggle override confirmation
            const overrideSection = document.getElementById('overrideConfirmation');
            const conflictDashboard = document.getElementById('conflictDashboard');
            if (overrideSection && conflictDashboard) {
                if (totalConflicts > 0) {
                    overrideSection.classList.remove('d-none');
                    conflictDashboard.className = 'card border-warning mb-4';
                    conflictDashboard.querySelector('.card-header').className = 'card-header bg-warning d-flex justify-content-between align-items-center';
                    conflictDashboard.querySelector('.card-header h6').innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i>Conflict Status';
                } else {
                    overrideSection.classList.add('d-none');
                    conflictDashboard.className = 'card border-success mb-4';
                    conflictDashboard.querySelector('.card-header').className = 'card-header bg-success d-flex justify-content-between align-items-center';
                    conflictDashboard.querySelector('.card-header h6').innerHTML = '<i class="bi bi-check-circle me-1"></i>Conflict Status';
                }
            }
        }

        function updateUI() {
            const hasConflict = !vAlert.classList.contains('d-none') || !dAlert.classList.contains('d-none');
            if (hasConflict) {
                overrideBox.classList.remove('d-none');
                if (approveBtn) {
                    approveBtn.disabled = !document.getElementById('confirmOverride').checked;
                }
            } else {
                overrideBox.classList.add('d-none');
                if (approveBtn) {
                    approveBtn.disabled = false;
                }
            }
        }

        if (vehicleSelect) vehicleSelect.addEventListener('change', () => check('vehicle', vehicleSelect.value, vAlert));
        if (driverSelect) driverSelect.addEventListener('change', () => check('driver', driverSelect.value, dAlert));
        if (overrideBox) document.getElementById('confirmOverride').addEventListener('change', updateUI);

        // Handle vehicle mileage display
        if (vehicleSelect) {
            const updateMileageDisplay = () => {
                const selectedOption = vehicleSelect.options[vehicleSelect.selectedIndex];
                const mileageDisplay = document.getElementById('currentMileageDisplay');
                const mileageInput = document.getElementById('mileage_start');
                if (selectedOption && selectedOption.value) {
                    const currentMileage = selectedOption.getAttribute('data-mileage');
                    if (mileageDisplay && currentMileage) {
                        mileageDisplay.innerHTML = `<br>Vehicle current mileage: <strong>${currentMileage} km</strong>`;
                    }
                    if (mileageInput && currentMileage) {
                        mileageInput.min = currentMileage;
                        mileageInput.placeholder = `Minimum: ${currentMileage} km`;
                    }
                } else {
                    if (mileageDisplay) mileageDisplay.innerHTML = '';
                    if (mileageInput) {
                        mileageInput.min = 0;
                        mileageInput.placeholder = 'Current odometer reading (optional)';
                    }
                }
            };
            vehicleSelect.addEventListener('change', updateMileageDisplay);
            // Initial call for pre-selected vehicle
            updateMileageDisplay();
        }

        // Initial check for requested driver (Approver view info)
        <?php if ($request->requested_driver_id): ?>
            fetch(`<?= APP_URL ?>/?page=api&action=check_conflict&type=driver&id=<?= $request->requested_driver_id ?>&start=${start}&end=${end}&exclude_id=${requestId}`)
                .then(res => res.json())
                .then(data => {
                    if (data.conflict) requestedConflict.classList.remove('d-none');
                });
        <?php endif; ?>
        
        // Set initial state - show assignment fields by default (for approval)
        toggleAssignmentFields('approve');

        // Toggle assignment fields based on action
        function toggleAssignmentFields(action) {
            const assignmentSection = document.getElementById('assignmentSection');
            const commentsField = document.getElementById('comments');
            const commentsRequired = document.getElementById('commentsRequired');
            const commentsOptional = document.getElementById('commentsOptional');
            const vehicleSelect = document.getElementById('vehicle_id');
            const driverSelect = document.getElementById('driver_id');
            const approvalType = '<?= $approvalType ?>';
            
            if (action === 'reject' || action === 'revision') {
                // Hide assignment section for rejection/revision
                if (assignmentSection) {
                    assignmentSection.style.display = 'none';
                }
                // Make comments required for rejection/revision
                if (commentsField) {
                    commentsField.setAttribute('required', 'required');
                    commentsField.placeholder = action === 'revision' 
                        ? 'Please explain what needs to be revised (required)...'
                        : 'Please provide a reason for rejection (required)...';
                    commentsField.classList.remove('is-invalid');
                }
                if (commentsRequired) commentsRequired.style.display = 'inline';
                if (commentsOptional) commentsOptional.style.display = 'none';
                // Remove required from vehicle/driver
                if (vehicleSelect) {
                    vehicleSelect.removeAttribute('required');
                    vehicleSelect.classList.remove('is-invalid');
                }
                if (driverSelect) {
                    driverSelect.removeAttribute('required');
                    driverSelect.classList.remove('is-invalid');
                }
            } else {
                // Show assignment section for approval
                if (assignmentSection) {
                    assignmentSection.style.display = 'block';
                }
                // Make comments optional for approval
                if (commentsField) {
                    commentsField.removeAttribute('required');
                    commentsField.placeholder = 'Optional comments...';
                    commentsField.classList.remove('is-invalid');
                }
                if (commentsRequired) commentsRequired.style.display = 'none';
                if (commentsOptional) commentsOptional.style.display = 'inline';
                // Add required to vehicle/driver for motorpool approval only
                if (approvalType === 'motorpool') {
                    if (vehicleSelect) {
                        vehicleSelect.setAttribute('required', 'required');
                    }
                    if (driverSelect) {
                        driverSelect.setAttribute('required', 'required');
                    }
                }
            }
        }
        
        // Track which button was clicked
        let clickedAction = null;
        
        // Function to validate and submit form
        function validateAndSubmit(action) {
            const actionInput = document.getElementById('approvalActionInput');
            if (actionInput) {
                actionInput.value = action;
            }
            
            const formActionUrl = approvalForm.action;
            const formData = new FormData(approvalForm);
            formData.set('approval_action', action);
            
            const submitBtn = action === 'approve' ? approveBtn : (action === 'revision' ? revisionBtn : rejectBtn);
            const btnText = submitBtn.querySelector('.btn-text');
            const btnLoading = submitBtn.querySelector('.btn-loading');
            const comments = formData.get('comments')?.trim() || '';
            
            // Validate comments for rejection or revision
            if ((action === 'reject' || action === 'revision') && !comments) {
                const msg = action === 'revision' 
                    ? 'Please explain what needs to be revised.' 
                    : 'Please provide a reason for rejection.';
                showToast(msg, 'warning');
                document.getElementById('comments').classList.add('is-invalid');
                document.getElementById('comments').focus();
                return;
            }
            
            // Validate vehicle/driver for motorpool approval
            const approvalType = '<?= $approvalType ?>';
            if (action === 'approve' && approvalType === 'motorpool') {
                const vehicleId = formData.get('vehicle_id');
                const driverId = formData.get('driver_id');
                const vehicleSelect = document.getElementById('vehicle_id');
                const driverSelect = document.getElementById('driver_id');
                
                if (!vehicleId || !driverId) {
                    showToast('Please select both a vehicle and driver for approval.', 'warning');
                    if (!vehicleId && vehicleSelect) vehicleSelect.classList.add('is-invalid');
                    if (!driverId && driverSelect) driverSelect.classList.add('is-invalid');
                    return;
                }
            }
            
            // Clear validation classes
            document.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
            
            // Disable buttons and show loading
            approveBtn.disabled = true;
            rejectBtn.disabled = true;
            if (revisionBtn) revisionBtn.disabled = true;
            btnText.classList.add('d-none');
            btnLoading.classList.remove('d-none');
            
            // Add AJAX header
            formData.append('ajax', '1');
            
            fetch(formActionUrl, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                const contentType = response.headers.get('content-type') || '';
                if (contentType.includes('application/json')) {
                    return response.json();
                }
                return response.text().then(() => null);
            })
            .then(data => {
                clickedAction = null;
                
                const success = data && typeof data === 'object' ? !!data.success : true;
                const message = data && typeof data === 'object' && data.message
                    ? data.message
                    : (action === 'approve' ? 'Request approved successfully!' : (action === 'revision' ? 'Request sent for revision.' : 'Request rejected.'));
                
                if (success) {
                    showToast(message, 'success');
                    setTimeout(() => {
                        window.location.href = '<?= APP_URL ?>/?page=approvals&tab=processed&p_processed=1';
                    }, 1500);
                } else {
                    showToast(data.message || 'An error occurred. Please try again.', 'danger');
                    if (approveBtn) approveBtn.disabled = false;
                    if (rejectBtn) rejectBtn.disabled = false;
                    if (revisionBtn) revisionBtn.disabled = false;
                    if (btnText) btnText.classList.remove('d-none');
                    if (btnLoading) btnLoading.classList.add('d-none');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                clickedAction = null;
                showToast('An error occurred. Please try again.', 'danger');
                if (approveBtn) approveBtn.disabled = false;
                if (rejectBtn) rejectBtn.disabled = false;
                if (revisionBtn) revisionBtn.disabled = false;
                if (btnText) btnText.classList.remove('d-none');
                if (btnLoading) btnLoading.classList.add('d-none');
            });
        }
        
        // Handle button clicks
        if (approveBtn) {
            approveBtn.addEventListener('click', function(e) {
                e.preventDefault();
                toggleAssignmentFields('approve');
                validateAndSubmit('approve');
            });
        }
        
        if (revisionBtn) {
            revisionBtn.addEventListener('click', function(e) {
                e.preventDefault();
                toggleAssignmentFields('revision');
                validateAndSubmit('revision');
            });
        }
        
        if (rejectBtn) {
            rejectBtn.addEventListener('click', function(e) {
                e.preventDefault();
                toggleAssignmentFields('reject');
                validateAndSubmit('reject');
            });
        }
    });
</script>