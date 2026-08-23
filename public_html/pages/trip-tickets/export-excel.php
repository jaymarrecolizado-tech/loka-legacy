<?php
/**
 * LOKA - Export Trip Ticket to Excel
 *
 * Generates an Excel file for trip tickets with signature lines
 */

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
            v.plate_number, v.make, v.model as vehicle_model, v.color,
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

// Check permission
if (isDriver()) {
    $currentDriverId = db()->fetchColumn(
        "SELECT id FROM drivers WHERE user_id = ? AND deleted_at IS NULL",
        [userId()]
    );
    if ($ticket->driver_id != $currentDriverId) {
        die('You can only export your own trip tickets.');
    }
}

// Get passengers from the original VRF (request)
$passengers = db()->fetchAll(
    "SELECT rp.id,
            CASE
                WHEN rp.user_id IS NOT NULL THEN u.name
                ELSE rp.guest_name
            END as passenger_name,
            u.phone as passenger_phone
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
    'maintenance' => 'Maintenance Run',
    'travel_order' => 'Travel Order',
    'other' => 'Other'
];
$tripTypeInfo = $tripTypeLabels[$ticket->trip_type] ?? 'Official Business';
// Use custom label for "Other" type
if ($ticket->trip_type === 'other' && !empty($ticket->trip_type_other)) {
    $tripTypeInfo = $ticket->trip_type_other;
}

// Calculate duration
$duration = '';
if ($ticket->start_date && $ticket->end_date) {
    $start = strtotime($ticket->start_date);
    $end = strtotime($ticket->end_date);
    $diff = $end - $start;
    $hours = floor($diff / 3600);
    $minutes = floor(($diff % 3600) / 60);
    $duration = $hours . 'h ' . $minutes . 'm';
}

// Set headers for Excel download
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="Trip_Ticket_TT-' . $ticket->request_id . '_VRF-' . $ticket->request_id . '_' . date('Y-m-d') . '.xls"');
header('Pragma: no-cache');
header('Expires: 0');

