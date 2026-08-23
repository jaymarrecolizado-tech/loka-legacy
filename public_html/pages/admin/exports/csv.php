<?php
/**
 * LOKA - CSV Export Handler
 */

requireRole(ROLE_ADMIN);

$type = get('type', 'requests');
$startDate = get('start_date', date('Y-m-01'));
$endDate = get('end_date', date('Y-m-t'));
$maxRows = 10000;

$allowedTypes = ['requests', 'users', 'vehicles', 'departments', 'maintenance', 'audit_logs', 'driver_history', 'vehicle_history', 'department_usage'];
if (!in_array($type, $allowedTypes)) {
    redirectWith('/?page=admin-reports', 'danger', 'Invalid report type.');
}

$data = [];
$filename = '';
$headers = [];

switch ($type) {
    case 'requests':
        $filename = 'vehicle_requests_' . $startDate . '_to_' . $endDate;
        $headers = ['ID', 'Created', 'Start', 'End', 'Purpose', 'Destination', 'Passenger Count', 'Status', 'Requester', 'Department', 'Vehicle', 'Driver'];
        $data = db()->fetchAll(
            "SELECT r.id, r.created_at, r.start_datetime, r.end_datetime, r.purpose, r.destination,
                    r.passenger_count, r.status, u.name as requester, d.name as department,
                    v.plate_number as vehicle, dr.name as driver
             FROM requests r
             JOIN users u ON r.user_id = u.id
             LEFT JOIN departments d ON r.department_id = d.id
             LEFT JOIN vehicles v ON r.vehicle_id = v.id
             LEFT JOIN drivers dr ON r.driver_id = dr.id
             WHERE DATE(r.created_at) BETWEEN ? AND ?
             ORDER BY r.created_at DESC
             LIMIT ?",
            [$startDate, $endDate, $maxRows]
        );
        break;

    case 'users':
        $filename = 'users_' . date('Y-m-d');
        $headers = ['ID', 'Name', 'Email', 'Role', 'Department', 'Status', 'Created', 'Last Login At'];
        $data = db()->fetchAll(
            "SELECT u.id, u.name, u.email, u.role, d.name as department, u.status, u.created_at, u.last_login_at
             FROM users u
             LEFT JOIN departments d ON u.department_id = d.id
             WHERE u.deleted_at IS NULL
             ORDER BY u.created_at DESC
             LIMIT ?",
            [$maxRows]
        );
        break;

    case 'vehicles':
        $filename = 'vehicles_' . date('Y-m-d');
        $headers = ['ID', 'Plate Number', 'Make', 'Model', 'Year', 'Type', 'Status', 'Mileage', 'Created'];
        $data = db()->fetchAll(
            "SELECT v.id, v.plate_number, v.make, v.model, v.year, vt.name as type, v.status, v.mileage, v.created_at
             FROM vehicles v
             LEFT JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
             WHERE v.deleted_at IS NULL
             ORDER BY v.plate_number
             LIMIT ?",
            [$maxRows]
        );
        break;

    case 'departments':
        $filename = 'departments_' . date('Y-m-d');
        $headers = ['ID', 'Name', 'Description', 'Head', 'Member Count', 'Status', 'Created'];
        $data = db()->fetchAll(
            "SELECT d.id, d.name, d.description,
                    COALESCE(hu.name, '-') as head,
                    (SELECT COUNT(*) FROM users u2 WHERE u2.department_id = d.id AND u2.deleted_at IS NULL) as member_count,
                    d.status, d.created_at
             FROM departments d
             LEFT JOIN users hu ON d.head_user_id = hu.id
             WHERE d.deleted_at IS NULL
             ORDER BY d.name
             LIMIT ?",
            [$maxRows]
        );
        break;

    case 'maintenance':
        $filename = 'maintenance_' . $startDate . '_to_' . $endDate;
        $headers = ['ID', 'Vehicle', 'Type', 'Priority', 'Status', 'Scheduled Date', 'Completed At', 'Estimated Cost', 'Actual Cost', 'Created'];
        $data = db()->fetchAll(
            "SELECT mr.id, v.plate_number as vehicle, mr.type, mr.priority, mr.status,
                    mr.scheduled_date, mr.completed_at, mr.estimated_cost, mr.actual_cost, mr.created_at
             FROM maintenance_requests mr
             JOIN vehicles v ON mr.vehicle_id = v.id
             WHERE DATE(mr.created_at) BETWEEN ? AND ?
             ORDER BY mr.created_at DESC
             LIMIT ?",
            [$startDate, $endDate, $maxRows]
        );
        break;

    case 'audit_logs':
        $filename = 'audit_logs_' . $startDate . '_to_' . $endDate;
        $headers = ['ID', 'Timestamp', 'User', 'Action', 'Entity Type', 'Entity ID', 'IP Address'];
        $data = db()->fetchAll(
            "SELECT al.id, al.created_at as timestamp, u.name as user, al.action,
                    al.entity_type, al.entity_id, al.ip_address
             FROM audit_logs al
             LEFT JOIN users u ON al.user_id = u.id
             WHERE DATE(al.created_at) BETWEEN ? AND ?
             ORDER BY al.created_at DESC
             LIMIT ?",
            [$startDate, $endDate, $maxRows]
        );
        break;

    case 'driver_history':
        $filename = 'driver_trip_history_' . $startDate . '_to_' . $endDate;
        $headers = ['Driver', 'Total Trips', 'Completed', 'Total Hours', 'Total Distance Km', 'Vehicles Driven', 'Total Passengers'];
        $data = db()->fetchAll(
            "SELECT u.name as driver,
                    COUNT(*) as total_trips,
                    SUM(CASE WHEN r.status = 'completed' THEN 1 ELSE 0 END) as completed,
                    ROUND(SUM(COALESCE(
                        TIMESTAMPDIFF(MINUTE, r.actual_dispatch_datetime, r.actual_arrival_datetime),
                        TIMESTAMPDIFF(MINUTE, r.start_datetime, r.end_datetime)
                    )) / 60, 1) as total_hours,
                    COALESCE(SUM(r.mileage_actual), 0) as total_distance_km,
                    COUNT(DISTINCT r.vehicle_id) as vehicles_driven,
                    SUM(r.passenger_count) as total_passengers
             FROM requests r
             JOIN drivers d ON r.driver_id = d.id
             JOIN users u ON d.user_id = u.id
             WHERE r.deleted_at IS NULL
             AND r.start_datetime BETWEEN ? AND ?
             GROUP BY d.id, u.name
             ORDER BY total_trips DESC
             LIMIT ?",
            [$startDate, $endDate . ' 23:59:59', $maxRows]
        );
        break;

    case 'vehicle_history':
        $filename = 'vehicle_trip_history_' . $startDate . '_to_' . $endDate;
        $headers = ['Vehicle', 'Type', 'Total Trips', 'Completed', 'Total Hours', 'Total Distance Km', 'Fuel Consumed L', 'Fuel Cost', 'Maintenance Count'];
        $data = db()->fetchAll(
            "SELECT CONCAT(v.plate_number, ' - ', v.make, ' ', v.model) as vehicle,
                    vt.name as type,
                    COUNT(DISTINCT r.id) as total_trips,
                    SUM(CASE WHEN r.status = 'completed' THEN 1 ELSE 0 END) as completed,
                    ROUND(SUM(COALESCE(
                        TIMESTAMPDIFF(MINUTE, r.actual_dispatch_datetime, r.actual_arrival_datetime),
                        TIMESTAMPDIFF(MINUTE, r.start_datetime, r.end_datetime)
                    )) / 60, 1) as total_hours,
                    COALESCE(SUM(r.mileage_actual), 0) as total_distance_km,
                    COALESCE(SUM(tt.fuel_consumed), 0) as fuel_consumed_l,
                    COALESCE(SUM(tt.fuel_cost), 0) as fuel_cost,
                    (SELECT COUNT(*) FROM maintenance_requests mr WHERE mr.vehicle_id = v.id
                     AND DATE(mr.created_at) BETWEEN ? AND ?) as maintenance_count
             FROM vehicles v
             LEFT JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
             LEFT JOIN requests r ON r.vehicle_id = v.id AND r.deleted_at IS NULL
                 AND r.start_datetime BETWEEN ? AND ?
             LEFT JOIN trip_tickets tt ON r.id = tt.request_id AND tt.deleted_at IS NULL
             WHERE v.deleted_at IS NULL
             GROUP BY v.id, v.plate_number, v.make, v.model, vt.name
             ORDER BY total_trips DESC
             LIMIT ?",
            [$startDate, $endDate, $startDate, $endDate . ' 23:59:59', $maxRows]
        );
        break;

    case 'department_usage':
        $filename = 'department_usage_' . $startDate . '_to_' . $endDate;
        $headers = ['Department', 'Total Requests', 'Approved', 'Completed', 'Rejected', 'Pending', 'Total Hours', 'Total Distance Km'];
        $data = db()->fetchAll(
            "SELECT dept.name as department,
                    COUNT(*) as total_requests,
                    SUM(CASE WHEN r.status = 'approved' THEN 1 ELSE 0 END) as approved,
                    SUM(CASE WHEN r.status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN r.status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                    SUM(CASE WHEN r.status IN ('pending', 'pending_motorpool') THEN 1 ELSE 0 END) as pending,
                    ROUND(SUM(COALESCE(
                        TIMESTAMPDIFF(MINUTE, r.actual_dispatch_datetime, r.actual_arrival_datetime),
                        TIMESTAMPDIFF(MINUTE, r.start_datetime, r.end_datetime)
                    )) / 60, 1) as total_hours,
                    COALESCE(SUM(r.mileage_actual), 0) as total_distance_km
             FROM requests r
             JOIN departments dept ON r.department_id = dept.id
             WHERE r.deleted_at IS NULL
             AND DATE(r.created_at) BETWEEN ? AND ?
             GROUP BY dept.id, dept.name
             ORDER BY total_requests DESC
             LIMIT ?",
            [$startDate, $endDate, $maxRows]
        );
        break;
}

auditLog('data_export', $type, null, null, ['format' => 'csv', 'rows' => count($data)]);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '.csv"');

$output = fopen('php://output', 'w');
fprintf($output, "\xEF\xBB\xBF");
fputcsv($output, $headers);

foreach ($data as $row) {
    $csvRow = [];
    $rowArray = (array) $row;
    foreach ($headers as $header) {
        $key = strtolower(str_replace(' ', '_', $header));
        $csvRow[] = $rowArray[$key] ?? '';
    }
    fputcsv($output, $csvRow);
}

fclose($output);
exit;
