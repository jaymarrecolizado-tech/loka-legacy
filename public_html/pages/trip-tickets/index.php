<?php
/**
 * LOKA - Guard Trip Tickets Page
 * 
 * Dedicated page for guards to manage trip tickets for completed trips
 */

requireRole(ROLE_GUARD);

$pageTitle = 'Trip Tickets';
$action = get('action', 'list');
$ticketId = (int) get('id', 0);

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== 'list') {
    requireCsrf();
    
    switch ($action) {
        case 'create':
            // Create trip ticket (after trip completion)
            header('Content-Type: application/json');
            
            $requestId = postInt('request_id');
            $driverId = postInt('driver_id');
            $tripType = post('trip_type', 'official');
            $tripTypeOther = postSafe('trip_type_other', '', 100);
            $startDate = post('start_date');
            $endDate = post('end_date');
            $destination = postSafe('destination', '', 255);
            $purpose = postSafe('purpose', '', 500);
            $passengers = (int) post('passengers', 0);

            // Mileage
            $startMileage = post('start_mileage') ? (int)post('start_mileage') : null;
            $endMileage = post('end_mileage') ? (int)post('end_mileage') : null;
            $distanceTraveled = post('distance_traveled') ? (int)post('distance_traveled') : null;
            
            // Fuel
            $fuelConsumed = post('fuel_consumed') ? (float)post('fuel_consumed') : null;
            $fuelCost = post('fuel_cost') ? (float)post('fuel_cost') : null;
            
            // Documents (will be handled by upload endpoint)
            $travelOrderPath = null; // From upload
            $obSlipPath = null; // From upload
            $otherDocumentsPath = null; // From upload
            
            // Issues
            $hasIssues = post('has_issues') ? 1 : 0;
            $issuesDescription = postSafe('issues_description', '', 1000);
            $resolved = post('resolved') ? 1 : 0;
            $resolutionNotes = postSafe('resolution_notes', '', 1000);
            $guardNotes = postSafe('guard_notes', '', 1000);
            
            // Validation
            $errors = [];
            
            if (!$requestId) {
                $errors[] = 'Request ID is required';
            }
            if (!$driverId) {
                $errors[] = 'Driver ID is required';
            }
            if (!$tripType || !in_array($tripType, ['official', 'personal', 'maintenance', 'travel_order', 'other'])) {
                $errors[] = 'Invalid trip type';
            }
            if ($tripType === 'other' && empty($tripTypeOther)) {
                $errors[] = 'Please specify the trip type when "Other" is selected.';
            }
            if (!$startDate) {
                $errors[] = 'Start date is required';
            }
            if (!$endDate) {
                $errors[] = 'End date is required';
            }
            if (!$destination) {
                $errors[] = 'Destination is required';
            }
            
            if (!empty($errors)) {
                try {
                    db()->beginTransaction();
                    
                    // Insert trip ticket
                    $ticketId = db()->insert('trip_tickets', [
                        'request_id' => $requestId,
                        'driver_id' => $driverId,
                        'trip_type' => $tripType,
                        'trip_type_other' => $tripType === 'other' ? $tripTypeOther : null,
                        'start_date' => date('Y-m-d H:i:s', strtotime($startDate)),
                        'end_date' => date('Y-m-d H:i:s', strtotime($endDate)),
                        'destination' => $destination,
                        'purpose' => $purpose,
                        'passengers' => $passengers,
                        'start_mileage' => $startMileage,
                        'end_mileage' => $endMileage,
                        'distance_traveled' => $distanceTraveled,
                        'fuel_consumed' => $fuelConsumed,
                        'fuel_cost' => $fuelCost,
                        'travel_order_path' => $travelOrderPath,
                        'ob_slip_path' => $obSlipPath,
                        'other_documents_path' => $otherDocumentsPath,
                        'has_issues' => $hasIssues,
                        'issues_description' => $issuesDescription,
                        'resolved' => $resolved,
                        'resolution_notes' => $resolutionNotes,
                        'dispatch_guard_id' => userId(),
                        'arrival_guard_id' => userId(),
                        'guard_notes' => $guardNotes,
                        'status' => 'draft',
                        'created_by' => userId()
                    ]);
                    
                    // Link ticket to request
                    db()->update('requests', 
                        ['trip_ticket_id' => $ticketId], 
                        'id = ?', 
                        [$requestId]
                    );
                    
                    // Audit log
                    auditLog(
                        'trip_ticket_created',
                        'trip_ticket',
                        $ticketId,
                        null,
                        [
                            'request_id' => $requestId,
                            'driver_id' => $driverId,
                            'trip_type' => $tripType
                        ]
                    );
                    
                    db()->commit();
                    
                    echo json_encode([
                        'success' => true,
                        'message' => 'Trip ticket created successfully',
                        'ticket_id' => $ticketId
                    ]);
                    
                } catch (Exception $e) {
                    db()->rollback();
                    http_response_code(500);
                    echo json_encode([
                        'success' => false,
                        'error' => 'Failed to create trip ticket: ' . $e->getMessage()
                    ]);
                }
            } else {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'errors' => $errors
                ]);
            }
            exit;
            
        case 'approve':
            // Review and approve trip ticket (motorpool/head can review)
            requireRole(ROLE_MOTORPOOL);
            
            header('Content-Type: application/json');
            
            $reviewNotes = postSafe('review_notes', '', 1000);
            
            if (!$ticketId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Ticket ID is required']);
                exit;
            }
            
            // Get ticket
            $ticket = db()->fetch(
                "SELECT * FROM trip_tickets WHERE id = ?",
                [$ticketId]
            );
            
            if (!$ticket) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Ticket not found']);
                exit;
            }
            
            if ($ticket->status === 'approved') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Ticket is already approved']);
                exit;
            }
            
            try {
                db()->update('trip_tickets', [
                    'status' => 'approved',
                    'reviewed_by' => userId(),
                    'reviewed_at' => date(DATETIME_FORMAT),
                    'guard_notes' => $ticket->guard_notes ? ($ticket->guard_notes . "\n\n[Review] " . $reviewNotes) : $reviewNotes
                ], 'id = ?', [$ticketId]);
                
                // Audit log
                auditLog(
                    'trip_ticket_approved',
                    'trip_ticket',
                    $ticketId,
                    null,
                    [
                        'review_notes' => $reviewNotes,
                        'reviewed_by' => userId()
                    ]
                );
                
                // Notify driver
                notify(
                    $ticket->driver_id,
                    'trip_ticket_approved',
                    'Trip Ticket Approved',
                    'Your trip ticket for request #' . $ticket->request_id . ' has been approved and reviewed.',
                    '/?page=trip-tickets&action=view&id=' . $ticketId,
                    $ticketId
                );
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Trip ticket approved successfully'
                ]);
                
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit;
            
        case 'reject':
            // Reject trip ticket
            requireRole(ROLE_MOTORPOOL);
            
            header('Content-Type: application/json');
            
            $rejectionReason = postSafe('rejection_reason', '', 1000);
            
            if (!$ticketId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Ticket ID is required']);
                exit;
            }
            
            // Get ticket
            $ticket = db()->fetch(
                "SELECT * FROM trip_tickets WHERE id = ?",
                [$ticketId]
            );
            
            if (!$ticket) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Ticket not found']);
                exit;
            }
            
            try {
                db()->update('trip_tickets', [
                    'status' => 'reviewed',
                    'reviewed_by' => userId(),
                    'reviewed_at' => date(DATETIME_FORMAT),
                    'guard_notes' => $ticket->guard_notes ? ($ticket->guard_notes . "\n\n[Rejection] " . $rejectionReason) : $rejectionReason
                ], 'id = ?', [$ticketId]);
                
                // Audit log
                auditLog(
                    'trip_ticket_rejected',
                    'trip_ticket',
                    $ticketId,
                    null,
                    [
                        'rejection_reason' => $rejectionReason,
                        'reviewed_by' => userId()
                    ]
                );
                
                // Notify driver
                notify(
                    $ticket->driver_id,
                    'trip_ticket_rejected',
                    'Trip Ticket Returned for Review',
                    'Your trip ticket for request #' . $ticket->request_id . ' has been returned for review. Please address the feedback provided.',
                    '/?page=trip-tickets&action=view&id=' . $ticketId,
                    $ticketId
                );
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Trip ticket returned for review'
                ]);
                
            } catch (Exception $e) {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit;
    }
}