// Excel XML format
echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<?php
echo '<?mso-application progid="Excel.Sheet"?>';
?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:html="http://www.w3.org/TR/REC-html40">
 <DocumentProperties xmlns="urn:schemas-microsoft-com:office:office">
  <Created><?php echo date('Y-m-d\TH:i:s'); ?></Created>
 </DocumentProperties>
 <ExcelWorkbook xmlns="urn:schemas-microsoft-com:office:excel">
  <WindowHeight>9000</WindowHeight>
  <WindowWidth>15000</WindowWidth>
  <WindowTopX>0</WindowTopX>
  <WindowTopY>0</WindowTopY>
  <ProtectStructure>False</ProtectStructure>
  <ProtectWindows>False</ProtectWindows>
 </ExcelWorkbook>
 <Styles>
  <Style ss:ID="Default" ss:Name="Normal">
   <Alignment ss:Vertical="Bottom"/>
   <Borders/>
   <Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="11"/>
   <Interior/>
   <NumberFormat/>
   <Protection/>
  </Style>
  <Style ss:ID="s62">
   <Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="11" ss:Bold="1"/>
  </Style>
  <Style ss:ID="s63">
   <Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="14" ss:Bold="1"/>
  </Style>
  <Style ss:ID="s64">
   <Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="12" ss:Bold="1"/>
   <Interior ss:Color="#D9534F"/>
  </Style>
  <Style ss:ID="s65">
   <Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="11"/>
   <Interior ss:Color="#F9F9F9"/>
  </Style>
  <Style ss:ID="s66">
   <Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="10" ss:Bold="1"/>
   <Interior ss:Color="#E0E0E0"/>
  </Style>
  <Style ss:ID="s67">
   <Borders>
    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
   </Borders>
  </Style>
 </Styles>
 <Worksheet ss:Name="Trip Ticket">
  <Table>
   <Column ss:Width="30"/>
   <Column ss:Width="80"/>
   <Column ss:Width="150"/>
   <Column ss:Width="200"/>
   <Column ss:Width="30"/>
   <Column ss:Width="80"/>
   <Column ss:Width="150"/>
   <Column ss:Width="200"/>

   <!-- Header -->
   <Row>
    <Cell ss:MergeAcross="7" ss:StyleID="s63"><Data ss:Type="String">DEPARTMENT OF INFORMATION AND COMMUNICATIONS TECHNOLOGY</Data></Cell>
   </Row>
   <Row>
    <Cell ss:MergeAcross="7" ss:StyleID="s63"><Data ss:Type="String">REGION II - CAGAYAN VALLEY</Data></Cell>
   </Row>
   <Row ss:Index="4"/>
   <Row>
    <Cell ss:MergeAcross="7" ss:StyleID="s64"><Data ss:Type="String">  TRIP TICKET / CERTIFICATE OF TRIP</Data></Cell>
   </Row>
   <Row ss:Index="6"/>
   <Row>
    <Cell ss:StyleID="s62"><Data ss:Type="String">Ticket No.:</Data></Cell>
    <Cell ss:MergeAcross="2" ss:StyleID="s62"><Data ss:Type="String">TT-<?php echo $ticket->request_id; ?> (Ref: VRF-<?php echo $ticket->request_id; ?>)</Data></Cell>
    <Cell><Data ss:Type="String">Date:</Data></Cell>
    <Cell ss:MergeAcross="2" ss:StyleID="s62"><Data ss:Type="String"><?php echo date('F j, Y'); ?></Data></Cell>
   </Row>
   <Row ss:Index="9"/>

   <!-- Driver Information -->
   <Row>
    <Cell ss:MergeAcross="7" ss:StyleID="s66"><Data ss:Type="String">  DRIVER INFORMATION</Data></Cell>
   </Row>
   <Row>
    <Cell ss:StyleID="s62"><Data ss:Type="String">Name:</Data></Cell>
    <Cell ss:MergeAcross="2"><Data ss:Type="String"><?php echo $ticket->driver_name; ?></Data></Cell>
    <Cell><Data ss:Type="String">License No.:</Data></Cell>
    <Cell ss:MergeAcross="2"><Data ss:Type="String"><?php echo $ticket->driver_license ?: 'N/A'; ?></Data></Cell>
   </Row>
   <Row>
    <Cell ss:StyleID="s62"><Data ss:Type="String">Phone:</Data></Cell>
    <Cell ss:MergeAcross="2"><Data ss:Type="String"><?php echo $ticket->driver_phone ?: 'N/A'; ?></Data></Cell>
    <Cell><Data ss:Type="String">Department:</Data></Cell>
    <Cell ss:MergeAcross="2"><Data ss:Type="String"><?php echo $ticket->department_name ?: 'N/A'; ?></Data></Cell>
   </Row>
   <Row/>

   <!-- Vehicle Information -->
   <Row>
    <Cell ss:MergeAcross="7" ss:StyleID="s66"><Data ss:Type="String">  VEHICLE INFORMATION</Data></Cell>
   </Row>
   <Row>
    <Cell ss:StyleID="s62"><Data ss:Type="String">Plate No.:</Data></Cell>
    <Cell><Data ss:Type="String"><?php echo $ticket->plate_number ?: 'N/A'; ?></Data></Cell>
    <Cell><Data ss:Type="String">Make:</Data></Cell>
    <Cell ss:MergeAcross="3"><Data ss:Type="String"><?php echo ($ticket->make ?: 'N/A') . ' ' . ($ticket->vehicle_model ?: ''); ?></Data></Cell>
   </Row>
   <Row/>

   <!-- Trip Details -->
   <Row>
    <Cell ss:MergeAcross="7" ss:StyleID="s66"><Data ss:Type="String">  TRIP DETAILS</Data></Cell>
   </Row>
   <Row>
    <Cell ss:StyleID="s62"><Data ss:Type="String">Trip Type:</Data></Cell>
    <Cell ss:MergeAcross="6"><Data ss:Type="String"><?php echo $tripTypeInfo; ?></Data></Cell>
   </Row>
   <Row>
    <Cell ss:StyleID="s62"><Data ss:Type="String">Destination:</Data></Cell>
    <Cell ss:MergeAcross="6"><Data ss:Type="String"><?php echo $ticket->destination; ?></Data></Cell>
   </Row>
   <Row>
    <Cell ss:StyleID="s62"><Data ss:Type="String">Purpose:</Data></Cell>
    <Cell ss:MergeAcross="6"><Data ss:Type="String"><?php echo $ticket->purpose ?: 'N/A'; ?></Data></Cell>
   </Row>
   <Row>
    <Cell ss:StyleID="s62"><Data ss:Type="String">Passengers:</Data></Cell>
    <Cell><Data ss:Type="Number"><?php echo count($passengers); ?></Data></Cell>
    <Cell ss:MergeAcross="5"><Data ss:Type="String"></Data></Cell>
   </Row>
   <Row>
    <Cell ss:StyleID="s62"><Data ss:Type="String">Departure:</Data></Cell>
    <Cell><Data ss:Type="String"><?php echo formatDateTime($ticket->start_date); ?></Data></Cell>
    <Cell><Data ss:Type="String">Arrival:</Data></Cell>
    <Cell><Data ss:Type="String"><?php echo formatDateTime($ticket->end_date); ?></Data></Cell>
    <Cell><Data ss:Type="String">Duration:</Data></Cell>
    <Cell ss:MergeAcross="2"><Data ss:Type="String"><?php echo $duration; ?></Data></Cell>
   </Row>
   <Row/>

   <!-- Passengers from VRF -->
   <Row>
    <Cell ss:MergeAcross="7" ss:StyleID="s66"><Data ss:Type="String">  PASSENGERS (From VRF-<?php echo $ticket->request_id; ?>)</Data></Cell>
   </Row>
