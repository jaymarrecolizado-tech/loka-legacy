<?php
/**
 * LOKA - Guard Completed Trips Page
 *
 * Dedicated page for guards to view completed trips for reference and audit
 */

requireRole(ROLE_GUARD);

$pageTitle = 'Completed Trips';

$startDate = get('start_date', date('Y-m-01'));
$endDate = get('end_date', date('Y-m-t'));
$search = get('search', '');

$sql = "SELECT r.*,
            u.name as requester_name, u.phone as requester_phone,
            d.name as department_name,
            v.plate_number, v.make, v.model as vehicle_model, v.color,
            dr.license_number as driver_license,
            driver_user.name as driver_name, driver_user.phone as driver_phone,
            dispatch_guard.name as dispatch_guard_name,
            arrival_guard.name as arrival_guard_name,
            TIMESTAMPDIFF(MINUTE, r.actual_dispatch_datetime, r.actual_arrival_datetime) as actual_duration
     FROM requests r
     JOIN users u ON r.user_id = u.id
     JOIN departments d ON r.department_id = d.id
     LEFT JOIN vehicles v ON r.vehicle_id = v.id AND v.deleted_at IS NULL
     LEFT JOIN drivers dr ON r.driver_id = dr.id AND dr.deleted_at IS NULL
     LEFT JOIN users driver_user ON dr.user_id = driver_user.id
     LEFT JOIN users dispatch_guard ON r.dispatch_guard_id = dispatch_guard.id
     LEFT JOIN users arrival_guard ON r.arrival_guard_id = arrival_guard.id
     WHERE r.status = 'approved'
     AND r.actual_dispatch_datetime IS NOT NULL
     AND r.actual_arrival_datetime IS NOT NULL
     AND r.deleted_at IS NULL";

$params = [];

// Apply date range filter
$sql .= " AND DATE(r.actual_arrival_datetime) BETWEEN ? AND ?";
$params[] = $startDate;
$params[] = $endDate . ' 23:59:59';

// Apply search filter
if ($search) {
    $sql .= " AND (
        v.plate_number LIKE ? OR
        u.name LIKE ? OR
        driver_user.name LIKE ? OR
        r.destination LIKE ? OR
        r.purpose LIKE ?
    )";
    $searchTerm = '%' . $search . '%';
    $params = array_merge($params, array_fill(0, 5, $searchTerm));
}

$sql .= " ORDER BY r.actual_arrival_datetime DESC";

$trips = db()->fetchAll($sql, $params);

// Calculate statistics
$totalTrips = count($trips);
$totalDistance = 0;
$totalHours = 0;
$withTravelOrder = 0;
$withObSlip = 0;

