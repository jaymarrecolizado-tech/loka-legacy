<?php
/**
 * LOKA - Dashboard Page
 */

$pageTitle = 'Dashboard';

// Get statistics
$userId = userId();
$userRole = userRole();
$departmentId = currentUser()->department_id;

// Pagination for Recent Activity
$perPage = 10;
$currentPage = max(1, (int) get('p', 1));
$offset = ($currentPage - 1) * $perPage;

// Dashboard statistics with error handling
try {
    $myRequestsCount = db()->count('requests', 'user_id = ? AND deleted_at IS NULL', [$userId]);
} catch (Exception $e) {
    error_log("Dashboard stats error (my requests): " . $e->getMessage());
    $myRequestsCount = 0;
}

// Pending Approvals count (based on role)
$pendingApprovalsCount = 0;
try {
    if (isMotorpool()) {
        $pendingApprovalsCount = db()->count('requests', "status = 'pending_motorpool' AND deleted_at IS NULL");
    } elseif (isApprover()) {
        $pendingApprovalsCount = db()->count('requests', "status = 'pending' AND department_id = ? AND deleted_at IS NULL", [$departmentId]);
    }
} catch (Exception $e) {
    error_log("Dashboard stats error (pending approvals): " . $e->getMessage());
    $pendingApprovalsCount = 0;
}

// Available Vehicles count
try {
    $availableVehiclesCount = db()->count('vehicles', "status = 'available' AND deleted_at IS NULL");
} catch (Exception $e) {
    error_log("Dashboard stats error (available vehicles): " . $e->getMessage());
    $availableVehiclesCount = 0;
}

// Active Drivers count
try {
    $activeDriversCount = db()->count('drivers', "status = 'available' AND deleted_at IS NULL");
} catch (Exception $e) {
    error_log("Dashboard stats error (active drivers): " . $e->getMessage());
    $activeDriversCount = 0;
}

// Upcoming Trips (approved requests starting in next 7 days) - optimized with subquery
try {
    $upcomingTrips = db()->fetchAll(
        "SELECT r.*, u.name as requester_name, v.plate_number, v.make, v.model
         FROM (
             SELECT * FROM requests
             WHERE status = 'approved' 
             AND start_datetime BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
             AND deleted_at IS NULL
             ORDER BY start_datetime ASC
             LIMIT 5
         ) r
         LEFT JOIN users u ON r.user_id = u.id
         LEFT JOIN vehicles v ON r.vehicle_id = v.id AND v.deleted_at IS NULL"
    );
} catch (Exception $e) {
    error_log("Dashboard error (upcoming trips): " . $e->getMessage());
    $upcomingTrips = [];
}

// Recent Activity with pagination (my requests or all for admin)
if (isAdmin()) {
    $totalActivity = db()->count('requests', 'deleted_at IS NULL');
    $recentActivity = db()->fetchAll(
        "SELECT r.*, u.name as requester_name, d.name as department_name
         FROM requests r
         JOIN users u ON r.user_id = u.id
         JOIN departments d ON r.department_id = d.id
         WHERE r.deleted_at IS NULL
         ORDER BY r.updated_at DESC
         LIMIT ? OFFSET ?",
        [$perPage, $offset]
    );
} else {
    $totalActivity = db()->count('requests', 'user_id = ? AND deleted_at IS NULL', [$userId]);
    $recentActivity = db()->fetchAll(
        "SELECT r.*, u.name as requester_name, d.name as department_name
         FROM requests r
         JOIN users u ON r.user_id = u.id
         JOIN departments d ON r.department_id = d.id
         WHERE r.user_id = ? AND r.deleted_at IS NULL
         ORDER BY r.updated_at DESC
         LIMIT ? OFFSET ?",
        [$userId, $perPage, $offset]
    );
}

$totalPages = ceil($totalActivity / $perPage);

