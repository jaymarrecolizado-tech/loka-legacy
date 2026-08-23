<?php
/**
 * LOKA - Export Vehicle History to CSV (Complete)
 */

requireRole(ROLE_APPROVER);

$vehicleId = get('vehicle_id');
$startDate = get('start_date', date('Y-m-01'));
$endDate = get('end_date', date('Y-m-t'));

if (!$vehicleId) {
    redirectWith('/?page=reports&action=vehicle-history', 'danger', 'Please select a vehicle.');
}

$vehicleInfo = db()->fetch(
    "SELECT v.*, vt.name as type_name
     FROM vehicles v
     LEFT JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
     WHERE v.id = ? AND v.deleted_at IS NULL",
    [$vehicleId]
);

if (!$vehicleInfo) {
    redirectWith('/?page=reports&action=vehicle-history', 'danger', 'Vehicle not found.');
}

$trips = db()->fetchAll(
    "SELECT r.id, r.created_at, r.start_datetime, r.end_datetime, r.purpose, r.destination,
            r.status, r.passenger_count, r.notes,
            r.actual_dispatch_datetime, r.actual_arrival_datetime, r.guard_notes,
            r.mileage_start, r.mileage_end, r.mileage_actual,
            r.has_travel_order, r.travel_order_number,
            u.name as requester_name, u.email as requester_email, d.name as department_name,
            dr_user.name as driver_name,
            tt.fuel_consumed, tt.fuel_cost,
            TIMESTAMPDIFF(MINUTE, r.start_datetime, r.end_datetime) as planned_duration,
            TIMESTAMPDIFF(MINUTE, r.actual_dispatch_datetime, r.actual_arrival_datetime) as actual_duration,
            dispatch_g.name as dispatched_by, arrival_g.name as received_by
     FROM requests r
     JOIN users u ON r.user_id = u.id
     LEFT JOIN departments d ON r.department_id = d.id
     LEFT JOIN drivers dr ON r.driver_id = dr.id
     LEFT JOIN users dr_user ON dr.user_id = dr_user.id
     LEFT JOIN users dispatch_g ON r.dispatch_guard_id = dispatch_g.id
     LEFT JOIN users arrival_g ON r.arrival_guard_id = arrival_g.id
     LEFT JOIN trip_tickets tt ON r.id = tt.request_id AND tt.deleted_at IS NULL
     WHERE r.vehicle_id = ? 
     AND r.start_datetime BETWEEN ? AND ?
     AND r.deleted_at IS NULL
     ORDER BY r.start_datetime DESC",
    [$vehicleId, $startDate, $endDate . ' 23:59:59']
);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="vehicle_history_' . $vehicleInfo->plate_number . '_' . $startDate . '_to_' . $endDate . '.csv"');

$output = fopen('php://output', 'w');

// Vehicle info header
fputcsv($output, ['VEHICLE HISTORY REPORT']);
fputcsv($output, ['Vehicle', $vehicleInfo->plate_number . ' - ' . $vehicleInfo->make . ' ' . $vehicleInfo->model]);
fputcsv($output, ['Type', $vehicleInfo->type_name ?: '-']);
fputcsv($output, ['Status', ucfirst($vehicleInfo->status)]);
fputcsv($output, ['Mileage', number_format($vehicleInfo->mileage ?? 0) . ' km']);
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
    'Driver',
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
    'Travel Order',
    'Dispatched By',
    'Received By',
    'Request Notes',
    'Guard Notes'
]);

foreach ($trips as $trip) {
    $plannedHrs = $trip->planned_duration ? floor($trip->planned_duration / 60) : 0;
    $plannedMins = $trip->planned_duration ? $trip->planned_duration % 60 : 0;
    $actualHrs = $trip->actual_duration ? floor($trip->actual_duration / 60) : 0;
    $actualMins = $trip->actual_duration ? $trip->actual_duration % 60 : 0;
    
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
        $trip->driver_name ?: '-',
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
        $trip->has_travel_order ? ($trip->travel_order_number ?: 'Yes') : 'No',
        $trip->dispatched_by ?: '-',
        $trip->received_by ?: '-',
        $trip->notes ?: '-',
        $trip->guard_notes ?: '-'
    ]);
}

fputcsv($output, []);
fputcsv($output, ['Total Trips', count($trips)]);
fputcsv($output, ['Generated', date('Y-m-d H:i:s')]);

fclose($output);
exit;
