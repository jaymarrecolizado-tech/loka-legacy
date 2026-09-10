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
    $queue = ['title' => 'Queue', 'href' => APP_URL . '/?page=requests', 'kind' => 'request', 'rows' => []];
    $upcoming = [];
    $vehicleStats = [];
    $analytics = null;
    $showCharts = false;
    $showUtilization = false;
    $showNewRequest = true;
    $showFleetKpis = false;
    $nextTrip = null;

    $availableVehicles = 0;
    try {
        $availableVehicles = (int) db()->count('vehicles', "status = 'available' AND deleted_at IS NULL");
    } catch (Throwable $e) {
        /* ignore */
    }

    $pendingApprovals = count(badgePendingIdsApprovals());
    $pendingGas = count(badgePendingIdsGasVouchers());
    $pendingTickets = count(badgePendingIdsTripTickets());
    $pendingMaint = count(badgePendingIdsMaintenance());

    if (isAdmin() || isMotorpool()) {
        $showCharts = true;
        $showUtilization = true;
        $showFleetKpis = true;
        $showNewRequest = isAdmin();

        $tripsToday = (int) db()->fetchColumn(
            "SELECT COUNT(*) FROM requests WHERE status = 'approved' AND DATE(start_datetime) = ? AND deleted_at IS NULL",
            [$today]
        );
        $onTrip = (int) db()->fetchColumn(
            "SELECT COUNT(*) FROM requests WHERE status = 'approved' AND actual_dispatch_datetime IS NOT NULL AND actual_arrival_datetime IS NULL AND deleted_at IS NULL"
        );
        $overdue = dashboardOverdueTripCount();

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
            ['label' => 'Trips Today', 'value' => $tripsToday, 'href' => APP_URL . '/?page=live-board&filter=today', 'tone' => 'primary', 'icon' => 'bi-calendar-day'],
            ['label' => 'On Trip Now', 'value' => $onTrip, 'href' => APP_URL . '/?page=live-board&filter=on_trip', 'tone' => 'info', 'icon' => 'bi-truck'],
            ['label' => 'Overdue', 'value' => $overdue, 'href' => APP_URL . '/?page=live-board&filter=overdue', 'tone' => 'error', 'icon' => 'bi-exclamation-triangle'],
            ['label' => 'Available Vehicles', 'value' => $availableVehicles, 'href' => APP_URL . '/?page=vehicles&status=available', 'tone' => 'success', 'icon' => 'bi-car-front'],
            ['label' => 'Gas Pending', 'value' => $pendingGas, 'href' => APP_URL . '/?page=gas-vouchers', 'tone' => 'warning', 'icon' => 'bi-fuel-pump'],
            ['label' => 'Tickets Submitted', 'value' => $pendingTickets, 'href' => APP_URL . '/?page=trip-tickets&status=submitted', 'tone' => 'info', 'icon' => 'bi-journal-check'],
        ];

        $queue = dashboardQueueApprovals(true);
        $upcoming = dashboardUpcomingTrips(null, 5);
        $vehicleStats = db()->fetchAll("SELECT status, COUNT(*) as count FROM vehicles WHERE deleted_at IS NULL GROUP BY status");
        $analytics = dashboardAnalyticsData(null);
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
            $unpaid = (int) db()->fetchColumn(
                "SELECT COUNT(*) FROM gas_vouchers WHERE status = 'approved' AND deleted_at IS NULL"
            );
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

        $queue = dashboardQueueCafVouchers();
        $upcoming = [];
    } else {
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
            'kind' => 'request',
            'rows' => dashboardMapQueueRows(db()->fetchAll(
                "SELECT r.id, r.purpose as title, r.status, r.updated_at, d.name as meta, r.destination
                 FROM requests r JOIN departments d ON r.department_id = d.id
                 WHERE r.user_id = ? AND r.status IN ('revision','pending','pending_motorpool') AND r.deleted_at IS NULL
                 ORDER BY r.updated_at DESC LIMIT 8",
                [$userId]
            ), 'request'),
        ];
        $upcoming = dashboardUpcomingTrips(null, 5, $userId);
    }

    if ($driver) {
        $driverOverlay = dashboardDriverOverlay($userId);
        if ($driverOverlay['nextTrip']) {
            $nextTrip = $driverOverlay['nextTrip'];
            array_unshift($actions, [
                'label' => 'Next trip: ' . date('M j g:ia', strtotime((string) $nextTrip['start_datetime'])),
                'count' => 1,
                'href' => $nextTrip['href'],
                'tone' => 'primary',
            ]);
        }
        if (!$showFleetKpis) {
            array_unshift($kpis, [
                'label' => 'On Trip Now',
                'value' => $driverOverlay['onTripNow'],
                'href' => APP_URL . '/?page=my-trips&filter=upcoming',
                'tone' => 'info',
                'icon' => 'bi-truck',
            ]);
        }
        $actions[] = ['label' => 'My assigned trips', 'count' => null, 'href' => APP_URL . '/?page=my-trips', 'tone' => 'info'];
        if ($driverOverlay['needsTicketCount'] > 0) {
            $actions[] = [
                'label' => 'Create ticket for completed trip',
                'count' => $driverOverlay['needsTicketCount'],
                'href' => $driverOverlay['needsTicketHref'],
                'tone' => 'warning',
            ];
        }
    }

    return [
        'kpis' => array_slice($kpis, 0, 8),
        'actions' => $actions,
        'queue' => $queue,
        'upcoming' => $upcoming,
        'vehicleStats' => $vehicleStats,
        'analytics' => $analytics,
        'showCharts' => $showCharts && $analytics !== null,
        'showUtilization' => $showUtilization,
        'showUpcoming' => !isChiefAdminFinance(),
        'showNewRequest' => $showNewRequest,
        'showFleetKpis' => $showFleetKpis,
        'isDriver' => $driver,
        'nextTrip' => $nextTrip,
        'reportsHref' => APP_URL . '/?page=reports&action=trips',
        'requestsHref' => APP_URL . '/?page=requests',
    ];
}

