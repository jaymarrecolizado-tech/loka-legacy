<?php
/**
 * LOKA - Maintenance Schedule Page
 *
 * View and manage scheduled maintenance with calendar view
 * Includes recurring maintenance reminders based on mileage and dates
 */

requireRole(ROLE_APPROVER);

$pageTitle = 'Maintenance Schedule';

$view = get('view', 'calendar'); // calendar, list, upcoming
$vehicleId = getInt('vehicle');
$month = get('month', date('Y-m'));
$page = getInt('page', 1);
$limit = 20;
$offset = ($page - 1) * $limit;

// Get all vehicles for filter
$vehicles = db()->fetchAll(
    "SELECT id, plate_number, make, model, mileage
     FROM vehicles
     WHERE deleted_at IS NULL
     ORDER BY plate_number"
);

// Build query
$sql = "SELECT mr.*,
               v.plate_number, v.make, v.model, v.mileage as current_mileage,
               reporter.name as reporter_name,
               assigned.name as assigned_name
        FROM maintenance_requests mr
        JOIN vehicles v ON mr.vehicle_id = v.id
        JOIN users reporter ON mr.reported_by = reporter.id
        LEFT JOIN users assigned ON mr.assigned_to = assigned.id
        WHERE mr.deleted_at IS NULL";

$params = [];

// Filter by vehicle
if ($vehicleId) {
    $sql .= " AND mr.vehicle_id = ?";
    $params[] = $vehicleId;
}

// Filter by view
switch ($view) {
    case 'upcoming':
        $sql .= " AND mr.status IN (?, ?)
                 AND mr.scheduled_date >= CURDATE()
                 AND mr.scheduled_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                 ORDER BY mr.scheduled_date ASC";
        $params[] = MAINTENANCE_STATUS_PENDING;
        $params[] = MAINTENANCE_STATUS_SCHEDULED;
        break;
    case 'overdue':
        $sql .= " AND mr.status IN (?, ?)
                 AND mr.scheduled_date < CURDATE()
                 ORDER BY mr.scheduled_date ASC";
        $params[] = MAINTENANCE_STATUS_PENDING;
        $params[] = MAINTENANCE_STATUS_SCHEDULED;
        break;
    case 'list':
    default:
        $sql .= " ORDER BY
            FIELD(mr.priority, 'critical', 'high', 'medium', 'low'),
            mr.scheduled_date ASC,
            mr.created_at DESC
            LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        break;
}

$maintenanceRequests = db()->fetchAll($sql, $params);

// Get total count for pagination
$totalCount = db()->fetchColumn(
    "SELECT COUNT(*) FROM maintenance_requests mr
     WHERE mr.deleted_at IS NULL" .
    ($vehicleId ? " AND mr.vehicle_id = ?" : ""),
    $vehicleId ? [$vehicleId] : []
);

$totalPages = ceil($totalCount / $limit);

// Get upcoming maintenance alerts (based on mileage and time)
$upcomingAlerts = [];

