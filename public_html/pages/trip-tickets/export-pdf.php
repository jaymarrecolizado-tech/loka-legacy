<?php
/**
 * LOKA - Export Trip Ticket to PDF (portrait A4, polished DICT format)
 * Rebuilt 2026-09-02 to match summary-print.php portrait design:
 * stacked sections, proper wrapping, no right-col overlap, auto page break.
 */

require_once BASE_PATH . '/vendor/tecnickcom/tcpdf/tcpdf.php';

$ticketId = (int) get('id', 0);
if (!$ticketId) {
    die('Invalid ticket ID.');
}

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

if (isDriver()) {
    $currentDriverId = db()->fetchColumn(
        "SELECT id FROM drivers WHERE user_id = ? AND deleted_at IS NULL",
        [userId()]
    );
    if ($ticket->driver_id != $currentDriverId) {
        die('You can only export your own trip tickets.');
    }
}

$passengers = db()->fetchAll(
    "SELECT rp.id,
            CASE WHEN rp.user_id IS NOT NULL THEN u.name ELSE rp.guest_name END as passenger_name
     FROM request_passengers rp
     LEFT JOIN users u ON rp.user_id = u.id
     WHERE rp.request_id = ?
     ORDER BY rp.id ASC",
    [$ticket->request_id]
);

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

$startDateStr = $ticket->start_date ? date('M j, Y', strtotime($ticket->start_date)) : '—';
$startTimeStr = $ticket->start_date ? date('g:i A', strtotime($ticket->start_date)) : '—';
$endDateStr   = $ticket->end_date ? date('M j, Y', strtotime($ticket->end_date)) : '—';
$endTimeStr   = $ticket->end_date ? date('g:i A', strtotime($ticket->end_date)) : '—';
$dateRange = $startDateStr === $endDateStr ? $startDateStr : $startDateStr . ' – ' . $endDateStr;
$ticketNo = 'TT-' . $ticket->request_id . ' (VRF-' . $ticket->request_id . ')';
$plate = $ticket->plate_number ?: '—';
$makeModel = trim(($ticket->make ?: '') . ' ' . ($ticket->vehicle_model ?: '')) ?: '—';
$fuelType = ucfirst($ticket->fuel_type ?? '—');
$driverName = $ticket->driver_name ?: '—';
$license = $ticket->driver_license ?: '—';
$dest = $ticket->destination ?: ($ticket->trip_destination ?: '—');
$purpose = $ticket->purpose ?: ($ticket->trip_purpose ?: '—');
$odoStart = $ticket->start_mileage !== null ? number_format($ticket->start_mileage) . ' km' : '—';
$odoEnd   = $ticket->end_mileage !== null ? number_format($ticket->end_mileage) . ' km' : '—';
$odoTotal = $ticket->distance_traveled !== null ? number_format($ticket->distance_traveled) . ' km' : '—';
$fuelConsumed = $ticket->fuel_consumed !== null ? number_format($ticket->fuel_consumed, 2) . ' L' : '—';
$fuelCost = $ticket->fuel_cost !== null ? '₱' . number_format($ticket->fuel_cost, 2) : '—';
$dispatchGuard = $ticket->dispatch_guard ?: '—';
$arrivalGuard  = $ticket->arrival_guard ?: '—';
$reviewedBy = $ticket->reviewed_by_name ?: '—';
$generatedAt = date('F j, Y g:i A');

// Passenger HTML (2-col table, no truncation)
$passHtml = '';
if (!empty($passengers)) {
    $passHtml .= '<table cellpadding="4" cellspacing="0" style="width:100%;border-collapse:collapse;font-size:8px;">';
    $col = 0;
    foreach ($passengers as $idx => $p) {
        $name = htmlspecialchars($p->passenger_name ?: '(Guest)', ENT_QUOTES);
        if ($col === 0) $passHtml .= '<tr>';
        $passHtml .= '<td style="width:8%;border:1px solid #bbbbbb;text-align:center;color:#444;">' . ($idx+1) . '.</td>';
        $passHtml .= '<td style="width:42%;border:1px solid #bbbbbb;padding:4px 6px;">' . $name . '</td>';
        $col++;
        if ($col === 2) { $passHtml .= '</tr>'; $col = 0; }
    }
    if ($col === 1) { $passHtml .= '<td style="border:1px solid #bbbbbb;"></td><td style="border:1px solid #bbbbbb;"></td></tr>'; }
    $passHtml .= '</table>';
} else {
    $passHtml = '<div style="border:1px solid #bbbbbb;padding:6px;font-size:8px;color:#666;text-align:center;">No additional passengers</div>';
}

$logoDict = BASE_PATH . '/assets/img/dict_logo.png';
$logoBp   = BASE_PATH . '/assets/img/bp_logo.png';
$hasDict = file_exists($logoDict);
$hasBp   = file_exists($logoBp);

$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('LOKA Fleet Management');
$pdf->SetAuthor('DICT Region II');
$pdf->SetTitle('Trip Ticket ' . $ticketNo);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(10, 8, 10);
$pdf->SetAutoPageBreak(true, 12);
$pdf->AddPage();
$pdf->SetFont('helvetica', '', 8);

