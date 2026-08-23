<?php
/**
 * LOKA - Export Report to CSV (Complete)
 */

requireRole(ROLE_APPROVER);

$startDate = get('start_date', date('Y-m-01'));
$endDate = get('end_date', date('Y-m-t'));
$status = get('status', '');
$filterDept = get('department_id', '');
$filterVehicle = get('vehicle_id', '');
$filterDriver = get('driver_id', '');

$maxRows = 10000;
$limit = min((int) get('limit', $maxRows), $maxRows);

$whereClause = "WHERE r.deleted_at IS NULL AND r.created_at BETWEEN ? AND ?";
$params = [$startDate, $endDate . ' 23:59:59'];

if ($status) {
    $whereClause .= " AND r.status = ?";
    $params[] = $status;
}
if ($filterDept) {
    $whereClause .= " AND r.department_id = ?";
    $params[] = $filterDept;
}
if ($filterVehicle) {
    $whereClause .= " AND r.vehicle_id = ?";
    $params[] = $filterVehicle;
}
if ($filterDriver) {
    $whereClause .= " AND r.driver_id = ?";
    $params[] = $filterDriver;
}

$params[] = $limit;

$requests = db()->fetchAll(
    "SELECT r.id, r.created_at, r.start_datetime, r.end_datetime, r.purpose, r.destination,
            r.passenger_count, r.status, r.notes,
            r.mileage_start, r.mileage_end, r.mileage_actual,
            r.has_travel_order, r.travel_order_number,
            u.name as requester, u.email as requester_email, u.phone as requester_phone,
            dept.name as department,
            v.plate_number, v.make as vehicle_make, v.model as vehicle_model, v.year as vehicle_year,
            v.color as vehicle_color, vt.name as vehicle_type,
            dr_u.name as driver, d.license_number as driver_license,
            r.actual_dispatch_datetime, r.actual_arrival_datetime, r.guard_notes,
            TIMESTAMPDIFF(MINUTE, r.start_datetime, r.end_datetime) as planned_duration_minutes,
            TIMESTAMPDIFF(MINUTE, r.actual_dispatch_datetime, r.actual_arrival_datetime) as actual_duration_minutes,
            dispatch_g.name as dispatched_by, arrival_g.name as received_by,
            approver.name as approved_by, mp.name as motorpool_head
     FROM requests r
     JOIN users u ON r.user_id = u.id
     LEFT JOIN departments dept ON r.department_id = dept.id
     LEFT JOIN vehicles v ON r.vehicle_id = v.id AND v.deleted_at IS NULL
     LEFT JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
     LEFT JOIN drivers d ON r.driver_id = d.id AND d.deleted_at IS NULL
     LEFT JOIN users dr_u ON d.user_id = dr_u.id
     LEFT JOIN users dispatch_g ON r.dispatch_guard_id = dispatch_g.id
     LEFT JOIN users arrival_g ON r.arrival_guard_id = arrival_g.id
     LEFT JOIN users approver ON r.approver_id = approver.id
     LEFT JOIN users mp ON r.motorpool_head_id = mp.id
     $whereClause
     ORDER BY r.created_at DESC
     LIMIT ?",
    $params
);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="fleet_report_' . $startDate . '_to_' . $endDate . '.csv"');

$output = fopen('php://output', 'w');

fputcsv($output, [
    'Request ID',
    'Created Date',
    'Requester Name',
    'Requester Email',
    'Requester Phone',
    'Department',
    'Scheduled Start',
    'Scheduled End',
    'Purpose',
    'Destination',
    'Passengers',
    'Status',
    'Vehicle Plate',
    'Vehicle Make',
    'Vehicle Model',
    'Vehicle Year',
    'Vehicle Type',
    'Vehicle Color',
    'Driver Name',
    'Driver License',
    'Approved By',
    'Motorpool Head',
    'Actual Dispatch',
    'Actual Arrival',
    'Planned Duration (min)',
    'Actual Duration (min)',
    'Mileage Start',
    'Mileage End',
    'Distance (km)',
    'Travel Order',
    'Dispatched By',
    'Received By',
    'Request Notes',
    'Guard Notes'
]);

foreach ($requests as $row) {
    $plannedHrs = $row->planned_duration_minutes ? floor($row->planned_duration_minutes / 60) : 0;
    $plannedMins = $row->planned_duration_minutes ? $row->planned_duration_minutes % 60 : 0;
    $actualHrs = $row->actual_duration_minutes ? floor($row->actual_duration_minutes / 60) : 0;
    $actualMins = $row->actual_duration_minutes ? $row->actual_duration_minutes % 60 : 0;
    
    fputcsv($output, [
        $row->id,
        $row->created_at,
        $row->requester,
        $row->requester_email ?: '-',
        $row->requester_phone ?: '-',
        $row->department ?: '-',
        $row->start_datetime,
        $row->end_datetime,
        $row->purpose,
        $row->destination,
        $row->passenger_count,
        ucfirst($row->status),
        $row->plate_number ?: '-',
        $row->vehicle_make ?: '-',
        $row->vehicle_model ?: '-',
        $row->vehicle_year ?: '-',
        $row->vehicle_type ?: '-',
        $row->vehicle_color ?: '-',
        $row->driver ?: '-',
        $row->driver_license ?: '-',
        $row->approved_by ?: '-',
        $row->motorpool_head ?: '-',
        $row->actual_dispatch_datetime ?: '-',
        $row->actual_arrival_datetime ?: '-',
        $row->planned_duration_minutes ? "{$plannedHrs}h {$plannedMins}m" : '-',
        $row->actual_duration_minutes ? "{$actualHrs}h {$actualMins}m" : '-',
        $row->mileage_start ?: '-',
        $row->mileage_end ?: '-',
        $row->mileage_actual ?: '-',
        $row->has_travel_order ? ($row->travel_order_number ?: 'Yes') : 'No',
        $row->dispatched_by ?: '-',
        $row->received_by ?: '-',
        $row->notes ?: '-',
        $row->guard_notes ?: '-'
    ]);
}

fclose($output);
exit;