function dashboardOverdueTripCount(): int
{
    return (int) db()->fetchColumn(
        "SELECT COUNT(*) FROM requests
         WHERE status = 'approved'
           AND end_datetime < NOW()
           AND actual_arrival_datetime IS NULL
           AND deleted_at IS NULL"
    );
}

/**
 * @return array{nextTrip: ?array<string, mixed>, onTripNow: int, needsTicketCount: int, needsTicketHref: string}
 */
function dashboardDriverOverlay(int $userId): array
{
    $empty = [
        'nextTrip' => null,
        'onTripNow' => 0,
        'needsTicketCount' => 0,
        'needsTicketHref' => APP_URL . '/?page=my-trips&filter=past',
    ];
    $driverRow = db()->fetch('SELECT id FROM drivers WHERE user_id = ? AND deleted_at IS NULL', [$userId]);
    if (!$driverRow) {
        return $empty;
    }

    $next = db()->fetch(
        "SELECT r.id, r.purpose, r.destination, r.start_datetime, v.plate_number
         FROM requests r LEFT JOIN vehicles v ON r.vehicle_id = v.id
         WHERE (r.driver_id = ? OR r.requested_driver_id = ?) AND r.status = 'approved'
         AND r.start_datetime >= NOW() AND r.deleted_at IS NULL
         ORDER BY r.start_datetime ASC LIMIT 1",
        [$driverRow->id, $driverRow->id]
    );
    $nextTrip = null;
    if ($next) {
        $nextTrip = [
            'id' => (int) $next->id,
            'purpose' => (string) ($next->purpose ?? ''),
            'destination' => (string) ($next->destination ?? ''),
            'start_datetime' => (string) $next->start_datetime,
            'plate_number' => (string) ($next->plate_number ?? ''),
            'href' => APP_URL . '/?page=requests&action=view&id=' . (int) $next->id,
        ];
    }

    $onTripNow = (int) db()->fetchColumn(
        "SELECT COUNT(*) FROM requests WHERE (driver_id = ? OR requested_driver_id = ?) AND status = 'approved'
         AND actual_dispatch_datetime IS NOT NULL AND actual_arrival_datetime IS NULL AND deleted_at IS NULL",
        [$driverRow->id, $driverRow->id]
    );

    $needsTicket = db()->fetch(
        "SELECT r.id FROM requests r
         LEFT JOIN trip_tickets tt ON tt.request_id = r.id AND tt.deleted_at IS NULL
         WHERE (r.driver_id = ? OR r.requested_driver_id = ?)
           AND r.status = 'completed' AND r.deleted_at IS NULL AND tt.id IS NULL
         ORDER BY r.end_datetime DESC LIMIT 1",
        [$driverRow->id, $driverRow->id]
    );
    $needsTicketCount = (int) db()->fetchColumn(
        "SELECT COUNT(*) FROM requests r
         LEFT JOIN trip_tickets tt ON tt.request_id = r.id AND tt.deleted_at IS NULL
         WHERE (r.driver_id = ? OR r.requested_driver_id = ?)
           AND r.status = 'completed' AND r.deleted_at IS NULL AND tt.id IS NULL",
        [$driverRow->id, $driverRow->id]
    );

    return [
        'nextTrip' => $nextTrip,
        'onTripNow' => $onTripNow,
        'needsTicketCount' => $needsTicketCount,
        'needsTicketHref' => $needsTicket
            ? APP_URL . '/?page=trip-tickets&action=create_form&request_id=' . (int) $needsTicket->id
            : APP_URL . '/?page=my-trips&filter=past',
    ];
}

