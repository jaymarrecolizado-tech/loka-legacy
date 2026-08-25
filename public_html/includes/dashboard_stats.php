<?php
/**
 * Role-aware dashboard metrics for LOKA.
 */

require_once __DIR__ . '/badge_counts.php';

/**
 * @return array<string, mixed>
 */
function dashboardStatsForUser(): array
{
    $userId = userId();
    $deptId = currentUser()->department_id ?? null;
    $driver = isDriver();
    $today = date('Y-m-d');
    $sevenDays = date('Y-m-d H:i:s', strtotime('+7 days'));

    $kpis = [];
    $actions = [];
    $queue = ['title' => 'Queue', 'href' => APP_URL . '/?page=requests', 'rows' => []];
    $upcoming = [];
    $vehicleStats = [];
    $analytics = null;
    $audit = [];
    $showCharts = false;
    $showUtilization = false;
    $showNewRequest = true;
    $showFleetKpis = false;

    // --- Guard-only users are redirected by dashboard/index.php ---

    // Shared fleet counts for ops roles
    $availableVehicles = 0;
    $availableDrivers = 0;
    try {
        $availableVehicles = (int) db()->count('vehicles', "status = 'available' AND deleted_at IS NULL");
        $availableDrivers = (int) db()->count('drivers', "status = 'available' AND deleted_at IS NULL");
    } catch (Throwable $e) {
        /* ignore */
    }

    $pendingApprovals = badgeCountPendingApprovals();
    $pendingGas = badgeCountPendingGasVouchers();
    $pendingTickets = badgeCountSubmittedTripTickets();
    $pendingMaint = badgeCountPendingMaintenance();

    if (isAdmin() || isMotorpool()) {
        $showCharts = true;
        $showUtilization = true;
        $showFleetKpis = true;
        $showNewRequest = isAdmin(); // motorpool rarely creates requests from dash

        $tripsToday = (int) db()->fetchColumn(
            "SELECT COUNT(*) FROM requests WHERE status = 'approved' AND DATE(start_datetime) = ? AND deleted_at IS NULL",
            [$today]
        );
        $onTrip = (int) db()->fetchColumn(
            "SELECT COUNT(*) FROM requests WHERE status = 'approved' AND actual_dispatch_datetime IS NOT NULL AND actual_arrival_datetime IS NULL AND deleted_at IS NULL"
        );

        if ($pendingApprovals > 0) {
            $actions[] = ['label' => 'Approvals waiting', 'count' => $pendingApprovals, 'href' => APP_URL . '/?page=approvals', 'tone' => 'warning'];
        }
        if ($pendingGas > 0) {
            $actions[] = ['label' => 'Gas vouchers pending', 'count' => $pendingGas, 'href' => APP_URL . '/?page=gas-vouchers&status=pending_approval', 'tone' => 'warning'];
        }
        if ($pendingTickets > 0) {
            $actions[] = ['label' => 'Trip tickets submitted', 'count' => $pendingTickets, 'href' => APP_URL . '/?page=trip-tickets&status=submitted', 'tone' => 'info'];
        }
        if ($pendingMaint > 0) {
            $actions[] = ['label' => 'Maintenance pending', 'count' => $pendingMaint, 'href' => APP_URL . '/?page=maintenance', 'tone' => 'warning'];
        }

        $kpis = [
            ['label' => 'Pending Approvals', 'value' => $pendingApprovals, 'href' => APP_URL . '/?page=approvals', 'tone' => 'warning', 'icon' => 'bi-hourglass-split'],
            ['label' => 'Trips Today', 'value' => $tripsToday, 'href' => APP_URL . '/?page=guard&filter=today', 'tone' => 'primary', 'icon' => 'bi-calendar-day'],
            ['label' => 'On Trip Now', 'value' => $onTrip, 'href' => APP_URL . '/?page=guard&filter=pending_arrival', 'tone' => 'info', 'icon' => 'bi-truck'],
            ['label' => 'Available Vehicles', 'value' => $availableVehicles, 'href' => APP_URL . '/?page=vehicles&status=available', 'tone' => 'success', 'icon' => 'bi-car-front'],
            ['label' => 'Gas Pending', 'value' => $pendingGas, 'href' => APP_URL . '/?page=gas-vouchers', 'tone' => 'warning', 'icon' => 'bi-fuel-pump'],
        ];

        $queue = dashboardQueueApprovals(true);
        $upcoming = dashboardUpcomingTrips(null, 5);
        $vehicleStats = db()->fetchAll("SELECT status, COUNT(*) as count FROM vehicles WHERE deleted_at IS NULL GROUP BY status");
        $analytics = dashboardAnalyticsData(null);

        if (isAdmin()) {
            try {
                $audit = db()->fetchAll(
                    "SELECT a.*, u.name as user_name FROM audit_logs a LEFT JOIN users u ON a.user_id = u.id ORDER BY a.created_at DESC LIMIT 5"
                );
            } catch (Throwable $e) {
                $audit = [];
            }
            $kpis[] = ['label' => 'Tickets Submitted', 'value' => $pendingTickets, 'href' => APP_URL . '/?page=trip-tickets&status=submitted', 'tone' => 'info', 'icon' => 'bi-journal-check'];
        }
    } elseif (isApprover()) {
        $showCharts = true;
        $unviewed = 0;
        try {
            $unviewed = (int) db()->fetchColumn(
                "SELECT COUNT(*) FROM requests WHERE status = 'pending' AND department_id = ? AND viewed_at IS NULL AND deleted_at IS NULL",
                [$deptId]
            );
        } catch (Throwable $e) {
            /* ignore */
        }

        if ($pendingApprovals > 0) {
            $actions[] = ['label' => 'Dept approvals pending', 'count' => $pendingApprovals, 'href' => APP_URL . '/?page=approvals', 'tone' => 'warning'];
        }
        if ($unviewed > 0) {
            $actions[] = ['label' => 'Unviewed requests', 'count' => $unviewed, 'href' => APP_URL . '/?page=approvals', 'tone' => 'error'];
        }
        if ($pendingGas > 0) {
            $actions[] = ['label' => 'Gas vouchers to review', 'count' => $pendingGas, 'href' => APP_URL . '/?page=gas-vouchers&status=pending_review', 'tone' => 'warning'];
        }
        if ($pendingTickets > 0) {
            $actions[] = ['label' => 'Trip tickets submitted', 'count' => $pendingTickets, 'href' => APP_URL . '/?page=my-trip-tickets&status=submitted', 'tone' => 'info'];
        }

        $kpis = [
            ['label' => 'Pending Approvals', 'value' => $pendingApprovals, 'href' => APP_URL . '/?page=approvals', 'tone' => 'warning', 'icon' => 'bi-hourglass-split'],
            ['label' => 'Unviewed', 'value' => $unviewed, 'href' => APP_URL . '/?page=approvals', 'tone' => 'error', 'icon' => 'bi-eye'],
            ['label' => 'Trip Tickets', 'value' => $pendingTickets, 'href' => APP_URL . '/?page=my-trip-tickets', 'tone' => 'info', 'icon' => 'bi-journal-check'],
            ['label' => 'Maintenance', 'value' => $pendingMaint, 'href' => APP_URL . '/?page=maintenance', 'tone' => 'warning', 'icon' => 'bi-wrench'],
            ['label' => 'Gas Review', 'value' => $pendingGas, 'href' => APP_URL . '/?page=gas-vouchers', 'tone' => 'warning', 'icon' => 'bi-fuel-pump'],
        ];

        $queue = dashboardQueueApprovals(false);
        $upcoming = dashboardUpcomingTrips($deptId, 5);
        $analytics = dashboardAnalyticsData($deptId);
    } elseif (isChiefAdminFinance()) {
        $showNewRequest = false;
        $cafPending = (int) db()->fetchColumn(
            "SELECT COUNT(*) FROM gas_vouchers WHERE status = 'pending_approval' AND deleted_at IS NULL"
        );
        $unpaid = 0;
        try {
            $unpaid = (int) db()->fetchColumn(
                "SELECT COUNT(*) FROM gas_vouchers WHERE status = 'approved' AND payment_status = 'unpaid' AND deleted_at IS NULL"
            );
        } catch (Throwable $e) {
            try {
                $unpaid = (int) db()->fetchColumn(
                    "SELECT COUNT(*) FROM gas_vouchers WHERE status = 'approved' AND deleted_at IS NULL"
                );
            } catch (Throwable $e2) {
                $unpaid = 0;
            }
        }

        if ($cafPending > 0) {
            $actions[] = ['label' => 'Vouchers awaiting your approval', 'count' => $cafPending, 'href' => APP_URL . '/?page=gas-vouchers&status=pending_approval', 'tone' => 'warning'];
        }
        if ($unpaid > 0) {
            $actions[] = ['label' => 'Approved unpaid vouchers', 'count' => $unpaid, 'href' => APP_URL . '/?page=gas-vouchers&status=approved', 'tone' => 'info'];
        }

        $kpis = [
            ['label' => 'Pending Approval', 'value' => $cafPending, 'href' => APP_URL . '/?page=gas-vouchers&status=pending_approval', 'tone' => 'warning', 'icon' => 'bi-fuel-pump'],
            ['label' => 'Approved Unpaid', 'value' => $unpaid, 'href' => APP_URL . '/?page=gas-vouchers&status=approved', 'tone' => 'info', 'icon' => 'bi-cash-stack'],
        ];

        $queue = [
            'title' => 'Gas Vouchers Needing Approval',
            'href' => APP_URL . '/?page=gas-vouchers&status=pending_approval',
            'rows' => db()->fetchAll(
                "SELECT gv.id, gv.voucher_no as title, gv.status, gv.created_at as updated_at, u.name as meta
                 FROM gas_vouchers gv JOIN users u ON gv.requested_by_user_id = u.id
                 WHERE gv.status = 'pending_approval' AND gv.deleted_at IS NULL
                 ORDER BY gv.created_at DESC LIMIT 8"
            ),
        ];
        $upcoming = [];
    } else {
        // Requester (and stacked driver capability)
        $myTotal = (int) db()->count('requests', 'user_id = ? AND deleted_at IS NULL', [$userId]);
        $myPending = (int) db()->count('requests', "user_id = ? AND status IN ('pending','pending_motorpool') AND deleted_at IS NULL", [$userId]);
        $myRevision = (int) db()->count('requests', "user_id = ? AND status = 'revision' AND deleted_at IS NULL", [$userId]);
        $myUpcoming = (int) db()->fetchColumn(
            "SELECT COUNT(*) FROM requests WHERE user_id = ? AND status = 'approved' AND start_datetime BETWEEN NOW() AND ? AND deleted_at IS NULL",
            [$userId, $sevenDays]
        );
        $myGas = (int) db()->fetchColumn(
            "SELECT COUNT(*) FROM gas_vouchers WHERE requested_by_user_id = ? AND status IN ('pending_review','pending_approval') AND deleted_at IS NULL",
            [$userId]
        );

        if ($myRevision > 0) {
            $actions[] = ['label' => 'Requests need revision', 'count' => $myRevision, 'href' => APP_URL . '/?page=requests&status=revision', 'tone' => 'warning'];
        }
        if ($myPending > 0) {
            $actions[] = ['label' => 'Requests still pending', 'count' => $myPending, 'href' => APP_URL . '/?page=requests&status=pending', 'tone' => 'info'];
        }
        if ($myGas > 0) {
            $actions[] = ['label' => 'Gas vouchers in progress', 'count' => $myGas, 'href' => APP_URL . '/?page=gas-vouchers', 'tone' => 'warning'];
        }

        $kpis = [
            ['label' => 'My Requests', 'value' => $myTotal, 'href' => APP_URL . '/?page=requests', 'tone' => 'primary', 'icon' => 'bi-file-earmark-text'],
            ['label' => 'Pending', 'value' => $myPending, 'href' => APP_URL . '/?page=requests&status=pending', 'tone' => 'warning', 'icon' => 'bi-hourglass-split'],
            ['label' => 'Needs Revision', 'value' => $myRevision, 'href' => APP_URL . '/?page=requests&status=revision', 'tone' => 'error', 'icon' => 'bi-pencil-square'],
            ['label' => 'Upcoming Trips', 'value' => $myUpcoming, 'href' => APP_URL . '/?page=requests&status=approved', 'tone' => 'success', 'icon' => 'bi-calendar-event'],
            ['label' => 'Gas Pending', 'value' => $myGas, 'href' => APP_URL . '/?page=gas-vouchers', 'tone' => 'warning', 'icon' => 'bi-fuel-pump'],
        ];

        $queue = [
            'title' => 'Requests Needing Attention',
            'href' => APP_URL . '/?page=requests',
            'rows' => db()->fetchAll(
                "SELECT r.id, r.purpose as title, r.status, r.updated_at, d.name as meta
                 FROM requests r JOIN departments d ON r.department_id = d.id
                 WHERE r.user_id = ? AND r.status IN ('revision','pending','pending_motorpool') AND r.deleted_at IS NULL
                 ORDER BY r.updated_at DESC LIMIT 8",
                [$userId]
            ),
        ];
        $upcoming = dashboardUpcomingTrips(null, 5, $userId);
    }

    // Driver overlay
    if ($driver) {
        $driverRow = db()->fetch('SELECT id FROM drivers WHERE user_id = ? AND deleted_at IS NULL', [$userId]);
        if ($driverRow) {
            $nextTrip = db()->fetch(
                "SELECT r.id, r.purpose, r.start_datetime, v.plate_number
                 FROM requests r LEFT JOIN vehicles v ON r.vehicle_id = v.id
                 WHERE (r.driver_id = ? OR r.requested_driver_id = ?) AND r.status = 'approved'
                 AND r.start_datetime >= NOW() AND r.deleted_at IS NULL
                 ORDER BY r.start_datetime ASC LIMIT 1",
                [$driverRow->id, $driverRow->id]
            );
            $onTripNow = (int) db()->fetchColumn(
                "SELECT COUNT(*) FROM requests WHERE (driver_id = ? OR requested_driver_id = ?) AND status = 'approved'
                 AND actual_dispatch_datetime IS NOT NULL AND actual_arrival_datetime IS NULL AND deleted_at IS NULL",
                [$driverRow->id, $driverRow->id]
            );
            array_unshift($kpis, [
                'label' => 'On Trip Now',
                'value' => $onTripNow,
                'href' => APP_URL . '/?page=my-trips&filter=upcoming',
                'tone' => 'info',
                'icon' => 'bi-truck',
            ]);
            if ($nextTrip) {
                array_unshift($actions, [
                    'label' => 'Next trip: ' . date('M j g:ia', strtotime($nextTrip->start_datetime)),
                    'count' => 1,
                    'href' => APP_URL . '/?page=requests&action=view&id=' . $nextTrip->id,
                    'tone' => 'primary',
                ]);
            }
            $actions[] = ['label' => 'My assigned trips', 'count' => null, 'href' => APP_URL . '/?page=my-trips', 'tone' => 'info'];
        }
    }

    return [
        'kpis' => array_slice($kpis, 0, 5),
        'actions' => $actions,
        'queue' => $queue,
        'upcoming' => $upcoming,
        'vehicleStats' => $vehicleStats,
        'analytics' => $analytics,
        'audit' => $audit,
        'showCharts' => $showCharts && $analytics !== null,
        'showUtilization' => $showUtilization,
        'showNewRequest' => $showNewRequest,
        'showFleetKpis' => $showFleetKpis,
        'isDriver' => $driver,
        'activity' => dashboardRecentActivity(),
    ];
}