// Use writeHTML for the whole ticket so wrapping/page-break works naturally
$html = '
<style>
  .hdrwrap{ border:1.5px solid #111; }
  .hdrtbl{ width:100%; }
  .hdrleft{ font-size:7px; color:#444; line-height:1.4; }
  .hdrleft strong{ font-size:8px; color:#111; }
  .hdrtitle{ font-size:14px; font-weight:700; letter-spacing:2px; color:#111; text-align:center; }
  .hdrbar{ width:28px; height:2px; background:#003580; margin:3px auto; }
  .hdrsub{ font-size:6.5px; color:#666; letter-spacing:1px; text-transform:uppercase; text-align:center; }
  .hdrright{ text-align:right; font-size:7px; color:#444; }
  .lbl{ font-size:6.5px; font-weight:700; letter-spacing:0.8px; text-transform:uppercase; color:#444; }
  .val{ font-size:9px; font-weight:700; color:#111; }
  .sec{ font-size:7px; font-weight:700; letter-spacing:1.2px; text-transform:uppercase; color:#003580; background:#f4f6f9; border-top:1.2px solid #888; border-bottom:1px solid #bbb; padding:3px 6px; }
  .sec em{ font-weight:400; font-style:italic; text-transform:none; color:#666; }
  .ibox{ border:1px solid #bbb; padding:4px 6px; }
  .ibox .lbl{ display:block; margin-bottom:2px; }
  .sigbox{ text-align:center; border-right:1px solid #bbb; padding:8px 4px; }
  .sigrole{ font-size:6.5px; font-weight:700; letter-spacing:1px; text-transform:uppercase; color:#444; margin-bottom:14px; }
  .sigline{ border-top:1px solid #111; margin:0 8px 4px 8px; }
  .signame{ font-size:8px; font-weight:700; color:#111; }
  .sigtitle{ font-size:6.5px; color:#666; }
  table.grid{ width:100%; border-collapse:collapse; }
  table.grid td{ border:1px solid #bbbbbb; }
</style>

<div class="hdrwrap">
<table class="hdrtbl" cellpadding="4">
<tr>
  <td style="width:32%; vertical-align:middle;">'
    . ($hasDict ? '<img src="' . $logoDict . '" width="34" /> ' : '') . '
    <span class="hdrleft"><strong>Republic of the Philippines</strong><br/>Department of Information and<br/>Communications Technology<br/>Regional Office No. II</span>
  </td>
  <td style="width:36%; text-align:center; vertical-align:middle;">
    <div class="hdrtitle">VEHICLE TRIP TICKET</div>
    <div class="hdrbar"></div>
    <div class="hdrsub">Motorpool Unit · Admin and Finance Division</div>
  </td>
  <td style="width:32%; text-align:right; vertical-align:middle;">
    <div class="lbl">Trip No.</div>
    <div style="font-family:courier; font-size:10px; color:#003580; font-weight:700;">' . htmlspecialchars($ticketNo) . '</div>
    <div class="lbl" style="margin-top:4px;">Base</div>
    <div style="font-size:8px; font-weight:700; color:#111;">Tuguegarao City</div>
    ' . ($hasBp ? '<img src="' . $logoBp . '" width="34" />' : '') . '
  </td>
</tr>
</table>
</div>

<div class="sec">Vehicle Information</div>
<table class="grid" cellpadding="0">
<tr>
  <td style="width:15%; padding:4px 6px;"><div class="lbl">Plate Number</div><div class="val">' . htmlspecialchars($plate) . '</div></td>
  <td style="width:30%; padding:4px 6px;"><div class="lbl">Make / Model</div><div class="val">' . htmlspecialchars($makeModel) . '</div></td>
  <td style="width:15%; padding:4px 6px;"><div class="lbl">Fuel Type</div><div class="val">' . htmlspecialchars($fuelType) . '</div></td>
  <td style="width:20%; padding:4px 6px;"><div class="lbl">Driver Assigned</div><div class="val">' . htmlspecialchars($driverName) . '</div></td>
  <td style="width:20%; padding:4px 6px;"><div class="lbl">Date of Trip</div><div class="val" style="font-size:7.5px;">' . htmlspecialchars($dateRange) . '</div></td>
</tr>
</table>

<div class="sec">Trip Details <em>(to be filled by driver)</em></div>
<table class="grid" cellpadding="4" style="font-size:7px;">
<tr style="background:#ffffff; font-weight:700; color:#222; text-align:center;">
  <td style="width:12%;">Date</td>
  <td style="width:11%;">Departure<br/>Time</td>
  <td style="width:11%;">Arrival<br/>Time</td>
  <td style="width:12%;">Odo Depart</td>
  <td style="width:12%;">Odo Arrival</td>
  <td style="width:21%;">Destination</td>
  <td style="width:21%;">Purpose</td>
</tr>
<tr>
  <td style="text-align:center;">' . htmlspecialchars($startDateStr) . '</td>
  <td style="text-align:center;">' . htmlspecialchars($startTimeStr) . '</td>
  <td style="text-align:center;">' . htmlspecialchars($endTimeStr) . '</td>
  <td style="text-align:center;">' . htmlspecialchars($odoStart) . '</td>
  <td style="text-align:center;">' . htmlspecialchars($odoEnd) . '</td>
  <td style="font-size:7.5px;">' . nl2br(htmlspecialchars($dest)) . '</td>
  <td style="font-size:7.5px;">' . nl2br(htmlspecialchars($purpose)) . '</td>
</tr>
<tr>
  <td colspan="2" style="text-align:center;"><span class="lbl">Type of Trip</span><br/><span class="val" style="font-size:8px;">' . htmlspecialchars($tripTypeInfo) . '</span></td>
  <td colspan="2" style="text-align:center;"><span class="lbl">Distance</span><br/><span class="val">' . htmlspecialchars($odoTotal) . '</span></td>
  <td colspan="3" style="text-align:center;"><span class="lbl">Passengers</span> ' . htmlspecialchars(count($passengers)+1) . ' incl. requester</td>
</tr>
</table>

<div class="sec">Passengers</div>
' . $passHtml . '

<div class="sec">Fuel &amp; Odometer <em>(to be filled by driver)</em></div>
<table class="grid" cellpadding="4" style="font-size:7.5px; text-align:center;">
<tr style="font-weight:700; color:#222; background:#fff;">
  <td style="width:16%;">Odo Start</td>
  <td style="width:16%;">Odo End</td>
  <td style="width:16%;">Total Distance</td>
  <td style="width:16%;">Fuel Consumed</td>
  <td style="width:18%;">Fuel Amount</td>
  <td style="width:18%;">Station / GAS Voucher</td>
</tr>
<tr>
  <td>' . htmlspecialchars($odoStart) . '</td>
  <td>' . htmlspecialchars($odoEnd) . '</td>
  <td>' . htmlspecialchars($odoTotal) . '</td>
  <td>' . htmlspecialchars($fuelConsumed) . '</td>
  <td>' . htmlspecialchars($fuelCost) . '</td>
  <td style="font-size:6.5px; color:#666;">—</td>
</tr>
</table>

<div class="sec">Driver Certification</div>
<div style="border:1px solid #bbb; padding:6px 8px; font-size:7.5px; color:#333; line-height:1.4;">
  I hereby certify that all information provided above is true and correct.<br/><br/>
  <table style="width:100%;" cellpadding="2">
    <tr>
      <td style="width:45%; border-bottom:1px solid #111; text-align:center; font-size:8px; font-weight:700;">' . htmlspecialchars($driverName) . '<br/><span style="font-size:6px; font-weight:400; color:#666;">Name (Driver)</span></td>
      <td style="width:10%;"></td>
      <td style="width:20%; border-bottom:1px solid #111; text-align:center;"><br/><span style="font-size:6px; color:#666;">Date</span></td>
      <td style="width:25%; border-bottom:1px solid #111; text-align:center;"><br/><span style="font-size:6px; color:#666;">Signature</span></td>
    </tr>
  </table>
</div>

<div class="sec">Signatory Clearance</div>
<table class="grid" cellpadding="0">
<tr>
  <td class="sigbox" style="width:33%;">
    <div class="sigrole">Prepared By: Driver</div>
    <div style="height:16px;"></div><div class="sigline"></div>
    <div class="signame">' . htmlspecialchars($driverName) . '</div><div class="sigtitle">Driver — certifies trip info</div>
  </td>
  <td class="sigbox" style="width:33%;">
    <div class="sigrole">Reviewed By: Motorpool Head</div>
    <div style="height:16px;"></div><div class="sigline"></div>
    <div class="signame">' . htmlspecialchars($dispatchGuard !== '—' ? $dispatchGuard : $reviewedBy) . '</div><div class="sigtitle">Motorpool Unit</div>
  </td>
  <td class="sigbox" style="width:34%; border-right:none;">
    <div class="sigrole">Approved By: Admin &amp; Finance</div>
    <div style="height:16px;"></div><div class="sigline"></div>
    <div class="signame">Mina Flor T. Villafuerte</div><div class="sigtitle">Admin and Finance Division</div>
  </td>
</tr>
</table>

<div style="margin-top:6px; text-align:center; font-size:6px; color:#777; border-top:1px solid #111; padding:4px 0;">
  NOTES: (1) Must be signed by all parties. (2) Attach TO/OB Slip if applicable. (3) Submit to Finance.<br/>
  <span style="font-size:5.5px;">Generated by LOKA Fleet Management | DICT Region II | ' . htmlspecialchars($generatedAt) . ' | ' . htmlspecialchars($ticketNo) . '</span>
</div>
';

$pdf->writeHTML($html, true, false, true, false, '');

$filename = 'Trip_Ticket_' . $ticket->request_id . '_' . date('Y-m-d') . '.pdf';
$pdf->Output($filename, 'D');
exit;
