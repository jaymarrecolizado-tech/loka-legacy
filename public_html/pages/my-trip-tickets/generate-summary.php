<?php
/**
 * LOKA - Generate Vehicle Summary Trip Ticket
 */

// Only drivers can access this page
if (!isDriver() && !isMotorpool() && !isAdmin()) {
    redirectWith('/?page=dashboard', 'danger', 'You do not have permission to view this page.');
}

$pageTitle = 'Generate Vehicle Summary';
$errors = [];

$vehicleId = getInt('vehicle_id');
$dateFrom = get('date_from', date('Y-m-01')); // Default to 1st of current month
$dateTo = get('date_to', date('Y-m-t')); // Default to last day of current month

// Action can be to print
$isPrint = get('print') == '1';

// Fetch vehicles for dropdown
$vehicles = db()->fetchAll("SELECT id, plate_number, make, model FROM vehicles WHERE deleted_at IS NULL ORDER BY plate_number");

if ($_SERVER['REQUEST_METHOD'] === 'POST' || $isPrint) {
    if (!$vehicleId)
        $errors[] = "Please select a vehicle.";
    if (!$dateFrom)
        $errors[] = "Please select a start date.";
    if (!$dateTo)
        $errors[] = "Please select an end date.";

    if (empty($errors)) {
        // Fetch driver information (the generator)
        $driver = db()->fetch(
            "SELECT d.*, u.name, u.email, u.phone
             FROM drivers d
             JOIN users u ON d.user_id = u.id
             WHERE d.user_id = ? AND d.deleted_at IS NULL",
            [userId()]
        );
        $generatorName = $driver ? $driver->name : currentUser()->name;

        // Fetch vehicle info
        $vInfo = db()->fetch("SELECT * FROM vehicles WHERE id = ?", [$vehicleId]);

        // Fetch guards for dropdown
        $guards = db()->fetchAll("SELECT id, name FROM users WHERE role = 'guard' ORDER BY name");

        // Fetch drivers for dropdown
        $drivers = db()->fetchAll("SELECT u.id, u.name FROM drivers d JOIN users u ON d.user_id = u.id ORDER BY u.name");

        // Fetch all completed trips (requests) for this vehicle within date range
        $sql = "SELECT r.id as req_id, r.destination, r.purpose,
                       du.name as trip_driver_name, r.passenger_count as passengers,
                       COALESCE(r.actual_dispatch_datetime, r.start_datetime) as start_date,
                       COALESCE(r.actual_arrival_datetime, r.end_datetime) as end_date,
                       r.mileage_start as start_mileage, r.mileage_end as end_mileage,
                       r.mileage_actual as distance_traveled,
                       tt.fuel_consumed, tt.fuel_cost
                FROM requests r
                LEFT JOIN trip_tickets tt ON r.id = tt.request_id AND tt.deleted_at IS NULL
                LEFT JOIN drivers d ON r.driver_id = d.id
                LEFT JOIN users du ON d.user_id = du.id
                WHERE r.vehicle_id = ?
                  AND r.status = 'completed'
                  AND DATE(COALESCE(r.actual_dispatch_datetime, r.start_datetime)) >= ?
                  AND DATE(COALESCE(r.actual_dispatch_datetime, r.start_datetime)) <= ?
                ORDER BY COALESCE(r.actual_dispatch_datetime, r.start_datetime) ASC";

        $trips = db()->fetchAll($sql, [$vehicleId, $dateFrom, $dateTo]);

        // Fetch passengers for each trip
        foreach ($trips as $t) {
            $passenger_sql = "SELECT
                    CASE
                        WHEN rp.user_id IS NOT NULL THEN u.name
                        ELSE rp.guest_name
                    END as name,
                    CASE
                        WHEN rp.user_id IS NOT NULL THEN CONCAT(u.name, ' (Passenger)')
                        ELSE CONCAT(rp.guest_name, ' (Guest)')
                    END as display_name
                FROM request_passengers rp
                LEFT JOIN users u ON rp.user_id = u.id
                WHERE rp.request_id = ?";
            $t->passengers_list = db()->fetchAll($passenger_sql, [$t->req_id]);

            // Build full list: driver + passengers
            $t->all_people = [];
            if ($t->trip_driver_name) {
                $t->all_people[] = ['name' => $t->trip_driver_name, 'role' => 'Driver'];
            }
            foreach ($t->passengers_list as $p) {
                $role = (strpos($p->display_name, '(Guest)') !== false) ? 'Guest' : 'Passenger';
                $t->all_people[] = ['name' => $p->name, 'role' => $role];
            }
        }

        // Calculate trip ticket number: year-vehicle plate number-month and week of the month
        // Determine month with most days in range, then week based on first day in that month
        $start = new DateTime($dateFrom);
        $end = new DateTime($dateTo);
        $end->modify('+1 day'); // include end date
        $interval = new DateInterval('P1D');
        $period = new DatePeriod($start, $interval, $end);

        $monthCounts = [];
        foreach ($period as $dt) {
            $month = $dt->format('m');
            $monthCounts[$month] = ($monthCounts[$month] ?? 0) + 1;
        }

        $maxCount = max($monthCounts);
        $selectedMonth = array_keys($monthCounts, $maxCount)[0]; // if tie, takes first (earliest month)

        // Find first date in selected month
        $firstDateInMonth = null;
        foreach ($period as $dt) {
            if ($dt->format('m') == $selectedMonth) {
                $firstDateInMonth = $dt;
                break;
            }
        }

        $year = date('Y', strtotime($dateFrom));
        $day = (int)$firstDateInMonth->format('d');
        $week = ceil($day / 7);
        $tripTicketNumber = "{$year}-{$vInfo->plate_number}-{$selectedMonth}" . str_pad($week, 2, '0', STR_PAD_LEFT);

        // Calculate totals
        $totalFuel = 0;
        $totalCost = 0;
        $totalDist = 0;
        $fuelEntries = [];

        foreach ($trips as $t) {
            $fCons = floatval($t->fuel_consumed);
            $fCost = floatval($t->fuel_cost);
            $dist = floatval($t->distance_traveled);

            $totalFuel += $fCons;
            $totalCost += $fCost;
            $totalDist += $dist;

            if ($fCons > 0 || $fCost > 0) {
                $fuelEntries[] = [
                    'qty' => $fCons,
                    'amt' => $fCost,
                    'date' => date('Y-m-d', strtotime($t->start_date)),
                    'items' => '', // Generic 
                    'remarks' => '' // Gas voucher 
                ];
            }
        }

        if ($isPrint) {
            // Include html layout directly
            require_once __DIR__ . '/summary-print.php';
            exit;
        }
    }
}

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <div class="mb-4 d-flex justify-content-between align-items-center">
        <div>
            <h4 class="mb-1"><i class="bi bi-file-earmark-spreadsheet me-2"></i>Generate Vehicle Summary</h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/?page=my-trip-tickets">My Trip Tickets</a></li>
                    <li class="breadcrumb-item active">Generate Summary</li>
                </ol>
            </nav>
        </div>
        <a href="?page=my-trip-tickets" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>

    <div class="row row-cols-md-2 justify-content-center">
        <div class="col-md-8">
            <div class="card shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0">Filter Parameters</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger">
                            <ul class="mb-0">
                                <?php foreach ($errors as $e): ?>
                                    <li>
                                        <?= e($e) ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <form method="GET" action="" target="_blank">
                        <!-- Keep page and action hidden -->
                        <input type="hidden" name="page" value="my-trip-tickets">
                        <input type="hidden" name="action" value="generate-summary">
                        <input type="hidden" name="print" value="1">

                        <div class="mb-3">
                            <label class="form-label">Vehicle <span class="text-danger">*</span></label>
                            <select class="form-select" name="vehicle_id" required>
                                <option value="">Select Vehicle...</option>
                                <?php foreach ($vehicles as $v): ?>
                                    <option value="<?= $v->id ?>" <?= $vehicleId == $v->id ? 'selected' : '' ?>>
                                        <?= e($v->plate_number) ?> -
                                        <?= e($v->make) ?>
                                        <?= e($v->model) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Date From <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="date_from" value="<?= $dateFrom ?>"
                                    required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Date To <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="date_to" value="<?= $dateTo ?>" required>
                            </div>
                        </div>

                        <div class="d-grid mt-3">
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-printer me-2"></i>Generate & Print Ticket
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>