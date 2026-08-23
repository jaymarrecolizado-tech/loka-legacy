<?php
/**
 * LOKA - Guard Dashboard
 * 
 * Guards can view today's scheduled trips and record:
 * - Dispatch time (when vehicle leaves)
 * - Arrival time (when vehicle returns)
 */

requireRole(ROLE_GUARD);

$today = date('Y-m-d');
$filter = get('filter', 'today'); // today, pending_dispatch, pending_arrival, completed

// Build query based on filter
$sql = "SELECT r.*,
            u.name as requester_name, u.phone as requester_phone,
            d.name as department_name,
            v.plate_number, v.make, v.model as vehicle_model,
            dr.license_number as driver_license,
            driver_user.name as driver_name, driver_user.phone as driver_phone,
            mph.name as motorpool_head_name,
            dispatch_guard.name as dispatch_guard_name,
            arrival_guard.name as arrival_guard_name
     FROM requests r
     JOIN users u ON r.user_id = u.id
     JOIN departments d ON r.department_id = d.id
     LEFT JOIN vehicles v ON r.vehicle_id = v.id AND v.deleted_at IS NULL
     LEFT JOIN drivers dr ON r.driver_id = dr.id AND dr.deleted_at IS NULL
     LEFT JOIN users driver_user ON dr.user_id = driver_user.id
     LEFT JOIN users mph ON r.motorpool_head_id = mph.id
     LEFT JOIN users dispatch_guard ON r.dispatch_guard_id = dispatch_guard.id
     LEFT JOIN users arrival_guard ON r.arrival_guard_id = arrival_guard.id
     WHERE r.status = 'approved'
     AND r.deleted_at IS NULL";

$params = [];

switch ($filter) {
    case 'pending_dispatch':
        // Approved requests that haven't departed yet
        $sql .= " AND r.status = 'approved' AND r.actual_dispatch_datetime IS NULL";
        break;
    case 'pending_arrival':
        // Dispatched but haven't returned yet
        $sql .= " AND r.status = 'approved' AND r.actual_dispatch_datetime IS NOT NULL 
                  AND r.actual_arrival_datetime IS NULL";
        break;
    case 'completed':
        // Trips that have been completed (returned)
        $sql .= " AND r.status = 'approved' AND r.actual_arrival_datetime IS NOT NULL";
        break;
    case 'today':
    default:
        // Show all approved requests for today
        $sql .= " AND r.status = 'approved' AND DATE(r.start_datetime) = ?";
        $params[] = $today;
        break;
}

$sql .= " ORDER BY r.start_datetime ASC";

$trips = db()->fetchAll($sql, $params);

// Get statistics for today (for the stat cards)
$statsToday = db()->fetch(
    "SELECT
        COUNT(*) as total_scheduled,
        SUM(CASE WHEN actual_dispatch_datetime IS NULL THEN 1 ELSE 0 END) as pending_dispatch,
        SUM(CASE WHEN actual_dispatch_datetime IS NOT NULL AND actual_arrival_datetime IS NULL THEN 1 ELSE 0 END) as on_trip,
        SUM(CASE WHEN actual_arrival_datetime IS NOT NULL THEN 1 ELSE 0 END) as completed
     FROM requests
     WHERE status = 'approved'
     AND DATE(start_datetime) = ?
     AND deleted_at IS NULL",
    [$today]
);

// Get tab counts that reflect what each filter actually shows
$tabCounts = db()->fetch(
    "SELECT
        COUNT(*) as all_scheduled,
        SUM(CASE WHEN actual_dispatch_datetime IS NULL THEN 1 ELSE 0 END) as all_pending_dispatch,
        SUM(CASE WHEN actual_dispatch_datetime IS NOT NULL AND actual_arrival_datetime IS NULL THEN 1 ELSE 0 END) as all_on_trip,
        SUM(CASE WHEN actual_arrival_datetime IS NOT NULL THEN 1 ELSE 0 END) as all_completed
     FROM requests
     WHERE status = 'approved'
     AND deleted_at IS NULL"
);

