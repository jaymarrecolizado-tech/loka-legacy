<?php
/**
 * LOKA - Create Trip Ticket Form
 *
 * Pre-filled form for creating trip ticket after trip completion
 * Only drivers can create tickets for their own completed trips
 */

// Allow drivers and guards to access
if (!isDriver() && !isGuard() && !isAdmin()) {
    redirectWith('/?page=dashboard', 'danger', 'You do not have permission to create trip tickets.');
}

// Load FileUpload class
require_once CLASSES_PATH . '/FileUpload.php';

$pageTitle = 'Create Trip Ticket';
$requestId = (int) get('request_id', 0);

// Validate request ID
if (!$requestId) {
    $redirectPage = isDriver() ? 'my-trips' : 'guard';
    redirectWith('/?page=' . $redirectPage, 'danger', 'Invalid request ID.');
}

// Get request with all trip details for pre-filling
$request = db()->fetch(
    "SELECT r.*,
            v.plate_number, v.make, v.model as vehicle_model, v.color,
            d.id as driver_id, du.name as driver_name, d.license_number as driver_license,
            u.name as requester_name, u.department_id, dept.name as department_name,
            dg.name as dispatch_guard,
            r.actual_dispatch_datetime, r.actual_arrival_datetime,
            r.mileage_start
     FROM requests r
     LEFT JOIN vehicles v ON r.vehicle_id = v.id AND v.deleted_at IS NULL
     LEFT JOIN drivers d ON r.driver_id = d.id AND d.deleted_at IS NULL
     LEFT JOIN users du ON d.user_id = du.id
     LEFT JOIN users u ON r.user_id = u.id
     LEFT JOIN departments dept ON u.department_id = dept.id
     LEFT JOIN users dg ON r.dispatch_guard_id = dg.id
     WHERE r.id = ? AND r.deleted_at IS NULL
     FOR UPDATE",
    [$requestId]
);

if (!$request) {
    $redirectPage = isDriver() ? 'my-trips' : 'guard';
    redirectWith('/?page=' . $redirectPage, 'danger', 'Request not found.');
}

// Drivers can only create tickets for their own assigned trips
if (isDriver()) {
    // Get current user's driver ID
    $currentDriverId = db()->fetchColumn(
        "SELECT id FROM drivers WHERE user_id = ? AND deleted_at IS NULL",
        [userId()]
    );

    if (!$currentDriverId) {
        redirectWith('/?page=my-trips', 'danger', 'You are not registered as a driver.');
    }

    // Check if this trip belongs to the current driver
    if ($request->driver_id != $currentDriverId && $request->requested_driver_id != $currentDriverId) {
        redirectWith('/?page=my-trips', 'danger', 'You can only create trip tickets for your own assigned trips.');
    }
}

// Can only create trip ticket for completed trips
if ($request->status !== STATUS_COMPLETED) {
    $redirectPage = isDriver() ? 'my-trips' : 'guard';
    redirectWith(
        '/?page=requests&action=view&id=' . $requestId,
        'warning',
        'Trip ticket can only be created for completed trips. Current status: ' . $request->status
    );
}

// Check if ticket already exists
$existingTicket = db()->fetch(
    "SELECT id FROM trip_tickets WHERE request_id = ? AND deleted_at IS NULL",
    [$requestId]
);

if ($existingTicket) {
    $redirectPage = isDriver() ? 'my-trips' : 'trip-tickets';
    redirectWith(
        '/?page=' . $redirectPage,
        'info',
        'A trip ticket already exists for this trip. You can view or update it.'
    );
}

// Calculate pre-filled values
$startDate = $request->actual_dispatch_datetime ? date('Y-m-d\TH:i', strtotime($request->actual_dispatch_datetime)) : '';
$endDate = $request->actual_arrival_datetime ? date('Y-m-d\TH:i', strtotime($request->actual_arrival_datetime)) : '';
$tripPurpose = $request->purpose;
$destination = $request->destination;
$startMileage = $request->mileage_start;