foreach ($trips as $t) {
    if ($t->mileage_actual) $totalDistance += $t->mileage_actual;
    if ($t->actual_duration) $totalHours += $t->actual_duration / 60;
    if ($t->has_travel_order) $withTravelOrder++;
    if ($t->has_official_business_slip) $withObSlip++;
}

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-check-all me-2"></i>Completed Trips</h4>
            <p class="text-muted mb-0">View all completed trips for reference</p>
        </div>
        <div>
            <a href="?page=guard" class="btn btn-outline-secondary">
                <i class="bi bi-shield-check me-1"></i>Guard Dashboard
            </a>
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
                                <i class="bi bi-list-check text-primary fs-4"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="text-muted mb-1">Total Trips</h6>
                            <h3 class="mb-0"><?= $totalTrips ?></h3>
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
                                <i class="bi bi-speedometer2 text-success fs-4"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="text-muted mb-1">Total Distance</h6>
                            <h3 class="mb-0"><?= number_format($totalDistance) ?> km</h3>
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
                                <i class="bi bi-clock text-info fs-4"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="text-muted mb-1">Total Hours</h6>
                            <h3 class="mb-0"><?= number_format($totalHours, 1) ?>h</h3>
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
                                <i class="bi bi-file-earmark-text text-warning fs-4"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="text-muted mb-1">With Documents</h6>
                            <h3 class="mb-0 small"><?= $withTravelOrder ?> TO / <?= $withObSlip ?> OB</h3>
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
                <input type="hidden" name="page" value="guard">
                <input type="hidden" name="action" value="completed">
                <div class="col-md-3">
                    <label class="form-label">Start Date</label>
                    <input type="date" class="form-control" name="start_date" value="<?= $startDate ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">End Date</label>
                    <input type="date" class="form-control" name="end_date" value="<?= $endDate ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Search</label>
                    <input type="text" class="form-control" name="search" value="<?= e($search) ?>" placeholder="Vehicle, requester, driver, destination...">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-search me-1"></i>Filter
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Completed Trips Table -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Completed Trips (<?= count($trips) ?>)</h5>
            <button type="button" class="btn btn-success btn-sm" onclick="exportCompletedTrips()">
                <i class="bi bi-file-earmark-excel me-1"></i>Export to CSV
            </button>
        </div>
        <div class="card-body">
            <?php if (empty($trips)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-calendar-x fs-1 text-muted"></i>
                    <p class="text-muted mt-3">No completed trips found for the selected criteria.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle" id="completedTripsTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Arrival Date</th>
                                <th>Vehicle</th>
                                <th>Driver</th>
                                <th>Requester</th>
                                <th>Destination</th>
                                <th>Duration</th>
                                <th>Mileage</th>
                                <th>Documents</th>
                                <th>Dispatch</th>
                                <th>Arrival</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($trips as $trip): ?>
                                <tr>
                                    <td><strong>#<?= $trip->id ?></strong></td>
                                    <td>
                                        <div class="small">
                                            <i class="bi bi-calendar3 me-1"></i>
                                            <?= formatDate($trip->actual_arrival_datetime) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($trip->plate_number): ?>
                                            <div class="fw-medium"><?= e($trip->plate_number) ?></div>
                                            <small class="text-muted"><?= e($trip->make . ' ' . $trip->vehicle_model) ?></small>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($trip->driver_name): ?>
                                            <div class="fw-medium"><?= e($trip->driver_name) ?></div>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="fw-medium"><?= e($trip->requester_name) ?></div>
                                        <small class="text-muted"><?= e($trip->department_name) ?></small>
                                    </td>
                                    <td>
                                        <div><?= e($trip->destination) ?></div>
                                        <small class="text-muted"><?= truncate($trip->purpose, 30) ?></small>
                                    </td>
                                    <td>
                                        <?php if ($trip->actual_duration): ?>
                                            <?= floor($trip->actual_duration / 60) ?>h <?= ($trip->actual_duration % 60) ?>m
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($trip->mileage_actual): ?>
                                            <span class="fw-medium text-primary"><?= number_format($trip->mileage_actual) ?> km</span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($trip->has_travel_order): ?>
                                            <div class="mb-1">
                                                <span class="badge bg-success">TO: <?= e($trip->travel_order_number) ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($trip->has_official_business_slip): ?>
                                            <div>
                                                <span class="badge bg-primary">OB: <?= e($trip->ob_slip_number) ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!$trip->has_travel_order && !$trip->has_official_business_slip): ?>
                                            <span class="text-muted">None</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($trip->actual_dispatch_datetime): ?>
                                            <small>
                                                <i class="bi bi-box-arrow-right text-success"></i>
                                                <?= date('M/d H:i', strtotime($trip->actual_dispatch_datetime)) ?>
                                            </small>
                                            <br><small class="text-muted">by <?= e($trip->dispatch_guard_name) ?></small>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($trip->actual_arrival_datetime): ?>
                                            <small>
                                                <i class="bi bi-box-arrow-in-left text-danger"></i>
                                                <?= date('M/d H:i', strtotime($trip->actual_arrival_datetime)) ?>
                                            </small>
                                            <br><small class="text-muted">by <?= e($trip->arrival_guard_name) ?></small>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
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

<script>
function exportCompletedTrips() {
    const table = document.getElementById('completedTripsTable');
    if (!table) return;

    let csv = [];
    const headers = ['ID', 'Arrival Date', 'Vehicle Plate', 'Vehicle Model', 'Driver', 'Requester', 'Department', 'Destination', 'Purpose', 'Duration (min)', 'Mileage (km)', 'Travel Order', 'OB Slip', 'Dispatch Time', 'Arrival Time'];
    csv.push(headers.map(h => `"${h}"`).join(','));

    const rows = table.querySelectorAll('tbody tr');
    rows.forEach(row => {
        const cells = row.querySelectorAll('td');
        if (cells.length > 0) {
            const id = cells[0].textContent.trim();
            const arrivalDate = cells[1].textContent.trim().replace('Calendar', '').trim();
            const vehiclePlate = cells[2].querySelector('.fw-medium')?.textContent.trim() || '';
            const vehicleModel = cells[2].querySelector('.text-muted')?.textContent.trim() || '';
            const driver = cells[3].querySelector('.fw-medium')?.textContent.trim() || '';
            const requester = cells[4].querySelector('.fw-medium')?.textContent.trim() || '';
            const department = cells[4].querySelector('.text-muted')?.textContent.trim() || '';
            const destination = cells[5].querySelector('div')?.textContent.trim() || '';
            const purpose = cells[5].querySelector('.text-muted')?.textContent.trim() || '';
            const duration = cells[6].textContent.trim();
            const mileage = cells[7].textContent.trim();
            const travelOrder = cells[8].querySelector('.bg-success')?.textContent.trim() || '';
            const obSlip = cells[8].querySelector('.bg-primary')?.textContent.trim() || '';
            const dispatchTime = cells[9].textContent.trim().replace('Arrow-right', '').replace('Arrow-in-left', '→').trim();
            const arrivalTime = cells[10].textContent.trim().replace('Arrow-right', '').replace('Arrow-in-left', '→').trim();

            const rowData = [
                id, arrivalDate, vehiclePlate, vehicleModel, driver, requester, department,
                destination, purpose, duration, mileage, travelOrder, obSlip, dispatchTime, arrivalTime
            ].map(val => `"${String(val).replace(/"/g, '""')}"`).join(',');

            csv.push(rowData);
        }
    });

    const csvContent = csv.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'completed_trips_' + new Date().toISOString().slice(0, 10) + '.csv';
    link.click();
}
</script>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
