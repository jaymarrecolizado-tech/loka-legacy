<?php
/**
 * LOKA - Completed Trips Page
 *
 * Role-based view of completed trips:
 * - Driver: Only their completed trips
 * - Guard: Trips they dispatched/received
 * - Approver: Department completed trips
 * - Motorpool Head: All completed trips
 * - Admin: All completed trips
 */

$pageTitle = 'Completed Trips';

$role = userRole();
$showAll = get('all', '1'); // Default to show all completed trips
$search = get('search', '');
$page = getInt('p', 1); // Use 'p' for pagination, not 'page' (which is for routing)
$perPage = getInt('per_page', 25); // Records per page: 10, 25, 50, 100
$limit = in_array($perPage, [10, 25, 50, 100]) ? $perPage : 25;
$offset = ($page - 1) * $limit;

// Sorting
$allowedSortColumns = [
    'id' => 'r.id',
    'completed_date' => 'r.actual_arrival_datetime',
    'plate_number' => 'v.plate_number',
    'driver_name' => 'driver_user.name',
    'requester_name' => 'u.name',
    'destination' => 'r.destination',
    'duration' => 'actual_duration',
    'mileage' => 'r.mileage_actual'
];
$sort = get('sort', 'completed_date');
$sortDir = get('dir', 'DESC');
$sortDir = in_array(strtoupper($sortDir), ['ASC', 'DESC']) ? strtoupper($sortDir) : 'DESC';
$sortColumn = $allowedSortColumns[$sort] ?? 'r.actual_arrival_datetime';

// Date filter - only apply if not showing all
$startDate = null;
$endDate = null;
if ($showAll !== '1') {
    $startDate = get('start_date', date('Y-m-01'));
    $endDate = get('end_date', date('Y-m-t'));
}

// Check if user is a driver
$driver = db()->fetch(
    "SELECT id FROM drivers WHERE user_id = ? AND deleted_at IS NULL",
    [userId()]
);
$isDriver = ($driver !== null);

// Build base query with role-based filtering
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
     WHERE r.status = ?
     AND r.actual_arrival_datetime IS NOT NULL
     AND r.deleted_at IS NULL";

$params = [STATUS_COMPLETED];

// Role-based filtering
if ($isDriver) {
    // Drivers see only their completed trips
    $sql .= " AND (r.driver_id = ? OR r.requested_driver_id = ?)";
    $params[] = $driver->id;
    $params[] = $driver->id;
} elseif ($role === ROLE_GUARD) {
    // Guards see trips they dispatched or received
    $sql .= " AND (r.dispatch_guard_id = ? OR r.arrival_guard_id = ?)";
    $params[] = userId();
    $params[] = userId();
} elseif ($role === ROLE_APPROVER) {
    // Approvers see completed trips from their department
    $userDepartmentId = db()->fetchColumn(
        "SELECT department_id FROM users WHERE id = ?",
        [userId()]
    );
    $sql .= " AND r.department_id = ?";
    $params[] = $userDepartmentId;
} elseif ($role === ROLE_MOTORPOOL || $role === ROLE_ADMIN) {
    // Motorpool heads and admins see all completed trips
    // No additional filtering needed
} else {
    // Requesters see only their own completed trips
    $sql .= " AND r.user_id = ?";
    $params[] = userId();
}

// Apply date range filter (only if specific dates are set)
if ($startDate && $endDate) {
    $sql .= " AND DATE(r.actual_arrival_datetime) BETWEEN ? AND ?";
    $params[] = $startDate;
    $params[] = $endDate . ' 23:59:59';
}

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

// Get total count for pagination
$countSql = str_replace(
    "SELECT r.*,\n            u.name as requester_name, u.phone as requester_phone,\n            d.name as department_name,\n            v.plate_number, v.make, v.model as vehicle_model, v.color,\n            dr.license_number as driver_license,\n            driver_user.name as driver_name, driver_user.phone as driver_phone,\n            dispatch_guard.name as dispatch_guard_name,\n            arrival_guard.name as arrival_guard_name,\n            TIMESTAMPDIFF(MINUTE, r.actual_dispatch_datetime, r.actual_arrival_datetime) as actual_duration",
    "SELECT COUNT(*) as total",
    $sql
);
$countSql = preg_replace('/ORDER BY.*/', '', $countSql);
$totalCount = db()->fetchColumn($countSql, $params);
$totalPages = ceil($totalCount / $limit);

