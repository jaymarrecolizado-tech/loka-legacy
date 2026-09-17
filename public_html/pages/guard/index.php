<?php
/**
 * LOKA - Guard Dashboard
 *
 * Guards can view today's scheduled trips and record:
 * - Dispatch time (when vehicle leaves)
 * - Arrival time (when vehicle returns)
 */

requireRole(ROLE_GUARD);

$today = date('Y-m-d');
$filter = get('filter', 'today'); // today, pending_dispatch, pending_arrival, completed

// Build query based on filter
$sql = "SELECT r.*,
            u.name as requester_name, u.phone as requester_phone,
            d.name as department_name,
            v.plate_number, v.make, v.model as vehicle_model,
            COALESCE(v.odometer_broken, 0) as odometer_broken,
            v.mileage as vehicle_mileage,
            dr.license_number as driver_license,
            driver_user.name as driver_name, driver_user.phone as driver_phone,
            mph.name as motorpool_head_name,
            dispatch_guard.name as dispatch_guard_name,
            arrival_guard.name as arrival_guard_name
     FROM requests r
     JOIN users u ON r.user_id = u.id
     JOIN departments d ON r.department_id = d.id
     LEFT JOIN vehicles v ON r.vehicle_id = v.id AND v.deleted_at IS NULL
     LEFT JOIN drivers dr ON r.driver_id = dr.id AND dr.deleted_at IS NULL
     LEFT JOIN users driver_user ON dr.user_id = driver_user.id
     LEFT JOIN users mph ON r.motorpool_head_id = mph.id
     LEFT JOIN users dispatch_guard ON r.dispatch_guard_id = dispatch_guard.id
     LEFT JOIN users arrival_guard ON r.arrival_guard_id = arrival_guard.id
     WHERE r.status = 'approved'
     AND r.deleted_at IS NULL";

$params = [];

switch ($filter) {
    case 'pending_dispatch':
        $sql .= " AND r.status = 'approved' AND r.actual_dispatch_datetime IS NULL";
        break;
    case 'pending_arrival':
        $sql .= " AND r.status = 'approved' AND r.actual_dispatch_datetime IS NOT NULL
                  AND r.actual_arrival_datetime IS NULL";
        break;
    case 'completed':
        $sql .= " AND r.status = 'approved' AND r.actual_arrival_datetime IS NOT NULL";
        break;
    case 'today':
    default:
        $sql .= " AND r.status = 'approved' AND DATE(r.start_datetime) = ?";
        $params[] = $today;
        break;
}

$sql .= " ORDER BY r.start_datetime ASC";

$trips = db()->fetchAll($sql, $params);

$statsToday = db()->fetch(
    "SELECT
        COUNT(*) as total_scheduled,
        SUM(CASE WHEN actual_dispatch_datetime IS NULL THEN 1 ELSE 0 END) as pending_dispatch,
        SUM(CASE WHEN actual_dispatch_datetime IS NOT NULL AND actual_arrival_datetime IS NULL THEN 1 ELSE 0 END) as on_trip,
        SUM(CASE WHEN actual_arrival_datetime IS NOT NULL THEN 1 ELSE 0 END) as completed
     FROM requests
     WHERE status = 'approved'
     AND DATE(start_datetime) = ?
     AND deleted_at IS NULL",
    [$today]
);

$tabCounts = db()->fetch(
    "SELECT
        COUNT(*) as all_scheduled,
        SUM(CASE WHEN actual_dispatch_datetime IS NULL THEN 1 ELSE 0 END) as all_pending_dispatch,
        SUM(CASE WHEN actual_dispatch_datetime IS NOT NULL AND actual_arrival_datetime IS NULL THEN 1 ELSE 0 END) as all_on_trip,
        SUM(CASE WHEN actual_arrival_datetime IS NOT NULL THEN 1 ELSE 0 END) as all_completed
     FROM requests
     WHERE status = 'approved'
     AND deleted_at IS NULL"
);

$obBoundByRequest = obBoundPassSlipsByRequestId();
$guardEsign = obUserEsignPath(userId());

$pageTitle = 'Guard Dashboard';
require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="bi bi-shield-check me-2"></i>Guard Dashboard</h4>
            <p class="text-muted mb-0">Track vehicle dispatch and arrival times</p>
        </div>
        <div>
            <span class="badge bg-light text-dark border">
                <i class="bi bi-calendar3 me-1"></i><?= formatDate($today) ?>
            </span>
        </div>
    </div>

    <?php require_once __DIR__ . '/partials/ob_section.php'; // OB Pass Slip time stamps (Plan #22) ?>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="bg-primary bg-opacity-10 rounded p-3">
                                <i class="bi bi-calendar-check text-primary fs-4"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="text-muted mb-1">Scheduled Today</h6>
                            <h3 class="mb-0"><?= $statsToday->total_scheduled ?? 0 ?></h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="bg-warning bg-opacity-10 rounded p-3">
                                <i class="bi bi-car-front text-warning fs-4"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="text-muted mb-1">Pending Dispatch</h6>
                            <h3 class="mb-0"><?= $tabCounts->all_pending_dispatch ?? 0 ?></h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="bg-info bg-opacity-10 rounded p-3">
                                <i class="bi bi-arrow-return-left text-info fs-4"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="text-muted mb-1">On Trip</h6>
                            <h3 class="mb-0"><?= $tabCounts->all_on_trip ?? 0 ?></h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <div class="bg-success bg-opacity-10 rounded p-3">
                                <i class="bi bi-check-circle text-success fs-4"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="text-muted mb-1">Completed</h6>
                            <h3 class="mb-0"><?= $tabCounts->all_completed ?? 0 ?></h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php require_once __DIR__ . '/partials/trip_list.php'; ?>
</div>

<?php require_once __DIR__ . '/partials/dispatch_modals.php'; ?>

<script>
function exportCompletedTrips() {
    const rows = document.querySelectorAll('tbody tr');
    const data = [['ID', 'Requester', 'Department', 'Date', 'Time', 'Vehicle', 'Driver', 'Destination', 'Dispatch Time', 'Arrival Time', 'Mileage Start', 'Mileage End', 'Mileage Actual', 'Travel Order', 'OB Slip']];

    rows.forEach(row => {
        const cells = row.querySelectorAll('td');
        if (cells.length > 0) {
            const id = cells[0].textContent.trim().replace('#', '');
            const requester = cells[1].textContent.trim();
            const dateTime = cells[2].textContent.trim();
            const vehicle = cells[3].textContent.trim();
            const driver = cells[4].textContent.trim();
            const destination = cells[5].textContent.trim();
            const dispatchTime = cells[7].textContent.trim();
            const arrivalTime = cells[8] ? cells[8].textContent.trim() : '';

            const dateParts = dateTime.match(/(\d{2}\/\d{2}\/\d{4})/g);
            const tripDate = dateParts ? dateParts[0] : '';
            const startTime = dateTime.includes('→') ? dateTime.split('→')[0].trim() : '';
            const endTime = dateTime.includes('→') ? dateTime.split('→')[1].trim() : '';

            data.push([
                id, requester, '', tripDate, startTime + ' - ' + endTime,
                vehicle, driver, destination, dispatchTime, arrivalTime,
                '', '', '', '', ''
            ]);
        }
    });

    let csv = data.map(row => row.map(cell => `"${String(cell).replace(/"/g, '""')}"`).join(',')).join('\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'completed_trips_' + new Date().toISOString().slice(0, 10) + '.csv';
    link.click();
}
</script>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