function dashboardQueueApprovals(bool $motorpoolMode): array
{
    $href = APP_URL . '/?page=approvals';
    if (isAdmin()) {
        $rows = db()->fetchAll(
            "SELECT r.id, r.purpose as title, r.status, r.updated_at, u.name as meta
             FROM requests r JOIN users u ON r.user_id = u.id
             WHERE r.status IN ('pending','pending_motorpool','revision') AND r.deleted_at IS NULL
             ORDER BY r.created_at DESC LIMIT 8"
        );
    } elseif ($motorpoolMode || isMotorpool()) {
        $rows = db()->fetchAll(
            "SELECT r.id, r.purpose as title, r.status, r.updated_at, u.name as meta
             FROM requests r JOIN users u ON r.user_id = u.id
             WHERE (r.status = 'pending_motorpool' OR r.status = 'revision')
             AND r.motorpool_head_id = ? AND r.deleted_at IS NULL
             ORDER BY r.created_at DESC LIMIT 8",
            [userId()]
        );
    } else {
        $deptId = currentUser()->department_id ?? 0;
        $rows = db()->fetchAll(
            "SELECT r.id, r.purpose as title, r.status, r.updated_at, u.name as meta
             FROM requests r JOIN users u ON r.user_id = u.id
             WHERE r.status = 'pending' AND r.department_id = ? AND r.deleted_at IS NULL
             ORDER BY (r.viewed_at IS NULL) DESC, r.created_at DESC LIMIT 8",
            [$deptId]
        );
    }

    return ['title' => 'Approval Queue', 'href' => $href, 'rows' => $rows];
}

