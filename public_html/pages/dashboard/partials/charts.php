<?php
/** @var array<string, mixed> $dash */
if (empty($dash['showCharts']) || empty($dash['analytics'])) {
    return;
}
$analytics = $dash['analytics'];
$reportsHref = (string) ($dash['reportsHref'] ?? (APP_URL . '/?page=reports&action=trips'));
$emptyHtml = '<div class="dash-chart-empty text-center text-muted py-5 px-3">'
    . '<i class="bi bi-bar-chart d-block mb-2 fs-3"></i>'
    . '<p class="mb-2">No trips in this window</p>'
    . '<a href="' . e($reportsHref) . '" class="small">Open Reports</a>'
    . '</div>';
?>
<div class="row g-4 mb-4 charts-row">
    <div class="col-12 col-lg-8">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-graph-up me-2"></i>Trips (Last 7 Days)</h5>
            </div>
            <div class="card-body">
                <?php if (!empty($analytics['hasDaily'])): ?>
                <div class="chart-container">
                    <canvas id="dailyTripsChart"></canvas>
                </div>
                <?php else: ?>
                <?= $emptyHtml ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-4">
        <div class="card h-100">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-pie-chart me-2"></i>Status Distribution</h5>
            </div>
            <div class="card-body">
                <?php if (!empty($analytics['hasStatus'])): ?>
                <div class="chart-container-sm">
                    <canvas id="statusChart"></canvas>
                </div>
                <?php else: ?>
                <?= $emptyHtml ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4 charts-row">
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-building me-2"></i>Trips by Department</h5>
            </div>
            <div class="card-body">
                <?php if (!empty($analytics['hasDepartment'])): ?>
                <div class="chart-container">
                    <canvas id="departmentChart"></canvas>
                </div>
                <?php else: ?>
                <?= $emptyHtml ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-clock me-2"></i>Peak Hours (Last 30 Days)</h5>
            </div>
            <div class="card-body">
                <?php if (!empty($analytics['hasPeak'])): ?>
                <div class="chart-container">
                    <canvas id="peakHoursChart"></canvas>
                </div>
                <?php else: ?>
                <?= $emptyHtml ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($analytics['hasDaily']) || !empty($analytics['hasStatus']) || !empty($analytics['hasDepartment']) || !empty($analytics['hasPeak'])): ?>
<script>
    const analyticsData = <?= json_encode($analytics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const isMobile = window.innerWidth < 768;
    const isSmallMobile = window.innerWidth < 576;
    const commonOptions = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    font: { size: isSmallMobile ? 10 : (isMobile ? 11 : 12), family: "'Segoe UI', Tahoma, Geneva, Verdana, sans-serif" },
                    boxWidth: isSmallMobile ? 12 : (isMobile ? 14 : 16),
                    padding: isSmallMobile ? 8 : 12
                }
            }
        }
    };

    const dailyTripsCtx = document.getElementById('dailyTripsChart');
    if (dailyTripsCtx && analyticsData.hasDaily) {
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
                scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
            }
        });
    }

    const statusCtx = document.getElementById('statusChart');
    if (statusCtx && analyticsData.hasStatus) {
        const statusLabels = {
            approved: 'Approved', pending: 'Pending', pending_motorpool: 'Motorpool',
            completed: 'Completed', cancelled: 'Cancelled', rejected: 'Rejected', revision: 'Revision'
        };
        const statusColors = {
            approved: '#198754', pending: '#ffc107', pending_motorpool: '#0dcaf0',
            completed: '#20c997', cancelled: '#6c757d', rejected: '#dc3545', revision: '#fd7e14'
        };
        new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: analyticsData.statusDistribution.map(s => statusLabels[s.status] || s.status),
                datasets: [{
                    data: analyticsData.statusDistribution.map(s => s.count),
                    backgroundColor: analyticsData.statusDistribution.map(s => statusColors[s.status] || '#6c757d')
                }]
            },
            options: commonOptions
        });
    }

    const deptCtx = document.getElementById('departmentChart');
    if (deptCtx && analyticsData.hasDepartment) {
        new Chart(deptCtx, {
            type: 'bar',
            data: {
                labels: analyticsData.departmentStats.map(d => d.department),
                datasets: [{
                    label: 'Trips',
                    data: analyticsData.departmentStats.map(d => d.count),
                    backgroundColor: ['#0d6efd', '#6610f2', '#d63384', '#dc3545', '#fd7e14', '#ffc107', '#198754', '#20c997']
                }]
            },
            options: {
                ...commonOptions,
                indexAxis: 'y',
                scales: { x: { beginAtZero: true, ticks: { stepSize: 1 } } }
            }
        });
    }

    const peakCtx = document.getElementById('peakHoursChart');
    if (peakCtx && analyticsData.hasPeak) {
        new Chart(peakCtx, {
            type: 'bar',
            data: {
                labels: Array.from({length: 24}, (_, i) => i + ':00'),
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
                scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } },
                plugins: { legend: { display: false } }
            }
        });
    }
</script>
<?php endif; ?>