// Calculate duration
$durationHours = 0;
$durationMinutes = 0;
if ($request->actual_dispatch_datetime && $request->actual_arrival_datetime) {
    $start = strtotime($request->actual_dispatch_datetime);
    $end = strtotime($request->actual_arrival_datetime);
    $diff = $end - $start;
    $durationHours = floor($diff / 3600);
    $durationMinutes = floor(($diff % 3600) / 60);
}

// Get passengers count
$passengers = db()->fetchColumn(
    "SELECT COUNT(*) FROM request_passengers WHERE request_id = ?",
    [$requestId]
);

// Pre-select vehicle plate if available
$plateNumber = '';
if ($request->plate_number) {
    $plateNumber = $request->plate_number;
    if ($request->make) {
        $plateNumber .= ' - ' . $request->make;
        if ($request->vehicle_model) {
            $plateNumber .= ' ' . $request->vehicle_model;
        }
    }
}

$errors = [];

// Initialize form variables from request data (for GET) or POST data (for submit)
$tripType = 'official'; // Default
$tripTypeValue = 'official'; // For form selection
$purposeValue = $tripPurpose; // Default to request purpose
$destinationValue = $destination;
$passengersValue = $passengers;
$startMileageValue = $startMileage;
$endMileageValue = null;
$distanceTraveledValue = null;
$fuelConsumedValue = null;
$fuelCostValue = null;
$hasIssuesValue = false;
$issuesDescriptionValue = '';
$resolvedValue = false;
$resolutionNotesValue = '';
$guardNotesValue = '';
$tripTypeOtherValue = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $tripType = post('trip_type', 'official');
    $tripTypeValue = $tripType;
    $newStartDate = post('start_date');
    $newEndDate = post('end_date');
    $destinationInput = postSafe('destination', '', 255);
    $purposeInput = postSafe('purpose', '', 500);
    $destinationValue = $destinationInput ?: $destination;
    $purposeValue = $purposeInput ?: $tripPurpose;
    $passengersValue = (int) post('passengers', $passengers);
    $startMileageValue = post('start_mileage') ? (int)post('start_mileage') : $startMileage;
    $endMileageValue = post('end_mileage') ? (int)post('end_mileage') : null;
    $distanceTraveledValue = post('distance_traveled') ? (int)post('distance_traveled') : null;
    $fuelConsumedValue = post('fuel_consumed') ? (float)post('fuel_consumed') : null;
    $fuelCostValue = post('fuel_cost') ? (float)post('fuel_cost') : null;

    // Other trip type description
    $tripTypeOther = postSafe('trip_type_other', '', 100);
    $tripTypeOtherValue = $tripTypeOther;

    // Documents - Handle file uploads
    $travelOrderPath = null;
    $obSlipPath = null;
    $otherDocumentsPath = null;

    // Create upload handler for trip tickets
    $uploadHandler = FileUpload::createTripTicketHandler($requestId);

    // Upload Travel Order if provided
    if (isset($_FILES['travel_order']) && $_FILES['travel_order']['error'] !== UPLOAD_ERR_NO_FILE) {
        $travelOrderPath = $uploadHandler->upload($_FILES['travel_order'], 'travel_order');
        if ($uploadHandler->hasErrors()) {
            $errors[] = 'Travel Order upload failed: ' . implode(', ', $uploadHandler->getErrors());
        }
    }

    // Upload OB Slip if provided
    if (isset($_FILES['ob_slip']) && $_FILES['ob_slip']['error'] !== UPLOAD_ERR_NO_FILE) {
        $obSlipPath = $uploadHandler->upload($_FILES['ob_slip'], 'ob_slip');
        if ($uploadHandler->hasErrors()) {
            $errors[] = 'OB Slip upload failed: ' . implode(', ', $uploadHandler->getErrors());
        }
    }

    // Upload other documents if provided (multiple files possible)
    $otherDocPaths = [];
    if (isset($_FILES['other_documents']) && $_FILES['other_documents']['error'] !== UPLOAD_ERR_NO_FILE) {
        // Check if multiple files were uploaded
        if (is_array($_FILES['other_documents']['name'])) {
            $otherDocPaths = $uploadHandler->uploadMultiple($_FILES['other_documents'], 'other_docs');
            if ($uploadHandler->hasErrors()) {
                $errors[] = 'Other documents upload failed: ' . implode(', ', $uploadHandler->getErrors());
            }
        } else {
            // Single file
            $singlePath = $uploadHandler->upload($_FILES['other_documents'], 'other_docs');
            if ($singlePath !== false && $singlePath !== '') {
                $otherDocPaths[] = $singlePath;
            }
            if ($uploadHandler->hasErrors()) {
                $errors[] = 'Other documents upload failed: ' . implode(', ', $uploadHandler->getErrors());
            }
        }
    }

    // Store other documents as JSON array
    $otherDocumentsPath = !empty($otherDocPaths) ? json_encode($otherDocPaths) : null;

    // Issues
    $hasIssuesValue = post('has_issues') ? 1 : 0;
    $hasIssues = $hasIssuesValue;
    $issuesDescriptionValue = postSafe('issues_description', '', 1000);
    $issuesDescription = $issuesDescriptionValue;
    $resolvedValue = post('resolved') ? 1 : 0;
    $resolved = $resolvedValue;
    $resolutionNotesValue = postSafe('resolution_notes', '', 1000);
    $resolutionNotes = $resolutionNotesValue;
    $guardNotesValue = postSafe('guard_notes', '', 1000);
    $guardNotes = $guardNotesValue;
    
    // Validation
    if (!$newStartDate) {
        $errors[] = 'Start date is required';
    }
    if (!$newEndDate) {
        $errors[] = 'End date is required';
    }
    if (!$destinationInput) {
        $errors[] = 'Destination is required';
    }
    if (!$tripType || !in_array($tripType, ['official', 'personal', 'maintenance', 'travel_order', 'other'])) {
        $errors[] = 'Invalid trip type';
    }
    if ($tripType === 'other' && empty($tripTypeOther)) {
        $errors[] = 'Please specify the trip type when "Other" is selected.';
    }

    if (empty($errors)) {
        try {
            db()->beginTransaction();
            
            // Insert trip ticket
            $ticketId = db()->insert('trip_tickets', [
                'request_id' => $requestId,
                'driver_id' => $request->driver_id,
                'trip_type' => $tripType,
                'trip_type_other' => $tripType === 'other' ? $tripTypeOther : null,
                'start_date' => date('Y-m-d H:i:s', strtotime($newStartDate)),
                'end_date' => date('Y-m-d H:i:s', strtotime($newEndDate)),
                'destination' => $destinationInput ?: $destination,
                'purpose' => $purposeInput ?: $tripPurpose,
                'passengers' => $passengersValue,
                'start_mileage' => $startMileageValue,
                'end_mileage' => $endMileageValue,
                'distance_traveled' => $distanceTraveledValue,
                'fuel_consumed' => $fuelConsumedValue,
                'fuel_cost' => $fuelCostValue,
                'travel_order_path' => $travelOrderPath,
                'ob_slip_path' => $obSlipPath,
                'other_documents_path' => $otherDocumentsPath,
                'has_issues' => $hasIssuesValue,
                'issues_description' => $issuesDescriptionValue,
                'resolved' => $resolvedValue,
                'resolution_notes' => $resolutionNotesValue,
                'dispatch_guard_id' => $request->dispatch_guard_id ?: userId(),
                'arrival_guard_id' => userId(),
                'guard_notes' => $guardNotesValue,
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
                    'driver_id' => $request->driver_id,
                    'trip_type' => $tripType
                ]
            );
            
            db()->commit();

            $redirectPage = isDriver() ? 'my-trip-tickets' : 'trip-tickets';
            redirectWith(
                '/?page=' . $redirectPage,
                'success',
                'Trip ticket created successfully! You can now review and submit it.'
            );
            
        } catch (Exception $e) {
            db()->rollback();
            $errors[] = 'Failed to create trip ticket: ' . $e->getMessage();
        }
    }
}

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="mb-1">
                <i class="bi bi-file-earmark-plus me-2"></i>
                Create Trip Ticket
            </h1>
            <p class="text-muted mb-0">Document completed trip details and documents</p>
        </div>
        <div>
            <a href="?page=<?= isDriver() ? 'my-trips' : 'guard' ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Back to <?= isDriver() ? 'My Trips' : 'Guard Dashboard' ?>
            </a>
            <?php if (!isDriver()): ?>
            <a href="?page=trip-tickets" class="btn btn-outline-primary">
                <i class="bi bi-list-check me-1"></i>All Trip Tickets
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Trip Reference Card -->
    <div class="card mb-4 border-primary">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">
                <i class="bi bi-link-45deg me-2"></i>
                Trip Reference: Request #<?= $requestId ?>
            </h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <dl class="row">
                        <dt class="col-sm-4">Requester:</dt>
                        <dd class="col-sm-8"><?= e($request->requester_name) ?></dd>
                        
                        <dt class="col-sm-4">Department:</dt>
                        <dd class="col-sm-8"><?= e($request->department_name) ?></dd>
                        
                        <dt class="col-sm-4">Driver:</dt>
                        <dd class="col-sm-8">
                            <?= e($request->driver_name) ?>
                            <small class="text-muted">(<?= e($request->driver_license) ?>)</small>
                        </dd>
                    </dl>
                </div>
                <div class="col-md-6">
                    <dl class="row">
                        <dt class="col-sm-4">Vehicle:</dt>
                        <dd class="col-sm-8">
                            <?= $plateNumber ?: 'No vehicle assigned' ?>
                        </dd>
                        
                        <dt class="col-sm-4">Destination:</dt>
                        <dd class="col-sm-8"><?= e($request->destination) ?></dd>
                        
                        <dt class="col-sm-4">Original Purpose:</dt>
                        <dd class="col-sm-8"><?= truncate($request->purpose, 50) ?></dd>
                    </dl>
                </div>
            </div>
            <div class="row">
                <div class="col-md-4">
                    <dl class="row">
                        <dt class="col-sm-5">Dispatched:</dt>
                        <dd class="col-sm-7">
                            <?= $request->actual_dispatch_datetime ? formatDateTime($request->actual_dispatch_datetime) : '-' ?>
                        </dd>
                    </dl>
                </div>
                <div class="col-md-4">
                    <dl class="row">
                        <dt class="col-sm-5">Arrived:</dt>
                        <dd class="col-sm-7">
                            <?= $request->actual_arrival_datetime ? formatDateTime($request->actual_arrival_datetime) : '-' ?>
                        </dd>
                    </dl>
                </div>
                <div class="col-md-4">
                    <dl class="row">
                        <dt class="col-sm-5">Duration:</dt>
                        <dd class="col-sm-7">
                            <?php if ($durationHours > 0): ?>
                                <span class="badge bg-info">
                                    <?= $durationHours ?>h <?= $durationMinutes ?>m
                                </span>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </dd>
                    </dl>
                </div>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <dl class="row">
                        <dt class="col-sm-5">Start Mileage:</dt>
                        <dd class="col-sm-7">
                            <strong><?= $startMileage ? number_format($startMileage) . ' km' : '-' ?></strong>
                        </dd>
                    </dl>
                </div>
                <div class="col-md-6">
                    <dl class="row">
                        <dt class="col-sm-5">Dispatched By:</dt>
                        <dd class="col-sm-7">
                            <?= e($request->dispatch_guard) ?: '-' ?>
                        </dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>

    <!-- Trip Ticket Form -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">
                <i class="bi bi-pencil-square me-2"></i>
                Trip Ticket Details
            </h5>
        </div>
        <div class="card-body">
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <strong><i class="bi bi-exclamation-triangle me-2"></i>Please fix the following errors:</strong>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?= e($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data" class="needs-validation">
                <?= csrfField() ?>
                
                <!-- Trip Type & Purpose -->
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Trip Type <span class="text-danger">*</span></label>
                        <select class="form-select" name="trip_type" id="tripTypeSelect" required onchange="toggleTripTypeOther()">
                            <option value="official" <?= $tripTypeValue === 'official' ? 'selected' : '' ?>>Official Business</option>
                            <option value="personal" <?= $tripTypeValue === 'personal' ? 'selected' : '' ?>>Personal</option>
                            <option value="maintenance" <?= $tripTypeValue === 'maintenance' ? 'selected' : '' ?>>Maintenance Run</option>
                            <option value="travel_order" <?= $tripTypeValue === 'travel_order' ? 'selected' : '' ?>>Travel Order</option>
                            <option value="other" <?= $tripTypeValue === 'other' ? 'selected' : '' ?>>Other</option>
                        </select>
                        <small class="text-muted">Select the nature of this trip</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Purpose</label>
                        <textarea class="form-control" name="purpose" rows="2" placeholder="Detailed purpose of the trip..."><?= e($purposeValue) ?></textarea>
                        <small class="text-muted">Original: <?= truncate($tripPurpose, 40) ?></small>
                    </div>
                </div>

                <!-- Other Trip Type Description (shown only when Other is selected) -->
                <div class="row mb-3" id="tripTypeOtherRow" style="display: <?= $tripTypeValue === 'other' ? 'block' : 'none' ?>;">
                    <div class="col-md-12">
                        <label class="form-label">Specify Trip Type <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="trip_type_other" value="<?= e($tripTypeOtherValue) ?>" placeholder="Please specify the type of trip..." <?= $tripTypeValue === 'other' ? 'required' : '' ?>>
                        <small class="text-muted">Required when "Other" is selected as trip type</small>
                    </div>
                </div>

                <!-- Date & Time (Pre-filled but editable) -->
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Start Date <span class="text-danger">*</span></label>
                        <input type="datetime-local" class="form-control" name="start_date" value="<?= $startDate ?>" required>
                        <small class="text-muted">
                            <i class="bi bi-info-circle me-1"></i>Pre-filled from dispatch time (can be edited)
                        </small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">End Date <span class="text-danger">*</span></label>
                        <input type="datetime-local" class="form-control" name="end_date" value="<?= $endDate ?>" required>
                        <small class="text-muted">
                            <i class="bi bi-info-circle me-1"></i>Pre-filled from arrival time (can be edited)
                        </small>
                    </div>
                </div>

                <!-- Destination (Pre-filled but editable) -->
                <div class="mb-3">
                    <label class="form-label">Destination <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="destination" value="<?= e($destinationValue) ?>" required>
                    <small class="text-muted">From request: <?= e($destination) ?></small>
                </div>

                <!-- Passengers -->
                <div class="mb-3">
                    <label class="form-label">Number of Passengers</label>
                    <input type="number" class="form-control" name="passengers" min="0" value="<?= $passengersValue ?>">
                    <small class="text-muted">Including driver (from request)</small>
                </div>

                <!-- Mileage -->
                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="form-label">Start Odometer</label>
                        <input type="number" class="form-control" name="start_mileage" value="<?= $startMileageValue ?>" placeholder="Starting reading">
                        <small class="text-muted">From request: <?= $startMileage ? number_format($startMileage) . ' km' : 'Not set' ?></small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">End Odometer</label>
                        <input type="number" class="form-control" name="end_mileage" value="<?= $endMileageValue ?>" placeholder="Ending reading" required>
                        <small class="text-danger">Required</small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Distance Traveled (km)</label>
                        <input type="number" class="form-control" name="distance_traveled" value="<?= $distanceTraveledValue ?>" placeholder="Auto-calculated if different">
                    </div>
                </div>

                <!-- Fuel -->
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Fuel Consumed (L)</label>
                        <input type="number" step="0.01" class="form-control" name="fuel_consumed" value="<?= $fuelConsumedValue ?>" placeholder="Total liters consumed">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Fuel Cost (PHP)</label>
                        <input type="number" step="0.01" class="form-control" name="fuel_cost" value="<?= $fuelCostValue ?>" placeholder="Total cost in PHP">
                    </div>
                </div>

                <hr class="my-4">

                <!-- Documents Upload Section -->
                <div class="mb-4">
                    <h6 class="mb-3">
                        <i class="bi bi-files me-1"></i>
                        Upload Documents
                    </h6>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Note:</strong> Upload Travel Order (TO) and OB Slip documents here. These are required for official business trips.
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <label class="form-label">Travel Order (TO)</label>
                            <input type="file" class="form-control" name="travel_order" accept=".pdf,.jpg,.jpeg,.png" id="travelOrderInput">
                            <small class="text-muted">PDF or Image files</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">OB Slip</label>
                            <input type="file" class="form-control" name="ob_slip" accept=".pdf,.jpg,.jpeg,.png" id="obSlipInput">
                            <small class="text-muted">PDF or Image files</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Other Documents</label>
                            <input type="file" class="form-control" name="other_documents" accept=".pdf,.zip" multiple id="otherDocsInput">
                            <small class="text-muted">Optional additional documents</small>
                        </div>
                    </div>
                </div>

                <hr class="my-4">

                <!-- Issues Section -->
                <div class="mb-4">
                    <h6 class="mb-3">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        Report Issues (Optional)
                    </h6>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="has_issues" id="hasIssues" onchange="toggleIssuesFields()" <?= $hasIssuesValue ? 'checked' : '' ?>>
                        <label class="form-check-label" for="hasIssues">Were there any issues or incidents during this trip?</label>
                    </div>

                    <div id="issuesFields" class="mb-3" style="display: <?= $hasIssuesValue ? 'block' : 'none' ?>;">
                        <div class="row">
                            <div class="col-md-6">
                                <label class="form-label">Issues Description</label>
                                <textarea class="form-control" name="issues_description" rows="3" placeholder="Describe any issues, incidents, or concerns..."><?= e($issuesDescriptionValue) ?></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Resolved?</label>
                                <select class="form-select" name="resolved">
                                    <option value="0" <?= !$resolvedValue ? 'selected' : '' ?>>No</option>
                                    <option value="1" <?= $resolvedValue ? 'selected' : '' ?>>Yes</option>
                                </select>
                            </div>
                        </div>
                        <div class="mt-2">
                            <label class="form-label">Resolution Notes</label>
                            <textarea class="form-control" name="resolution_notes" rows="2" placeholder="How were the issues resolved? (if applicable)"><?= e($resolutionNotesValue) ?></textarea>
                        </div>
                    </div>
                </div>

                <hr class="my-4">

                <!-- Guard Notes -->
                <div class="mb-4">
                    <label class="form-label">Guard Notes</label>
                    <textarea class="form-control" name="guard_notes" rows="3" placeholder="Any additional observations, comments, or notes..."><?= e($guardNotesValue) ?></textarea>
                    <small class="text-muted">Include any special circumstances or relevant information</small>
                </div>

                <!-- Action Buttons -->
                <div class="d-flex justify-content-between align-items-center">
                    <a href="?page=<?= isDriver() ? 'my-trips' : 'guard' ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-x-circle me-1"></i>
                        Cancel
                    </a>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-circle me-1"></i>
                        Create Trip Ticket
                    </button>
                </div>

                <!-- Info Box -->
                <div class="alert alert-info mb-0">
                    <i class="bi bi-info-circle me-2"></i>
                    <strong>Note:</strong> You can upload Travel Order and OB Slip documents now, or leave them empty to upload later. All trip details will be saved and linked to request #<?= $requestId ?>.
                    <br><br>
                    <small class="text-muted">
                        <i class="bi bi-file-earmark-pdf me-1"></i>
                        Accepted formats: PDF, JPG, PNG, GIF (max 10MB per file)
                    </small>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function toggleIssuesFields() {
    const hasIssues = document.getElementById('hasIssues').checked;
    document.getElementById('issuesFields').style.display = hasIssues ? 'block' : 'none';
}

function toggleTripTypeOther() {
    const tripTypeSelect = document.getElementById('tripTypeSelect');
    const otherRow = document.getElementById('tripTypeOtherRow');
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

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    toggleIssuesFields();
    toggleTripTypeOther();
});
</script>

<style>
.needs-validation input:invalid {
    border-color: #dc3545;
}

.card-header.bg-primary {
    background: linear-gradient(135deg, #0d6efd 0%, #0b5ed7 100%);
}
</style>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