function dashboardUpcomingTrips(?int $departmentId, int $limit = 5, ?int $userId = null): array
{
    $sql = "SELECT r.id, r.start_datetime, r.purpose, u.name as requester_name, v.plate_number
            FROM requests r
            LEFT JOIN users u ON r.user_id = u.id
            LEFT JOIN vehicles v ON r.vehicle_id = v.id AND v.deleted_at IS NULL
            WHERE r.status = 'approved'
            AND r.start_datetime BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
            AND r.deleted_at IS NULL";
    $params = [];
    if ($departmentId) {
        $sql .= ' AND r.department_id = ?';
        $params[] = $departmentId;
    }
    if ($userId) {
        $sql .= ' AND r.user_id = ?';
        $params[] = $userId;
    }
    $sql .= ' ORDER BY r.start_datetime ASC LIMIT ' . (int) $limit;
    return db()->fetchAll($sql, $params);
}

function dashboardRecentActivity(): array
{
    $perPage = 8;
    if (isAdmin() || isMotorpool()) {
        $total = (int) db()->count('requests', 'deleted_at IS NULL');
        $rows = db()->fetchAll(
            "SELECT r.*, u.name as requester_name, d.name as department_name
             FROM requests r
             JOIN users u ON r.user_id = u.id
             JOIN departments d ON r.department_id = d.id
             WHERE r.deleted_at IS NULL
             ORDER BY r.updated_at DESC LIMIT ?",
            [$perPage]
        );
        return ['total' => $total, 'rows' => $rows, 'title' => 'Recent Activity'];
    }

    $userId = userId();
    $total = (int) db()->count('requests', 'user_id = ? AND deleted_at IS NULL', [$userId]);
    $rows = db()->fetchAll(
        "SELECT r.*, u.name as requester_name, d.name as department_name
         FROM requests r
         JOIN users u ON r.user_id = u.id
         JOIN departments d ON r.department_id = d.id
         WHERE r.user_id = ? AND r.deleted_at IS NULL
         ORDER BY r.updated_at DESC LIMIT ?",
        [$userId, $perPage]
    );
    return ['total' => $total, 'rows' => $rows, 'title' => 'My Requests'];
}

