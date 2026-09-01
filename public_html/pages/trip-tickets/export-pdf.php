<?php
/**
 * LOKA - Export Trip Ticket to PDF
 *
 * Generates a printable trip ticket matching the official DICT format
 */

require_once BASE_PATH . '/vendor/tecnickcom/tcpdf/tcpdf.php';

requireAnyRole([ROLE_APPROVER, ROLE_MOTORPOOL, ROLE_ADMIN]);

$ticketId = (int) get('id', 0);
if (!$ticketId) {
    die('Invalid ticket ID.');
}

// Get ticket with full details
$ticket = db()->fetch(
    "SELECT tt.*,
            r.id as request_id, r.destination as trip_destination, r.purpose as trip_purpose,
            r.actual_dispatch_datetime, r.actual_arrival_datetime,
            d.license_number as driver_license, du.name as driver_name, du.phone as driver_phone,
            u_req.name as requester_name, u_req.email as requester_email, u_req.phone as requester_phone,
            dg.name as dispatch_guard, dg.phone as dispatch_guard_phone,
            ag.name as arrival_guard, ag.phone as arrival_guard_phone,
            u_rev.name as reviewed_by_name, u_rev.email as reviewed_by_email,
            v.plate_number, v.make, v.model as vehicle_model, v.color, v.fuel_type,
            dept.name as department_name
     FROM trip_tickets tt
     JOIN requests r ON tt.request_id = r.id
     LEFT JOIN drivers d ON tt.driver_id = d.id
     LEFT JOIN users du ON d.user_id = du.id
     LEFT JOIN users u_req ON r.user_id = u_req.id
     LEFT JOIN departments dept ON u_req.department_id = dept.id
     LEFT JOIN vehicles v ON r.vehicle_id = v.id AND v.deleted_at IS NULL
     LEFT JOIN users dg ON tt.dispatch_guard_id = dg.id
     LEFT JOIN users ag ON tt.arrival_guard_id = ag.id
     LEFT JOIN users u_rev ON tt.reviewed_by = u_rev.id
     WHERE tt.id = ? AND tt.deleted_at IS NULL",
    [$ticketId]
);

if (!$ticket) {
    die('Ticket not found.');
}

// Get ticket with full details
$ticket = db()->fetch(
    "SELECT tt.*,
            r.id as request_id, r.destination as trip_destination, r.purpose as trip_purpose,
            r.actual_dispatch_datetime, r.actual_arrival_datetime,
            d.license_number as driver_license, du.name as driver_name, du.phone as driver_phone,
            u_req.name as requester_name, u_req.email as requester_email, u_req.phone as requester_phone,
            dg.name as dispatch_guard, dg.phone as dispatch_guard_phone,
            ag.name as arrival_guard, ag.phone as arrival_guard_phone,
            u_rev.name as reviewed_by_name, u_rev.email as reviewed_by_email,
            v.plate_number, v.make, v.model as vehicle_model, v.color, v.fuel_type,
            dept.name as department_name
     FROM trip_tickets tt
     JOIN requests r ON tt.request_id = r.id
     LEFT JOIN drivers d ON tt.driver_id = d.id
     LEFT JOIN users du ON d.user_id = du.id
     LEFT JOIN users u_req ON r.user_id = u_req.id
     LEFT JOIN departments dept ON u_req.department_id = dept.id
     LEFT JOIN vehicles v ON r.vehicle_id = v.id AND v.deleted_at IS NULL
     LEFT JOIN users dg ON tt.dispatch_guard_id = dg.id
     LEFT JOIN users ag ON tt.arrival_guard_id = ag.id
     LEFT JOIN users u_rev ON tt.reviewed_by = u_rev.id
     WHERE tt.id = ? AND tt.deleted_at IS NULL",
    [$ticketId]
);

if (!$ticket) {
    die('Ticket not found.');
}