// Get tickets based on role
$sql = "SELECT tt.*,
            r.id as request_id, r.destination as trip_destination,
            d.license_number as driver_license, du.name as driver_name,
            dg.name as dispatch_guard, ag.name as arrival_guard,
            u.name as reviewed_by_name
     FROM trip_tickets tt
     JOIN requests r ON tt.request_id = r.id
     LEFT JOIN drivers d ON tt.driver_id = d.id
     LEFT JOIN users du ON d.user_id = du.id
     LEFT JOIN users dg ON tt.dispatch_guard_id = dg.id
     LEFT JOIN users ag ON tt.arrival_guard_id = ag.id
     LEFT JOIN users u ON tt.reviewed_by = u.id
     WHERE tt.deleted_at IS NULL";

$params = [];

// Role-based filtering
if (isGuard()) {
    // Guards see all tickets they created or are involved in
    $sql .= " AND (tt.created_by = ? OR tt.driver_id = ?)";
    $params[] = userId();
    $params[] = userId();
} elseif (isMotorpool()) {
    // Motorpool sees all tickets for review
    $sql .= " AND tt.status IN ('submitted', 'reviewed', 'approved')";
} else {
    // Other roles (admin) see all
    $sql .= "";
}

// Filter by status
$statusFilter = get('status', '');
if ($statusFilter && in_array($statusFilter, ['draft', 'submitted', 'reviewed', 'approved'])) {
    $sql .= " AND tt.status = ?";
    $params[] = $statusFilter;
}