// Add ordering and pagination
$sql .= " ORDER BY {$sortColumn} {$sortDir} LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

$trips = db()->fetchAll($sql, $params);

// Calculate statistics (without pagination)
$orderByPattern = '/ ORDER BY [^ ]+ (ASC|DESC)/';
$statsSql = preg_replace($orderByPattern, '', $sql);
$statsSql = preg_replace('/LIMIT \? OFFSET \?$/', '', $statsSql);
$allTripsForStats = db()->fetchAll($statsSql, array_slice($params, 0, -2));

$totalTrips = count($allTripsForStats);
$totalDistance = 0;
$totalHours = 0;
$totalPassengers = 0;

foreach ($allTripsForStats as $t) {
    if ($t->mileage_actual) $totalDistance += $t->mileage_actual;
    if ($t->actual_duration) $totalHours += $t->actual_duration / 60;
    $passengerCount = db()->fetchColumn(
        "SELECT COUNT(*) FROM request_passengers WHERE request_id = ?",
        [$t->id]
    );
    $totalPassengers += $passengerCount;
}

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-check-all me-2"></i>Completed Trips</h4>
            <p class="text-muted mb-0">
                <?php if ($isDriver): ?>
                    Your completed trip history
                <?php elseif ($role === ROLE_GUARD): ?>
                    Trips you tracked at the gate
                <?php elseif ($role === ROLE_APPROVER): ?>
                    Your department's completed trips
                <?php elseif (in_array($role, [ROLE_MOTORPOOL, ROLE_ADMIN])): ?>
                    All completed trips in the system
                <?php else: ?>
                    Your completed trip history
                <?php endif; ?>
            </p>
        </div>
        <div>
            <button type="button" class="btn btn-success" onclick="exportCompletedTrips()">
                <i class="bi bi-file-earmark-excel me-1"></i>Export CSV
            </button>
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
                                <i class="bi bi-people text-warning fs-4"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="text-muted mb-1">Total Passengers</h6>
                            <h3 class="mb-0"><?= $totalPassengers ?></h3>
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
                <input type="hidden" name="page" value="completed-trips">
                <div class="col-md-2">
                    <label class="form-label">Date Range</label>
                    <select class="form-select" name="all" onchange="this.form.submit()">
                        <option value="1" <?= $showAll === '1' ? 'selected' : '' ?>>All Time</option>
                        <option value="0" <?= $showAll !== '1' ? 'selected' : '' ?>>Custom Range</option>
                    </select>
                </div>
                <?php if ($showAll !== '1'): ?>
                <div class="col-md-2">
                    <label class="form-label">Start Date</label>
                    <input type="date" class="form-control" name="start_date" value="<?= $startDate ?? date('Y-m-01') ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">End Date</label>
                    <input type="date" class="form-control" name="end_date" value="<?= $endDate ?? date('Y-m-t') ?>">
                </div>
                <?php endif; ?>
                <div class="<?= $showAll === '1' ? 'col-md-3' : 'col-md-2' ?>">
                    <label class="form-label">Per Page</label>
                    <select class="form-select" name="per_page" onchange="this.form.submit()">
                        <option value="10" <?= $limit === 10 ? 'selected' : '' ?>>10</option>
                        <option value="25" <?= $limit === 25 ? 'selected' : '' ?>>25</option>
                        <option value="50" <?= $limit === 50 ? 'selected' : '' ?>>50</option>
                        <option value="100" <?= $limit === 100 ? 'selected' : '' ?>>100</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Search</label>
                    <input type="text" class="form-control" name="search" value="<?= e($search) ?>"
                           placeholder="Vehicle, requester, driver, destination...">
                </div>
                <div class="col-md-2 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-primary flex-grow-1">
                        <i class="bi bi-search me-1"></i>Filter
                    </button>
                    <a href="<?= APP_URL ?>/?page=completed-trips" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-clockwise"></i>
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Completed Trips Table -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div>
                <h5 class="mb-0">Completed Trips (<?= $totalCount ?> total)</h5>
                <small class="text-muted">
                    <?php if ($showAll === '1'): ?>
                        Showing all time • Page <?= $page ?> of <?= $totalPages ?>
                    <?php else: ?>
                        Showing from <?= formatDate($startDate) ?> to <?= formatDate($endDate) ?> • Page <?= $page ?> of <?= $totalPages ?>
                    <?php endif; ?>
                </small>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="text-muted small">
                    <?= ($page - 1) * $limit + 1 ?>-<?= min($page * $limit, $totalCount) ?> of <?= $totalCount ?>
                </span>
            </div>
        </div>
        <div class="card-body">
            <?php
