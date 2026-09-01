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
$templateType = get('template', 'vehicle'); // 'vehicle' or 'travelorder'

// Fetch vehicles for dropdown
$vehicles = db()->fetchAll("SELECT id, plate_number, make, model FROM vehicles WHERE deleted_at IS NULL ORDER BY plate_number");

if ($_SERVER['REQUEST_METHOD'] === 'POST' || $isPrint) {
    if (!$vehicleId)
        $errors[] = "Please select a vehicle.";
    if (!$dateFrom)
        $errors[] = "Please select a start date.";
    if (!$dateTo)
        $errors[] = "Please select a end date.";

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
            // Include html layout directly based on template type
            if ($templateType === 'travelorder') {
                require_once __DIR__ . '/summary-print-travelorder.php';
            } else {
                require_once __DIR__ . '/summary-print.php';
            }
            exit;
        }
    }
}

require_once INCLUDES_PATH . '/header.php';
?>

<div class="p-4 md:p-6 space-y-6">
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
            <h1 class="text-2xl font-bold">
                <i class="bi bi-file-earmark-spreadsheet mr-2"></i>Generate Vehicle Summary
            </h1>
            <nav class="text-sm breadcrumbs mt-1">
                <ul>
                    <li><a href="<?= APP_URL ?>">Dashboard</a></li>
                    <li><a href="<?= APP_URL ?>/?page=my-trip-tickets">My Trip Tickets</a></li>
                    <li class="text-base-content/60">Generate Summary</li>
                </ul>
            </nav>
        </div>
        <a href="?page=my-trip-tickets" class="loka-btn-secondary">
            <i class="bi bi-arrow-left mr-1"></i>Back
        </a>
    </div>

    <div class="flex justify-center">
        <div class="w-full max-w-2xl">
            <div class="loka-card">
                <div class="p-6">
                    <div class="font-bold text-lg mb-4">Filter Parameters</div>

                    <?php if (!empty($errors)): ?>
                        <div class="loka-alert loka-alert-danger">
                            <ul class="list-disc list-inside">
                                <?php foreach ($errors as $err): ?>
                                    <li><?= e($err) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <form method="GET" action="" target="_blank">
                        <!-- Keep page and action hidden -->
                        <input type="hidden" name="page" value="my-trip-tickets">
                        <input type="hidden" name="action" value="generate-summary">
                        <input type="hidden" name="print" value="1">

                        <div class="flex flex-col gap-1.5 mb-4">
                            <label class="label">
                                <span class="label-text font-medium">Vehicle <span class="text-error">*</span></span>
                            </label>
                            <select class="select select-bordered w-full" name="vehicle_id" required>
                                <option value="">Select Vehicle...</option>
                                <?php foreach ($vehicles as $v): ?>
                                    <option value="<?= $v->id ?>" <?= $vehicleId == $v->id ? 'selected' : '' ?>>
                                        <?= e($v->plate_number) ?> - <?= e($v->make) ?> <?= e($v->model) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div class="flex flex-col gap-1.5">
                                <label class="label">
                                    <span class="label-text font-medium">Date From <span class="text-error">*</span></span>
                                </label>
                                <input type="date" class="input input-bordered w-full" name="date_from" value="<?= $dateFrom ?>" required>
                            </div>
                            <div class="flex flex-col gap-1.5">
                                <label class="label">
                                    <span class="label-text font-medium">Date To <span class="text-error">*</span></span>
                                </label>
                                <input type="date" class="input input-bordered w-full" name="date_to" value="<?= $dateTo ?>" required>
                            </div>
                        </div>

                        <div class="flex flex-col gap-1.5 mb-4">
                            <label class="label">
                                <span class="label-text font-medium">Template Style</span>
                            </label>
                            <select class="select select-bordered w-full" name="template" id="templateSelect">
                                <option value="vehicle" <?= $templateType === 'vehicle' ? 'selected' : '' ?>>Vehicle Trip Ticket</option>
                                <option value="travelorder" <?= $templateType === 'travelorder' ? 'selected' : '' ?>>Travel Order</option>
                            </select>
                            <label class="label">
                                <span class="label-text-alt text-base-content/60">Select the format for your trip ticket printout.</span>
                            </label>
                        </div>

                        <div class="mt-4">
                            <button type="submit" class="bg-success text-success-content hover:bg-success/90 px-4 py-2 text-sm font-medium rounded-xl inline-flex items-center gap-2 transition-colors w-full">
                                <i class="bi bi-printer mr-2"></i>Generate & Print Ticket
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>