// Search
$search = get('search', '');
if ($search) {
    $sql .= " AND (
        r.destination LIKE ? OR
        du.name LIKE ? OR
        r.purpose LIKE ? OR
        tt.issues_description LIKE ?
    )";
    $searchTerm = '%' . $search . '%';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$sql .= " ORDER BY tt.created_at DESC";

$tickets = db()->fetchAll($sql, $params);

// Statistics
$totalTickets = count($tickets);
$pendingTickets = count(array_filter($tickets, fn($t) => $t->status === 'submitted'));
$approvedTickets = count(array_filter($tickets, fn($t) => $t->status === 'approved'));

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="mb-1"><i class="bi bi-file-earmark-text me-2"></i>Trip Tickets</h1>
            <p class="text-muted mb-0">Manage trip completion tickets and documentation</p>
        </div>
        <div>
            <?php if (isGuard()): ?>
                <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#createTicketModal">
                    <i class="bi bi-plus-circle me-1"></i>Create Trip Ticket
                </button>
            <?php endif; ?>
            <?php if (isMotorpool()): ?>
                <a href="?page=reports" class="btn btn-outline-secondary">
                    <i class="bi bi-bar-chart me-1"></i>View Reports
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row g-4 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="bg-primary bg-opacity-10 rounded p-3">
                                <i class="bi bi-file-earmark text-primary fs-4"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="text-muted mb-1">Total Tickets</h6>
                            <h3 class="mb-0"><?= $totalTickets ?></h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="bg-warning bg-opacity-10 rounded p-3">
                                <i class="bi bi-clock-history text-warning fs-4"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="text-muted mb-1">Pending Review</h6>
                            <h3 class="mb-0"><?= $pendingTickets ?></h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="bg-success bg-opacity-10 rounded p-3">
                                <i class="bi bi-check-circle text-success fs-4"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="text-muted mb-1">Approved</h6>
                            <h3 class="mb-0"><?= $approvedTickets ?></h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="bg-info bg-opacity-10 rounded p-3">
                                <i class="bi bi-info-circle text-info fs-4"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="text-muted mb-1">Action Required</h6>
                            <h3 class="mb-0 small"><?= $totalTickets - $pendingTickets - $approvedTickets ?></h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status">
                        <option value="">All Status</option>
                        <option value="submitted" <?= $statusFilter === 'submitted' ? 'selected' : '' ?>>Pending Review</option>
                        <option value="reviewed" <?= $statusFilter === 'reviewed' ? 'selected' : '' ?>>Returned for Review</option>
                        <option value="approved" <?= $statusFilter === 'approved' ? 'selected' : '' ?>>Approved</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Search</label>
                    <input type="text" class="form-control" name="search" value="<?= e($search) ?>" placeholder="Search by destination, driver, request ID...">
                </div>
                <div class="col-md-3">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-search me-1"></i>Filter
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Trip Tickets Table -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Trip Tickets (<?= count($tickets) ?>)</h5>
            <button type="button" class="btn btn-outline-primary btn-sm" onclick="exportTickets()">
                <i class="bi bi-file-earmark-excel me-1"></i>Export
            </button>
        </div>
        <div class="card-body">
            <?php if (empty($tickets)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-inbox fs-1 text-muted"></i>
                    <p class="text-muted mt-3">No trip tickets found.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle" id="ticketsTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Request</th>
                                <th>Trip Type</th>
                                <th>Driver</th>
                                <th>Destination</th>
                                <th>Date Range</th>
                                <th>Status</th>
                                <th>Documents</th>
                                <th>Issues</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tickets as $ticket): ?>
                                <tr>
                                    <td><strong>TT-<?= $ticket->request_id ?></strong></td>
                                    <td>
                                        <small>(Ref: VRF-<?= $ticket->request_id ?>)</small><br>
                                        <small class="text-muted"><?= e($ticket->trip_destination) ?></small>
                                    </td>
                                    <td>
                                        <?php
                                        $tripTypeColors = [
                                            'official' => 'success',
                                            'personal' => 'info',
                                            'maintenance' => 'warning',
                                            'travel_order' => 'primary',
                                            'other' => 'secondary'
                                        ];
                                        $tripTypeLabels = [
                                            'official' => 'Official Business',
                                            'personal' => 'Personal',
                                            'maintenance' => 'Maintenance',
                                            'travel_order' => 'Travel Order',
                                            'other' => 'Other'
                                        ];
                                        $color = $tripTypeColors[$ticket->trip_type] ?? 'secondary';
                                        $label = $tripTypeLabels[$ticket->trip_type] ?? 'Other';
                                        // Use custom label for "Other" type
                                        if ($ticket->trip_type === 'other' && !empty($ticket->trip_type_other)) {
                                            $label = e($ticket->trip_type_other);
                                        }
                                        ?>
                                        <span class="badge bg-<?= $color ?>">
                                            <?= $label ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($ticket->driver_name): ?>
                                            <?= e($ticket->driver_name) ?><br>
                                            <small class="text-muted"><?= e($ticket->driver_license) ?></small>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= e($ticket->destination) ?><br>
                                        <small class="text-muted"><?= truncate($ticket->purpose, 30) ?></small>
                                    </td>
                                    <td>
                                        <small>
                                            <i class="bi bi-calendar3 me-1"></i>
                                            <?= formatDate($ticket->start_date, 'M/d') ?>
                                        </small><br>
                                        <small>
                                            <i class="bi bi-calendar3 me-1"></i>
                                            <?= formatDate($ticket->end_date, 'M/d') ?>
                                        </small>
                                    </td>
                                    <td>
                                        <?php
                                        $statusClass = '';
                                        $statusIcon = '';
                                        switch ($ticket->status) {
                                            case 'submitted':
                                                $statusClass = 'warning';
                                                $statusIcon = 'clock';
                                                break;
                                            case 'reviewed':
                                                $statusClass = 'info';
                                                $statusIcon = 'arrow-counterclockwise';
                                                break;
                                            case 'approved':
                                                $statusClass = 'success';
                                                $statusIcon = 'check-circle';
                                                break;
                                        }
                                        ?>
                                        <span class="badge bg-<?= $statusClass ?>">
                                            <i class="bi bi-<?= $statusIcon ?> me-1"></i>
                                            <?= ucfirst($ticket->status) ?>
                                        </span>
                                        <?php if ($ticket->reviewed_by_name): ?>
                                            <br><small class="text-muted">by <?= e($ticket->reviewed_by_name) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $docs = [];
                                        if ($ticket->travel_order_path) $docs[] = '<span class="badge bg-secondary">TO</span>';
                                        if ($ticket->ob_slip_path) $docs[] = '<span class="badge bg-primary">OB</span>';
                                        if ($ticket->other_documents_path) $docs[] = '<span class="badge bg-info">Docs</span>';
                                        ?>
                                        <?php if (!empty($docs)): ?>
                                            <?= implode(' ', $docs) ?>
                                        <?php else: ?>
                                            <span class="text-muted">None</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($ticket->has_issues): ?>
                                            <span class="badge bg-danger">
                                                <i class="bi bi-exclamation-triangle me-1"></i>
                                                Issues
                                            </span>
                                        <?php elseif ($ticket->resolved): ?>
                                            <span class="badge bg-success">
                                                <i class="bi bi-check me-1"></i>
                                                Resolved
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <small>
                                            <i class="bi bi-clock me-1"></i>
                                            <?= formatDateTime($ticket->created_at) ?>
                                        </small>
                                    </td>
                                    <td>
                                        <a href="?page=trip-tickets&action=view&id=<?= $ticket->id ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-eye me-1"></i>View
                                        </a>
                                        <?php if (isMotorpool() && $ticket->status === 'submitted'): ?>
                                            <button type="button" class="btn btn-sm btn-success ms-1" onclick="approveTicket(<?= $ticket->id ?>)">
                                                <i class="bi bi-check-lg me-1"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-warning ms-1" onclick="rejectTicket(<?= $ticket->id ?>)">
                                                <i class="bi bi-x-lg me-1"></i>
                                            </button>
                                        <?php endif; ?>
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