<?php if (!empty($passengers)): ?>
<?php foreach ($passengers as $index => $p): ?>
   <Row>
    <Cell ss:StyleID="s62"><Data ss:Type="String">Passenger <?php echo $index + 1; ?>:</Data></Cell>
    <Cell ss:MergeAcross="3"><Data ss:Type="String"><?php echo $p->passenger_name ?: '(Guest)'; ?></Data></Cell>
    <Cell><Data ss:Type="String">Date:</Data></Cell>
    <Cell ss:MergeAcross="2"><Data ss:Type="String">_____________</Data></Cell>
   </Row>
   <Row>
    <Cell><Data ss:Type="String">Signature:</Data></Cell>
    <Cell ss:MergeAcross="6"><Data ss:Type="String">_______________________________________________</Data></Cell>
   </Row>
<?php endforeach; ?>
<?php else: ?>
   <Row>
    <Cell ss:MergeAcross="7"><Data ss:Type="String">No passengers recorded</Data></Cell>
   </Row>
<?php endif; ?>
   <Row/>

   <!-- Mileage & Fuel -->
   <Row>
    <Cell ss:MergeAcross="7" ss:StyleID="s66"><Data ss:Type="String">  MILEAGE & FUEL CONSUMPTION</Data></Cell>
   </Row>
   <Row>
    <Cell ss:StyleID="s62"><Data ss:Type="String">Start Odometer:</Data></Cell>
    <Cell><Data ss:Type="String"><?php echo $ticket->start_mileage ? number_format($ticket->start_mileage) . ' km' : 'N/A'; ?></Data></Cell>
    <Cell><Data ss:Type="String">End Odometer:</Data></Cell>
    <Cell><Data ss:Type="String"><?php echo $ticket->end_mileage ? number_format($ticket->end_mileage) . ' km' : 'N/A'; ?></Data></Cell>
    <Cell><Data ss:Type="String">Distance:</Data></Cell>
    <Cell ss:MergeAcross="2"><Data ss:Type="String"><?php echo $ticket->distance_traveled ? number_format($ticket->distance_traveled) . ' km' : 'N/A'; ?></Data></Cell>
   </Row>
   <Row>
    <Cell ss:StyleID="s62"><Data ss:Type="String">Fuel Consumed:</Data></Cell>
    <Cell><Data ss:Type="String"><?php echo $ticket->fuel_consumed ? number_format($ticket->fuel_consumed, 2) . ' L' : 'N/A'; ?></Data></Cell>
    <Cell><Data ss:Type="String">Cost:</Data></Cell>
    <Cell ss:MergeAcross="4"><Data ss:Type="String"><?php echo $ticket->fuel_cost ? 'PHP ' . number_format($ticket->fuel_cost, 2) : 'N/A'; ?></Data></Cell>
   </Row>
   <Row ss:Index="26"/>

   <!-- Driver Certification Section -->
   <Row>
    <Cell ss:MergeAcross="7" ss:StyleID="s66"><Data ss:Type="String">  DRIVER CERTIFICATION</Data></Cell>
   </Row>
   <Row>
    <Cell ss:MergeAcross="7"><Data ss:Type="String">I hereby certify that all information stated above is true and correct.</Data></Cell>
   </Row>
   <Row>
    <Cell><Data ss:Type="String">Name:</Data></Cell>
    <Cell ss:MergeAcross="3"><Data ss:Type="String"><?php echo $ticket->driver_name; ?></Data></Cell>
    <Cell><Data ss:Type="String">Date:</Data></Cell>
    <Cell ss:MergeAcross="2"><Data ss:Type="String">_____________________</Data></Cell>
   </Row>
   <Row>
    <Cell><Data ss:Type="String">Signature:</Data></Cell>
    <Cell ss:MergeAcross="6"><Data ss:Type="String">___________________________________________</Data></Cell>
   </Row>
   <Row/>

   <!-- Signatory Clearance -->
   <Row>
    <Cell ss:MergeAcross="7" ss:StyleID="s64"><Data ss:Type="String">  SIGNATORY CLEARANCE (For Manual Routing)</Data></Cell>
   </Row>

   <!-- 1. Prepared by: Driver -->
   <Row>
    <Cell ss:MergeAcross="7" ss:StyleID="s66"><Data ss:Type="String">  1. PREPARED BY: DRIVER</Data></Cell>
   </Row>
   <Row>
    <Cell ss:MergeAcross="7"><Data ss:Type="String">Prepared this trip ticket and certifies all information is correct.</Data></Cell>
   </Row>
   <Row>
    <Cell><Data ss:Type="String">Name:</Data></Cell>
    <Cell ss:MergeAcross="3"><Data ss:Type="String">_____________________________</Data></Cell>
    <Cell><Data ss:Type="String">Date:</Data></Cell>
    <Cell ss:MergeAcross="2"><Data ss:Type="String">_____________________</Data></Cell>
   </Row>
   <Row>
    <Cell><Data ss:Type="String">Signature:</Data></Cell>
    <Cell ss:MergeAcross="6"><Data ss:Type="String">___________________________________________</Data></Cell>
   </Row>
   <Row/>

   <!-- 2. Reviewed by: Motorpool Head -->
   <Row>
    <Cell ss:MergeAcross="7" ss:StyleID="s66"><Data ss:Type="String">  2. REVIEWED BY: MOTORPOOL HEAD</Data></Cell>
   </Row>
   <Row>
    <Cell ss:MergeAcross="7"><Data ss:Type="String">Reviewed and verified trip details, vehicle condition, and mileage.</Data></Cell>
   </Row>
   <Row>
    <Cell><Data ss:Type="String">Name:</Data></Cell>
    <Cell ss:MergeAcross="3"><Data ss:Type="String">_____________________________</Data></Cell>
    <Cell><Data ss:Type="String">Date:</Data></Cell>
    <Cell ss:MergeAcross="2"><Data ss:Type="String">_____________________</Data></Cell>
   </Row>
   <Row>
    <Cell><Data ss:Type="String">Signature:</Data></Cell>
    <Cell ss:MergeAcross="6"><Data ss:Type="String">___________________________________________</Data></Cell>
   </Row>
   <Row/>

   <!-- 3. Approved by: Admin & Finance Division Chief -->
   <Row>
    <Cell ss:MergeAcross="7" ss:StyleID="s66"><Data ss:Type="String">  3. APPROVED BY: ADMIN &amp; FINANCE DIVISION CHIEF</Data></Cell>
   </Row>
   <Row>
    <Cell ss:MergeAcross="7"><Data ss:Type="String">Approved and certified that all documents are complete and trip is valid for payment processing.</Data></Cell>
   </Row>
   <Row>
    <Cell><Data ss:Type="String">Name:</Data></Cell>
    <Cell ss:MergeAcross="3"><Data ss:Type="String">_____________________________</Data></Cell>
    <Cell><Data ss:Type="String">Date:</Data></Cell>
    <Cell ss:MergeAcross="2"><Data ss:Type="String">_____________________</Data></Cell>
   </Row>
   <Row>
    <Cell><Data ss:Type="String">Signature:</Data></Cell>
    <Cell ss:MergeAcross="6"><Data ss:Type="String">___________________________________________</Data></Cell>
   </Row>
   <Row/>

   <!-- Notes -->
   <Row>
    <Cell ss:StyleID="s62"><Data ss:Type="String">NOTES:</Data></Cell>
   </Row>
   <Row>
    <Cell ss:Index="44"><Data ss:Type="String">1. This document must be signed by all parties indicated above.</Data></Cell>
   </Row>
   <Row>
    <Cell><Data ss:Type="String">2. Attach original Travel Order (TO) and Official Business Slip if applicable.</Data></Cell>
   </Row>
   <Row>
    <Cell><Data ss:Type="String">3. Submit completed form to the Finance Division for processing.</Data></Cell>
   </Row>
   <Row ss:Index="47"/>

   <!-- Footer -->
   <Row>
    <Cell ss:MergeAcross="7" ss:StyleID="s65"><Data ss:Type="String">Generated by LOKA Fleet Management System | <?php echo date('F j, Y g:i A'); ?></Data></Cell>
   </Row>
  </Table>
 </Worksheet>
</Workbook>