// Vehicle status distribution (for motorpool/admin)
$vehicleStats = [];
if (isMotorpool()) {
    $vehicleStats = db()->fetchAll(
        "SELECT status, COUNT(*) as count FROM vehicles WHERE deleted_at IS NULL GROUP BY status"
    );
}

require_once INCLUDES_PATH . '/header.php';

// Analytics Data for Charts (only for admin/motorpool/approver)
$analyticsData = null;
$showCharts = isAdmin() || isMotorpool() || isApprover();

if ($showCharts) {
    // Get data for the last 30 days
    $thirtyDaysAgo = date('Y-m-d', strtotime('-30 days'));
    $sevenDaysAgo = date('Y-m-d', strtotime('-7 days'));
    $today = date('Y-m-d');

    // Daily trip counts (last 7 days)
    $dailyTrips = db()->fetchAll(
        "SELECT DATE(start_datetime) as trip_date, COUNT(*) as count
         FROM requests
         WHERE DATE(start_datetime) >= ?
         AND deleted_at IS NULL
         GROUP BY DATE(start_datetime)
         ORDER BY trip_date ASC",
        [$sevenDaysAgo]
    );

    // Fill missing dates with 0
    $dailyTripData = [];
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $count = 0;
        foreach ($dailyTrips as $dt) {
            if ($dt->trip_date === $date) {
                $count = $dt->count;
                break;
            }
        }
        $dailyTripData[] = [
            'date' => date('M/d', strtotime($date)),
            'count' => $count
        ];
    }

    // Status distribution
    $statusDistribution = db()->fetchAll(
        "SELECT status, COUNT(*) as count
         FROM requests
         WHERE created_at >= ?
         AND deleted_at IS NULL
         GROUP BY status",
        [$thirtyDaysAgo]
    );

    // Department trip counts (last 30 days)
    $departmentStats = db()->fetchAll(
        "SELECT d.name as department, COUNT(*) as count
         FROM requests r
         JOIN departments d ON r.department_id = d.id
         WHERE r.created_at >= ?
         AND r.deleted_at IS NULL
         GROUP BY d.name
         ORDER BY count DESC
         LIMIT 8",
        [$thirtyDaysAgo]
    );

    // Vehicle utilization (last 30 days)
    $vehicleUtilization = db()->fetchAll(
        "SELECT v.plate_number,
                 COUNT(r.id) as trip_count,
                 COALESCE(SUM(r.mileage_actual), 0) as total_mileage
         FROM vehicles v
         LEFT JOIN requests r ON r.vehicle_id = v.id
             AND r.status = 'completed'
             AND r.actual_arrival_datetime >= ?
             AND r.deleted_at IS NULL
         WHERE v.deleted_at IS NULL
         GROUP BY v.id
         ORDER BY trip_count DESC
         LIMIT 10",
        [$thirtyDaysAgo]
    );

    // Peak hours analysis
    $peakHours = db()->fetchAll(
        "SELECT HOUR(start_datetime) as hour, COUNT(*) as count
         FROM requests
         WHERE DATE(start_datetime) >= ?
         AND deleted_at IS NULL
         GROUP BY HOUR(start_datetime)
         ORDER BY hour ASC",
        [$thirtyDaysAgo]
    );

    // Fill all hours (0-23) with 0
    $hourlyData = array_fill(0, 24, 0);
    foreach ($peakHours as $ph) {
        $hourlyData[$ph->hour] = $ph->count;
    }

    $analyticsData = [
        'dailyTrips' => $dailyTripData,
        'statusDistribution' => $statusDistribution,
        'departmentStats' => $departmentStats,
        'vehicleUtilization' => $vehicleUtilization,
        'peakHours' => $hourlyData
    ];
}
?>

