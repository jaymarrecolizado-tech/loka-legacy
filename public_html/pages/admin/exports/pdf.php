<?php
/**
 * LOKA - PDF Export Handler
 */

requireRole(ROLE_ADMIN);

require_once BASE_PATH . '/vendor/tecnickcom/tcpdf/tcpdf.php';

$type = get('type', 'requests');
$startDate = get('start_date', date('Y-m-01'));
$endDate = get('end_date', date('Y-m-t'));
$maxRows = 1000;

$allowedTypes = ['requests', 'users', 'vehicles', 'departments', 'maintenance', 'audit_logs', 'driver_history', 'vehicle_history', 'department_usage'];
if (!in_array($type, $allowedTypes)) {
    redirectWith('/?page=admin-reports', 'danger', 'Invalid report type.');
}

$data = [];
$filename = '';
$title = '';
$columns = [];
$colWidths = [];
$orientation = 'P'; // Portrait by default

switch ($type) {
    case 'requests':
        $filename = 'vehicle_requests_' . $startDate . '_to_' . $endDate;
        $title = 'Vehicle Requests Report';
        $orientation = 'L'; // Landscape for wide table
        $columns = ['ID', 'Created', 'Start', 'End', 'Purpose', 'Destination', 'Passengers', 'Status', 'Requester', 'Department'];
        // Conservative column widths (total: 235mm) to fit within A4 landscape
        $colWidths = [10, 23, 23, 23, 36, 36, 14, 16, 24, 24];
        $data = db()->fetchAll(
            "SELECT r.id, r.created_at, r.start_datetime, r.end_datetime, r.purpose, r.destination,
                    r.passenger_count, r.status, u.name as requester, d.name as department
             FROM requests r
             JOIN users u ON r.user_id = u.id
             LEFT JOIN departments d ON r.department_id = d.id
             WHERE DATE(r.created_at) BETWEEN ? AND ?
             ORDER BY r.created_at DESC
             LIMIT ?",
            [$startDate, $endDate, $maxRows]
        );
        break;

    case 'users':
        $filename = 'users_' . date('Y-m-d');
        $title = 'Users Report';
        $orientation = 'L';
        $columns = ['ID', 'Name', 'Email', 'Role', 'Department', 'Status', 'Created'];
        $colWidths = [12, 40, 55, 25, 40, 20, 28];
        $data = db()->fetchAll(
            "SELECT u.id, u.name, u.email, u.role, d.name as department, u.status, u.created_at
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
        $title = 'Vehicles Report';
        $orientation = 'L';
        $columns = ['ID', 'Plate', 'Make', 'Model', 'Year', 'Type', 'Status', 'Mileage'];
        $colWidths = [12, 25, 30, 30, 15, 25, 25, 20];
        $data = db()->fetchAll(
            "SELECT v.id, v.plate_number, v.make, v.model, v.year, vt.name as type, v.status, v.mileage
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
        $title = 'Departments Report';
        $orientation = 'P';
        $columns = ['ID', 'Name', 'Head', 'Members', 'Status', 'Created'];
        $colWidths = [12, 45, 35, 18, 25, 30];
        $data = db()->fetchAll(
            "SELECT d.id, d.name,
                    COALESCE(hu.name, '-') as head,
                    (SELECT COUNT(*) FROM users u2 WHERE u2.department_id = d.id AND u2.deleted_at IS NULL) as members,
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
        $title = 'Maintenance Records';
        $orientation = 'L';
        $columns = ['ID', 'Vehicle', 'Type', 'Priority', 'Status', 'Scheduled', 'Estimated Cost', 'Actual Cost', 'Created'];
        $colWidths = [10, 22, 25, 18, 18, 26, 24, 24, 28];
        $data = db()->fetchAll(
            "SELECT mr.id, v.plate_number as vehicle, mr.type, mr.priority, mr.status,
                    mr.scheduled_date, mr.estimated_cost, mr.actual_cost, mr.created_at
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
        $title = 'Audit Logs';
        $orientation = 'L';
        $columns = ['ID', 'Timestamp', 'User', 'Action', 'Entity', 'IP Address'];
        $colWidths = [12, 35, 35, 35, 50, 35];
        $data = db()->fetchAll(
            "SELECT al.id, al.created_at as timestamp, u.name as user, al.action,
                    CONCAT(al.entity_type, ' #', al.entity_id) as entity, al.ip_address
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
        $title = 'Driver Trip History';
        $orientation = 'L';
        $columns = ['Driver', 'Trips', 'Completed', 'Hours', 'Distance', 'Vehicles', 'Passengers'];
        $colWidths = [50, 25, 25, 25, 30, 25, 25];
        $data = db()->fetchAll(
            "SELECT u.name as driver,
                    COUNT(*) as trips,
                    SUM(CASE WHEN r.status = 'completed' THEN 1 ELSE 0 END) as completed,
                    ROUND(SUM(COALESCE(
                        TIMESTAMPDIFF(MINUTE, r.actual_dispatch_datetime, r.actual_arrival_datetime),
                        TIMESTAMPDIFF(MINUTE, r.start_datetime, r.end_datetime)
                    )) / 60, 1) as hours,
                    CONCAT(COALESCE(SUM(r.mileage_actual), 0), ' km') as distance,
                    COUNT(DISTINCT r.vehicle_id) as vehicles,
                    SUM(r.passenger_count) as passengers
             FROM requests r
             JOIN drivers d ON r.driver_id = d.id
             JOIN users u ON d.user_id = u.id
             WHERE r.deleted_at IS NULL
             AND r.start_datetime BETWEEN ? AND ?
             GROUP BY d.id, u.name
             ORDER BY trips DESC
             LIMIT ?",
            [$startDate, $endDate . ' 23:59:59', $maxRows]
        );
        break;

    case 'vehicle_history':
        $filename = 'vehicle_trip_history_' . $startDate . '_to_' . $endDate;
        $title = 'Vehicle Trip History';
        $orientation = 'L';
        $columns = ['Vehicle', 'Type', 'Trips', 'Completed', 'Hours', 'Distance', 'Fuel', 'Maint.'];
        $colWidths = [45, 25, 20, 22, 22, 28, 28, 18];
        $data = db()->fetchAll(
            "SELECT CONCAT(v.plate_number, ' - ', v.make, ' ', v.model) as vehicle,
                    COALESCE(vt.name, '-') as type,
                    COUNT(DISTINCT r.id) as trips,
                    SUM(CASE WHEN r.status = 'completed' THEN 1 ELSE 0 END) as completed,
                    ROUND(SUM(COALESCE(
                        TIMESTAMPDIFF(MINUTE, r.actual_dispatch_datetime, r.actual_arrival_datetime),
                        TIMESTAMPDIFF(MINUTE, r.start_datetime, r.end_datetime)
                    )) / 60, 1) as hours,
                    CONCAT(COALESCE(SUM(r.mileage_actual), 0), ' km') as distance,
                    CONCAT(ROUND(COALESCE(SUM(tt.fuel_consumed), 0), 1), 'L') as fuel,
                    (SELECT COUNT(*) FROM maintenance_requests mr WHERE mr.vehicle_id = v.id
                     AND DATE(mr.created_at) BETWEEN ? AND ?) as \"maint.\"
             FROM vehicles v
             LEFT JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
             LEFT JOIN requests r ON r.vehicle_id = v.id AND r.deleted_at IS NULL
                 AND r.start_datetime BETWEEN ? AND ?
             LEFT JOIN trip_tickets tt ON r.id = tt.request_id AND tt.deleted_at IS NULL
             WHERE v.deleted_at IS NULL
             GROUP BY v.id, v.plate_number, v.make, v.model, vt.name
             ORDER BY trips DESC
             LIMIT ?",
            [$startDate, $endDate, $startDate, $endDate . ' 23:59:59', $maxRows]
        );
        break;

    case 'department_usage':
        $filename = 'department_usage_' . $startDate . '_to_' . $endDate;
        $title = 'Department Usage Report';
        $orientation = 'L';
        $columns = ['Department', 'Requests', 'Approved', 'Completed', 'Rejected', 'Pending', 'Hours', 'Distance'];
        $colWidths = [45, 22, 22, 25, 22, 22, 22, 28];
        $data = db()->fetchAll(
            "SELECT dept.name as department,
                    COUNT(*) as requests,
                    SUM(CASE WHEN r.status = 'approved' THEN 1 ELSE 0 END) as approved,
                    SUM(CASE WHEN r.status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN r.status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                    SUM(CASE WHEN r.status IN ('pending', 'pending_motorpool') THEN 1 ELSE 0 END) as pending,
                    ROUND(SUM(COALESCE(
                        TIMESTAMPDIFF(MINUTE, r.actual_dispatch_datetime, r.actual_arrival_datetime),
                        TIMESTAMPDIFF(MINUTE, r.start_datetime, r.end_datetime)
                    )) / 60, 1) as hours,
                    CONCAT(COALESCE(SUM(r.mileage_actual), 0), ' km') as distance
             FROM requests r
             JOIN departments dept ON r.department_id = dept.id
             WHERE r.deleted_at IS NULL
             AND DATE(r.created_at) BETWEEN ? AND ?
             GROUP BY dept.id, dept.name
             ORDER BY requests DESC
             LIMIT ?",
            [$startDate, $endDate, $maxRows]
        );
        break;
}

auditLog('data_export', $type, null, null, ['format' => 'pdf', 'rows' => count($data)]);

// Create PDF with appropriate orientation
$pdf = new TCPDF($orientation, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
$pdf->SetCreator('LOKA Fleet Management');
$pdf->SetAuthor(currentUser()->name);
$pdf->SetTitle($title);
$pdf->SetHeaderData('', 0, $title, 'Generated: ' . date('Y-m-d H:i:s') . ' | Period: ' . $startDate . ' to ' . $endDate);
$pdf->setHeaderFont([PDF_FONT_NAME_MAIN, '', 10]);
$pdf->setFooterFont([PDF_FONT_NAME_DATA, '', 8]);
$pdf->SetMargins(10, 15, 10);
$pdf->SetAutoPageBreak(TRUE, 15);
$pdf->AddPage();
$pdf->SetFont('helvetica', '', 8);

// Table header
$pdf->SetFont('helvetica', 'B', 8);
$pdf->SetFillColor(66, 139, 202);
$pdf->SetTextColor(255, 255, 255);
foreach ($columns as $i => $col) {
    $w = $colWidths[$i] ?? 20;
    $pdf->Cell($w, 7, $col, 1, 0, 'C', true);
}
$pdf->Ln();

// Table rows
$pdf->SetFillColor(248, 248, 248);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('helvetica', '', 7);

$fill = false;
foreach ($data as $row) {
    $rowArray = (array) $row;
    
    // Calculate max height needed for this row
    $maxLines = 1;
    $cellContents = [];
    foreach ($columns as $i => $col) {
        $key = strtolower(str_replace(' ', '_', $col));
        $val = $rowArray[$key] ?? '-';
        $w = $colWidths[$i] ?? 20;
        $maxChars = floor($w / 1.8);
        if (strlen($val) > $maxChars) {
            $lines = ceil(strlen($val) / $maxChars);
            $maxLines = max($maxLines, $lines);
            $val = wordwrap($val, $maxChars, "\n", true);
        }
        $cellContents[] = $val;
    }
    
    $rowHeight = max(6, $maxLines * 4.5);
    
    // Check for page break
    if ($pdf->GetY() + $rowHeight > $pdf->getPageHeight() - 20) {
        $pdf->AddPage();
        // Reprint header
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetFillColor(66, 139, 202);
        $pdf->SetTextColor(255, 255, 255);
        foreach ($columns as $i => $col) {
            $w = $colWidths[$i] ?? 20;
            $pdf->Cell($w, 7, $col, 1, 0, 'C', true);
        }
        $pdf->Ln();
        $pdf->SetFillColor(248, 248, 248);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('helvetica', '', 7);
        $fill = false;
    }
    
    // Output row cells
    $x = $pdf->GetX();
    $y = $pdf->GetY();
    
    foreach ($cellContents as $i => $val) {
        $w = $colWidths[$i] ?? 20;
        $pdf->SetXY($x + array_sum(array_slice($colWidths, 0, $i)), $y);
        $pdf->MultiCell($w, $rowHeight, $val, 1, 'L', $fill);
    }
    
    $pdf->SetY($y + $rowHeight);
    $fill = !$fill;
}

// Footer
$pdf->Ln(5);
$pdf->SetFont('helvetica', 'I', 8);
$pdf->Cell(0, 5, 'Total Records: ' . count($data) . ' | Generated by LOKA Fleet Management System', 0, 1, 'C');

$pdf->Output($filename . '.pdf', 'D');
exit;
