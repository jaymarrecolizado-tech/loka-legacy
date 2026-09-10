<?php
/**
 * LOKA - Role-aware dashboard
 */

$pageTitle = 'Dashboard';

if (isGuard() && !isDriver()) {
    redirect('/?page=guard');
}

$dash = dashboardStatsForUser();

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
        <div>
            <h4 class="mb-1">Dashboard</h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item active">Dashboard</li>
                </ol>
            </nav>
            <p class="text-muted mb-0 mt-1 small">Welcome back, <?= e(currentUser()->name) ?>!</p>
        </div>
        <?php if (!empty($dash['showNewRequest'])): ?>
        <div>
            <a href="<?= APP_URL ?>/?page=requests&action=create" class="btn btn-primary">
                <i class="bi bi-plus-lg me-1"></i>New Request
            </a>
        </div>
        <?php endif; ?>
    </div>

    <?php require __DIR__ . '/partials/kpis.php'; ?>
    <?php require __DIR__ . '/partials/actions.php'; ?>
    <?php require __DIR__ . '/partials/charts.php'; ?>
    <?php require __DIR__ . '/partials/queue.php'; ?>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
