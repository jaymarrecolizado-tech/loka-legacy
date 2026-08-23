<?php
/**
 * LOKA - Export Report to PDF (Complete)
 */

requireRole(ROLE_APPROVER);

require_once BASE_PATH . '/vendor/tecnickcom/tcpdf/tcpdf.php';

$startDate = get('start_date', date('Y-m-01'));
$endDate = get('end_date', date('Y-m-t'));
$status = get('status', '');
$filterDept = get('department_id', '');
$filterVehicle = get('vehicle_id', '');
$filterDriver = get('driver_id', '');
$maxRows = 500;

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

$requestLimit = (int)get('limit', 500);
$params[] = $requestLimit;

$requests = db()->fetchAll(
    "SELECT r.id, r.created_at, r.start_datetime, r.end_datetime, r.purpose, r.destination,
            r.passenger_count, r.status, r.notes,
            r.mileage_start, r.mileage_end, r.mileage_actual,
            u.name as requester, dept.name as department,
            v.plate_number, v.make as vehicle_make, v.model as vehicle_model,
            dr_u.name as driver,
            tt.fuel_consumed, tt.fuel_cost,
            r.actual_dispatch_datetime, r.actual_arrival_datetime, r.guard_notes,
            TIMESTAMPDIFF(MINUTE, r.start_datetime, r.end_datetime) as planned_duration,
            TIMESTAMPDIFF(MINUTE, r.actual_dispatch_datetime, r.actual_arrival_datetime) as actual_duration,
            dispatch_g.name as dispatched_by, arrival_g.name as received_by
     FROM requests r
     JOIN users u ON r.user_id = u.id
     LEFT JOIN departments dept ON r.department_id = dept.id
     LEFT JOIN vehicles v ON r.vehicle_id = v.id AND v.deleted_at IS NULL
     LEFT JOIN drivers d ON r.driver_id = d.id AND d.deleted_at IS NULL
     LEFT JOIN users dr_u ON d.user_id = dr_u.id
     LEFT JOIN trip_tickets tt ON r.id = tt.request_id AND tt.deleted_at IS NULL
     LEFT JOIN users dispatch_g ON r.dispatch_guard_id = dispatch_g.id
     LEFT JOIN users arrival_g ON r.arrival_guard_id = arrival_g.id
     $whereClause
     ORDER BY r.created_at DESC
     LIMIT ?",
    $params
);

auditLog('data_export', 'requests', null, null, ['format' => 'pdf', 'rows' => count($requests)]);

$filename = 'fleet_report_' . $startDate . '_to_' . $endDate;
$title = 'Fleet Report - ' . $startDate . ' to ' . $endDate;

$pdf = new TCPDF('L', PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
$pdf->SetCreator('LOKA Fleet Management');
$pdf->SetAuthor(currentUser()->name);
$pdf->SetTitle($title);
$pdf->SetHeaderData('', 0, 'DICT - Fleet Management Report', 
    'Period: ' . $startDate . ' to ' . $endDate . ' | Generated: ' . date('Y-m-d H:i:s'));
$pdf->setHeaderFont([PDF_FONT_NAME_MAIN, '', 10]);
$pdf->setFooterFont([PDF_FONT_NAME_DATA, '', 8]);
$pdf->SetMargins(15, 15, 15);
$pdf->SetAutoPageBreak(TRUE, 15);
$pdf->AddPage();

// Summary Stats
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(0, 8, 'Summary', 0, 1);
$pdf->SetFont('helvetica', '', 8);

$stats = ['total' => count($requests), 'approved' => 0, 'completed' => 0, 'rejected' => 0, 'pending' => 0];
foreach ($requests as $r) {
    if ($r->status === 'approved') $stats['approved']++;
    elseif ($r->status === 'completed') $stats['completed']++;
    elseif ($r->status === 'rejected') $stats['rejected']++;
    elseif (in_array($r->status, ['pending', 'pending_motorpool'])) $stats['pending']++;
}

$pdf->Cell(35, 5, 'Total: ' . $stats['total'], 0, 0);
$pdf->Cell(35, 5, 'Approved: ' . $stats['approved'], 0, 0);
$pdf->Cell(35, 5, 'Completed: ' . $stats['completed'], 0, 0);
$pdf->Cell(35, 5, 'Rejected: ' . $stats['rejected'], 0, 0);
$pdf->Cell(35, 5, 'Pending: ' . $stats['pending'], 0, 1);
$pdf->Ln(3);

$maxRows = 500;
$tripKm = function ($row) {
    if (!empty($row->mileage_actual)) return (float)$row->mileage_actual;
    if (!empty($row->mileage_end) && !empty($row->mileage_start)) return max(0, (float)$row->mileage_end - (float)$row->mileage_start);
    return null;
};

$columns = ['ID', 'Created', 'Scheduled', 'Requester', 'Dept', 'Destination', 'Purpose', 'Vehicle', 'Driver', 'Status', 'Pax', 'Duration', 'Km', 'Fuel (L)', 'Dispatch', 'Arrival'];
$colWidths = [9, 15, 24, 20, 19, 28, 28, 14, 17, 13, 8, 14, 12, 11, 16, 17];

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
foreach ($requests as $row) {
    $duration = $row->actual_duration
        ? floor($row->actual_duration / 60) . 'h ' . ($row->actual_duration % 60) . 'm'
        : ($row->planned_duration ? floor($row->planned_duration / 60) . 'h ' . ($row->planned_duration % 60) . 'm' : '-');

    $scheduled = date('m/d H:i', strtotime($row->start_datetime)) . '-' . date('H:i', strtotime($row->end_datetime));
    $km = $tripKm($row);

    $rowData = [
        $row->id,
        date('m/d/y', strtotime($row->created_at)),
        $scheduled,
        $row->requester ?: '-',
        $row->department ?: '-',
        $row->destination ?: '-',
        $row->purpose ?: '-',
        $row->plate_number ?: '-',
        $row->driver ?: '-',
        ucfirst($row->status),
        $row->passenger_count ?: '-',
        $duration,
        $km !== null ? number_format($km) : '-',
        $row->fuel_consumed ? number_format($row->fuel_consumed, 2) : '-',
        $row->actual_dispatch_datetime ? date('m/d H:i', strtotime($row->actual_dispatch_datetime)) : '-',
        $row->actual_arrival_datetime ? date('m/d H:i', strtotime($row->actual_arrival_datetime)) : '-'
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

$pdf->Ln(3);
$pdf->SetFont('helvetica', 'I', 7);
$pdf->Cell(0, 4, 'Total Records: ' . count($requests) . ' | Generated by LOKA Fleet Management System', 0, 1, 'C');

$pdf->Output($filename . '.pdf', 'D');
exit;