<!-- Create Trip Ticket Modal (for guards) -->
<?php if (isGuard()): ?>
<div class="modal fade" id="createTicketModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-file-earmark-plus me-2"></i>Create Trip Ticket
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="createTicketForm" enctype="multipart/form-data">
                    <?= csrfField() ?>
                    
                    <!-- Trip Selection -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Request <span class="text-danger">*</span></label>
                            <select class="form-select" name="request_id" required>
                                <option value="">Select completed trip...</option>
                                <?php
                                // Get driver's recent completed trips without tickets
                                $completedTrips = db()->fetchAll(
                                    "SELECT r.id, r.destination, r.actual_arrival_datetime,
                                           d.id as driver_id, u.name as driver_name
                                     FROM requests r
                                     JOIN drivers d ON r.driver_id = d.id
                                     JOIN users u ON d.user_id = u.id
                                     LEFT JOIN trip_tickets tt ON r.id = tt.request_id
                                     WHERE d.user_id = ?
                                       AND r.status = 'completed'
                                       AND r.actual_arrival_datetime IS NOT NULL
                                       AND tt.id IS NULL
                                     ORDER BY r.actual_arrival_datetime DESC
                                     LIMIT 50",
                                    [userId()]
                                );
                                ?>
                                <?php foreach ($completedTrips as $trip): ?>
                                    <option value="<?= $trip->id ?>">
                                        <?= '#'.$trip->id.' - '.$trip->destination.' ('.formatDate($trip->actual_arrival_datetime, 'M/d').')' ?>
                                        - <?= e($trip->driver_name) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Select from your recent completed trips</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Trip Type <span class="text-danger">*</span></label>
                            <select class="form-select" name="trip_type" required onchange="toggleTripTypeOtherModal()">
                                <option value="official">Official Business</option>
                                <option value="personal">Personal</option>
                                <option value="maintenance">Maintenance Run</option>
                                <option value="travel_order">Travel Order</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>

                    <!-- Other Trip Type Description (shown only when Other is selected) -->
                    <div class="row mb-3" id="tripTypeOtherRowModal" style="display: none;">
                        <div class="col-12">
                            <label class="form-label">Specify Trip Type <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="trip_type_other" placeholder="Please specify the type of trip...">
                            <small class="text-muted">Required when "Other" is selected as trip type</small>
                        </div>
                    </div>

                    <!-- Date & Time -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Start Date <span class="text-danger">*</span></label>
                            <input type="datetime-local" class="form-control" name="start_date" required>
                            <small class="text-muted">Actual departure time</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">End Date <span class="text-danger">*</span></label>
                            <input type="datetime-local" class="form-control" name="end_date" required>
                            <small class="text-muted">Actual arrival time</small>
                        </div>
                    </div>

                    <!-- Destination & Purpose -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Destination <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="destination" required placeholder="e.g., Main Office, Warehouse">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Purpose</label>
                            <textarea class="form-control" name="purpose" rows="2" placeholder="Purpose of this trip..."></textarea>
                        </div>
                    </div>

                    <!-- Passengers -->
                    <div class="mb-3">
                        <label class="form-label">Number of Passengers</label>
                        <input type="number" class="form-control" name="passengers" min="0" value="0">
                    </div>

                    <!-- Mileage -->
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Start Odometer</label>
                            <input type="number" class="form-control" name="start_mileage" placeholder="Starting reading">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">End Odometer</label>
                            <input type="number" class="form-control" name="end_mileage" placeholder="Ending reading">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Distance (km)</label>
                            <input type="number" class="form-control" name="distance_traveled" placeholder="Auto-calculated if different">
                        </div>
                    </div>

                    <!-- Fuel -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Fuel Consumed (L)</label>
                            <input type="number" step="0.01" class="form-control" name="fuel_consumed" placeholder="Total liters">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Fuel Cost (PHP)</label>
                            <input type="number" step="0.01" class="form-control" name="fuel_cost" placeholder="Total cost">
                        </div>
                    </div>

                    <!-- Documents -->
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Travel Order (TO)</label>
                            <input type="file" class="form-control" name="travel_order" accept=".pdf,.jpg,.png">
                            <small class="text-muted">Optional</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">OB Slip</label>
                            <input type="file" class="form-control" name="ob_slip" accept=".pdf,.jpg,.png">
                            <small class="text-muted">Optional</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Other Documents</label>
                            <input type="file" class="form-control" name="other_documents" accept=".pdf,.zip" multiple>
                            <small class="text-muted">Optional</small>
                        </div>
                    </div>

                    <!-- Issues -->
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="has_issues" id="hasIssues" onchange="toggleIssuesFields()">
                            <label class="form-check-label" for="hasIssues">Any issues or incidents?</label>
                        </div>
                    </div>

                    <div id="issuesFields" class="mb-3" style="display: none;">
                        <div class="row">
                            <div class="col-md-6">
                                <label class="form-label">Issues Description</label>
                                <textarea class="form-control" name="issues_description" rows="2" placeholder="Describe any issues..."></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Resolved?</label>
                                <select class="form-select" name="resolved">
                                    <option value="0">No</option>
                                    <option value="1">Yes</option>
                                </select>
                            </div>
                        </div>
                        <div class="mt-2">
                            <label class="form-label">Resolution Notes</label>
                            <textarea class="form-control" name="resolution_notes" rows="2" placeholder="How was it resolved?"></textarea>
                        </div>
                    </div>

                    <!-- Guard Notes -->
                    <div class="mb-3">
                        <label class="form-label">Additional Notes</label>
                        <textarea class="form-control" name="guard_notes" rows="3" placeholder="Any additional observations..."></textarea>
                    </div>

                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Note:</strong> Documents will be uploaded after creating the ticket. You can then attach TO/OB slips and other documentation.
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" onclick="createTicket()">
                    <i class="bi bi-plus-circle me-1"></i>Create Ticket
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function toggleIssuesFields() {
    const hasIssues = document.getElementById('hasIssues').checked;
    document.getElementById('issuesFields').style.display = hasIssues ? 'block' : 'none';
}