// Get passengers from the original VRF (request)
$passengers = db()->fetchAll(
    "SELECT rp.id,
            CASE
                WHEN rp.user_id IS NOT NULL THEN u.name
                ELSE rp.guest_name
            END as passenger_name
     FROM request_passengers rp
     LEFT JOIN users u ON rp.user_id = u.id
     WHERE rp.request_id = ?
     ORDER BY rp.id ASC",
    [$ticket->request_id]
);

// Trip type labels
$tripTypeLabels = [
    'official' => 'Official Business',
    'personal' => 'Personal',
    'maintenance' => 'Maintenance',
    'travel_order' => 'Travel Order',
    'other' => 'Other'
];
$tripTypeInfo = $tripTypeLabels[$ticket->trip_type] ?? 'Official Business';
if ($ticket->trip_type === 'other' && !empty($ticket->trip_type_other)) {
    $tripTypeInfo = $ticket->trip_type_other;
}

// Create PDF - LANDSCAPE LEGAL
$pdf = new TCPDF('L', PDF_UNIT, 'LEGAL', true, 'UTF-8', false);
$pdf->SetCreator('LOKA Fleet Management System');
$pdf->SetAuthor('DICT - Region II');
$pdf->SetTitle('Trip Ticket TT-' . $ticket->request_id);

// Remove default header/footer
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(12, 12, 12);
$pdf->SetAutoPageBreak(FALSE, 0);
$pdf->AddPage();

// Styles
$pdf->SetFont('times', '', 10);

// ===== HEADER =====
// Top border line
$pdf->Line(12, 12, 278, 12);

$pdf->SetFont('times', 'B', 11);
$pdf->Cell(0, 6, 'Republic of the Philippines', 0, 1, 'C');
$pdf->Cell(0, 6, 'DEPARTMENT OF INFORMATION AND COMMUNICATIONS TECHNOLOGY', 0, 1, 'C');
$pdf->SetFont('times', 'B', 10);
$pdf->Cell(0, 6, 'REGION II - CAGAYAN VALLEY', 0, 1, 'C');

// Bottom border line
$pdf->Line(12, 36, 278, 36);
$pdf->Ln(5);

// Title
$pdf->SetFont('times', 'B', 16);
$pdf->Cell(0, 8, 'VEHICLE USE REQUEST / TRIP TICKET', 0, 1, 'C');
$pdf->Ln(2);

// Ticket info
$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(70, 6, 'Ticket No.: ______________________', 0, 0);
$pdf->Cell(30, 6, 'Date Prepared:', 0, 0);
$pdf->Cell(0, 6, date('F j, Y'), 'B', 1);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(0, 6, 'TT-' . $ticket->request_id . ' (Ref: VRF-' . $ticket->request_id . ')', 0, 1);
$pdf->Ln(4);

// Main content columns
$leftColX = 12;
$leftColWidth = 175;
$rightColX = 195;
$rightColWidth = 70;

// ===== SECTION I: PARTICULARS OF TRIP =====
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(0, 5, 'I. PARTICULARS OF TRIP', 0, 1);
$pdf->Ln(2);

$pdf->SetFont('helvetica', '', 9);

  // Row 1: Date of Trip & Destination (using MultiCell for proper wrapping)
  $pdf->Cell(25, 6, 'Date of Trip:', 0, 0);
  $pdf->SetFont('helvetica', 'B', 9);
  
  // Check if it's a multi-day trip
  $startDate = date('F j, Y', strtotime($ticket->start_date));
  $endDate = date('F j, Y', strtotime($ticket->end_date));
  if ($startDate !== $endDate) {
      // Multi-day trip: stack both dates
      $dateText = $startDate . "\n" . $endDate;
  } else {
      // Single day trip
      $dateText = $startDate;
  }
  // Use WriteHTMLCell for proper formatting
  $pdf->WriteHTMLCell(85, 0, '', '', $dateText, 'B', 0, 0, true, 'L', true);
  $pdf->SetFont('helvetica', '', 9);
  $pdf->Cell(22, 6, 'Destination:', 0, 0);
  $pdf->WriteHTMLCell(0, 0, '', '', $ticket->destination, 'B', 1, 0, true, 'L', true);