function buildSortUrl($column, $currentSort, $currentDir, $baseParams) {
    $newDir = ($currentSort === $column && $currentDir === 'DESC') ? 'ASC' : 'DESC';
    $params = array_merge($baseParams, ['sort' => $column, 'dir' => $newDir, 'p' => 1]);
    return '?' . http_build_query(array_filter($params));
}

$baseParams = [
    'page' => 'completed-trips',
    'all' => $showAll,
    'per_page' => $perPage,
    'search' => $search
];
if ($startDate) $baseParams['start_date'] = $startDate;
if ($endDate) $baseParams['end_date'] = $endDate;
?>
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
                                <th><a href="<?= buildSortUrl('id', $sort, $sortDir, $baseParams) ?>" class="text-decoration-none text-dark<?= $sort === 'id' ? ' text-primary' : '' ?>" style="cursor:pointer">ID <?= $sort === 'id' ? ($sortDir === 'ASC' ? '<i class="bi bi-arrow-up"></i>' : '<i class="bi bi-arrow-down"></i>') : '' ?></a></th>
                                <th><a href="<?= buildSortUrl('completed_date', $sort, $sortDir, $baseParams) ?>" class="text-decoration-none text-dark<?= $sort === 'completed_date' ? ' text-primary' : '' ?>" style="cursor:pointer">Completed Date <?= $sort === 'completed_date' ? ($sortDir === 'ASC' ? '<i class="bi bi-arrow-up"></i>' : '<i class="bi bi-arrow-down"></i>') : '' ?></a></th>
                                <th><a href="<?= buildSortUrl('plate_number', $sort, $sortDir, $baseParams) ?>" class="text-decoration-none text-dark<?= $sort === 'plate_number' ? ' text-primary' : '' ?>" style="cursor:pointer">Vehicle <?= $sort === 'plate_number' ? ($sortDir === 'ASC' ? '<i class="bi bi-arrow-up"></i>' : '<i class="bi bi-arrow-down"></i>') : '' ?></a></th>
                                <th><a href="<?= buildSortUrl('driver_name', $sort, $sortDir, $baseParams) ?>" class="text-decoration-none text-dark<?= $sort === 'driver_name' ? ' text-primary' : '' ?>" style="cursor:pointer">Driver <?= $sort === 'driver_name' ? ($sortDir === 'ASC' ? '<i class="bi bi-arrow-up"></i>' : '<i class="bi bi-arrow-down"></i>') : '' ?></a></th>
                                <th><a href="<?= buildSortUrl('requester_name', $sort, $sortDir, $baseParams) ?>" class="text-decoration-none text-dark<?= $sort === 'requester_name' ? ' text-primary' : '' ?>" style="cursor:pointer">Requester <?= $sort === 'requester_name' ? ($sortDir === 'ASC' ? '<i class="bi bi-arrow-up"></i>' : '<i class="bi bi-arrow-down"></i>') : '' ?></a></th>
                                <th><a href="<?= buildSortUrl('destination', $sort, $sortDir, $baseParams) ?>" class="text-decoration-none text-dark<?= $sort === 'destination' ? ' text-primary' : '' ?>" style="cursor:pointer">Destination <?= $sort === 'destination' ? ($sortDir === 'ASC' ? '<i class="bi bi-arrow-up"></i>' : '<i class="bi bi-arrow-down"></i>') : '' ?></a></th>
                                <th><a href="<?= buildSortUrl('duration', $sort, $sortDir, $baseParams) ?>" class="text-decoration-none text-dark<?= $sort === 'duration' ? ' text-primary' : '' ?>" style="cursor:pointer">Duration <?= $sort === 'duration' ? ($sortDir === 'ASC' ? '<i class="bi bi-arrow-up"></i>' : '<i class="bi bi-arrow-down"></i>') : '' ?></a></th>
                                <th><a href="<?= buildSortUrl('mileage', $sort, $sortDir, $baseParams) ?>" class="text-decoration-none text-dark<?= $sort === 'mileage' ? ' text-primary' : '' ?>" style="cursor:pointer">Mileage <?= $sort === 'mileage' ? ($sortDir === 'ASC' ? '<i class="bi bi-arrow-up"></i>' : '<i class="bi bi-arrow-down"></i>') : '' ?></a></th>
                                <th>Passengers</th>
                                <?php if (in_array($role, [ROLE_MOTORPOOL, ROLE_ADMIN])): ?>
                                <th>Dispatch</th>
                                <th>Arrival</th>
                                <?php endif; ?>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($trips as $trip): ?>
                            <?php
                            $passengerCount = db()->fetchColumn(
                                "SELECT COUNT(*) FROM request_passengers WHERE request_id = ?",
                                [$trip->id]
                            );
                            ?>
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
                                        <?php if ($trip->driver_license): ?>
                                        <small class="text-muted"><?= e($trip->driver_license) ?></small>
                                        <?php endif; ?>
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
                                    <span class="badge bg-info"><?= $passengerCount ?></span>
                                </td>
                                <?php if (in_array($role, [ROLE_MOTORPOOL, ROLE_ADMIN])): ?>
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
                                <?php endif; ?>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="<?= APP_URL ?>/?page=requests&action=view&id=<?= $trip->id ?>"
                                           class="btn btn-outline-primary" title="View trip">
                                            <i class="bi bi-eye"></i> View
                                        </a>
                                        <a href="<?= APP_URL ?>/?page=requests&action=print&id=<?= $trip->id ?>"
                                           class="btn btn-outline-secondary" target="_blank" title="Print form">
                                            <i class="bi bi-printer"></i> Print
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <nav class="mt-3" aria-label="Trips pagination">
                    <ul class="pagination justify-content-center">
                        <?php
                        // Build base query string for pagination
                        $queryParams = [
                            'page' => 'completed-trips',
                            'p' => null, // Will be set per link
                            'per_page' => $limit
                        ];
                        if ($showAll !== '1') $queryParams['all'] = $showAll;
                        if ($startDate) $queryParams['start_date'] = $startDate;
                        if ($endDate) $queryParams['end_date'] = $endDate;
                        if ($search) $queryParams['search'] = $search;

                        function buildPageUrl($params, $pageNum) {
                            $params['p'] = $pageNum;
                            return '?' . http_build_query(array_filter($params));
                        }
                        ?>

                        <!-- First Page & Previous -->
                        <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="<?= buildPageUrl($queryParams, 1) ?>" aria-label="First">
                                <i class="bi bi-chevron-bar-left"></i>
                            </a>
                        </li>
                        <li class="page-item">
                            <a class="page-link" href="<?= buildPageUrl($queryParams, $page - 1) ?>" aria-label="Previous">
                                <i class="bi bi-chevron-left"></i>
                            </a>
                        </li>
                        <?php else: ?>
                        <li class="page-item disabled">
                            <span class="page-link"><i class="bi bi-chevron-bar-left"></i></span>
                        </li>
                        <li class="page-item disabled">
                            <span class="page-link"><i class="bi bi-chevron-left"></i></span>
                        </li>
                        <?php endif; ?>

                        <!-- Page Numbers -->
                        <?php
                        // Always show first page
                        if ($page > 3) {
                            echo '<li class="page-item"><a class="page-link" href="' . buildPageUrl($queryParams, 1) . '">1</a></li>';
                            if ($page > 4) {
                                echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                            }
                        }

                        // Show range around current page
                        $startPage = max(1, $page - 1);
                        $endPage = min($totalPages, $page + 1);

                        for ($i = $startPage; $i <= $endPage; $i++):
                        ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                            <?php if ($i === $page): ?>
                                <span class="page-link"><?= $i ?></span>
                            <?php else: ?>
                                <a class="page-link" href="<?= buildPageUrl($queryParams, $i) ?>"><?= $i ?></a>
                            <?php endif; ?>
                        </li>
                        <?php endfor; ?>

                        <!-- Always show last page -->
                        <?php if ($page < $totalPages - 2) {
                            if ($page < $totalPages - 3) {
                                echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                            }
                            echo '<li class="page-item"><a class="page-link" href="' . buildPageUrl($queryParams, $totalPages) . '">' . $totalPages . '</a></li>';
                        } ?>

                        <!-- Next & Last Page -->
                        <?php if ($page < $totalPages): ?>
                        <li class="page-item">
                            <a class="page-link" href="<?= buildPageUrl($queryParams, $page + 1) ?>" aria-label="Next">
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        </li>
                        <li class="page-item">
                            <a class="page-link" href="<?= buildPageUrl($queryParams, $totalPages) ?>" aria-label="Last">
                                <i class="bi bi-chevron-bar-right"></i>
                            </a>
                        </li>
                        <?php else: ?>
                        <li class="page-item disabled">
                            <span class="page-link"><i class="bi bi-chevron-right"></i></span>
                        </li>
                        <li class="page-item disabled">
                            <span class="page-link"><i class="bi bi-chevron-bar-right"></i></span>
                        </li>
                        <?php endif; ?>
                    </ul>

                    <!-- Pagination Info -->
                    <div class="text-center mt-2">
                        <span class="text-muted small">
                            Showing <?= ($totalCount > 0 ? ($page - 1) * $limit + 1 : 0) ?>-<?= min($page * $limit, $totalCount) ?> of <?= $totalCount ?> trips
                            (Page <?= $page ?> of <?= $totalPages ?>)
                        </span>
                    </div>
                </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function exportCompletedTrips() {
    const table = document.getElementById('completedTripsTable');
    if (!table) return;

    let csv = [];
    const headers = ['ID', 'Completed Date', 'Vehicle Plate', 'Vehicle Model', 'Driver', 'Requester', 'Department', 'Destination', 'Purpose', 'Duration (min)', 'Mileage (km)', 'Passengers'];
    csv.push(headers.map(h => `"${h}"`).join(','));

    const rows = table.querySelectorAll('tbody tr');
    rows.forEach(row => {
        const cells = row.querySelectorAll('td');
        if (cells.length > 0) {
            const id = cells[0].textContent.trim();
            const completedDate = cells[1].textContent.trim().replace('Calendar', '').trim();
            const vehiclePlate = cells[2].querySelector('.fw-medium')?.textContent.trim() || '';
            const vehicleModel = cells[2].querySelector('.text-muted')?.textContent.trim() || '';
            const driver = cells[3].querySelector('.fw-medium')?.textContent.trim() || '';
            const requester = cells[4].querySelector('.fw-medium')?.textContent.trim() || '';
            const department = cells[4].querySelector('.text-muted')?.textContent.trim() || '';
            const destination = cells[5].querySelector('div')?.textContent.trim() || '';
            const purpose = cells[5].querySelector('.text-muted')?.textContent.trim() || '';
            const duration = cells[6].textContent.trim();
            const mileage = cells[7].textContent.trim();
            const passengers = cells[8].textContent.trim();

            const rowData = [
                id, completedDate, vehiclePlate, vehicleModel, driver, requester, department,
                destination, purpose, duration, mileage, passengers
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
