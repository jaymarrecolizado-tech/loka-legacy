<?php
/**
 * LOKA - Export Driver Trips to PDF
 * For manual trip ticket purposes
 */

require_once BASE_PATH . '/vendor/tecnickcom/tcpdf/tcpdf.php';

$driver = db()->fetch(
    "SELECT d.*, u.name, u.email, u.phone, dept.name as department_name
     FROM drivers d
     JOIN users u ON d.user_id = u.id
     LEFT JOIN departments dept ON u.department_id = dept.id
     WHERE d.user_id = ? AND d.deleted_at IS NULL",
    [userId()]
);

if (!$driver) {
    redirectWith('/?page=my-trips', 'danger', 'Driver not found.');
}

$filter = get('filter', 'all');
$startDate = get('start_date', date('Y-m-01'));
$endDate = get('end_date', date('Y-m-t'));

// Build SQL based on filter
$sql = "SELECT r.*,
            u.name as requester_name, u.phone as requester_phone, d.name as department_name,
            v.plate_number, v.make, v.model as vehicle_model, v.color,
            dr_user.name as driver_name,
            TIMESTAMPDIFF(MINUTE, r.start_datetime, r.end_datetime) as planned_duration,
            TIMESTAMPDIFF(MINUTE, r.actual_dispatch_datetime, r.actual_arrival_datetime) as actual_duration
        FROM requests r
        JOIN users u ON r.user_id = u.id
        JOIN departments d ON r.department_id = d.id
        LEFT JOIN vehicles v ON r.vehicle_id = v.id AND v.deleted_at IS NULL
        LEFT JOIN drivers dr ON r.driver_id = dr.id AND dr.deleted_at IS NULL
        LEFT JOIN users dr_user ON dr.user_id = dr_user.id
        WHERE (r.driver_id = ? OR r.requested_driver_id = ?)
        AND r.deleted_at IS NULL";

$params = [$driver->id, $driver->id];

switch ($filter) {
    case 'upcoming':
        $sql .= " AND r.end_datetime >= NOW()";
        $sql .= " ORDER BY r.start_datetime ASC";
        break;
    case 'past':
        $sql .= " AND r.end_datetime < NOW()";
        $sql .= " ORDER BY r.start_datetime DESC";
        break;
    case 'all':
    default:
        $sql .= " ORDER BY r.start_datetime DESC";
        break;
}

$trips = db()->fetchAll($sql, $params);

// Calculate stats
$totalTrips = count($trips);
$completedTrips = 0;
$totalHours = 0;
$totalDistance = 0;

foreach ($trips as $t) {
    if ($t->status === 'completed') $completedTrips++;
    if ($t->actual_duration) {
        $totalHours += $t->actual_duration / 60;
    } elseif ($t->planned_duration) {
        $totalHours += $t->planned_duration / 60;
    }
    if ($t->mileage_actual) {
        $totalDistance += $t->mileage_actual;
    }
}

$filename = 'driver_trips_' . preg_replace('/[^a-zA-Z0-9]/', '_', $driver->name) . '_' . date('Y-m-d');
$title = 'Driver Trip Report - ' . $driver->name;