foreach ($vehicles as $vehicle) {
    // Get last completed maintenance for each type
    foreach (RECURRING_MAINTENANCE_TYPES as $type => $typeInfo) {
        $lastMaintenance = db()->fetch(
            "SELECT mr.*
             FROM maintenance_requests mr
             WHERE mr.vehicle_id = ?
             AND mr.type = ?
             AND mr.status = ?
             AND mr.deleted_at IS NULL
             ORDER BY mr.completed_at DESC
             LIMIT 1",
            [$vehicle->id, $type, MAINTENANCE_STATUS_COMPLETED]
        );

        if ($lastMaintenance && $lastMaintenance->completed_at) {
            // Calculate due dates
            $daysSinceCompleted = (time() - strtotime($lastMaintenance->completed_at)) / 86400;
            $kmSinceCompleted = $vehicle->mileage - ($lastMaintenance->mileage_at_completion ?? 0);

            $dueByDays = $typeInfo['interval_days'] ? $typeInfo['interval_days'] - $daysSinceCompleted : null;
            $dueByKm = $typeInfo['interval_km'] ? $typeInfo['interval_km'] - $kmSinceCompleted : null;

            $isDueSoon = false;
            $isOverdue = false;
            $dueReason = [];

            if ($dueByDays !== null) {
                if ($dueByDays < 0) {
                    $isOverdue = true;
                    $dueReason[] = abs($daysSinceCompleted) . ' days overdue';
                } elseif ($dueByDays <= 30) {
                    $isDueSoon = true;
                    $dueReason[] = 'Due in ' . $dueByDays . ' days';
                }
            }

            if ($dueByKm !== null) {
                if ($dueByKm < 0) {
                    $isOverdue = true;
                    $dueReason[] = abs($kmSinceCompleted) . ' km overdue';
                } elseif ($dueByKm <= 1000) {
                    $isDueSoon = true;
                    $dueReason[] = 'Due in ' . $dueByKm . ' km';
                }
            }

            if ($isDueSoon || $isOverdue) {
                $upcomingAlerts[] = [
                    'vehicle' => $vehicle,
                    'type' => $type,
                    'type_info' => $typeInfo,
                    'last_maintenance' => $lastMaintenance,
                    'is_overdue' => $isOverdue,
                    'due_reason' => implode(', ', $dueReason)
                ];
            }
        }
    }
}

