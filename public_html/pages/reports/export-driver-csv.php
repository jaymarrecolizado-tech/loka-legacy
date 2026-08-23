<?php
/**
 * LOKA - Export Driver Report to CSV (Complete)
 */

requireReportsAccess();

$driverId = get('driver_id');
$startDate = get('start_date', date('Y-m-01'));
$endDate = get('end_date', date('Y-m-t'));

// Non-approver drivers may only export their own report
if (isSelfScopedDriverReporter()) {
    $driverId = (string) currentDriverId();
}

if (!$driverId) {
    redirectWith('/?page=reports&action=driver', 'danger', 'Please select a driver.');
}

$driverInfo = db()->fetch(
    "SELECT d.*, u.name, u.email, u.phone,
            dept.name as department_name
     FROM drivers d
     JOIN users u ON d.user_id = u.id
     LEFT JOIN departments dept ON u.department_id = dept.id
     WHERE d.id = ? AND d.deleted_at IS NULL",
    [$driverId]
);

if (!$driverInfo) {
    redirectWith('/?page=reports&action=driver', 'danger', 'Driver not found.');
}

$trips = db()->fetchAll(
    "SELECT r.id, r.created_at, r.start_datetime, r.end_datetime, r.purpose, r.destination,
            r.status, r.passenger_count, r.notes,
            r.actual_dispatch_datetime, r.actual_arrival_datetime, r.guard_notes,
            r.mileage_start, r.mileage_end, r.mileage_actual,
            u.name as requester_name, u.email as requester_email, d.name as department_name,
            v.plate_number, v.make as vehicle_make, v.model as vehicle_model, v.color as vehicle_color,
            tt.fuel_consumed, tt.fuel_cost,
            TIMESTAMPDIFF(MINUTE, r.start_datetime, r.end_datetime) as planned_duration,
            TIMESTAMPDIFF(MINUTE, r.actual_dispatch_datetime, r.actual_arrival_datetime) as actual_duration,
            dispatch_g.name as dispatched_by, arrival_g.name as received_by
     FROM requests r
     JOIN users u ON r.user_id = u.id
     LEFT JOIN departments d ON r.department_id = d.id
     LEFT JOIN vehicles v ON r.vehicle_id = v.id
     LEFT JOIN users dispatch_g ON r.dispatch_guard_id = dispatch_g.id
     LEFT JOIN users arrival_g ON r.arrival_guard_id = arrival_g.id
     LEFT JOIN trip_tickets tt ON r.id = tt.request_id AND tt.deleted_at IS NULL
     WHERE r.driver_id = ? 
     AND r.start_datetime BETWEEN ? AND ?
     AND r.deleted_at IS NULL
     ORDER BY r.start_datetime DESC",
    [$driverId, $startDate, $endDate . ' 23:59:59']
);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="driver_report_' . preg_replace('/[^a-zA-Z0-9]/', '_', $driverInfo->name) . '_' . $startDate . '_to_' . $endDate . '.csv"');

$output = fopen('php://output', 'w');

// Driver info header
fputcsv($output, ['DRIVER REPORT']);
fputcsv($output, ['Driver Name', $driverInfo->name]);
fputcsv($output, ['Phone', $driverInfo->phone ?: '-']);
fputcsv($output, ['Email', $driverInfo->email ?: '-']);
fputcsv($output, ['License No', $driverInfo->license_number ?: '-']);
fputcsv($output, ['License Class', $driverInfo->license_class ?: '-']);
fputcsv($output, ['License Expiry', $driverInfo->license_expiry ?: '-']);
fputcsv($output, ['Experience', $driverInfo->years_experience ? $driverInfo->years_experience . ' years' : '-']);
fputcsv($output, ['Department', $driverInfo->department_name ?: '-']);
fputcsv($output, ['Status', ucfirst($driverInfo->status)]);
fputcsv($output, ['Emergency Contact', $driverInfo->emergency_contact_name ?: '-']);
fputcsv($output, ['Emergency Phone', $driverInfo->emergency_contact_phone ?: '-']);
fputcsv($output, ['Period', $startDate . ' to ' . $endDate]);
fputcsv($output, []);
fputcsv($output, ['TRIP DETAILS']);
fputcsv($output, [
    'Trip ID',
    'Created',
    'Scheduled Start',
    'Scheduled End',
    'Purpose',
    'Destination',
    'Requester',
    'Requester Email',
    'Department',
    'Vehicle Plate',
    'Vehicle Make',
    'Vehicle Model',
    'Vehicle Color',
    'Passengers',
    'Status',
    'Planned Duration',
    'Actual Duration',
    'Actual Dispatch',
    'Actual Arrival',
    'Mileage Start',
    'Mileage End',
    'Distance (km)',
    'Fuel Consumed (L)',
    'Fuel Cost',
    'Dispatched By',
    'Received By',
    'Request Notes',
    'Guard Notes'
]);

$totalHours = 0;
$totalPassengers = 0;
$totalKm = 0;

foreach ($trips as $trip) {
    $plannedHrs = $trip->planned_duration ? floor($trip->planned_duration / 60) : 0;
    $plannedMins = $trip->planned_duration ? $trip->planned_duration % 60 : 0;
    $actualHrs = $trip->actual_duration ? floor($trip->actual_duration / 60) : 0;
    $actualMins = $trip->actual_duration ? $trip->actual_duration % 60 : 0;
    
    if ($trip->actual_duration) $totalHours += $trip->actual_duration / 60;
    elseif ($trip->planned_duration) $totalHours += $trip->planned_duration / 60;
    $totalPassengers += $trip->passenger_count;
    
    if ($trip->mileage_actual) $totalKm += $trip->mileage_actual;

    fputcsv($output, [
        $trip->id,
        $trip->created_at,
        $trip->start_datetime,
        $trip->end_datetime,
        $trip->purpose,
        $trip->destination,
        $trip->requester_name,
        $trip->requester_email ?: '-',
        $trip->department_name ?: '-',
        $trip->plate_number ?: '-',
        $trip->vehicle_make ?: '-',
        $trip->vehicle_model ?: '-',
        $trip->vehicle_color ?: '-',
        $trip->passenger_count,
        ucfirst($trip->status),
        $trip->planned_duration ? "{$plannedHrs}h {$plannedMins}m" : '-',
        $trip->actual_duration ? "{$actualHrs}h {$actualMins}m" : '-',
        $trip->actual_dispatch_datetime ?: '-',
        $trip->actual_arrival_datetime ?: '-',
        $trip->mileage_start ?: '-',
        $trip->mileage_end ?: '-',
        $trip->mileage_actual ?: '-',
        $trip->fuel_consumed ?: '-',
        $trip->fuel_cost ?: '-',
        $trip->dispatched_by ?: '-',
        $trip->received_by ?: '-',
        $trip->notes ?: '-',
        $trip->guard_notes ?: '-'
    ]);
}

fputcsv($output, []);
fputcsv($output, ['Total Trips', count($trips)]);
fputcsv($output, ['Total Hours', number_format($totalHours, 1) . 'h']);
fputcsv($output, ['Total Distance', number_format($totalKm) . ' km']);
fputcsv($output, ['Total Passengers', $totalPassengers]);
fputcsv($output, ['Generated', date('Y-m-d H:i:s')]);

fclose($output);
exit;
