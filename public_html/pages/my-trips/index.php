<?php
/**
 * LOKA - Driver Schedule / My Trips Page
 * 
 * Shows drivers their assigned trips (both past and upcoming)
 */

$driver = db()->fetch(
    "SELECT d.*, u.name, u.email, u.phone 
     FROM drivers d 
     JOIN users u ON d.user_id = u.id 
     WHERE d.user_id = ? AND d.deleted_at IS NULL",
    [userId()]
);

if (!$driver) {
    redirectWith('/?page=dashboard', 'danger', 'You are not registered as a driver.');
}

$pageTitle = 'My Trips';

$filter = get('filter', 'upcoming');
$today = date('Y-m-d');

$sql = "SELECT r.*,
            u.name as requester_name, u.phone as requester_phone,
            d.name as department_name,
            v.plate_number, v.make, v.model as vehicle_model,
            mph.name as motorpool_head_name,
            tt.id as trip_ticket_id,
            tt.status as trip_ticket_status,
            tt.trip_type as trip_ticket_type
        FROM requests r
        JOIN users u ON r.user_id = u.id
        JOIN departments d ON r.department_id = d.id
        LEFT JOIN vehicles v ON r.vehicle_id = v.id AND v.deleted_at IS NULL
        LEFT JOIN users mph ON r.motorpool_head_id = mph.id
        LEFT JOIN trip_tickets tt ON r.id = tt.request_id AND tt.deleted_at IS NULL
        WHERE (r.driver_id = ? OR r.requested_driver_id = ?)
        AND r.deleted_at IS NULL";

$params = [$driver->id, $driver->id];

switch ($filter) {
    case 'past':
        $sql .= " AND r.end_datetime < NOW()";
        $sql .= " ORDER BY r.start_datetime DESC";
        break;
    case 'all':
        $sql .= " ORDER BY r.start_datetime DESC";
        break;
    case 'upcoming':
    default:
        $sql .= " AND r.end_datetime >= NOW()";
        $sql .= " ORDER BY r.start_datetime ASC";
        break;
}

$trips = db()->fetchAll($sql, $params);