// Row 2: Time Out & Time In
$pdf->Cell(25, 6, 'Time Out:', 0, 0);
$pdf->Cell(85, 6, date('h:i A', strtotime($ticket->start_date)), 'B', 0, 'C');
$pdf->Cell(22, 6, 'Time In:', 0, 0);
$pdf->Cell(0, 6, date('h:i A', strtotime($ticket->end_date)), 'B', 1, 'C');

  // Row 3: Type of Trip & No. of Passengers
  $pdf->Cell(25, 6, 'Type of Trip:', 0, 0);
  $pdf->WriteHTMLCell(85, 0, '', '', $tripTypeInfo, 'B', 0, 0, true, 'L', true);
  $pdf->Cell(22, 6, 'No. of Passengers:', 0, 0);
  $pdf->Cell(0, 6, count($passengers), 'B', 1, 'C');
$pdf->Ln(3);

// ===== SECTION II: VEHICLE & DRIVER =====
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(0, 5, 'II. VEHICLE & DRIVER INFORMATION', 0, 1);
$pdf->Ln(2);

$pdf->SetFont('helvetica', '', 9);

  // Row 1: Plate Number & Driver (using WriteHTMLCell for proper wrapping)
  $pdf->Cell(25, 6, 'Plate Number:', 0, 0);
  $pdf->WriteHTMLCell(85, 0, '', '', ($ticket->plate_number ?: 'N/A'), 'B', 0, 0, true, 'L', true);
  $pdf->Cell(22, 6, 'Driver:', 0, 0);
  $pdf->WriteHTMLCell(0, 0, '', '', '<b><span style="color:#dc3545;">' . htmlspecialchars(strtoupper($ticket->driver_name ?: 'N/A')) . '</span></b>', 'B', 1, 0, true, 'L', true);

  // Row 2: Make / Model & License
   $pdf->Cell(25, 6, 'Make / Model:', 0, 0);
  $makeModel = trim((($ticket->make ?: 'N/A') . ' ' . ($ticket->vehicle_model ?: 'N/A')));
  $pdf->WriteHTMLCell(85, 0, '', '', $makeModel, 'B', 0, 0, true, 'L', true);
  $pdf->Cell(22, 6, 'License No.:', 0, 0);
  $pdf->WriteHTMLCell(0, 0, '', '', ($ticket->driver_license ?: 'N/A'), 'B', 1, 0, true, 'L', true);

  // Row 3: Fuel Type & Color
  $pdf->Cell(25, 6, 'Fuel Type:', 0, 0);
  $fuelType = $ticket->fuel_type ?? 'N/A';
  $pdf->WriteHTMLCell(85, 0, '', '', ucfirst($fuelType), 'B', 0, 0, true, 'L', true);
  $pdf->Cell(22, 6, 'Color:', 0, 0);
  $color = $ticket->color ?? 'N/A';
  $pdf->WriteHTMLCell(0, 0, '', '', ucfirst($color), 'B', 1, 0, true, 'L', true);

$pdf->Ln(2);

// ===== SECTION III: PASSENGERS =====
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(0, 5, 'III. PASSENGERS', 0, 1);
$pdf->Ln(2);

if (!empty($passengers)) {
    $passNum = 1;
    $twoCols = true;
    $startY = $pdf->GetY();
    foreach ($passengers as $p) {
        if ($twoCols) {
            $pdf->Cell(12, 5, $passNum . '.', 0, 0);
            $pdf->WriteHTMLCell(85, 0, '', '', '<b>' . htmlspecialchars(strtoupper($p->passenger_name ?: '(Guest)')) . '</b>', 'B', 0, 0, true, 'L', true);
        } else {
            $pdf->Cell(12, 5, $passNum . '.', 0, 0);
            $pdf->WriteHTMLCell(85, 0, '', '', '<b>' . htmlspecialchars(strtoupper($p->passenger_name ?: '(Guest)')) . '</b>', 'B', 1, 0, true, 'L', true);
        }
        $twoCols = !$twoCols;
        $passNum++;
    }
    if (!$twoCols) {
        $pdf->Ln(1);
    }
} else {
    $pdf->Cell(0, 6, 'No passengers', 0, 1);
}
$pdf->Ln(3);