function dashboardAnalyticsData(?int $departmentId): array
{
    $thirtyDaysAgo = date('Y-m-d', strtotime('-30 days'));
    $sevenDaysAgo = date('Y-m-d', strtotime('-7 days'));
    $deptClause = $departmentId ? ' AND department_id = ' . (int) $departmentId : '';
    $deptClauseR = $departmentId ? ' AND r.department_id = ' . (int) $departmentId : '';

    $dailyTrips = db()->fetchAll(
        "SELECT DATE(start_datetime) as trip_date, COUNT(*) as count
         FROM requests WHERE DATE(start_datetime) >= ? AND deleted_at IS NULL {$deptClause}
         GROUP BY DATE(start_datetime) ORDER BY trip_date ASC",
        [$sevenDaysAgo]
    );

    $dailyTripData = [];
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-{$i} days"));
        $count = 0;
        foreach ($dailyTrips as $dt) {
            if ($dt->trip_date === $date) {
                $count = (int) $dt->count;
                break;
            }
        }
        $dailyTripData[] = ['date' => date('M/d', strtotime($date)), 'count' => $count];
    }

    $statusDistribution = db()->fetchAll(
        "SELECT status, COUNT(*) as count FROM requests
         WHERE created_at >= ? AND deleted_at IS NULL {$deptClause} GROUP BY status",
        [$thirtyDaysAgo]
    );

    $departmentStats = db()->fetchAll(
        "SELECT d.name as department, COUNT(*) as count
         FROM requests r JOIN departments d ON r.department_id = d.id
         WHERE r.created_at >= ? AND r.deleted_at IS NULL {$deptClauseR}
         GROUP BY d.name ORDER BY count DESC LIMIT 8",
        [$thirtyDaysAgo]
    );

    $vehicleUtilization = db()->fetchAll(
        "SELECT v.plate_number, COUNT(r.id) as trip_count, COALESCE(SUM(r.mileage_actual), 0) as total_mileage
         FROM vehicles v
         LEFT JOIN requests r ON r.vehicle_id = v.id AND r.status = 'completed'
             AND r.actual_arrival_datetime >= ? AND r.deleted_at IS NULL
         WHERE v.deleted_at IS NULL
         GROUP BY v.id ORDER BY trip_count DESC LIMIT 10",
        [$thirtyDaysAgo]
    );

    $peakHours = db()->fetchAll(
        "SELECT HOUR(start_datetime) as hour, COUNT(*) as count FROM requests
         WHERE DATE(start_datetime) >= ? AND deleted_at IS NULL {$deptClause}
         GROUP BY HOUR(start_datetime) ORDER BY hour ASC",
        [$thirtyDaysAgo]
    );
    $hourlyData = array_fill(0, 24, 0);
    foreach ($peakHours as $ph) {
        $hourlyData[(int) $ph->hour] = (int) $ph->count;
    }

    return [
        'dailyTrips' => $dailyTripData,
        'statusDistribution' => $statusDistribution,
        'departmentStats' => $departmentStats,
        'vehicleUtilization' => $vehicleUtilization,
        'peakHours' => $hourlyData,
    ];
}