// Sort alerts: overdue first, then by priority
usort($upcomingAlerts, function($a, $b) {
    if ($a['is_overdue'] && !$b['is_overdue']) return -1;
    if (!$a['is_overdue'] && $b['is_overdue']) return 1;
    return 0;
});

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-calendar-check me-2"></i>Maintenance Schedule</h4>
            <p class="text-muted mb-0">Plan and track vehicle maintenance</p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= APP_URL ?>/?page=maintenance&action=create" class="btn btn-primary">
                <i class="bi bi-plus-lg me-1"></i>New Request
            </a>
        </div>
    </div>

    <!-- Upcoming Maintenance Alerts -->
    <?php if (!empty($upcomingAlerts)): ?>
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-warning">
                <div class="card-header bg-warning bg-opacity-10">
                    <h6 class="mb-0">
                        <i class="bi bi-bell me-2"></i>
                        Upcoming Maintenance Alerts
                        <span class="badge bg-warning ms-2"><?= count($upcomingAlerts) ?></span>
                    </h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead>
                                <tr>
                                    <th>Vehicle</th>
                                    <th>Maintenance Type</th>
                                    <th>Last Completed</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($upcomingAlerts as $alert): ?>
                                <tr class="<?= $alert['is_overdue'] ? 'table-danger' : 'table-warning' ?>">
                                    <td>
                                        <div class="fw-medium"><?= e($alert['vehicle']->plate_number) ?></div>
                                        <small class="text-muted">
                                            <?= e($alert['vehicle']->make . ' ' . $alert['vehicle']->model) ?>
                                            (<?= number_format($alert['vehicle']->mileage) ?> km)
                                        </small>
                                    </td>
                                    <td>
                                        <i class="bi <?= $alert['type_info']['icon'] ?> me-1"></i>
                                        <?= $alert['type_info']['label'] ?>
                                    </td>
                                    <td>
                                        <?= formatDate($alert['last_maintenance']->completed_at) ?>
                                        <?php if ($alert['last_maintenance']->mileage_at_completion): ?>
                                            @ <?= number_format($alert['last_maintenance']->mileage_at_completion) ?> km
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($alert['is_overdue']): ?>
                                            <span class="badge bg-danger">
                                                <i class="bi bi-exclamation-triangle me-1"></i>
                                                Overdue
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">
                                                <i class="bi bi-clock me-1"></i>
                                                Due Soon
                                            </span>
                                        <?php endif; ?>
                                        <small class="d-block text-muted"><?= $alert['due_reason'] ?></small>
                                    </td>
                                    <td>
                                        <a href="<?= APP_URL ?>/?page=maintenance&action=create&vehicle_id=<?= $alert['vehicle']->id ?>&type=<?= $alert['type'] ?>"
                                           class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-plus me-1"></i>Schedule
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <input type="hidden" name="page" value="maintenance">
                <input type="hidden" name="action" value="schedule">

                <div class="col-md-3">
                    <label class="form-label">View</label>
                    <select name="view" class="form-select" onchange="this.form.submit()">
                        <option value="calendar" <?= $view === 'calendar' ? 'selected' : '' ?>>Calendar View</option>
                        <option value="list" <?= $view === 'list' ? 'selected' : '' ?>>List View</option>
                        <option value="upcoming" <?= $view === 'upcoming' ? 'selected' : '' ?>>Upcoming (30 days)</option>
                        <option value="overdue" <?= $view === 'overdue' ? 'selected' : '' ?>>Overdue</option>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Vehicle</label>
                    <select name="vehicle" class="form-select" onchange="this.form.submit()">
                        <option value="">All Vehicles</option>
                        <?php foreach ($vehicles as $v): ?>
                        <option value="<?= $v->id ?>" <?= $vehicleId === $v->id ? 'selected' : '' ?>>
                            <?= e($v->plate_number) ?> - <?= e($v->make . ' ' . $v->model) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-filter me-1"></i>Apply Filters
                    </button>
                    <a href="<?= APP_URL ?>/?page=maintenance&action=schedule" class="btn btn-outline-secondary ms-2">
                        <i class="bi bi-x-circle me-1"></i>Clear
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Calendar View -->
    <?php if ($view === 'calendar'): ?>
    <div class="card">
        <div class="card-header bg-white">
            <h6 class="mb-0">
                <i class="bi bi-calendar3 me-2"></i>
                <?= date('F Y', strtotime($month . '-01')) ?>
            </h6>
        </div>
        <div class="card-body">
            <?php
            // Generate calendar
            $timestamp = strtotime($month . '-01');
            $daysInMonth = date('t', $timestamp);
            $firstDayOfWeek = date('N', $timestamp) - 1; // 0 = Monday
            $today = date('Y-m-d');

            // Get maintenance for this month
            $monthStart = date('Y-m-01', $timestamp);
            $monthEnd = date('Y-m-t', $timestamp);

            $calendarEvents = [];
            $monthMaintenance = db()->fetchAll(
                "SELECT mr.*, v.plate_number
                 FROM maintenance_requests mr
                 JOIN vehicles v ON mr.vehicle_id = v.id
                 WHERE mr.scheduled_date BETWEEN ? AND ?
                 AND mr.deleted_at IS NULL" .
                ($vehicleId ? " AND mr.vehicle_id = ?" : "") .
                " ORDER BY mr.scheduled_date ASC",
                $vehicleId ? [$monthStart, $monthEnd, $vehicleId] : [$monthStart, $monthEnd]
            );

            foreach ($monthMaintenance as $m) {
                $day = date('j', strtotime($m->scheduled_date));
                if (!isset($calendarEvents[$day])) {
                    $calendarEvents[$day] = [];
                }
                $calendarEvents[$day][] = $m;
            }
            ?>
            <div class="table-responsive">
                <table class="table table-bordered calendar-table">
                    <thead>
                        <tr>
                            <th class="text-center" style="width: 14%">Mon</th>
                            <th class="text-center" style="width: 14%">Tue</th>
                            <th class="text-center" style="width: 14%">Wed</th>
                            <th class="text-center" style="width: 14%">Thu</th>
                            <th class="text-center" style="width: 14%">Fri</th>
                            <th class="text-center" style="width: 14%">Sat</th>
                            <th class="text-center" style="width: 14%">Sun</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $day = 1;
                        $rows = ceil(($daysInMonth + $firstDayOfWeek) / 7);

                        for ($row = 0; $row < $rows; $row++):
                        ?>
                        <tr style="height: 120px;">
                            <?php for ($col = 0; $col < 7; $col++): ?>
                                <?php
                                $cellDay = null;
                                $isToday = false;

                                if ($row === 0 && $col < $firstDayOfWeek) {
                                    // Empty cell before month starts
                                } elseif ($day > $daysInMonth) {
                                    // Empty cell after month ends
                                } else {
                                    $cellDay = $day;
                                    $isToday = (date('Y-m-d') === date('Y-m-d', strtotime($month . '-' . sprintf('%02d', $day))));
                                    $day++;
                                }
                                ?>
                                <td class="<?= $isToday ? 'table-primary' : '' ?> <?= $cellDay === null ? 'bg-light' : '' ?>" valign="top">
                                    <?php if ($cellDay): ?>
                                        <div class="d-flex justify-content-between">
                                            <span class="badge <?= $isToday ? 'bg-primary' : 'bg-secondary' ?>">
                                                <?= $cellDay ?>
                                            </span>
                                        </div>
                                        <?php if (isset($calendarEvents[$cellDay])): ?>
                                            <div class="mt-1">
                                                <?php foreach ($calendarEvents[$cellDay] as $event): ?>
                                                    <?php
                                                    $statusColors = [
                                                        'pending' => 'warning',
                                                        'scheduled' => 'info',
                                                        'in_progress' => 'primary',
                                                        'completed' => 'success',
                                                        'cancelled' => 'secondary'
                                                    ];
                                                    $color = $statusColors[$event->status] ?? 'secondary';
                                                    ?>
                                                    <a href="<?= APP_URL ?>/?page=maintenance&action=view&id=<?= $event->id ?>"
                                                       class="d-block mb-1 p-1 rounded bg-<?= $color ?> bg-opacity-10 text-decoration-none">
                                                        <small>
                                                            <strong>#<?= $event->id ?></strong>
                                                            <?= e($event->plate_number) ?>
                                                            <br>
                                                            <?= truncate(e($event->title), 20) ?>
                                                        </small>
                                                    </a>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            <?php endfor; ?>
                        </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- List View -->
    <?php if ($view !== 'calendar'): ?>
    <div class="card">
        <div class="card-body">
            <?php if (empty($maintenanceRequests)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-calendar-x fs-1 text-muted"></i>
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
                                        <?php
                                        $typeInfo = MAINTENANCE_TYPES[$req->type] ?? null;
                                        if (!$typeInfo && isset(RECURRING_MAINTENANCE_TYPES[$req->type])) {
                                            $typeInfo = RECURRING_MAINTENANCE_TYPES[$req->type];
                                        }
                                        if ($typeInfo):
                                        ?>
                                        <span class="badge bg-light text-dark">
                                            <i class="bi <?= $typeInfo['icon'] ?> me-1"></i>
                                            <?= $typeInfo['label'] ?>
                                        </span>
                                        <?php else: ?>
                                        <span class="badge bg-light text-dark"><?= ucfirst($req->type) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php $priorityInfo = MAINTENANCE_PRIORITIES[$req->priority] ?? ['label' => ucfirst($req->priority), 'color' => 'secondary']; ?>
                                        <span class="badge bg-<?= $priorityInfo['color'] ?>">
                                            <?= $priorityInfo['label'] ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($req->scheduled_date): ?>
                                            <?php
                                            $isOverdue = $req->status === MAINTENANCE_STATUS_PENDING && strtotime($req->scheduled_date) < time();
                                            ?>
                                            <?php if ($isOverdue): ?>
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

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <nav class="mt-3">
                    <ul class="pagination justify-content-center">
                        <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=maintenance&action=schedule&view=<?= $view ?>&vehicle=<?= $vehicleId ?>&p=<?= $page - 1 ?>">
                                <i class="bi bi-chevron-left"></i>
                            </a>
                        </li>
                        <?php endif; ?>

                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                            <a class="page-link" href="?page=maintenance&action=schedule&view=<?= $view ?>&vehicle=<?= $vehicleId ?>&p=<?= $i ?>">
                                <?= $i ?>
                            </a>
                        </li>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=maintenance&action=schedule&view=<?= $view ?>&vehicle=<?= $vehicleId ?>&p=<?= $page + 1 ?>">
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<style>
.calendar-table td {
    min-height: 100px;
    position: relative;
}
.calendar-table .badge {
    font-size: 0.75rem;
}
</style>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