function dashboardQueueApprovals(bool $motorpoolMode): array
{
    $href = APP_URL . '/?page=approvals';
    if (isAdmin()) {
        $rows = db()->fetchAll(
            "SELECT r.id, r.purpose as title, r.status, r.updated_at, u.name as meta, r.destination
             FROM requests r JOIN users u ON r.user_id = u.id
             WHERE r.status IN ('pending','pending_motorpool','revision') AND r.deleted_at IS NULL
             ORDER BY r.created_at DESC LIMIT 8"
        );
    } elseif ($motorpoolMode || isMotorpool()) {
        $rows = db()->fetchAll(
            "SELECT r.id, r.purpose as title, r.status, r.updated_at, u.name as meta, r.destination
             FROM requests r JOIN users u ON r.user_id = u.id
             WHERE (r.status = 'pending_motorpool' OR r.status = 'revision')
             AND r.motorpool_head_id = ? AND r.deleted_at IS NULL
             ORDER BY r.created_at DESC LIMIT 8",
            [userId()]
        );
    } else {
        $deptId = currentUser()->department_id ?? 0;
        $rows = db()->fetchAll(
            "SELECT r.id, r.purpose as title, r.status, r.updated_at, u.name as meta, r.destination
             FROM requests r JOIN users u ON r.user_id = u.id
             WHERE r.status = 'pending' AND r.department_id = ? AND r.deleted_at IS NULL
             ORDER BY (r.viewed_at IS NULL) DESC, r.created_at DESC LIMIT 8",
            [$deptId]
        );
    }

    return [
        'title' => 'Approval Queue',
        'href' => $href,
        'kind' => 'request',
        'rows' => dashboardMapQueueRows($rows, 'request'),
    ];
}

