<!-- Sidebar -->
<nav class="sidebar" id="sidebar">
    <div class="sidebar-content">
        <ul class="nav flex-column">
            <!-- Dashboard -->
            <li class="nav-item">
                <a class="nav-link <?= activeMenu('dashboard') ?>" href="<?= APP_URL ?>/?page=dashboard">
                    <i class="bi bi-speedometer2"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            
            <!-- Requests -->
            <li class="nav-item">
                <a class="nav-link <?= activeMenu('requests') ?>" href="<?= APP_URL ?>/?page=requests">
                    <i class="bi bi-file-earmark-text"></i>
                    <span>Requests</span>
                </a>
            </li>

            <?php if (isDriver()): ?>
            <!-- My Trips (tagged drivers) -->
            <li class="nav-item">
                <a class="nav-link <?= activeMenu('my-trips') ?>" href="<?= APP_URL ?>/?page=my-trips">
                    <i class="bi bi-truck"></i>
                    <span>My Trips</span>
                </a>
            </li>
            <?php endif; ?>

            <!-- Completed Trips -->
            <li class="nav-item">
                <a class="nav-link <?= activeMenu('completed-trips') ?>" href="<?= APP_URL ?>/?page=completed-trips">
                    <i class="bi bi-check-all"></i>
                    <span>Completed Trips</span>
                </a>
            </li>

            <!-- Schedule Calendar (All Users) -->
            <li class="nav-item">
                <a class="nav-link <?= activeMenu('schedule') ?>" href="<?= APP_URL ?>/?page=schedule&action=calendar">
                    <i class="bi bi-calendar3"></i>
                    <span>Availability</span>
                </a>
            </li>
            
            <?php if (isApprover()): ?>
            <!-- My Trip Tickets (Approvers Only) -->
            <li class="nav-item">
                <a class="nav-link <?= activeMenu('my-trip-tickets') ?>" href="<?= APP_URL ?>/?page=my-trip-tickets">
                    <i class="bi bi-file-earmark-text"></i>
                    <span>My Trip Tickets</span>
                    <?php
                    $pendingTicketsCount = db()->fetchColumn(
                        "SELECT COUNT(*) FROM trip_tickets WHERE status = 'submitted' AND deleted_at IS NULL"
                    );
                    if ($pendingTicketsCount > 0):
                    ?>
                    <span class="badge bg-warning ms-auto"><?= $pendingTicketsCount ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <?php endif; ?>
            
            <?php if (isGuard()): ?>
            <!-- Guard Dashboard -->
            <li class="nav-item">
                <a class="nav-link <?= activeMenu('guard') ?>" href="<?= APP_URL ?>/?page=guard">
                    <i class="bi bi-shield-check"></i>
                    <span>Guard Dashboard</span>
                </a>
            </li>
            <!-- Trip Tickets -->
            <li class="nav-item">
                <a class="nav-link <?= activeMenu('trip-tickets') ?>" href="<?= APP_URL ?>/?page=trip-tickets">
                    <i class="bi bi-file-earmark-text"></i>
                    <span>Trip Tickets</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if (isMotorpool() || isAdmin()): ?>
            <!-- Review Trip Tickets -->
            <li class="nav-item">
                <a class="nav-link <?= activeMenu('trip-tickets') ?>" href="<?= APP_URL ?>/?page=trip-tickets">
                    <i class="bi bi-clipboard-check"></i>
                    <span>Review Trip Tickets</span>
                </a>
            </li>
            <?php endif; ?>

            <?php if (isApprover()): ?>
            <!-- Approvals -->
            <li class="nav-item">
                <a class="nav-link <?= activeMenu('approvals') ?>" href="<?= APP_URL ?>/?page=approvals">
                    <i class="bi bi-check-circle"></i>
                    <span>Approvals</span>
                    <?php
                    $pendingCount = 0;
                    if (isMotorpool()) {
                        $pendingCount = db()->count('requests', "status = 'pending_motorpool'");
                    } else {
                        $pendingCount = db()->count('requests', "status = 'pending' AND department_id = ?", [currentUser()->department_id]);
                    }
                    if ($pendingCount > 0):
                    ?>
                    <span class="badge bg-warning ms-auto"><?= $pendingCount ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <?php endif; ?>
            
            <li class="nav-header">Fleet Management</li>
            
            <?php if (isApprover()): ?>
            <!-- Vehicles -->
            <li class="nav-item">
                <a class="nav-link <?= activeMenu('vehicles') ?>" href="<?= APP_URL ?>/?page=vehicles">
                    <i class="bi bi-car-front"></i>
                    <span>Vehicles</span>
                </a>
            </li>
            
            <!-- Drivers -->
            <li class="nav-item">
                <a class="nav-link <?= activeMenu('drivers') ?>" href="<?= APP_URL ?>/?page=drivers">
                    <i class="bi bi-person-badge"></i>
                    <span>Drivers</span>
                </a>
            </li>

            <?php if (isAdmin() || isMotorpool() || isApprover()): ?>
            <!-- Vehicle Types -->
            <li class="nav-item">
                <a class="nav-link <?= activeMenu('vehicle_types') ?>" href="<?= APP_URL ?>/?page=vehicle_types">
                    <i class="bi bi-car-front"></i>
                    <span>Vehicle Types</span>
                </a>
            </li>
            <?php endif; ?>

            <!-- Maintenance -->
            <li class="nav-item">
                <a class="nav-link <?= activeMenu('maintenance') ?>" href="<?= APP_URL ?>/?page=maintenance">
                    <i class="bi bi-wrench"></i>
                    <span>Maintenance</span>
                    <?php
                    $pendingMaintenance = db()->count('maintenance_requests', "status IN (?, ?) AND deleted_at IS NULL", [MAINTENANCE_STATUS_PENDING, MAINTENANCE_STATUS_SCHEDULED]);
                    if ($pendingMaintenance > 0):
                    ?>
                    <span class="badge bg-warning ms-auto"><?= $pendingMaintenance ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <!-- Maintenance Schedule -->
            <li class="nav-item">
                <a class="nav-link <?= activeMenu('maintenance', 'schedule') ?>" href="<?= APP_URL ?>/?page=maintenance&action=schedule">
                    <i class="bi bi-calendar-check"></i>
                    <span>Schedule</span>
                </a>
            </li>
            <?php endif; ?>
            
            <?php if (canAccessReports()): ?>
            <li class="nav-header">Reports</li>
            
            <!-- Reports (approver+ or any user tagged as driver) -->
            <li class="nav-item">
                <a class="nav-link <?= activeMenu('reports') ?>" href="<?= APP_URL ?>/?page=reports">
                    <i class="bi bi-bar-chart"></i>
                    <span>Reports</span>
                </a>
            </li>
            <?php endif; ?>
            
            <?php if (isMotorpool()): ?>
            <li class="nav-header">Administration</li>
            
            <!-- Users -->
            <li class="nav-item">
                <a class="nav-link <?= activeMenu('users') ?>" href="<?= APP_URL ?>/?page=users">
                    <i class="bi bi-people"></i>
                    <span>Users</span>
                </a>
            </li>
            
            <!-- Departments -->
            <li class="nav-item">
                <a class="nav-link <?= activeMenu('departments') ?>" href="<?= APP_URL ?>/?page=departments">
                    <i class="bi bi-building"></i>
                    <span>Departments</span>
                </a>
            </li>
            <?php endif; ?>
            
            <?php if (isAdmin()): ?>
            <!-- Audit Logs -->
            <li class="nav-item">
                <a class="nav-link <?= activeMenu('audit') ?>" href="<?= APP_URL ?>/?page=audit">
                    <i class="bi bi-journal-text"></i>
                    <span>Audit Logs</span>
                </a>
            </li>
            
            <!-- Settings -->
            <li class="nav-item">
                <a class="nav-link <?= activeMenu('settings') ?>" href="<?= APP_URL ?>/?page=settings">
                    <i class="bi bi-gear"></i>
                    <span>Settings</span>
                </a>
            </li>
            <?php endif; ?>
            
            <!-- Patch Notes - Visible to All Roles -->
            <li class="nav-item">
                <a class="nav-link <?= activeMenu('patch-notes') ?>" href="<?= APP_URL ?>/?page=patch-notes">
                    <i class="bi bi-journal-text"></i>
                    <span>Patch Notes</span>
                </a>
            </li>
        </ul>
    </div>
</nav>
