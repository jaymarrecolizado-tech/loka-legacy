<?php
/**
 * LOKA - Admin Export Reports Page
 *
 * Admin-only page for exporting system data in CSV and PDF formats
 */

requireRole(ROLE_ADMIN);

$pageTitle = 'Export Reports';
$type = get('type', 'requests');
$startDate = get('start_date', date('Y-m-01'));
$endDate = get('end_date', date('Y-m-t'));

$availableReports = [
    'requests' => [
        'title' => 'Vehicle Requests',
        'description' => 'All vehicle requests with details, status, and timeline',
        'icon' => 'bi-car-front',
    ],
    'users' => [
        'title' => 'Users',
        'description' => 'All users with roles, departments, and account status',
        'icon' => 'bi-people',
    ],
    'vehicles' => [
        'title' => 'Vehicles',
        'description' => 'All vehicles with types, status, and specifications',
        'icon' => 'bi-truck',
    ],
    'departments' => [
        'title' => 'Departments',
        'description' => 'All departments with heads and member counts',
        'icon' => 'bi-building',
    ],
    'maintenance' => [
        'title' => 'Maintenance Records',
        'description' => 'All maintenance requests and records',
        'icon' => 'bi-tools',
    ],
    'audit_logs' => [
        'title' => 'Audit Logs',
        'description' => 'System audit logs and activity history',
        'icon' => 'bi-journal-text',
    ],
    'driver_history' => [
        'title' => 'Driver Trip History',
        'description' => 'Driver performance and trip statistics',
        'icon' => 'bi-person-badge',
    ],
    'vehicle_history' => [
        'title' => 'Vehicle Trip History',
        'description' => 'Vehicle utilization and usage statistics',
        'icon' => 'bi-car-front-fill',
    ],
    'department_usage' => [
        'title' => 'Department Usage',
        'description' => 'Department request frequency and utilization',
        'icon' => 'bi-building-fill',
    ],
];

if (!isset($availableReports[$type])) {
    $type = 'requests';
}

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <div class="mb-4">
        <h4 class="mb-1">Export Reports</h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
                <li class="breadcrumb-item active">Export Reports</li>
            </ol>
        </nav>
    </div>

    <div class="row">
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0"><i class="bi bi-file-earmark-bar me-2"></i>Select Report</h6>
                </div>
                <div class="list-group list-group-flush">
                    <?php foreach ($availableReports as $reportType => $report): ?>
                        <a href="?page=admin-reports&type=<?= $reportType ?>&start_date=<?= $startDate ?>&end_date=<?= $endDate ?>"
                           class="list-group-item list-group-item-action d-flex align-items-center <?= $type === $reportType ? 'active' : '' ?>">
                            <i class="bi <?= $report['icon'] ?> me-3"></i>
                            <div>
                                <div class="fw-bold"><?= e($report['title']) ?></div>
                                <small class="text-muted"><?= e($report['description']) ?></small>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header">
                    <h6 class="mb-0"><i class="bi bi-calendar-range me-2"></i>Date Range</h6>
                </div>
                <div class="card-body">
                    <form method="GET" action="">
                        <input type="hidden" name="page" value="admin-reports">
                        <input type="hidden" name="type" value="<?= $type ?>">
                        <div class="mb-3">
                            <label class="form-label">Start Date</label>
                            <input type="date" class="form-control" name="start_date" value="<?= e($startDate) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">End Date</label>
                            <input type="date" class="form-control" name="end_date" value="<?= e($endDate) ?>" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-filter me-1"></i>Apply Filter
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0">
                        <i class="bi <?= $availableReports[$type]['icon'] ?> me-2"></i>
                        <?= e($availableReports[$type]['title']) ?>
                    </h6>
                </div>
                <div class="card-body">
                    <p class="text-muted"><?= e($availableReports[$type]['description']) ?></p>

                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        Date Range: <strong><?= e($startDate) ?></strong> to <strong><?= e($endDate) ?></strong>
                    </div>

                    <hr>

                    <h6 class="mb-3">Download Report</h6>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <a href="?page=export&format=csv&type=<?= $type ?>&start_date=<?= $startDate ?>&end_date=<?= $endDate ?>"
                               class="btn btn-success btn-lg w-100">
                                <i class="bi bi-filetype-csv me-2"></i>Download CSV
                            </a>
                            <small class="text-muted d-block text-center mt-1">Opens in Excel, Google Sheets</small>
                        </div>
                        <div class="col-md-6">
                            <a href="?page=export&format=pdf&type=<?= $type ?>&start_date=<?= $startDate ?>&end_date=<?= $endDate ?>"
                               class="btn btn-danger btn-lg w-100">
                                <i class="bi bi-filetype-pdf me-2"></i>Download PDF
                            </a>
                            <small class="text-muted d-block text-center mt-1">Best for printing and sharing</small>
                        </div>
                    </div>
                </div>
            </div>

            <?php
            $recentExports = db()->fetchAll(
                "SELECT action, entity_type, created_at, new_data as details
                 FROM audit_logs
                 WHERE action = 'data_export'
                 ORDER BY created_at DESC
                 LIMIT 5"
            );
            ?>

            <?php if (!empty($recentExports)): ?>
            <div class="card mt-3">
                <div class="card-header">
                    <h6 class="mb-0"><i class="bi bi-clock-history me-2"></i>Recent Exports</h6>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>Date/Time</th>
                                <th>Report Type</th>
                                <th>Format</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentExports as $export):
                                $details = json_decode($export->details, true) ?? [];
                            ?>
                                <tr>
                                    <td><?= e($export->created_at) ?></td>
                                    <td><?= e(ucfirst(str_replace('_', ' ', $export->entity_type))) ?></td>
                                    <td>
                                        <span class="badge <?= ($details['format'] ?? 'csv') === 'pdf' ? 'bg-danger' : 'bg-success' ?>">
                                            <?= e(strtoupper($details['format'] ?? 'csv')) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
