<?php
/**
 * LOKA - Export Driver Report to PDF (Complete)
 */

requireReportsAccess();

require_once BASE_PATH . '/vendor/tecnickcom/tcpdf/tcpdf.php';

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
            u.name as requester_name, d.name as department_name,
            v.plate_number, v.make, v.model, v.color,
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

$filename = 'driver_report_' . preg_replace('/[^a-zA-Z0-9]/', '_', $driverInfo->name) . '_' . $startDate . '_to_' . $endDate;
$title = 'Driver Report - ' . $driverInfo->name;

$pdf = new TCPDF('L', PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
$pdf->SetCreator('LOKA Fleet Management');
$pdf->SetAuthor(currentUser()->name);
$pdf->SetTitle($title);
$pdf->SetHeaderData('', 0, 'DICT - Driver Report', 
    $driverInfo->name . ' | Period: ' . $startDate . ' to ' . $endDate);
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
$pdf->Cell(55, 6, $driverInfo->name, 1, 0);
$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(30, 6, 'Phone:', 1, 0);
$pdf->Cell(0, 6, $driverInfo->phone ?: '-', 1, 1);

$pdf->Cell(35, 6, 'License No:', 1, 0);
$pdf->Cell(55, 6, $driverInfo->license_number ?: '-', 1, 0);
$pdf->Cell(30, 6, 'License Class:', 1, 0);
$pdf->Cell(0, 6, $driverInfo->license_class ?: '-', 1, 1);

$pdf->Cell(35, 6, 'License Expiry:', 1, 0);
$pdf->Cell(55, 6, $driverInfo->license_expiry ? date('F j, Y', strtotime($driverInfo->license_expiry)) : '-', 1, 0);
$pdf->Cell(30, 6, 'Experience:', 1, 0);
$pdf->Cell(0, 6, $driverInfo->years_experience ? $driverInfo->years_experience . ' years' : '-', 1, 1);

$pdf->Cell(35, 6, 'Department:', 1, 0);
$pdf->Cell(55, 6, $driverInfo->department_name ?: '-', 1, 0);
$pdf->Cell(30, 6, 'Status:', 1, 0);
$pdf->Cell(0, 6, ucfirst($driverInfo->status), 1, 1);

$pdf->Cell(35, 6, 'Emergency Contact:', 1, 0);
$pdf->Cell(55, 6, $driverInfo->emergency_contact_name ?: '-', 1, 0);
$pdf->Cell(30, 6, 'Emergency Phone:', 1, 0);
$pdf->Cell(0, 6, $driverInfo->emergency_contact_phone ?: '-', 1, 1);

// Calculate stats
$totalHours = 0;
$completedTrips = 0;
$uniqueVehicles = [];
$totalPassengers = 0;
$totalKm = 0;
$totalFuel = 0;

foreach ($trips as $t) {
    if ($t->status === 'completed') $completedTrips++;
    if ($t->actual_duration) $totalHours += $t->actual_duration / 60;
    elseif ($t->planned_duration) $totalHours += $t->planned_duration / 60;
    if ($t->plate_number) $uniqueVehicles[$t->plate_number] = true;
    $totalPassengers += $t->passenger_count;
    if ($t->mileage_actual) $totalKm += $t->mileage_actual;
    if ($t->fuel_consumed) $totalFuel += $t->fuel_consumed;
}

// Statistics Section
$pdf->Ln(3);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetFillColor(40, 167, 69);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(0, 7, '  TRIP STATISTICS', 1, 1, 'L', true);

$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(45, 6, 'Total Trips: ' . count($trips), 1, 0, 'C');
$pdf->Cell(45, 6, 'Completed: ' . $completedTrips, 1, 0, 'C');
$pdf->Cell(45, 6, 'Total Hours: ' . number_format($totalHours, 1) . 'h', 1, 0, 'C');
$pdf->Cell(0, 6, 'Vehicles Driven: ' . count($uniqueVehicles), 1, 1, 'C');

$pdf->Cell(45, 6, 'Total Passengers: ' . $totalPassengers, 1, 0, 'C');
$pdf->Cell(45, 6, 'Distance: ' . number_format($totalKm) . ' km', 1, 0, 'C');
$pdf->Cell(45, 6, 'Fuel: ' . number_format($totalFuel, 1) . 'L', 1, 0, 'C');
$pdf->Cell(0, 6, 'Period: ' . $startDate . ' to ' . $endDate, 1, 1, 'C');

// Trip History Table
$pdf->Ln(5);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(0, 7, 'TRIP HISTORY', 0, 1);

$columns = ['ID', 'Date/Time', 'Vehicle', 'Destination', 'Purpose', 'Requester', 'Pass', 'Status', 'Duration', 'Km', 'Dispatch', 'Arrival'];
$colWidths = [10, 24, 28, 34, 26, 22, 9, 16, 15, 14, 20, 20];

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
    
    $vehicle = $trip->plate_number ? $trip->plate_number : '-';
    $scheduled = date('m/d H:i', strtotime($trip->start_datetime));
    
    $rowData = [
        $trip->id,
        $scheduled,
        strlen($vehicle) > 18 ? substr($vehicle, 0, 18) . '..' : $vehicle,
        strlen($trip->destination) > 24 ? substr($trip->destination, 0, 24) . '..' : $trip->destination,
        strlen($trip->purpose) > 18 ? substr($trip->purpose, 0, 18) . '..' : $trip->purpose,
        strlen($trip->requester_name) > 15 ? substr($trip->requester_name, 0, 15) . '..' : $trip->requester_name,
        $trip->passenger_count,
        ucfirst(substr($trip->status, 0, 8)),
        $duration,
        $trip->mileage_actual ? number_format($trip->mileage_actual) : '-',
        $trip->actual_dispatch_datetime ? date('m/d H:i', strtotime($trip->actual_dispatch_datetime)) : '-',
        $trip->actual_arrival_datetime ? date('m/d H:i', strtotime($trip->actual_arrival_datetime)) : '-'
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
$pdf->Cell(0, 4, 'Trips: ' . count($trips) . ' | Completed: ' . $completedTrips . ' | Hours: ' . number_format($totalHours, 1) . 'h | Distance: ' . number_format($totalKm) . ' km | Fuel: ' . number_format($totalFuel, 1) . 'L | Vehicles: ' . count($uniqueVehicles), 0, 1, 'C');
$pdf->Cell(0, 4, 'Generated by LOKA Fleet Management System on ' . date('F j, Y \a\t g:i A'), 0, 1, 'C');

$pdf->Output($filename . '.pdf', 'D');
exit;