// ===== RIGHT COLUMN: ODOMETER & FUEL =====
$pdf->SetY($pdf->GetY() - 5);
$rightY = $pdf->GetY();

$pdf->SetX($rightColX);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell($rightColWidth, 5, 'IV. ODOMETER', 0, 1);
$pdf->SetX($rightColX);
$pdf->Ln(2);

$pdf->SetFont('helvetica', '', 8);
$pdf->SetX($rightColX);
$pdf->Cell(25, 5, 'Start:', 0, 0);
$pdf->Cell(45, 5, ($ticket->start_mileage ? number_format($ticket->start_mileage) : '____') . ' km', 'B', 1);

$pdf->SetX($rightColX);
$pdf->Cell(25, 5, 'End:', 0, 0);
$pdf->Cell(45, 5, ($ticket->end_mileage ? number_format($ticket->end_mileage) : '____') . ' km', 'B', 1);

$pdf->SetX($rightColX);
$pdf->Cell(25, 5, 'Total:', 0, 0);
$pdf->Cell(45, 5, ($ticket->distance_traveled ? number_format($ticket->distance_traveled) : '____') . ' km', 'B', 1);

$pdf->SetX($rightColX);
$pdf->Ln(3);

$pdf->SetX($rightColX);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell($rightColWidth, 5, 'V. FUEL', 0, 1);
$pdf->SetX($rightColX);
$pdf->Ln(2);

$pdf->SetFont('helvetica', '', 8);
$pdf->SetX($rightColX);
$pdf->Cell(25, 5, 'Consumed:', 0, 0);
$pdf->Cell(45, 5, ($ticket->fuel_consumed ? number_format($ticket->fuel_consumed, 1) . ' L' : '____'), 'B', 1);

$pdf->SetX($rightColX);
$pdf->Cell(25, 5, 'Amount:', 0, 0);
$pdf->Cell(45, 5, ($ticket->fuel_cost ? 'P' . number_format($ticket->fuel_cost, 2) : 'P____'), 'B', 1);

$pdf->SetX($rightColX);
$pdf->Ln(2);
$pdf->SetX($rightColX);
$pdf->SetFont('helvetica', 'I', 7);
$pdf->Cell(15, 4, 'Station:', 0, 0);
$pdf->Cell(55, 4, '', 'B', 1);

$pdf->SetX($rightColX);
$pdf->Cell(15, 4, 'Refill P', 0, 0);
$pdf->Cell(55, 4, '', 'B', 1);

$pdf->SetX($rightColX);
$pdf->Cell(20, 4, 'Odo Before:', 0, 0);
$pdf->Cell(50, 4, '', 'B', 1);

$pdf->SetX($rightColX);
$pdf->Cell(20, 4, 'Odo After:', 0, 0);
$pdf->Cell(50, 4, '', 'B', 1);

// Continue left column for signatories
$pdf->SetY($rightY + 85);
$pdf->SetX($leftColX);

// ===== SECTION VI: DRIVER CERTIFICATION =====
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(0, 5, 'VI. DRIVER CERTIFICATION', 0, 1);
$pdf->Ln(1);

$pdf->SetFont('helvetica', 'I', 8);
$pdf->MultiCell(0, 4, 'I hereby certify that all information provided above is true and correct.', 0, 'L');
$pdf->Ln(2);

$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(30, 8, 'Name:', 0, 0);
$pdf->WriteHTMLCell(100, 0, '', '', '<b><span style="color:#dc3545;">' . htmlspecialchars(strtoupper($ticket->driver_name)) . '</span></b>', 'B', 0, 0, true, 'L', true);
$pdf->Cell(25, 8, 'Date:', 0, 0);
$pdf->Cell(0, 8, '', 'B', 1);