$stats = [
    'upcoming' => db()->fetchColumn(
        "SELECT COUNT(*) FROM requests
         WHERE (driver_id = ? OR requested_driver_id = ?)
         AND end_datetime >= NOW()
         AND status IN (?, ?)
         AND deleted_at IS NULL",
        [$driver->id, $driver->id, STATUS_APPROVED, STATUS_PENDING_MOTORPOOL]
    ),
    'completed' => db()->fetchColumn(
        "SELECT COUNT(*) FROM requests
         WHERE driver_id = ?
         AND status = ?
         AND deleted_at IS NULL",
        [$driver->id, STATUS_COMPLETED]
    ),
    'this_month' => db()->fetchColumn(
        "SELECT COUNT(*) FROM requests
         WHERE driver_id = ?
         AND MONTH(start_datetime) = MONTH(NOW())
         AND YEAR(start_datetime) = YEAR(NOW())
         AND status IN (?, ?)
         AND deleted_at IS NULL",
        [$driver->id, STATUS_APPROVED, STATUS_COMPLETED]
    ),
    'trip_tickets_pending' => db()->fetchColumn(
        "SELECT COUNT(*) FROM trip_tickets tt
         JOIN requests r ON tt.request_id = r.id
         WHERE r.driver_id = ?
         AND tt.status = 'submitted'
         AND tt.deleted_at IS NULL",
        [$driver->id]
    ),
    'trip_tickets_approved' => db()->fetchColumn(
        "SELECT COUNT(*) FROM trip_tickets tt
         JOIN requests r ON tt.request_id = r.id
         WHERE r.driver_id = ?
         AND tt.status = 'approved'
         AND tt.deleted_at IS NULL",
        [$driver->id]
    )
];

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-truck me-2"></i>My Trips</h4>
            <p class="text-muted mb-0">View your assigned and requested trips</p>
        </div>
        <div class="d-flex gap-2">
            <span class="badge bg-light text-dark border fs-6">
                <i class="bi bi-person-badge me-1"></i><?= e($driver->name) ?>
            </span>
            <a href="?page=my-trips&action=export-pdf&filter=<?= $filter ?>" class="btn btn-success">
                <i class="bi bi-file-earmark-pdf me-1"></i>Export PDF
            </a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="bg-primary bg-opacity-10 rounded p-3">
                                <i class="bi bi-calendar-event text-primary fs-4"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="text-muted mb-1">Upcoming Trips</h6>
                            <h3 class="mb-0"><?= $stats['upcoming'] ?></h3>
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
                            <h6 class="text-muted mb-1">Completed Trips</h6>
                            <h3 class="mb-0"><?= $stats['completed'] ?></h3>
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
                            <h6 class="text-muted mb-1">This Month</h6>
                            <h3 class="mb-0"><?= $stats['this_month'] ?></h3>
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
                            <div class="bg-warning bg-opacity-10 rounded p-3">
                                <i class="bi bi-file-earmark-text text-warning fs-4"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="text-muted mb-1">Trip Tickets</h6>
                            <h3 class="mb-0">
                                <?= $stats['trip_tickets_approved'] ?>
                                <?php if ($stats['trip_tickets_pending'] > 0): ?>
                                    <span class="badge bg-warning ms-1"><?= $stats['trip_tickets_pending'] ?> pending</span>
                                <?php endif; ?>
                            </h3>
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
                    <a class="nav-link <?= $filter === 'upcoming' ? 'active' : '' ?>" 
                       href="<?= APP_URL ?>/?page=my-trips">
                        <i class="bi bi-calendar-event me-1"></i>Upcoming
                        <?php if ($stats['upcoming'] > 0): ?>
                            <span class="badge bg-primary ms-1"><?= $stats['upcoming'] ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $filter === 'past' ? 'active' : '' ?>" 
                       href="<?= APP_URL ?>/?page=my-trips&filter=past">
                        <i class="bi bi-clock-history me-1"></i>Past Trips
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $filter === 'all' ? 'active' : '' ?>" 
                       href="<?= APP_URL ?>/?page=my-trips&filter=all">
                        <i class="bi bi-list-ul me-1"></i>All Trips
                    </a>
                </li>
            </ul>
        </div>
        <div class="card-body">
            <?php if (empty($trips)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-calendar-x fs-1 text-muted"></i>
                    <p class="text-muted mt-3">
                        <?php if ($filter === 'upcoming'): ?>
                            No upcoming trips assigned to you.
                        <?php elseif ($filter === 'past'): ?>
                            No past trips found.
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
                                <th>Date & Time</th>
                                <th>Destination</th>
                                <th>Vehicle</th>
                                <th>Mileage</th>
                                <th>Requester</th>
                                <th>Status</th>
                                <th>Role</th>
                                <th>Trip Ticket</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($trips as $trip): ?>
                                <?php 
                                $isAssignedDriver = ($trip->driver_id == $driver->id);
                                $isRequestedDriver = ($trip->requested_driver_id == $driver->id && !$isAssignedDriver);
                                ?>
                                <tr>
                                    <td>
                                        <strong>#<?= $trip->id ?></strong>
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
                                        <div class="fw-medium"><?= e($trip->destination) ?></div>
                                        <small class="text-muted"><?= truncate($trip->purpose, 50) ?></small>
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
                                        <?php if ($trip->mileage_actual): ?>
                                            <div class="fw-medium text-primary"><?= number_format($trip->mileage_actual) ?> km</div>
                                            <small class="text-muted">Actual distance</small>
                                        <?php elseif ($trip->mileage_start): ?>
                                            <div class="fw-medium"><?= number_format($trip->mileage_start) ?> km</div>
                                            <small class="text-muted">Start only</small>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="fw-medium"><?= e($trip->requester_name) ?></div>
                                        <small class="text-muted"><?= e($trip->department_name) ?></small>
                                    </td>
                                    <td><?= requestStatusBadge($trip->status) ?></td>
                                    <td>
                                        <?php if ($isAssignedDriver): ?>
                                            <span class="badge bg-success">Assigned Driver</span>
                                        <?php elseif ($isRequestedDriver): ?>
                                            <span class="badge bg-warning text-dark">Requested</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <!-- Trip Ticket Status -->
                                        <?php if ($trip->trip_ticket_id): ?>
                                            <?php
                                            $ticketStatusClass = '';
                                            $ticketStatusIcon = '';
                                            switch ($trip->trip_ticket_status) {
                                                case 'submitted':
                                                    $ticketStatusClass = 'warning';
                                                    $ticketStatusIcon = 'clock';
                                                    break;
                                                case 'reviewed':
                                                    $ticketStatusClass = 'info';
                                                    $ticketStatusIcon = 'arrow-counterclockwise';
                                                    break;
                                                case 'approved':
                                                    $ticketStatusClass = 'success';
                                                    $ticketStatusIcon = 'check-circle';
                                                    break;
                                            }
                                            ?>
                                            <a href="<?= APP_URL ?>/?page=trip-tickets&action=view&id=<?= $trip->trip_ticket_id ?>"
                                               class="badge bg-<?= $ticketStatusClass ?> text-decoration-none"
                                               title="View Trip Ticket">
                                                <i class="bi bi-<?= $ticketStatusIcon ?> me-1"></i>
                                                <?= ucfirst($trip->trip_ticket_status) ?>
                                            </a>
                                        <?php elseif ($trip->status === STATUS_COMPLETED): ?>
                                            <button type="button" class="btn btn-sm btn-outline-success"
                                                    onclick="createTripTicket(<?= $trip->id ?>)"
                                                    title="Create Trip Ticket">
                                                <i class="bi bi-file-earmark-plus me-1"></i>
                                                Create Ticket
                                            </button>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="<?= APP_URL ?>/?page=requests&action=view&id=<?= $trip->id ?>"
                                               class="btn btn-outline-primary" title="View trip">
                                                <i class="bi bi-eye"></i> View
                                            </a>
                                            <?php if ($trip->status === STATUS_COMPLETED): ?>
                                            <a href="<?= APP_URL ?>/?page=requests&action=print&id=<?= $trip->id ?>"
                                               class="btn btn-outline-secondary" target="_blank" title="Print form">
                                                <i class="bi bi-printer"></i> Print
                                            </a>
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

    <?php if ($driver->status !== 'available'): ?>
    <div class="alert alert-warning mt-4">
        <i class="bi bi-exclamation-triangle me-2"></i>
        <strong>Status Notice:</strong> Your current driver status is 
        <span class="badge bg-<?= $driver->status === 'on_trip' ? 'primary' : ($driver->status === 'on_leave' ? 'warning text-dark' : 'danger') ?>">
            <?= ucfirst(str_replace('_', ' ', $driver->status)) ?>
        </span>.
        <?php if ($driver->status === 'on_leave'): ?>
            Contact motorpool to update your availability.
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<script>
function createTripTicket(requestId) {
    if (confirm('Create a trip ticket for this completed trip?')) {
        window.location.href = '<?= APP_URL ?>/?page=trip-tickets&action=create_form&request_id=' + requestId;
    }
}
</script>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