function toggleTripTypeOtherModal() {
    const tripTypeSelect = document.querySelector('#createTicketForm select[name="trip_type"]');
    const otherRow = document.getElementById('tripTypeOtherRowModal');
    const otherInput = otherRow.querySelector('input[name="trip_type_other"]');

    if (tripTypeSelect.value === 'other') {
        otherRow.style.display = 'block';
        otherInput.required = true;
    } else {
        otherRow.style.display = 'none';
        otherInput.required = false;
        otherInput.value = '';
    }
}

async function createTicket() {
    const form = document.getElementById('createTicketForm');
    const formData = new FormData(form);
    
    try {
        const response = await fetch('?page=trip-tickets&action=create', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            // Close modal
            bootstrap.Modal.getInstance(document.getElementById('createTicketModal')).hide();
            
            // Show success message
            showAlert('success', result.message);
            
            // Reload page
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        } else {
            showAlert('danger', result.error || 'Failed to create ticket');
            if (result.errors) {
                showValidationErrors(result.errors);
            }
        }
    } catch (error) {
        showAlert('danger', 'An error occurred. Please try again.');
    }
}

function approveTicket(ticketId) {
    if (!confirm('Are you sure you want to approve this trip ticket?')) return;
    
    const notes = prompt('Review notes (optional):');
    
    fetch('?page=trip-tickets&action=approve', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: new URLSearchParams({
            ticket_id: ticketId,
            review_notes: notes || '',
            '<?= csrf_token ?>': '<?= csrf_token ?>'
        })
    })
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            showAlert('success', result.message);
            setTimeout(() => window.location.reload(), 1000);
        } else {
            showAlert('danger', result.error);
        }
    })
    .catch(error => {
        showAlert('danger', 'An error occurred');
    });
}