function dashboardQueueCafVouchers(): array
{
    $rows = [];
    try {
        $rows = db()->fetchAll(
            "SELECT gv.id, gv.voucher_no as title, gv.status, gv.created_at as updated_at,
                    CONCAT(u.name, ' · ', gv.vehicle_plate) as meta, gv.purpose as destination
             FROM gas_vouchers gv JOIN users u ON gv.requested_by_user_id = u.id
             WHERE gv.deleted_at IS NULL
               AND (gv.status = 'pending_approval' OR (gv.status = 'approved' AND gv.payment_status = 'unpaid'))
             ORDER BY gv.created_at DESC LIMIT 8"
        );
    } catch (Throwable $e) {
        $rows = db()->fetchAll(
            "SELECT gv.id, gv.voucher_no as title, gv.status, gv.created_at as updated_at,
                    u.name as meta, gv.purpose as destination
             FROM gas_vouchers gv JOIN users u ON gv.requested_by_user_id = u.id
             WHERE gv.status = 'pending_approval' AND gv.deleted_at IS NULL
             ORDER BY gv.created_at DESC LIMIT 8"
        );
    }

    return [
        'title' => 'Vouchers Needing Action',
        'href' => APP_URL . '/?page=gas-vouchers&status=pending_approval',
        'kind' => 'voucher',
        'rows' => dashboardMapQueueRows($rows, 'voucher'),
    ];
}

/**
 * @param list<object> $rows
 * @return list<array<string, mixed>>
 */
function dashboardMapQueueRows(array $rows, string $kind): array
{
    $mapped = [];
    foreach ($rows as $row) {
        $id = (int) ($row->id ?? 0);
        $mapped[] = [
            'id' => $id,
            'title' => (string) ($row->title ?? ''),
            'status' => (string) ($row->status ?? ''),
            'meta' => (string) ($row->meta ?? ''),
            'destination' => (string) ($row->destination ?? ''),
            'age' => dashboardRelativeAge($row->updated_at ?? null),
            'href' => $kind === 'voucher'
                ? APP_URL . '/?page=gas-vouchers&action=view&id=' . $id
                : APP_URL . '/?page=requests&action=view&id=' . $id,
        ];
    }
    return $mapped;
}

function dashboardRelativeAge(?string $datetime): string
{
    if (!$datetime) {
        return '—';
    }
    $ts = strtotime($datetime);
    if ($ts === false) {
        return '—';
    }
    $diff = time() - $ts;
    if ($diff < 60) {
        return 'just now';
    }
    if ($diff < 3600) {
        return (int) floor($diff / 60) . 'm ago';
    }
    if ($diff < 86400) {
        return (int) floor($diff / 3600) . 'h ago';
    }
    return (int) floor($diff / 86400) . 'd ago';
}

function dashboardUpcomingTrips(?int $departmentId, int $limit = 5, ?int $userId = null): array
{
    $sql = "SELECT r.id, r.start_datetime, r.purpose, r.destination, u.name as requester_name, v.plate_number
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

    $statusDistribution = dashboardRowsToArrays(db()->fetchAll(
        "SELECT status, COUNT(*) as count FROM requests
         WHERE created_at >= ? AND deleted_at IS NULL {$deptClause} GROUP BY status",
        [$thirtyDaysAgo]
    ));

    $departmentStats = dashboardRowsToArrays(db()->fetchAll(
        "SELECT d.name as department, COUNT(*) as count
         FROM requests r JOIN departments d ON r.department_id = d.id
         WHERE r.created_at >= ? AND r.deleted_at IS NULL {$deptClauseR}
         GROUP BY d.name ORDER BY count DESC LIMIT 8",
        [$thirtyDaysAgo]
    ));

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

    $dailyTotal = 0;
    foreach ($dailyTripData as $row) {
        $dailyTotal += (int) $row['count'];
    }

    return [
        'dailyTrips' => $dailyTripData,
        'statusDistribution' => $statusDistribution,
        'departmentStats' => $departmentStats,
        'peakHours' => $hourlyData,
        'hasDaily' => $dailyTotal > 0,
        'hasStatus' => $statusDistribution !== [],
        'hasDepartment' => $departmentStats !== [],
        'hasPeak' => array_sum($hourlyData) > 0,
    ];
}

/**
 * @param list<object|array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function dashboardRowsToArrays(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        if (is_object($row)) {
            $row = get_object_vars($row);
        }
        if (isset($row['count'])) {
            $row['count'] = (int) $row['count'];
        }
        $out[] = $row;
    }
    return $out;
}