$pdf->Cell(30, 8, 'Signature:', 0, 0);
$pdf->Cell(0, 8, '', 'B', 1);
$pdf->Ln(4);

// ===== SECTION VII: SIGNATORY CLEARANCE =====
$pdf->SetFont('helvetica', 'B', 12);
$pdf->SetFillColor(0, 51, 102);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(0, 7, 'VII. SIGNATORY CLEARANCE', 0, 1, 'C', true);
$pdf->SetTextColor(0, 0, 0);
$pdf->Ln(3);

// Signatories in two columns
$signColWidth = 125;

// 1. PREPARED BY: DRIVER
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(0, 5, '1. PREPARED BY: DRIVER', 0, 1);
$pdf->SetFont('helvetica', 'I', 8);
$pdf->Cell(0, 4, '   Prepared this trip ticket and certifies all information is correct.', 0, 1);
$pdf->Ln(1);

$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(25, 8, 'Name:', 0, 0);
$pdf->Cell($signColWidth, 8, '', 'B', 0);
$pdf->Cell(20, 8, 'Date:', 0, 0);
$pdf->Cell(0, 8, '', 'B', 1);

$pdf->Cell(25, 8, 'Signature:', 0, 0);
$pdf->Cell(0, 8, '', 'B', 1);
$pdf->Ln(2);

// 2. REVIEWED BY: MOTORPOOL HEAD
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(0, 5, '2. REVIEWED BY: MOTORPOOL HEAD', 0, 1);
$pdf->SetFont('helvetica', 'I', 8);
$pdf->Cell(0, 4, '   Reviewed and verified trip details, vehicle condition, and mileage.', 0, 1);
$pdf->Ln(1);

$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(25, 8, 'Name:', 0, 0);
$pdf->Cell($signColWidth, 8, '', 'B', 0);
$pdf->Cell(20, 8, 'Date:', 0, 0);
$pdf->Cell(0, 8, '', 'B', 1);

$pdf->Cell(25, 8, 'Signature:', 0, 0);
$pdf->Cell(0, 8, '', 'B', 1);
$pdf->Ln(2);

// 3. APPROVED BY: ADMIN & FINANCE
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(0, 5, '3. APPROVED BY: ADMIN & FINANCE DIVISION CHIEF', 0, 1);
$pdf->SetFont('helvetica', 'I', 8);
$pdf->Cell(0, 4, '   Approved and certified that all documents are complete and trip is valid for payment.', 0, 1);
$pdf->Ln(1);

$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(25, 8, 'Name:', 0, 0);
$pdf->Cell($signColWidth, 8, '', 'B', 0);
$pdf->Cell(20, 8, 'Date:', 0, 0);
$pdf->Cell(0, 8, '', 'B', 1);

$pdf->Cell(25, 8, 'Signature:', 0, 0);
$pdf->Cell(0, 8, '', 'B', 1);
$pdf->Ln(4);

// ===== FOOTER =====
$pdf->SetY(-22);
$pdf->SetFont('helvetica', 'I', 7);
$footerText = 'NOTES: (1) This document must be signed by all parties. (2) Attach TO/OB Slip if applicable. (3) Submit to Finance for processing.';
$pdf->WriteHTMLCell(0, 4, '', '', $footerText, 0, 1, 0, true, 'C', true);

$pdf->SetY(-12);
$pdf->SetFont('helvetica', '', 6);
$pdf->Cell(0, 4, 'Generated by LOKA Fleet Management System | DICT Region II | ' . date('F j, Y g:i A'), 0, 1, 'C');

// Border around content
$pdf->Rect(11, 11, 268, 195);

// Output PDF
$filename = 'Trip_Ticket_TT-' . $ticket->request_id . '_VRF-' . $ticket->request_id . '_' . date('Y-m-d');
$pdf->Output($filename . '.pdf', 'D');
exit;