function rejectTicket(ticketId) {
    if (!confirm('Are you sure you want to return this ticket for review?')) return;
    
    const reason = prompt('Rejection reason (required):');
    
    if (!reason) {
        showAlert('warning', 'Please provide a rejection reason');
        return;
    }
    
    fetch('?page=trip-tickets&action=reject', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: new URLSearchParams({
            ticket_id: ticketId,
            rejection_reason: reason,
            '<?= csrf_token ?>': '<?= csrf_token ?>'
        })
    })
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            showAlert('success', result.message);
            setTimeout(() => window.location.reload(), 1000);
        } else {
            showAlert('danger', result.error);
        }
    })
    .catch(error => {
        showAlert('danger', 'An error occurred');
    });
}

function exportTickets() {
    const table = document.getElementById('ticketsTable');
    if (!table) return;

    let csv = [];
    const headers = ['ID', 'Request ID', 'Destination', 'Trip Type', 'Driver', 'Start Date', 'End Date', 'Status', 'Documents', 'Issues', 'Created'];
    csv.push(headers.map(h => `"${h}"`).join(','));

    const rows = table.querySelectorAll('tbody tr');
    rows.forEach(row => {
        const cells = row.querySelectorAll('td');
        if (cells.length > 0) {
            const rowData = [
                cells[0].textContent.trim(),
                cells[1].querySelector('small')?.textContent.trim() || '',
                cells[3].textContent.trim(),
                cells[2].querySelector('.badge')?.textContent.trim() || '',
                cells[4].textContent.trim(),
                cells[5].textContent.trim(),
                cells[6].textContent.trim(),
                cells[7].textContent.trim(),
                cells[8].textContent.trim(),
                cells[9].textContent.trim()
            ].map(val => `"${String(val).replace(/"/g, '""')}"`).join(',');

            csv.push(rowData);
        }
    });

    const csvContent = csv.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'trip_tickets_' + new Date().toISOString().slice(0, 10) + '.csv';
    link.click();
}
</script>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
