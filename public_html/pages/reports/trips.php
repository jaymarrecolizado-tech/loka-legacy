<?php
/**
 * LOKA - Trip Requests Report
 */

requireRole(ROLE_APPROVER);

$pageTitle = 'Trip Requests Report';

$startDate = get('start_date', date('Y-m-01'));
$endDate = get('end_date', date('Y-m-t'));
$status = get('status', '');
$filterDept = get('department_id', '');
$filterVehicle = get('vehicle_id', '');
$filterDriver = get('driver_id', '');

// Dropdown data for filters
$allDepartments = db()->fetchAll("SELECT id, name FROM departments WHERE deleted_at IS NULL ORDER BY name");
$allVehicles = db()->fetchAll("SELECT v.id, v.plate_number, v.make, v.model FROM vehicles v WHERE v.deleted_at IS NULL ORDER BY v.plate_number");
$allDrivers = db()->fetchAll("SELECT d.id, u.name FROM drivers d JOIN users u ON d.user_id = u.id WHERE d.deleted_at IS NULL AND u.deleted_at IS NULL ORDER BY u.name");

// Build query with filters
$whereClause = "WHERE r.deleted_at IS NULL AND r.created_at BETWEEN ? AND ?";
$params = [$startDate, $endDate . ' 23:59:59'];

if ($status) {
    $whereClause .= " AND r.status = ?";
    $params[] = $status;
}
if ($filterDept) {
    $whereClause .= " AND r.department_id = ?";
    $params[] = $filterDept;
}
if ($filterVehicle) {
    $whereClause .= " AND r.vehicle_id = ?";
    $params[] = $filterVehicle;
}
if ($filterDriver) {
    $whereClause .= " AND r.driver_id = ?";
    $params[] = $filterDriver;
}

