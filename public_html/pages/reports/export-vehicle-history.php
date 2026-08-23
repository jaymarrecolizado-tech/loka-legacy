<?php
/**
 * LOKA - Export Vehicle History to PDF (Complete)
 */

requireRole(ROLE_APPROVER);

require_once BASE_PATH . '/vendor/tecnickcom/tcpdf/tcpdf.php';

$vehicleId = get('vehicle_id');
$startDate = get('start_date', date('Y-m-01'));
$endDate = get('end_date', date('Y-m-t'));

if (!$vehicleId) {
    redirectWith('/?page=reports&action=vehicle-history', 'danger', 'Please select a vehicle.');
}

$vehicleInfo = db()->fetch(
    "SELECT v.*, vt.name as type_name, vt.passenger_capacity
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
            u.name as requester_name, d.name as department_name,
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

$filename = 'vehicle_history_' . $vehicleInfo->plate_number . '_' . $startDate . '_to_' . $endDate;
$title = 'Vehicle History Report - ' . $vehicleInfo->plate_number;

$pdf = new TCPDF('L', PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
$pdf->SetCreator('LOKA Fleet Management');
$pdf->SetAuthor(currentUser()->name);
$pdf->SetTitle($title);
$pdf->SetHeaderData('', 0, 'DICT - Vehicle History Report', 
    $vehicleInfo->plate_number . ' - ' . $vehicleInfo->make . ' ' . $vehicleInfo->model . 
    ' | Period: ' . $startDate . ' to ' . $endDate);
$pdf->setHeaderFont([PDF_FONT_NAME_MAIN, '', 10]);
$pdf->setFooterFont([PDF_FONT_NAME_DATA, '', 8]);
$pdf->SetMargins(15, 15, 15);
$pdf->SetAutoPageBreak(TRUE, 15);
$pdf->AddPage();

// Vehicle Information Section
$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetFillColor(13, 110, 253);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(0, 8, '  VEHICLE INFORMATION', 1, 1, 'L', true);

$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('helvetica', '', 9);

$pdf->Cell(35, 6, 'Plate Number:', 1, 0);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(40, 6, $vehicleInfo->plate_number, 1, 0);
$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(30, 6, 'Make/Model:', 1, 0);
$pdf->Cell(50, 6, $vehicleInfo->make . ' ' . $vehicleInfo->model, 1, 0);
$pdf->Cell(20, 6, 'Year:', 1, 0);
$pdf->Cell(0, 6, $vehicleInfo->year ?: '-', 1, 1);

$pdf->Cell(35, 6, 'Vehicle Type:', 1, 0);
$pdf->Cell(40, 6, $vehicleInfo->type_name ?: '-', 1, 0);
$pdf->Cell(30, 6, 'Color:', 1, 0);
$pdf->Cell(50, 6, $vehicleInfo->color ?: '-', 1, 0);
$pdf->Cell(20, 6, 'Capacity:', 1, 0);
$pdf->Cell(0, 6, $vehicleInfo->passenger_capacity . ' passengers', 1, 1);

$pdf->Cell(35, 6, 'Status:', 1, 0);
$pdf->Cell(40, 6, ucfirst($vehicleInfo->status), 1, 0);
$pdf->Cell(30, 6, 'Mileage:', 1, 0);
$pdf->Cell(50, 6, number_format($vehicleInfo->mileage ?? 0) . ' km', 1, 0);
$pdf->Cell(20, 6, 'Fuel:', 1, 0);
$pdf->Cell(0, 6, ucfirst($vehicleInfo->fuel_type ?: '-'), 1, 1);

// Calculate stats (km falls back to odometer difference when actual is missing)
$totalHours = 0;
$completedTrips = 0;
$totalKm = 0;
$totalFuel = 0;
$totalFuelCost = 0;
$tripKm = function ($t) {
    if (!empty($t->mileage_actual)) return (float)$t->mileage_actual;
    if (!empty($t->mileage_end) && !empty($t->mileage_start)) return max(0, (float)$t->mileage_end - (float)$t->mileage_start);
    return null;
};
foreach ($trips as $t) {
    if ($t->status === 'completed') $completedTrips++;
    if ($t->actual_duration) $totalHours += $t->actual_duration / 60;
    elseif ($t->planned_duration) $totalHours += $t->planned_duration / 60;
    $km = $tripKm($t);
    if ($km !== null) $totalKm += $km;
    if ($t->fuel_consumed) $totalFuel += $t->fuel_consumed;
    if ($t->fuel_cost) $totalFuelCost += $t->fuel_cost;
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
$pdf->Cell(0, 6, 'Distance: ' . number_format($totalKm) . ' km', 1, 1, 'C');
$pdf->Cell(45, 6, 'Fuel: ' . number_format($totalFuel, 1) . 'L', 1, 0, 'C');
$pdf->Cell(45, 6, 'Fuel Cost: P' . number_format($totalFuelCost, 2), 1, 0, 'C');
$pdf->Cell(0, 6, 'Period: ' . $startDate . ' to ' . $endDate, 1, 1, 'C');

// Trip History Table
$pdf->Ln(5);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(0, 7, 'TRIP HISTORY', 0, 1);

$columns = ['ID', 'Date/Time', 'Destination', 'Purpose', 'Requester', 'Dept', 'Driver', 'Status', 'Pax', 'Duration', 'Km', 'Fuel (L)', 'Fuel Cost', 'Dispatch', 'Arrival'];
$colWidths = [9, 24, 30, 30, 21, 20, 20, 15, 9, 15, 14, 12, 14, 17, 17];

$pdf->SetFont('helvetica', 'B', 7);
$pdf->SetFillColor(13, 110, 253);
$pdf->SetTextColor(255, 255, 255);
foreach ($columns as $i => $col) {
    $pdf->Cell($colWidths[$i], 6, $col, 1, 0, 'C', true);
}
$pdf->Ln();

$pdf->SetFillColor(248, 248, 248);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('helvetica', '', 6.5);

$lineHeight = 4;
$fill = false;
foreach ($trips as $trip) {
    $duration = $trip->actual_duration
        ? floor($trip->actual_duration / 60) . 'h ' . ($trip->actual_duration % 60) . 'm'
        : ($trip->planned_duration ? floor($trip->planned_duration / 60) . 'h ' . ($trip->planned_duration % 60) . 'm' : '-');

    $scheduled = date('m/d H:i', strtotime($trip->start_datetime));
    $km = $tripKm($trip);

    $rowData = [
        $trip->id,
        $scheduled,
        $trip->destination ?: '-',
        $trip->purpose ?: '-',
        $trip->requester_name ?: '-',
        $trip->department_name ?: '-',
        $trip->driver_name ?: '-',
        ucfirst($trip->status),
        $trip->passenger_count ?: '-',
        $duration,
        $km !== null ? number_format($km) : '-',
        $trip->fuel_consumed ? number_format($trip->fuel_consumed, 2) : '-',
        $trip->fuel_cost ? 'P' . number_format($trip->fuel_cost, 2) : '-',
        $trip->actual_dispatch_datetime ? date('m/d H:i', strtotime($trip->actual_dispatch_datetime)) : '-',
        $trip->actual_arrival_datetime ? date('m/d H:i', strtotime($trip->actual_arrival_datetime)) : '-'
    ];

    // Full text with wrapping - no truncation. Row height = tallest cell.
    $rowMax = 1;
    foreach ($rowData as $i => $val) {
        $n = $pdf->getNumLines($val, $colWidths[$i]);
        if ($n > $rowMax) $rowMax = $n;
    }
    $rowH = $rowMax * $lineHeight;

    foreach ($rowData as $i => $val) {
        $pdf->MultiCell($colWidths[$i], $lineHeight, $val, 1, 'L', $fill, 0, '', '', true, 0, false, true, $rowH, 'M');
    }
    $pdf->Ln($rowH);
    $fill = !$fill;
}

// Footer
$pdf->Ln(5);
$pdf->SetFont('helvetica', 'I', 7);
$pdf->Cell(0, 4, 'Total Trips: ' . count($trips) . ' | Completed: ' . $completedTrips . ' | Hours: ' . number_format($totalHours, 1) . 'h | Distance: ' . number_format($totalKm) . ' km | Fuel: ' . number_format($totalFuel, 1) . 'L', 0, 1, 'C');
$pdf->Cell(0, 4, 'Generated by LOKA Fleet Management System on ' . date('F j, Y \a\t g:i A'), 0, 1, 'C');

$pdf->Output($filename . '.pdf', 'D');
exit;