<div class="container-fluid py-4">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1">Dashboard</h4>
            <p class="text-muted mb-0">Welcome back, <?= e(currentUser()->name) ?>!</p>
        </div>
        <div>
            <a href="<?= APP_URL ?>/?page=requests&action=create" class="btn btn-primary">
                <i class="bi bi-plus-lg me-1"></i>New Request
            </a>
        </div>
    </div>
    
    <!-- Statistics Cards -->
    <div class="row g-4 mb-4 stats-row">
        <!-- My Requests -->
        <div class="col-12 col-md-6 col-xl-3">
            <div class="card stat-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value text-primary"><?= $myRequestsCount ?></div>
                            <div class="stat-label">My Requests</div>
                        </div>
                        <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                            <i class="bi bi-file-earmark-text"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <?php if (isApprover()): ?>
        <!-- Pending Approvals -->
        <div class="col-12 col-md-6 col-xl-3">
            <div class="card stat-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value text-warning"><?= $pendingApprovalsCount ?></div>
                            <div class="stat-label">Pending Approvals</div>
                        </div>
                        <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                            <i class="bi bi-hourglass-split"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Available Vehicles -->
        <div class="col-12 col-md-6 col-xl-3">
            <div class="card stat-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value text-success"><?= $availableVehiclesCount ?></div>
                            <div class="stat-label">Available Vehicles</div>
                        </div>
                        <div class="stat-icon bg-success bg-opacity-10 text-success">
                            <i class="bi bi-car-front"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Active Drivers -->
        <div class="col-xl-3 col-md-6">
            <div class="card stat-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-value text-info"><?= $activeDriversCount ?></div>
                            <div class="stat-label">Available Drivers</div>
                        </div>
                        <div class="stat-icon bg-info bg-opacity-10 text-info">
                            <i class="bi bi-person-badge"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($showCharts && $analyticsData): ?>
    <!-- Analytics Charts -->
    <div class="row g-4 mb-4 charts-row">
        <!-- Daily Trips Chart -->
        <div class="col-12 col-lg-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-graph-up me-2"></i>Trips (Last 7 Days)</h5>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="dailyTripsChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Status Distribution -->
        <div class="col-12 col-lg-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-pie-chart me-2"></i>Status Distribution</h5>
                </div>
                <div class="card-body">
                    <div class="chart-container-sm">
                        <canvas id="statusChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4 charts-row">
        <!-- Department Trips -->
        <div class="col-12 col-lg-6">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-building me-2"></i>Trips by Department</h5>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="departmentChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Peak Hours -->
        <div class="col-12 col-lg-6">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-clock me-2"></i>Peak Hours (Last 30 Days)</h5>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="peakHoursChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Chart data from PHP
        const analyticsData = <?= json_encode($analyticsData) ?>;

        // Detect mobile for chart adjustments
        const isMobile = window.innerWidth < 768;
        const isSmallMobile = window.innerWidth < 576;

        // Common chart options with mobile adjustments
        const commonOptions = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        font: {
                            size: isSmallMobile ? 10 : (isMobile ? 11 : 12),
                            family: "'Segoe UI', Tahoma, Geneva, Verdana, sans-serif"
                        },
                        boxWidth: isSmallMobile ? 12 : (isMobile ? 14 : 16),
                        padding: isSmallMobile ? 8 : 12
                    }
                },
                tooltip: {
                    titleFont: {
                        size: isSmallMobile ? 11 : 12
                    },
                    bodyFont: {
                        size: isSmallMobile ? 10 : 11
                    },
                    padding: isSmallMobile ? 6 : 8
                }
            },
            scales: {
                x: {
                    ticks: {
                        font: {
                            size: isSmallMobile ? 9 : (isMobile ? 10 : 11)
                        },
                        maxRotation: isMobile ? 45 : 0,
                        minRotation: isMobile ? 45 : 0
                    },
                    grid: {
                        display: !isSmallMobile
                    }
                },
                y: {
                    ticks: {
                        font: {
                            size: isSmallMobile ? 9 : (isMobile ? 10 : 11)
                        },
                        stepSize: 1
                    },
                    grid: {
                        display: !isSmallMobile
                    }
                }
            }
        };

        // Daily Trips Chart
        const dailyTripsCtx = document.getElementById('dailyTripsChart');
        if (dailyTripsCtx) {
            new Chart(dailyTripsCtx, {
                type: 'line',
                data: {
                    labels: analyticsData.dailyTrips.map(d => d.date),
                    datasets: [{
                        label: 'Number of Trips',
                        data: analyticsData.dailyTrips.map(d => d.count),
                        borderColor: '#0d6efd',
                        backgroundColor: 'rgba(13, 110, 253, 0.1)',
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: {
                    ...commonOptions,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { stepSize: 1 }
                        }
                    }
                }
            });
        }

        // Status Distribution Chart
        const statusCtx = document.getElementById('statusChart');
        if (statusCtx) {
            const statusLabels = {
                'approved': 'Approved',
                'pending': 'Pending',
                'pending_motorpool': 'Motorpool',
                'completed': 'Completed',
                'cancelled': 'Cancelled',
                'rejected': 'Rejected',
                'revision': 'Revision'
            };
            const statusColors = {
                'approved': '#198754',
                'pending': '#ffc107',
                'pending_motorpool': '#0dcaf0',
                'completed': '#20c997',
                'cancelled': '#6c757d',
                'rejected': '#dc3545',
                'revision': '#fd7e14'
            };

            new Chart(statusCtx, {
                type: 'doughnut',
                data: {
                    labels: analyticsData.statusDistribution.map(s => statusLabels[s->status] || s->status),
                    datasets: [{
                        data: analyticsData.statusDistribution.map(s => s->count),
                        backgroundColor: analyticsData.statusDistribution.map(s => statusColors[s->status] || '#6c757d')
                    }]
                },
                options: commonOptions
            });
        }

        // Department Trips Chart
        const deptCtx = document.getElementById('departmentChart');
        if (deptCtx) {
            new Chart(deptCtx, {
                type: 'bar',
                data: {
                    labels: analyticsData.departmentStats.map(d => d->department),
                    datasets: [{
                        label: 'Trips',
                        data: analyticsData.departmentStats.map(d => d->count),
                        backgroundColor: [
                            '#0d6efd', '#6610f2', '#d63384', '#dc3545',
                            '#fd7e14', '#ffc107', '#198754', '#20c997'
                        ]
                    }]
                },
                options: {
                    ...commonOptions,
                    indexAxis: 'y',
                    scales: {
                        x: {
                            beginAtZero: true,
                            ticks: { stepSize: 1 }
                        }
                    }
                }
            });
        }

        // Peak Hours Chart
        const peakCtx = document.getElementById('peakHoursChart');
        if (peakCtx) {
            const hourLabels = Array.from({length: 24}, (_, i) => i + ':00');
            new Chart(peakCtx, {
                type: 'bar',
                data: {
                    labels: hourLabels,
                    datasets: [{
                        label: 'Trips',
                        data: analyticsData.peakHours,
                        backgroundColor: 'rgba(13, 110, 253, 0.7)',
                        borderColor: '#0d6efd',
                        borderWidth: 1
                    }]
                },
                options: {
                    ...commonOptions,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { stepSize: 1 }
                        }
                    },
                    plugins: {
                        legend: { display: false }
                    }
                }
            });
        }
    </script>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Upcoming Trips -->
        <div class="col-12 col-lg-6">
            <div class="card table-card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5><i class="bi bi-calendar-event me-2"></i>Upcoming Trips</h5>
                    <a href="<?= APP_URL ?>/?page=requests" class="btn btn-sm btn-outline-primary">View All</a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($upcomingTrips)): ?>
                    <div class="empty-state py-4">
                        <i class="bi bi-calendar-x"></i>
                        <p class="mb-0">No upcoming trips</p>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Date/Time</th>
                                    <th>Requester</th>
                                    <th>Vehicle</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($upcomingTrips as $trip): ?>
                                <tr>
                                    <td>
                                        <div class="fw-medium"><?= formatDateTime($trip->start_datetime) ?></div>
                                    </td>
                                    <td><?= e($trip->requester_name) ?></td>
                                    <td>
                                        <?php if ($trip->plate_number): ?>
                                        <span class="badge bg-light text-dark"><?= e($trip->plate_number) ?></span>
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
        
        <!-- Recent Activity / My Requests -->
        <div class="col-12 col-lg-6">
            <div class="card table-card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5><i class="bi bi-clock-history me-2"></i><?= isAdmin() ? 'Recent Activity' : 'My Requests' ?></h5>
                    <small class="text-muted"><?= $totalActivity ?> total</small>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($recentActivity)): ?>
                    <div class="empty-state py-4">
                        <i class="bi bi-inbox"></i>
                        <p class="mb-0">No requests found</p>
                    </div>
                    <?php else: ?>
                    <ul class="activity-feed px-3">
                        <?php foreach ($recentActivity as $activity): ?>
                        <li>
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="fw-medium">
                                        <a href="<?= APP_URL ?>/?page=requests&action=view&id=<?= $activity->id ?>" class="text-decoration-none">
                                            <?= truncate($activity->purpose, 40) ?>
                                        </a>
                                    </div>
                                    <small class="text-muted">
                                        <?= e($activity->requester_name) ?> • <?= e($activity->department_name) ?>
                                    </small>
                                </div>
                                <div class="text-end">
                                    <?= requestStatusBadge($activity->status) ?>
                                    <div class="activity-time"><?= formatDateTime($activity->updated_at) ?></div>
                                </div>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    
                    <?php if ($totalPages > 1): ?>
                    <div class="card-footer bg-transparent border-top">
                        <nav aria-label="Activity pagination">
                            <ul class="pagination pagination-sm justify-content-center mb-0">
                                <!-- Previous -->
                                <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= APP_URL ?>/?page=dashboard&p=<?= $currentPage - 1 ?>">&laquo;</a>
                                </li>
                                
                                <?php
                                $start = max(1, $currentPage - 2);
                                $end = min($totalPages, $currentPage + 2);
                                
                                if ($start > 1): ?>
                                <li class="page-item"><a class="page-link" href="<?= APP_URL ?>/?page=dashboard&p=1">1</a></li>
                                <?php if ($start > 2): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php endif; ?>
                                <?php endif; ?>
                                
                                <?php for ($i = $start; $i <= $end; $i++): ?>
                                <li class="page-item <?= $i === $currentPage ? 'active' : '' ?>">
                                    <a class="page-link" href="<?= APP_URL ?>/?page=dashboard&p=<?= $i ?>"><?= $i ?></a>
                                </li>
                                <?php endfor; ?>
                                
                                <?php if ($end < $totalPages): ?>
                                <?php if ($end < $totalPages - 1): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php endif; ?>
                                <li class="page-item"><a class="page-link" href="<?= APP_URL ?>/?page=dashboard&p=<?= $totalPages ?>"><?= $totalPages ?></a></li>
                                <?php endif; ?>
                                
                                <!-- Next -->
                                <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= APP_URL ?>/?page=dashboard&p=<?= $currentPage + 1 ?>">&raquo;</a>
                                </li>
                            </ul>
                        </nav>
                        <div class="text-center mt-2">
                            <small class="text-muted">Page <?= $currentPage ?> of <?= $totalPages ?></small>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <?php if (isMotorpool() && !empty($vehicleStats)): ?>
    <!-- Vehicle Status Overview -->
    <div class="row g-4 mt-2">
        <div class="col-12">
            <div class="card table-card">
                <div class="card-header">
                    <h5><i class="bi bi-pie-chart me-2"></i>Vehicle Status Overview</h5>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <?php foreach ($vehicleStats as $stat): ?>
                        <div class="col-md-3 col-6 mb-3">
                            <div class="py-3">
                                <div class="h3 mb-1"><?= $stat->count ?></div>
                                <div><?= vehicleStatusBadge($stat->status) ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
