<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="LOKA Fleet Management System">
    <title><?= e($pageTitle ?? 'Dashboard') ?> - <?= APP_NAME ?></title>
    
    <!-- Bootstrap 5.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.css" rel="stylesheet">
    
    <!-- DataTables CSS -->
    <link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    
    <!-- Flatpickr CSS -->
    <link href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" rel="stylesheet">
    
    <!-- Tom Select CSS -->
    <link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">

    <!-- Chart.js for Analytics -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>

    <!-- Custom CSS -->
    <link href="<?= ASSETS_PATH ?>/css/style.css" rel="stylesheet">
</head>
<body>
    <!-- Top Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary fixed-top">
        <div class="container-fluid">
            <!-- Sidebar Toggle -->
            <button class="btn btn-primary me-2" type="button" id="sidebarToggle">
                <i class="bi bi-list"></i>
            </button>
            
            <!-- Brand -->
            <a class="navbar-brand" href="<?= APP_URL ?>">
                <i class="bi bi-truck me-2"></i><?= APP_NAME ?>
            </a>
            
            <!-- Right Side -->
            <div class="ms-auto d-flex align-items-center">
                <!-- Notifications -->
                <div class="dropdown me-3">
                    <a class="nav-link text-white position-relative" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false" id="notificationDropdown">
                        <i class="bi bi-bell fs-5"></i>
                        <?php $unreadCount = unreadNotificationCount(); ?>
                        <?php if ($unreadCount > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                            <?= $unreadCount > 9 ? '9+' : $unreadCount ?>
                        </span>
                        <?php endif; ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end notification-dropdown" id="notificationDropdownList" aria-labelledby="notificationDropdown">
                        <li><h6 class="dropdown-header">Notifications</h6></li>
                        <?php
                        $notifications = db()->fetchAll(
                            "SELECT * FROM notifications WHERE user_id = ? AND is_read = 0 AND is_archived = 0 AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 5",
                            [userId()]
                        );
                        if (empty($notifications)):
                        ?>
                        <li><span class="dropdown-item-text text-muted">No notifications</span></li>
                        <?php else: ?>
                        <?php foreach ($notifications as $notif): ?>
                        <li>
                            <a class="dropdown-item <?= $notif->is_read ? '' : 'fw-bold' ?>" href="<?= APP_URL ?>/?page=notifications&action=read&id=<?= $notif->id ?>">
                                <small class="text-muted"><?= formatDateTime($notif->created_at) ?></small><br>
                                <?= e($notif->title) ?>
                            </a>
                        </li>
                        <?php endforeach; ?>
                        <?php endif; ?>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-center" href="<?= APP_URL ?>/?page=notifications">View All</a></li>
                    </ul>
                </div>
                
                <!-- User Dropdown -->
                <div class="dropdown">
                    <a class="nav-link dropdown-toggle text-white d-flex align-items-center" href="#" data-bs-toggle="dropdown">
                        <div class="avatar-circle me-2">
                            <?= strtoupper(substr(currentUser()->name ?? 'U', 0, 1)) ?>
                        </div>
                        <span class="d-none d-md-inline"><?= e(currentUser()->name ?? 'User') ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><h6 class="dropdown-header"><?= e(currentUser()->email ?? '') ?></h6></li>
                        <li><span class="dropdown-item-text"><?= roleBadge(userRole()) ?></span></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?= APP_URL ?>/?page=profile"><i class="bi bi-person me-2"></i>Profile</a></li>
                        <li><a class="dropdown-item text-danger" href="<?= APP_URL ?>/?page=logout"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>
    
    <!-- Main Wrapper -->
    <div class="wrapper">
        <?php require_once INCLUDES_PATH . '/sidebar.php'; ?>
        
        <!-- Toast Container -->
        <div id="toast-container" class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1100;"></div>
        
        <!-- Main Content -->
        <main class="main-content" id="main-content">
            <!-- Flash Messages -->
            <?php if ($flash = getFlash()): ?>
            <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show m-3" role="alert">
                <?= e($flash['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
