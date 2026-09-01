<?php
/**
 * LOKA - Reports Page
 */

requireReportsAccess();

$pageTitle = 'Reports';
$selfScoped = isSelfScopedDriverReporter();
$ownDriverId = currentDriverId();
$driverReportUrl = APP_URL . '/?page=reports&action=driver';
if ($selfScoped && $ownDriverId) {
    $driverReportUrl .= '&driver_id=' . $ownDriverId;
}

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <div class="mb-4">
        <h4 class="mb-1">Reports</h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
                <li class="breadcrumb-item active">Reports</li>
            </ol>
        </nav>
    </div>

    <div class="row g-4">
        <?php if (isApprover()): ?>
        <!-- Vehicle History -->
        <div class="col-lg-4 col-md-6">
            <a href="<?= APP_URL ?>/?page=reports&action=vehicle-history" class="text-decoration-none">
                <div class="card h-100 report-card">
                    <div class="card-body text-center py-5">
                        <div class="mb-3">
                            <i class="bi bi-car-front text-primary" style="font-size: 3.5rem;"></i>
                        </div>
                        <h5>Vehicle History</h5>
                        <p class="text-muted mb-3">View trip history and utilization for each vehicle with export options</p>
                        <span class="badge bg-primary">CSV & PDF Export</span>
                    </div>
                </div>
            </a>
        </div>
        <?php endif; ?>

        <!-- Driver Report (approvers: any driver; tagged drivers: own report) -->
        <div class="col-lg-4 col-md-6">
            <a href="<?= e($driverReportUrl) ?>" class="text-decoration-none">
                <div class="card h-100 report-card">
                    <div class="card-body text-center py-5">
                        <div class="mb-3">
                            <i class="bi bi-person-badge text-success" style="font-size: 3.5rem;"></i>
                        </div>
                        <h5><?= $selfScoped ? 'My Driver Report' : 'Driver Report' ?></h5>
                        <p class="text-muted mb-3">
                            <?= $selfScoped
                                ? 'View your trip history and statistics with export options'
                                : 'View trip history and statistics for each driver with export options' ?>
                        </p>
                        <span class="badge bg-success">CSV & PDF Export</span>
                    </div>
                </div>
            </a>
        </div>

        <?php if (isApprover()): ?>
        <!-- Trip Requests Report -->
        <div class="col-lg-4 col-md-6">
            <a href="<?= APP_URL ?>/?page=reports&action=trips" class="text-decoration-none">
                <div class="card h-100 report-card">
                    <div class="card-body text-center py-5">
                        <div class="mb-3">
                            <i class="bi bi-journal-text text-info" style="font-size: 3.5rem;"></i>
                        </div>
                        <h5>Trip Requests</h5>
                        <p class="text-muted mb-3">View all trip requests with filtering and export options</p>
                        <span class="badge bg-info">CSV & PDF Export</span>
                    </div>
                </div>
            </a>
        </div>
        <?php endif; ?>

        <?php if (isAdmin()): ?>
        <!-- Admin Reports -->
        <div class="col-lg-4 col-md-6">
            <a href="<?= APP_URL ?>/?page=admin-reports" class="text-decoration-none">
                <div class="card h-100 report-card">
                    <div class="card-body text-center py-5">
                        <div class="mb-3">
                            <i class="bi bi-bar-chart-fill text-warning" style="font-size: 3.5rem;"></i>
                        </div>
                        <h5>Admin Reports</h5>
                        <p class="text-muted mb-3">Advanced reports: users, vehicles, departments, audit logs</p>
                        <span class="badge bg-warning text-dark">Admin Only</span>
                    </div>
                </div>
            </a>
        </div>
        <?php endif; ?>

        <!-- Driver Rankings (anonymous) -->
        <div class="col-lg-4 col-md-6">
            <a href="<?= APP_URL ?>/?page=reports&action=driver-rankings" class="text-decoration-none">
                <div class="card h-100 report-card">
                    <div class="card-body text-center py-5">
                        <div class="mb-3">
                            <i class="bi bi-trophy text-warning" style="font-size: 3.5rem;"></i>
                        </div>
                        <h5>Driver Rankings</h5>
                        <p class="text-muted mb-3">Anonymous passenger evaluations — per-driver averages, GRAB-like rankings</p>
                        <span class="badge bg-warning text-dark"><i class="bi bi-shield-lock me-1"></i>Anonymous</span>
                    </div>
                </div>
            </a>
        </div>

        <!-- Evaluations -->
        <div class="col-lg-4 col-md-6">
            <a href="<?= APP_URL ?>/?page=evaluations" class="text-decoration-none">
                <div class="card h-100 report-card">
                    <div class="card-body text-center py-5">
                        <div class="mb-3">
                            <i class="bi bi-star-half text-primary" style="font-size: 3.5rem;"></i>
                        </div>
                        <h5>Evaluations</h5>
                        <p class="text-muted mb-3">Response rates per trip and anonymous remarks dashboard</p>
                        <span class="badge bg-primary">Anonymous</span>
                    </div>
                </div>
            </a>
        </div>
    </div>
</div>

<style>
.report-card {
    transition: transform 0.2s, box-shadow 0.2s;
    border: 2px solid transparent;
}
.report-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.15);
    border-color: var(--bs-primary);
}
.report-card h5 {
    color: #333;
    font-weight: 600;
}
</style>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