$pdf = new TCPDF('L', PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
$pdf->SetCreator('LOKA Fleet Management');
$pdf->SetAuthor(currentUser()->name);
$pdf->SetTitle($title);
$pdf->SetHeaderData('', 0, 'DICT - Driver Trip Report',
    $driver->name . ' | Generated: ' . date('Y-m-d H:i:s'));
$pdf->setHeaderFont([PDF_FONT_NAME_MAIN, '', 10]);
$pdf->setFooterFont([PDF_FONT_NAME_DATA, '', 8]);
$pdf->SetMargins(15, 15, 15);
$pdf->SetAutoPageBreak(TRUE, 15);
$pdf->AddPage();

// Driver Information Section
$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetFillColor(13, 110, 253);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(0, 8, '  DRIVER INFORMATION', 1, 1, 'L', true);

$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('helvetica', '', 9);

$pdf->Cell(35, 6, 'Driver Name:', 1, 0);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(55, 6, $driver->name, 1, 0);
$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(30, 6, 'Phone:', 1, 0);
$pdf->Cell(0, 6, $driver->phone ?: '-', 1, 1);

$pdf->Cell(35, 6, 'Department:', 1, 0);
$pdf->Cell(55, 6, $driver->department_name ?: '-', 1, 0);
$pdf->Cell(30, 6, 'Status:', 1, 0);
$pdf->Cell(0, 6, ucfirst($driver->status), 1, 1);

$pdf->Cell(35, 6, 'License No:', 1, 0);
$pdf->Cell(55, 6, $driver->license_number ?: '-', 1, 0);
$pdf->Cell(30, 6, 'License Class:', 1, 0);
$pdf->Cell(0, 6, $driver->license_class ?: '-', 1, 1);

// Statistics Section
$pdf->Ln(3);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetFillColor(40, 167, 69);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(0, 7, '  TRIP SUMMARY', 1, 1, 'L', true);

$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(45, 6, 'Total Trips: ' . $totalTrips, 1, 0, 'C');
$pdf->Cell(45, 6, 'Completed: ' . $completedTrips, 1, 0, 'C');
$pdf->Cell(45, 6, 'Total Hours: ' . number_format($totalHours, 1) . 'h', 1, 0, 'C');
$pdf->Cell(0, 6, 'Total Distance: ' . number_format($totalDistance) . ' km', 1, 1, 'C');

// Trip History Table
$pdf->Ln(5);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(0, 7, 'TRIP DETAILS', 0, 1);

$columns = ['ID', 'Date', 'Time', 'Destination', 'Vehicle', 'Requester', 'Dept', 'Status', 'Duration', 'Mileage'];
$colWidths = [10, 20, 30, 45, 35, 28, 22, 18, 16, 15];

$pdf->SetFont('helvetica', 'B', 7);
$pdf->SetFillColor(13, 110, 253);
$pdf->SetTextColor(255, 255, 255);
foreach ($columns as $i => $col) {
    $pdf->Cell($colWidths[$i], 6, $col, 1, 0, 'C', true);
}
$pdf->Ln();

$pdf->SetFillColor(248, 248, 248);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('helvetica', '', 6);

$fill = false;
foreach ($trips as $trip) {
    $duration = $trip->actual_duration
        ? floor($trip->actual_duration / 60) . 'h ' . ($trip->actual_duration % 60) . 'm'
        : ($trip->planned_duration ? floor($trip->planned_duration / 60) . 'h ' . ($trip->planned_duration % 60) . 'm' : '-');

    $date = date('m/d/Y', strtotime($trip->start_datetime));
    $time = date('H:i', strtotime($trip->start_datetime)) . '-' . date('H:i', strtotime($trip->end_datetime));

    $rowData = [
        $trip->id,
        $date,
        $time,
        strlen($trip->destination) > 28 ? substr($trip->destination, 0, 28) . '..' : $trip->destination,
        $trip->plate_number ?: '-',
        strlen($trip->requester_name) > 15 ? substr($trip->requester_name, 0, 15) . '..' : $trip->requester_name,
        strlen($trip->department_name ?: '-') > 12 ? substr($trip->department_name ?: '-', 0, 12) . '..' : ($trip->department_name ?: '-'),
        ucfirst(substr($trip->status, 0, 8)),
        $duration,
        $trip->mileage_actual ? number_format($trip->mileage_actual) . ' km' : '-'
    ];

    foreach ($rowData as $i => $val) {
        $pdf->Cell($colWidths[$i], 5, $val, 1, 0, 'L', $fill);
    }
    $pdf->Ln();
    $fill = !$fill;
}

// Footer
$pdf->Ln(5);
$pdf->SetFont('helvetica', 'I', 7);
$pdf->Cell(0, 4, 'Total Trips: ' . $totalTrips . ' | Completed: ' . $completedTrips . ' | Total Hours: ' . number_format($totalHours, 1) . 'h | Total Distance: ' . number_format($totalDistance) . ' km', 0, 1, 'C');
$pdf->Cell(0, 4, 'Generated by LOKA Fleet Management System on ' . date('F j, Y \a\t g:i A'), 0, 1, 'C');

$pdf->Output($filename . '.pdf', 'D');
exit;