$pageTitle = 'Guard Dashboard';
require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-shield-check me-2"></i>Guard Dashboard</h4>
            <p class="text-muted mb-0">Track vehicle dispatch and arrival times</p>
        </div>
        <div>
            <span class="badge bg-light text-dark border">
                <i class="bi bi-calendar3 me-1"></i><?= formatDate($today) ?>
            </span>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="bg-primary bg-opacity-10 rounded p-3">
                                <i class="bi bi-calendar-check text-primary fs-4"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="text-muted mb-1">Scheduled Today</h6>
                            <h3 class="mb-0"><?= $statsToday->total_scheduled ?? 0 ?></h3>
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
                                <i class="bi bi-car-front text-warning fs-4"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="text-muted mb-1">Pending Dispatch</h6>
                            <h3 class="mb-0"><?= $tabCounts->all_pending_dispatch ?? 0 ?></h3>
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
                                <i class="bi bi-arrow-return-left text-info fs-4"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="text-muted mb-1">On Trip</h6>
                            <h3 class="mb-0"><?= $tabCounts->all_on_trip ?? 0 ?></h3>
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
                            <h6 class="text-muted mb-1">Completed</h6>
                            <h3 class="mb-0"><?= $tabCounts->all_completed ?? 0 ?></h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Tabs -->
    <div class="card mb-4">
        <div class="card-header bg-white">
            <ul class="nav nav-tabs card-header-tabs">
                <li class="nav-item">
                    <a class="nav-link <?= $filter === 'today' ? 'active' : '' ?>"
                       href="<?= APP_URL ?>/?page=guard">
                        <i class="bi bi-calendar-day me-1"></i>Today's Trips
                        <span class="badge bg-primary ms-1"><?= $statsToday->total_scheduled ?? 0 ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $filter === 'pending_dispatch' ? 'active' : '' ?>"
                       href="<?= APP_URL ?>/?page=guard&filter=pending_dispatch">
                        <i class="bi bi-clock me-1"></i>Pending Dispatch
                        <span class="badge bg-warning ms-1"><?= $tabCounts->all_pending_dispatch ?? 0 ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $filter === 'pending_arrival' ? 'active' : '' ?>"
                       href="<?= APP_URL ?>/?page=guard&filter=pending_arrival">
                        <i class="bi bi-arrow-return-left me-1"></i>On Trip
                        <span class="badge bg-info ms-1"><?= $tabCounts->all_on_trip ?? 0 ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $filter === 'completed' ? 'active' : '' ?>"
                       href="<?= APP_URL ?>/?page=guard&filter=completed">
                        <i class="bi bi-check-circle me-1"></i>Completed
                        <span class="badge bg-success ms-1"><?= $tabCounts->all_completed ?? 0 ?></span>
                    </a>
                </li>
            </ul>
        </div>
        <div class="card-body">
            <?php if (empty($trips)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-calendar-x fs-1 text-muted"></i>
                    <p class="text-muted mt-3">
                        <?php if ($filter === 'today'): ?>
                            No approved trips scheduled for today.
                        <?php elseif ($filter === 'pending_dispatch'): ?>
                            No trips pending dispatch.
                        <?php elseif ($filter === 'pending_arrival'): ?>
                            No vehicles currently on trip.
                        <?php elseif ($filter === 'completed'): ?>
                            No trips completed today.
                        <?php else: ?>
                            No trips found.
                        <?php endif; ?>
                    </p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Request #</th>
                                <th>Time</th>
                                <th>Vehicle</th>
                                <th>Driver</th>
                                <th>Destination</th>
                                <th>Status</th>
                                <th>Dispatch</th>
                                <th>Arrival</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($trips as $trip): ?>
                                <tr>
                                    <td>
                                        <strong>#<?= $trip->id ?></strong><br>
                                        <small class="text-muted"><?= e($trip->requester_name) ?></small>
                                    </td>
                                    <td>
                                        <div class="small">
                                            <i class="bi bi-box-arrow-right text-success me-1"></i>
                                            <?= formatDateTime($trip->start_datetime) ?>
                                        </div>
                                        <div class="small">
                                            <i class="bi bi-box-arrow-in-left text-danger me-1"></i>
                                            <?= formatDateTime($trip->end_datetime) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($trip->plate_number): ?>
                                            <div class="fw-medium"><?= e($trip->plate_number) ?></div>
                                            <small class="text-muted"><?= e($trip->make . ' ' . $trip->vehicle_model) ?></small>
                                        <?php else: ?>
                                            <span class="text-muted">Not assigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($trip->driver_name): ?>
                                            <div class="fw-medium"><?= e($trip->driver_name) ?></div>
                                            <small class="text-muted"><?= e($trip->driver_phone) ?></small>
                                        <?php else: ?>
                                            <span class="text-muted">Not assigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= e($trip->destination) ?>
                                    </td>
                                    <td>
                                        <?php if ($trip->actual_arrival_datetime): ?>
                                            <span class="badge bg-success">Completed</span>
                                        <?php elseif ($trip->actual_dispatch_datetime): ?>
                                            <span class="badge bg-primary">On Trip</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($trip->actual_dispatch_datetime): ?>
                                            <div class="text-success">
                                                <i class="bi bi-check-circle me-1"></i>
                                                <?= formatDateTime($trip->actual_dispatch_datetime) ?>
                                            </div>
                                            <small class="text-muted">by <?= e($trip->dispatch_guard_name ?? 'Unknown') ?></small>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($trip->actual_arrival_datetime): ?>
                                            <div class="text-success">
                                                <i class="bi bi-check-circle me-1"></i>
                                                <?= formatDateTime($trip->actual_arrival_datetime) ?>
                                            </div>
                                            <small class="text-muted">by <?= e($trip->arrival_guard_name ?? 'Unknown') ?></small>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group">
                                            <?php if (!$trip->actual_dispatch_datetime): ?>
                                                <button type="button" class="btn btn-sm btn-success" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#dispatchModal<?= $trip->id ?>">
                                                    <i class="bi bi-box-arrow-right me-1"></i>Dispatch
                                                </button>
                                            <?php elseif (!$trip->actual_arrival_datetime): ?>
                                                <button type="button" class="btn btn-sm btn-primary" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#arrivalModal<?= $trip->id ?>">
                                                    <i class="bi bi-box-arrow-in-left me-1"></i>Arrival
                                                </button>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-sm btn-outline-secondary" disabled>
                                                    <i class="bi bi-check-all me-1"></i>Done
                                                </button>
                                            <?php endif; ?>
                                            
                                            <a href="<?= APP_URL ?>/?page=requests&action=view&id=<?= $trip->id ?>" 
                                               class="btn btn-sm btn-outline-primary" target="_blank">
                                                <i class="bi bi-eye"></i>
                                            </a>
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

<!-- Dispatch Modals -->
<?php foreach ($trips as $trip): ?>
    <?php if (!$trip->actual_dispatch_datetime): ?>
        <div class="modal fade" id="dispatchModal<?= $trip->id ?>" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST" action="<?= APP_URL ?>/?page=guard&action=record_dispatch">
                        <?= csrfField() ?>
                        <input type="hidden" name="request_id" value="<?= $trip->id ?>">
                        
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="bi bi-box-arrow-right text-success me-2"></i>Record Dispatch
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        
                        <div class="modal-body">
                            <div class="alert alert-info">
                                <strong>Request #<?= $trip->id ?></strong><br>
                                <?= e($trip->requester_name) ?> - <?= e($trip->destination) ?>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Vehicle</label>
                                <div class="fw-medium"><?= e($trip->plate_number ?? 'Not assigned') ?></div>
                                <small class="text-muted"><?= e($trip->make . ' ' . $trip->vehicle_model) ?></small>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Driver</label>
                                <div class="fw-medium"><?= e($trip->driver_name ?? 'Not assigned') ?></div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="dispatch_time<?= $trip->id ?>" class="form-label">Dispatch Time <span class="text-danger">*</span></label>
                                <input type="datetime-local"
                                       class="form-control"
                                       id="dispatch_time<?= $trip->id ?>"
                                       name="dispatch_time"
                                       value="<?= date('Y-m-d\TH:i') ?>"
                                       required>
                                <small class="text-muted">Current time is pre-filled. Adjust if needed.</small>
                            </div>

                            <!-- Travel Documents -->
                            <div class="mb-3">
                                <label class="form-label">Travel Documents (Optional)</label>
                                <div class="card card-body bg-light">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" name="has_travel_order" id="has_travel_order<?= $trip->id ?>" value="1" onchange="toggleTravelOrderInput(<?= $trip->id ?>)">
                                        <label class="form-check-label" for="has_travel_order<?= $trip->id ?>">
                                            <i class="bi bi-file-earmark-text me-1"></i>Travel Order Present
                                        </label>
                                        <input type="text" name="travel_order_number" id="travel_order_number<?= $trip->id ?>" class="form-control form-control-sm mt-2" placeholder="Travel Order No. (Required if checked)" style="display:none;">
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="has_official_business_slip" id="has_ob_slip<?= $trip->id ?>" value="1" onchange="toggleObSlipInput(<?= $trip->id ?>)">
                                        <label class="form-check-label" for="has_ob_slip<?= $trip->id ?>">
                                            <i class="bi bi-file-earmark me-1"></i>Official Business Slip Present
                                        </label>
                                        <input type="text" name="ob_slip_number" id="ob_slip_number<?= $trip->id ?>" class="form-control form-control-sm mt-2" placeholder="OB Slip No. (Required if checked)" style="display:none;">
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="guard_notes<?= $trip->id ?>" class="form-label">Notes (Optional)</label>
                                <textarea class="form-control"
                                          id="guard_notes<?= $trip->id ?>"
                                          name="guard_notes"
                                          rows="2"
                                          placeholder="Any observations about the vehicle condition, passengers, etc."></textarea>
                            </div>
                        </div>
                        
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-check-lg me-1"></i>Confirm Dispatch
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>
    
    <?php if ($trip->actual_dispatch_datetime && !$trip->actual_arrival_datetime): ?>
        <div class="modal fade" id="arrivalModal<?= $trip->id ?>" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST" action="<?= APP_URL ?>/?page=guard&action=record_arrival">
                        <?= csrfField() ?>
                        <input type="hidden" name="request_id" value="<?= $trip->id ?>">
                        
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="bi bi-box-arrow-in-left text-primary me-2"></i>Record Arrival
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        
                        <div class="modal-body">
                            <div class="alert alert-info">
                                <strong>Request #<?= $trip->id ?></strong><br>
                                <?= e($trip->requester_name) ?> - <?= e($trip->destination) ?>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Vehicle</label>
                                <div class="fw-medium"><?= e($trip->plate_number ?? 'Not assigned') ?></div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Driver</label>
                                <div class="fw-medium"><?= e($trip->driver_name ?? 'Not assigned') ?></div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Dispatched At</label>
                                <div class="text-success">
                                    <i class="bi bi-check-circle me-1"></i>
                                    <?= formatDateTime($trip->actual_dispatch_datetime) ?>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="arrival_time<?= $trip->id ?>" class="form-label">Arrival Time <span class="text-danger">*</span></label>
                                <input type="datetime-local"
                                       class="form-control"
                                       id="arrival_time<?= $trip->id ?>"
                                       name="arrival_time"
                                       value="<?= date('Y-m-d\TH:i') ?>"
                                       required>
                                <small class="text-muted">Current time is pre-filled. Adjust if needed.</small>
                            </div>

                            <!-- Ending Mileage (Optional) -->
                            <?php if ($trip->mileage_start): ?>
                            <div class="mb-3">
                                <label for="mileage_end<?= $trip->id ?>" class="form-label">Ending Mileage (Optional)</label>
                                <input type="number" class="form-control" id="mileage_end<?= $trip->id ?>" name="mileage_end"
                                       min="<?= $trip->mileage_start ?>" placeholder="Current odometer reading">
                                <small class="text-muted">
                                    <i class="bi bi-info-circle me-1"></i>
                                    Starting mileage was <strong><?= $trip->mileage_start ?> km</strong>.
                                    If entered, system will calculate actual trip distance.
                                </small>
                            </div>
                            <?php endif; ?>

                            <div class="mb-3">
                                <label for="guard_notes<?= $trip->id ?>" class="form-label">Notes (Optional)</label>
                                <textarea class="form-control"
                                          id="guard_notes<?= $trip->id ?>"
                                          name="guard_notes"
                                          rows="2"
                                          placeholder="Any observations about the vehicle condition upon return..."></textarea>
                            </div>
                        </div>
                        
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg me-1"></i>Confirm Arrival
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>
<?php endforeach; ?>

<script>
function toggleTravelOrderInput(id) {
    const checkbox = document.getElementById('has_travel_order' + id);
    const input = document.getElementById('travel_order_number' + id);
    if (checkbox && input) {
        input.style.display = checkbox.checked ? 'block' : 'none';
        input.required = checkbox.checked;
    }
}

function toggleObSlipInput(id) {
    const checkbox = document.getElementById('has_ob_slip' + id);
    const input = document.getElementById('ob_slip_number' + id);
    if (checkbox && input) {
        input.style.display = checkbox.checked ? 'block' : 'none';
        input.required = checkbox.checked;
    }
}

function exportCompletedTrips() {
    // Collect data from visible table rows
    const rows = document.querySelectorAll('tbody tr');
    const data = [['ID', 'Requester', 'Department', 'Date', 'Time', 'Vehicle', 'Driver', 'Destination', 'Dispatch Time', 'Arrival Time', 'Mileage Start', 'Mileage End', 'Mileage Actual', 'Travel Order', 'OB Slip']];

    rows.forEach(row => {
        const cells = row.querySelectorAll('td');
        if (cells.length > 0) {
            const id = cells[0].textContent.trim().replace('#', '');
            const requester = cells[1].textContent.trim();
            const dateTime = cells[2].textContent.trim();
            const vehicle = cells[3].textContent.trim();
            const driver = cells[4].textContent.trim();
            const destination = cells[5].textContent.trim();
            const status = cells[6].textContent.trim();
            const dispatchTime = cells[7].textContent.trim();
            const arrivalTime = cells[8] ? cells[8].textContent.trim() : '';

            // Parse date and time from the datetime cells
            const dateParts = dateTime.match(/(\d{2}\/\d{2}\/\d{4})/g);
            const tripDate = dateParts ? dateParts[0] : '';

            const startTime = dateTime.includes('→') ? dateTime.split('→')[0].trim() : '';
            const endTime = dateTime.includes('→') ? dateTime.split('→')[1].trim() : '';

            data.push([
                id,
                requester,
                '',
                tripDate,
                startTime + ' - ' + endTime,
                vehicle,
                driver,
                destination,
                dispatchTime,
                arrivalTime,
                '', '', '', '', ''
            ]);
        }
    });

    // Convert to CSV
    let csv = data.map(row => row.map(cell => `"${String(cell).replace(/"/g, '""')}"`).join(',')).join('\n');

    // Create download link
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'completed_trips_' + new Date().toISOString().slice(0, 10) + '.csv';
    link.click();
}
</script>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