// Get stats
$stats = db()->fetch(
    "SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN r.status = 'approved' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN r.status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN r.status = 'rejected' THEN 1 ELSE 0 END) as rejected,
        SUM(CASE WHEN r.status IN ('pending', 'pending_motorpool') THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN r.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
        SUM(CASE WHEN r.status = 'revision' THEN 1 ELSE 0 END) as revision,
        COALESCE(SUM(r.mileage_actual), 0) as total_km
     FROM requests r
     $whereClause",
    $params
);

// Pagination
$perPage = 50;
$totalRows = (int)db()->fetch("SELECT COUNT(*) as cnt FROM requests r $whereClause", $params)->cnt;
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$pageNum = min($totalPages, max(1, (int)get('page_num', 1)));
$offset = ($pageNum - 1) * $perPage;

// Get requests
$requests = db()->fetchAll(
    "SELECT r.id, r.created_at, r.start_datetime, r.end_datetime, r.purpose, r.destination,
            r.status, r.passenger_count, r.actual_dispatch_datetime, r.actual_arrival_datetime,
            r.mileage_actual,
            u.name as requester_name, dept.name as department_name,
            v.plate_number, v.make, v.model,
            dr_user.name as driver_name,
            TIMESTAMPDIFF(MINUTE, r.start_datetime, r.end_datetime) as planned_duration,
            TIMESTAMPDIFF(MINUTE, r.actual_dispatch_datetime, r.actual_arrival_datetime) as actual_duration
     FROM requests r
     JOIN users u ON r.user_id = u.id
     LEFT JOIN departments dept ON r.department_id = dept.id
     LEFT JOIN vehicles v ON r.vehicle_id = v.id
     LEFT JOIN drivers dr ON r.driver_id = dr.id
     LEFT JOIN users dr_user ON dr.user_id = dr_user.id
     $whereClause
     ORDER BY r.created_at DESC
     LIMIT $perPage OFFSET $offset",
    $params
);

// Trips per day trend (for chart)
$dailyTrend = db()->fetchAll(
    "SELECT DATE(r.start_datetime) as trip_date, COUNT(*) as cnt
     FROM requests r
     $whereClause
     GROUP BY DATE(r.start_datetime)
     ORDER BY trip_date",
    $params
);

// Top departments by request count
$deptUsage = db()->fetchAll(
    "SELECT COALESCE(dept.name, 'Unassigned') as department, COUNT(*) as cnt,
            SUM(CASE WHEN r.status = 'completed' THEN 1 ELSE 0 END) as completed
     FROM requests r
     LEFT JOIN departments dept ON r.department_id = dept.id
     $whereClause
     GROUP BY dept.name
     ORDER BY cnt DESC
     LIMIT 8",
    $params
);

// Vehicle utilization
$vehicleUsage = db()->fetchAll(
    "SELECT COALESCE(CONCAT(v.plate_number, ' (', v.make, ')'), 'Unassigned') as vehicle, COUNT(*) as cnt
     FROM requests r
     LEFT JOIN vehicles v ON r.vehicle_id = v.id
     $whereClause AND v.id IS NOT NULL
     GROUP BY v.id, v.plate_number, v.make
     ORDER BY cnt DESC
     LIMIT 8",
    $params
);

// Status distribution (for donut chart)
$statusBreakdown = db()->fetchAll(
    "SELECT r.status, COUNT(*) as cnt
     FROM requests r
     $whereClause
     GROUP BY r.status
     ORDER BY cnt DESC",
    $params
);

// Derived metrics
$totalTrips = (int)($stats->total ?: 0);
$completedCount = (int)($stats->completed ?: 0);
$approvedCount = (int)($stats->approved ?: 0);
$approvalRate = ($totalTrips > 0) ? round((($approvedCount + $completedCount) / $totalTrips) * 100, 1) : 0;
$avgDuration = 0;
if (!empty($requests)) {
    $durations = array_filter(array_map(fn($r) => $r->actual_duration ?: $r->planned_duration, $requests));
    $avgDuration = count($durations) ? round(array_sum($durations) / count($durations)) : 0;
}

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1">Trip Requests Report</h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/?page=reports">Reports</a></li>
                    <li class="breadcrumb-item active">Trip Requests</li>
                </ol>
            </nav>
        </div>
        <?php
            $exportParams = "start_date={$startDate}&end_date={$endDate}";
            if ($status) $exportParams .= '&status=' . urlencode($status);
            if ($filterDept) $exportParams .= '&department_id=' . urlencode($filterDept);
            if ($filterVehicle) $exportParams .= '&vehicle_id=' . urlencode($filterVehicle);
            if ($filterDriver) $exportParams .= '&driver_id=' . urlencode($filterDriver);
        ?>
        <div class="btn-group">
            <a href="<?= APP_URL ?>/?page=reports&action=export&<?= $exportParams ?>" 
               class="btn btn-outline-primary">
                <i class="bi bi-file-earmark-csv me-1"></i>Export CSV
            </a>
            <a href="<?= APP_URL ?>/?page=reports&action=export-pdf&<?= $exportParams ?>" 
               class="btn btn-outline-danger">
                <i class="bi bi-file-earmark-pdf me-1"></i>Export PDF
            </a>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <input type="hidden" name="page" value="reports">
                <input type="hidden" name="action" value="trips">
                <div class="col-md-2">
                    <label class="form-label">Start Date</label>
                    <input type="date" class="form-control" name="start_date" value="<?= e($startDate) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">End Date</label>
                    <input type="date" class="form-control" name="end_date" value="<?= e($endDate) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status">
                        <option value="">All Statuses</option>
                        <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="pending_motorpool" <?= $status === 'pending_motorpool' ? 'selected' : '' ?>>Pending Motorpool</option>
                        <option value="approved" <?= $status === 'approved' ? 'selected' : '' ?>>Approved</option>
                        <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="rejected" <?= $status === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                        <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                        <option value="revision" <?= $status === 'revision' ? 'selected' : '' ?>>Revision</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Department</label>
                    <select class="form-select" name="department_id">
                        <option value="">All Departments</option>
                        <?php foreach ($allDepartments as $dept): ?>
                        <option value="<?= $dept->id ?>" <?= $filterDept == $dept->id ? 'selected' : '' ?>><?= e($dept->name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Vehicle</label>
                    <select class="form-select" name="vehicle_id">
                        <option value="">All Vehicles</option>
                        <?php foreach ($allVehicles as $vh): ?>
                        <option value="<?= $vh->id ?>" <?= $filterVehicle == $vh->id ? 'selected' : '' ?>><?= e($vh->plate_number) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Driver</label>
                    <select class="form-select" name="driver_id">
                        <option value="">All Drivers</option>
                        <?php foreach ($allDrivers as $drv): ?>
                        <option value="<?= $drv->id ?>" <?= $filterDriver == $drv->id ? 'selected' : '' ?>><?= e($drv->name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <div class="btn-group btn-group-sm me-2" role="group" aria-label="Date range presets">
                        <button type="button" class="btn btn-outline-primary date-preset" data-preset="week">This Week</button>
                        <button type="button" class="btn btn-outline-primary date-preset" data-preset="month">This Month</button>
                        <button type="button" class="btn btn-outline-primary date-preset" data-preset="last-month">Last Month</button>
                        <button type="button" class="btn btn-outline-primary date-preset" data-preset="quarter">This Quarter</button>
                        <button type="button" class="btn btn-outline-primary date-preset" data-preset="ytd">Year to Date</button>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-search me-1"></i>Filter
                    </button>
                    <a href="<?= APP_URL ?>/?page=reports&action=trips" class="btn btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row g-3 mb-4">
        <div class="col">
            <div class="card bg-primary bg-opacity-10">
                <div class="card-body text-center py-2">
                    <h4 class="text-primary mb-0"><?= $stats->total ?></h4>
                    <small class="text-muted">Total</small>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card bg-success bg-opacity-10">
                <div class="card-body text-center py-2">
                    <h4 class="text-success mb-0"><?= $stats->approved ?></h4>
                    <small class="text-muted">Approved</small>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card bg-info bg-opacity-10">
                <div class="card-body text-center py-2">
                    <h4 class="text-info mb-0"><?= $stats->completed ?></h4>
                    <small class="text-muted">Completed</small>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card bg-danger bg-opacity-10">
                <div class="card-body text-center py-2">
                    <h4 class="text-danger mb-0"><?= $stats->rejected ?></h4>
                    <small class="text-muted">Rejected</small>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card bg-warning bg-opacity-10">
                <div class="card-body text-center py-2">
                    <h4 class="text-warning mb-0"><?= $stats->pending ?></h4>
                    <small class="text-muted">Pending</small>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card bg-secondary bg-opacity-10">
                <div class="card-body text-center py-2">
                    <h4 class="text-secondary mb-0"><?= $stats->cancelled ?></h4>
                    <small class="text-muted">Cancelled</small>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card bg-dark bg-opacity-10">
                <div class="card-body text-center py-2">
                    <h4 class="text-dark mb-0"><?= number_format($stats->total_km) ?></h4>
                    <small class="text-muted">Total km</small>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card bg-success bg-opacity-10">
                <div class="card-body text-center py-2">
                    <h4 class="text-success mb-0"><?= $approvalRate ?>%</h4>
                    <small class="text-muted">Approval Rate</small>
                </div>
            </div>
        </div>
        <div class="col">
            <div class="card bg-primary bg-opacity-10">
                <div class="card-body text-center py-2">
                    <h4 class="text-primary mb-0"><?= floor($avgDuration / 60) ?>h <?= $avgDuration % 60 ?>m</h4>
                    <small class="text-muted">Avg Duration</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Analytics Charts -->
    <?php if ($stats->total > 0): ?>
    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><h6 class="mb-0"><i class="bi bi-graph-up me-2"></i>Trips Over Time</h6></div>
                <div class="card-body"><canvas id="trendChart" height="110"></canvas></div>
            </div>
        </div>
        <div class="col-lg-3">
            <div class="card h-100">
                <div class="card-header"><h6 class="mb-0"><i class="bi bi-pie-chart me-2"></i>Status Breakdown</h6></div>
                <div class="card-body"><canvas id="statusDonutChart"></canvas></div>
            </div>
        </div>
        <div class="col-lg-3">
            <div class="card h-100">
                <div class="card-header"><h6 class="mb-0"><i class="bi bi-building me-2"></i>Top Departments</h6></div>
                <div class="card-body"><canvas id="deptChart"></canvas></div>
            </div>
        </div>
        <div class="col-lg-6 offset-lg-6">
            <div class="card h-100">
                <div class="card-header"><h6 class="mb-0"><i class="bi bi-car-front me-2"></i>Vehicle Utilization</h6></div>
                <div class="card-body"><canvas id="vehicleChart" height="110"></canvas></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Requests Table -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>Trip Requests</h5>
            <small class="text-muted">
                Showing <?= $totalRows > 0 ? $offset + 1 : 0 ?>&ndash;<?= min($offset + $perPage, $totalRows) ?> of <?= number_format($totalRows) ?>
            </small>
        </div>
        <div class="card-body p-0">
            <?php if (empty($requests)): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-clipboard-x fs-1"></i>
                <p class="mt-2">No trip requests found for the selected period.</p>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Created</th>
                            <th>Scheduled</th>
                            <th>Requester</th>
                            <th>Destination</th>
                            <th>Vehicle</th>
                            <th>Driver</th>
                            <th>Status</th>
                            <th>Duration</th>
                            <th>Km</th>
                            <th>Dispatch / Arrival</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($requests as $req): ?>
                        <tr>
                            <td>
                                <a href="<?= APP_URL ?>/?page=requests&action=view&id=<?= $req->id ?>">
                                    <strong>#<?= $req->id ?></strong>
                                </a>
                            </td>
                            <td><?= formatDate($req->created_at) ?></td>
                            <td>
                                <small>
                                    <?= formatDateTime($req->start_datetime) ?><br>
                                    <span class="text-muted">to <?= formatDateTime($req->end_datetime) ?></span>
                                </small>
                            </td>
                            <td>
                                <?= e($req->requester_name) ?>
                                <small class="d-block text-muted"><?= e($req->department_name) ?></small>
                            </td>
                            <td title="<?= e($req->destination) ?>"><?= e(strlen($req->destination) > 30 ? substr($req->destination, 0, 30) . '...' : $req->destination) ?></td>
                            <td>
                                <?php if ($req->plate_number): ?>
                                <strong><?= e($req->plate_number) ?></strong>
                                <small class="d-block text-muted"><?= e($req->make) ?></small>
                                <?php else: ?>
                                <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($req->driver_name ?: '-') ?></td>
                            <td><?= requestStatusBadge($req->status) ?></td>
                            <td>
                                <?php if ($req->actual_duration): ?>
                                    <span class="text-success"><?= floor($req->actual_duration / 60) ?>h <?= $req->actual_duration % 60 ?>m</span>
                                <?php elseif ($req->planned_duration): ?>
                                    <span class="text-muted"><?= floor($req->planned_duration / 60) ?>h <?= $req->planned_duration % 60 ?>m</span>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td><?= $req->mileage_actual ? number_format($req->mileage_actual) : '-' ?></td>
                            <td>
                                <?php if ($req->actual_dispatch_datetime): ?>
                                    <small><?= formatDateTime($req->actual_dispatch_datetime) ?></small>
                                    <?php if ($req->actual_arrival_datetime): ?>
                                        <small class="text-muted d-block"><?= formatDateTime($req->actual_arrival_datetime) ?></small>
                                    <?php endif; ?>
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
        <?php if ($totalPages > 1): ?>
        <div class="card-footer">
            <?php
                $pageParams = $_GET;
                unset($pageParams['page_num']);
            ?>
            <nav aria-label="Report pagination" class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <small class="text-muted">Page <?= $pageNum ?> of <?= $totalPages ?></small>
                <ul class="pagination mb-0">
                    <li class="page-item <?= $pageNum <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($pageParams, ['page_num' => $pageNum - 1])) ?>">&laquo;</a>
                    </li>
                    <?php
                    $start = max(1, $pageNum - 2);
                    $end = min($totalPages, $pageNum + 2);
                    if ($start > 1): ?>
                        <li class="page-item"><a class="page-link" href="?<?= http_build_query(array_merge($pageParams, ['page_num' => 1])) ?>">1</a></li>
                        <?php if ($start > 2): ?><li class="page-item disabled"><span class="page-link">&hellip;</span></li><?php endif; ?>
                    <?php endif; ?>
                    <?php for ($p = $start; $p <= $end; $p++): ?>
                        <li class="page-item <?= $p === $pageNum ? 'active' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query(array_merge($pageParams, ['page_num' => $p])) ?>"><?= $p ?></a>
                        </li>
                    <?php endfor; ?>
                    <?php if ($end < $totalPages): ?>
                        <?php if ($end < $totalPages - 1): ?><li class="page-item disabled"><span class="page-link">&hellip;</span></li><?php endif; ?>
                        <li class="page-item"><a class="page-link" href="?<?= http_build_query(array_merge($pageParams, ['page_num' => $totalPages])) ?>"><?= $totalPages ?></a></li>
                    <?php endif; ?>
                    <li class="page-item <?= $pageNum >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($pageParams, ['page_num' => $pageNum + 1])) ?>">&raquo;</a>
                    </li>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($stats->total > 0): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    Chart.defaults.font.family = "'Segoe UI', Tahoma, Geneva, Verdana, sans-serif";

    const statusLabels = {
        'approved': 'Approved', 'pending': 'Pending', 'pending_motorpool': 'Motorpool',
        'completed': 'Completed', 'cancelled': 'Cancelled', 'rejected': 'Rejected', 'revision': 'Revision'
    };
    const statusColors = {
        'approved': '#198754', 'pending': '#ffc107', 'pending_motorpool': '#0dcaf0',
        'completed': '#20c997', 'cancelled': '#6c757d', 'rejected': '#dc3545', 'revision': '#fd7e14'
    };

    // Trips Over Time
    new Chart(document.getElementById('trendChart'), {
        type: 'line',
        data: {
            labels: <?= json_encode(array_map(fn($d) => date('M j', strtotime($d->trip_date)), $dailyTrend)) ?>,
            datasets: [{
                label: 'Trips',
                data: <?= json_encode(array_map(fn($d) => (int)$d->cnt, $dailyTrend)) ?>,
                borderColor: '#0d6efd',
                backgroundColor: 'rgba(13, 110, 253, 0.1)',
                fill: true,
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } },
            plugins: { legend: { display: false } }
        }
    });

    // Status Breakdown
    const statusRows = <?= json_encode(array_map(fn($s) => ['status' => $s->status, 'count' => (int)$s->cnt], $statusBreakdown)) ?>;
    new Chart(document.getElementById('statusDonutChart'), {
        type: 'doughnut',
        data: {
            labels: statusRows.map(s => statusLabels[s.status] || s.status),
            datasets: [{
                data: statusRows.map(s => s.count),
                backgroundColor: statusRows.map(s => statusColors[s.status] || '#6c757d')
            }]
        },
        options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
    });

    // Top Departments
    new Chart(document.getElementById('deptChart'), {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_map(fn($d) => $d->department, $deptUsage)) ?>,
            datasets: [{
                label: 'Requests',
                data: <?= json_encode(array_map(fn($d) => (int)$d->cnt, $deptUsage)) ?>,
                backgroundColor: ['#0d6efd', '#6610f2', '#d63384', '#dc3545', '#fd7e14', '#ffc107', '#198754', '#20c997']
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            scales: { x: { beginAtZero: true, ticks: { stepSize: 1 } } },
            plugins: { legend: { display: false } }
        }
    });

    // Vehicle Utilization
    new Chart(document.getElementById('vehicleChart'), {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_map(fn($v) => $v->vehicle, $vehicleUsage)) ?>,
            datasets: [{
                label: 'Trips',
                data: <?= json_encode(array_map(fn($v) => (int)$v->cnt, $vehicleUsage)) ?>,
                backgroundColor: 'rgba(13, 110, 253, 0.7)',
                borderColor: '#0d6efd',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } },
            plugins: { legend: { display: false } }
        }
    });
});
</script>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.date-preset').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var now = new Date();
            var start = null, end = null;
            switch (btn.dataset.preset) {
                case 'week':
                    start = new Date(now); start.setDate(now.getDate() - ((now.getDay() + 6) % 7));
                    end = now; break;
                case 'month':
                    start = new Date(now.getFullYear(), now.getMonth(), 1); end = now; break;
                case 'last-month':
                    start = new Date(now.getFullYear(), now.getMonth() - 1, 1);
                    end = new Date(now.getFullYear(), now.getMonth(), 0); break;
                case 'quarter':
                    start = new Date(now.getFullYear(), Math.floor(now.getMonth() / 3) * 3, 1); end = now; break;
                case 'ytd':
                    start = new Date(now.getFullYear(), 0, 1); end = now; break;
            }
            if (!start || !end) return;
            var form = btn.closest('form');
            if (!form) return;
            function fmt(d) {
                return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
            }
            form.querySelector('[name=start_date]').value = fmt(start);
            form.querySelector('[name=end_date]').value = fmt(end);
            form.submit();
        });
    });
});
</script>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
