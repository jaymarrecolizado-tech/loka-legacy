<?php
/**
 * LOKA - Print Travel Order Trip Ticket
 * Alternative format for official business trips
 */
if (!defined('BASE_PATH'))
    exit;
$__verifyUrl = function_exists('tripTicketVerifyUrl') ? tripTicketVerifyUrl($tripTicketNumber ?? '') : '';
$__qrHtml = '';
if ($__verifyUrl) {
    require_once BASE_PATH . '/vendor/tecnickcom/tcpdf/tcpdf_barcodes_2d.php';
    $__qrBarcode = new TCPDF2DBarcode($__verifyUrl, 'QRCODE,M');
    $__pngRaw = function_exists('imagecreate') ? $__qrBarcode->getBarcodePngData(4, 4, [0,0,0]) : false;
    if (is_string($__pngRaw) && $__pngRaw !== '' && function_exists('imagecreatefromstring')) {
        $__src = @imagecreatefromstring($__pngRaw);
        if ($__src !== false) {
            $__sw = imagesx($__src); $__sh = imagesy($__src);
            $__pad = (int) max(16, round(min($__sw,$__sh)*0.14));
            $__flat = imagecreatetruecolor($__sw, $__sh);
            $__w = imagecolorallocate($__flat,255,255,255); imagefilledrectangle($__flat,0,0,$__sw,$__sh,$__w);
            imagecopy($__flat,$__src,0,0,0,0,$__sw,$__sh); imagedestroy($__src);
            $__dst = imagecreatetruecolor($__sw+($__pad*2), $__sh+($__pad*2));
            $__white = imagecolorallocate($__dst,255,255,255); imagefilledrectangle($__dst,0,0,$__sw+($__pad*2),$__sh+($__pad*2),$__white);
            imagecopy($__dst,$__flat,$__pad,$__pad,0,0,$__sw,$__sh); imagedestroy($__flat);
            ob_start(); imagepng($__dst); $__padded = ob_get_clean(); imagedestroy($__dst);
            $__qrHtml = '<img alt="Verify QR" style="width:84px;height:84px;display:block;" src="data:image/png;base64,' . base64_encode($__padded) . '">';
        }
    }
    if ($__qrHtml === '') {
        $__svg = $__qrBarcode->getBarcodeSVGcode(3,3,'black');
        $__svg = preg_replace('/<\?xml[^>]*\?>\s*/i','',$__svg);
        $__svg = preg_replace('/<!DOCTYPE[^>]*>\s*/i','',$__svg);
        if (preg_match('/<svg[^>]+width="([\d.]+)"[^>]+height="([\d.]+)"/i',$__svg,$m)) {
            $__svg = preg_replace('/<svg([^>]+)>/i','<svg$1 viewBox="0 0 '.$m[1].' '.$m[2].'">',$__svg,1);
        }
        $__qrHtml = '<div style="background:#fff;padding:8px;line-height:0;">' . $__svg . '</div>';
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Travel Order – DICT Region II</title>
    <link
        href="https://fonts.googleapis.com/css2?family=Libre+Baskerville:wght@400;700&family=Libre+Franklin:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <style>
        :root {
            --ink: #1a1a1a;
            --dark: #2c2c2c;
            --body: #3a3a3a;
            --label: #555555;
            --sub: #777777;
            --border: #cccccc;
            --border2: #999999;
            --accent: #8b0000;
            --accent2: #003366;
            --stripe: #f5f5f5;
            --white: #ffffff;
            --gold: #b8860b;
        }

        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Libre Franklin', sans-serif;
            background: #d0d0d0;
            min-height: 100vh;
            padding: 20px 16px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .controls {
            display: flex;
            gap: 10px;
            margin-bottom: 16px;
        }

        .btn {
            font-family: 'Libre Franklin', sans-serif;
            font-size: 13px;
            font-weight: 600;
            padding: 9px 26px;
            border-radius: 3px;
            cursor: pointer;
            border: 2px solid var(--ink);
            transition: all .15s;
        }

        .btn-print {
            background: var(--ink);
            color: #fff;
        }

        .btn-print:hover {
            background: var(--accent2);
            border-color: var(--accent2);
        }

        .btn-reset {
            background: #fff;
            color: var(--ink);
        }

        .btn-reset:hover {
            background: #eee;
        }

        /* TICKET — Letter size */
        .ticket {
            width: 8.5in;
            max-width: 100%;
            background: #fff;
            box-shadow: 0 4px 30px rgba(0, 0, 0, .15);
            border: 1px solid #aaa;
            flex-shrink: 0;
        }

        /* COAT OF ARMS AREA */
        .emblem {
            text-align: center;
            padding: 12px 20px 8px;
            border-bottom: 3px double var(--ink);
        }

        .emblem img {
            width: 55px;
            height: auto;
        }

        .emblem-text {
            font-size: 7px;
            letter-spacing: 0.15em;
            color: var(--sub);
            text-transform: uppercase;
            margin-top: 2px;
        }

        /* HEADER */
        .hdr {
            text-align: center;
            padding: 8px 20px 10px;
            border-bottom: 2px solid var(--ink);
        }

        .hdr-title {
            font-family: 'Libre Baskerville', serif;
            font-weight: 700;
            font-size: 22px;
            color: var(--ink);
            letter-spacing: 0.08em;
            text-transform: uppercase;
            margin-bottom: 2px;
        }

        .hdr-sub {
            font-size: 10px;
            color: var(--accent);
            letter-spacing: 0.2em;
            text-transform: uppercase;
            font-weight: 600;
        }

        .hdr-agency {
            font-size: 8px;
            color: var(--sub);
            letter-spacing: 0.1em;
            margin-top: 4px;
        }

        /* TO NUMBER BADGE */
        .to-badge {
            display: flex;
            justify-content: flex-end;
            padding: 6px 16px 4px;
            background: var(--stripe);
            border-bottom: 1px solid var(--border);
        }

        .to-number {
            font-family: 'Libre Franklin', sans-serif;
            font-size: 10px;
            font-weight: 700;
            color: var(--accent2);
            letter-spacing: 0.05em;
        }

        .to-number span {
            font-weight: 400;
            color: var(--label);
        }

        /* SECTIONS */
        .sec {
            font-family: 'Libre Franklin', sans-serif;
            font-size: 8px;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--white);
            padding: 4px 14px 4px;
            background: var(--accent2);
            margin: 0;
        }

        .sec-num {
            display: inline-block;
            background: var(--accent);
            color: #fff;
            padding: 2px 8px;
            margin-right: 10px;
            font-size: 9px;
            font-weight: 700;
        }

        /* INFO ROWS */
        .irow {
            display: flex;
            border-bottom: 1px solid var(--border);
        }

        .if {
            flex: 1;
            padding: 5px 10px 6px;
            border-right: 1px solid var(--border);
        }

        .if:last-child {
            border-right: none;
        }

        .if.f2 { flex: 2; }
        .if.f3 { flex: 3; }
        .if.f4 { flex: 4; }
        .if.f5 { flex: 5; }

        .if > .lbl {
            display: block;
            font-size: 7px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--label);
            margin-bottom: 3px;
        }

        .if input,
        .if textarea,
        .if select {
            width: 100%;
            border: none;
            outline: none;
            font-family: 'Libre Franklin', sans-serif;
            font-size: 11px;
            font-weight: 500;
            color: var(--ink);
            background: transparent;
            padding: 0;
            line-height: 1.3;
        }

        .if textarea {
            resize: none;
            font-size: 10px;
            min-height: 28px;
        }

        .if input::placeholder,
        .if textarea::placeholder {
            color: #bbb;
        }

        /* TABLES */
        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            font-family: 'Libre Franklin', sans-serif;
            font-size: 7px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: var(--white);
            text-align: center;
            vertical-align: middle;
            padding: 5px 3px;
            border: 1px solid var(--border2);
            background: var(--accent2);
        }

        td {
            border: 1px solid var(--border);
            vertical-align: middle;
            padding: 0;
            background: var(--white);
        }

        td input,
        td textarea {
            width: 100%;
            height: 100%;
            min-height: 24px;
            border: none;
            outline: none;
            font-family: 'Libre Franklin', sans-serif;
            font-size: 9px;
            font-weight: 500;
            color: var(--ink);
            background: transparent;
            padding: 2px 4px;
            text-align: center;
        }

        td input.left,
        td textarea.left {
            text-align: left;
        }

        td input::placeholder,
        td textarea::placeholder {
            color: #ccc;
            font-weight: 400;
        }

        /* Passenger table specific */
        .tbl-passengers td:nth-child(1) { width: 8%; }
        .tbl-passengers td:nth-child(2) { width: 30%; }
        .tbl-passengers td:nth-child(3) { width: 25%; }
        .tbl-passengers td:nth-child(4) { width: 25%; }
        .tbl-passengers td:nth-child(5) { width: 12%; }

        /* ITINERARY TABLE */
        .tbl-itinerary td:nth-child(1) { width: 12%; }
        .tbl-itinerary td:nth-child(2) { width: 14%; }
        .tbl-itinerary td:nth-child(3) { width: 14%; }
        .tbl-itinerary td:nth-child(4) { width: 10%; }
        .tbl-itinerary td:nth-child(5) { width: 10%; }
        .tbl-itinerary td:nth-child(6) { width: 22%; }
        .tbl-itinerary td:nth-child(7) { width: 18%; }

        /* PURPOSE TABLE */
        .tbl-purpose td:nth-child(1) { width: 15%; }
        .tbl-purpose td:nth-child(2) { width: 85%; }

        /* SIGNATURES */
        .sigs {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            border-top: 2px solid var(--ink);
        }

        .sig {
            padding: 12px 14px 14px;
            border-right: 1px solid var(--border);
            text-align: center;
        }

        .sig:last-child {
            border-right: none;
        }

        .sig-role {
            font-size: 8px;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: var(--accent2);
            margin-bottom: 18px;
        }

        .sig-line {
            border-top: 1px solid var(--ink);
            margin: 0 8px 4px;
        }

        .sig-name {
            font-size: 10px;
            font-weight: 700;
            color: var(--ink);
        }

        .sig-title {
            font-size: 8px;
            color: var(--sub);
            margin-top: 2px;
            font-weight: 400;
        }

        .sig-input {
            display: block;
            margin: 0 auto 2px;
            width: 85%;
            border: none;
            border-bottom: 1px solid var(--ink);
            outline: none;
            background: transparent;
            font-family: 'Libre Franklin', sans-serif;
            font-size: 11px;
            font-weight: 600;
            color: var(--ink);
            text-align: center;
            padding: 1px 2px;
            text-transform: uppercase;
        }

        .sig-input::placeholder {
            color: #bbb;
        }

        /* APPROVAL BOX */
        .approval-box {
            border: 2px solid var(--accent2);
            margin: 10px 14px;
            padding: 8px 12px;
            background: #f8fafc;
        }

        .approval-title {
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: var(--accent2);
            text-align: center;
            margin-bottom: 8px;
        }

        .approval-row {
            display: flex;
            gap: 12px;
        }

        .approval-item {
            flex: 1;
            text-align: center;
        }

        .approval-item .sig-line {
            margin: 0 4px 3px;
        }

        /* FOOTER */
        .ftr {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 6px 14px;
            border-top: 2px solid var(--ink);
            background: var(--stripe);
        }

        .ftr span {
            font-size: 7px;
            color: var(--sub);
            letter-spacing: 0.05em;
        }

        .ftr .ftr-ref {
            font-family: 'Libre Franklin', sans-serif;
            font-size: 8px;
            color: var(--accent2);
            font-weight: 600;
        }

        /* REMARKS SECTION */
        .remarks {
            padding: 6px 14px;
            border-top: 1px solid var(--border);
        }

        .remarks textarea {
            width: 100%;
            min-height: 40px;
            border: 1px solid var(--border);
            padding: 4px 6px;
            font-family: 'Libre Franklin', sans-serif;
            font-size: 9px;
            resize: none;
        }

        /* PRINT STYLES */
        @media print {
            @page {
                size: letter;
                margin: 10mm 10mm 15mm 10mm;
            }

            body {
                background: white;
                padding: 0;
            }

            .controls,
            .verify-bar {
                display: none !important;
            }

            .ticket {
                box-shadow: none;
                border: none;
                width: 100%;
            }

            table {
                page-break-inside: auto;
            }

            tr {
                page-break-inside: avoid;
            }

            .sec {
                page-break-inside: avoid;
                page-break-after: avoid;
            }

            .tbl-wrap {
                page-break-inside: auto;
            }

            .sigs,
            .approval-box,
            .remarks {
                page-break-inside: avoid;
            }

            .ftr {
                page-break-inside: avoid;
                page-break-before: avoid;
            }

            .ftr img {
                width: 38px !important;
                height: 38px !important;
            }

            th,
            td {
                border-color: #000 !important;
            }

            thead {
                display: table-header-group;
            }

            .sig-input,
            input,
            textarea {
                color: #000 !important;
            }

            .sig-title {
                color: #555 !important;
            }
        }

        .tbl-wrap {
            border: 1px solid var(--border);
        }

        /* Vehicle/Destination Info Box */
        .info-box {
            background: var(--stripe);
            border: 1px solid var(--border);
            margin: 10px 14px;
            padding: 8px 12px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
        }

        .info-item {
            text-align: center;
        }

        .info-item .lbl {
            font-size: 6px;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: var(--label);
            display: block;
            margin-bottom: 2px;
        }

        .info-item .val {
            font-size: 11px;
            font-weight: 700;
            color: var(--ink);
            border-bottom: 1px solid var(--border);
            padding-bottom: 2px;
        }
    </style>
</head>

<body>

    <div class="controls" style="flex-wrap:wrap; justify-content:center; align-items:center;">
        <a href="<?= APP_URL ?>/" class="btn btn-reset" style="text-decoration:none; display:inline-flex; align-items:center; gap:6px;"><span>🏠</span> Home</a>
        <a href="<?= APP_URL ?>/?page=my-trip-tickets" class="btn btn-reset" style="text-decoration:none; display:inline-flex; align-items:center; gap:6px;">← Back to My Trip Tickets</a>
        <a href="<?= APP_URL ?>/?page=my-trip-tickets&action=generate-summary" class="btn btn-reset" style="text-decoration:none; display:inline-flex; align-items:center; gap:6px;">↩ Generate</a>
        <button class="btn btn-print" onclick="window.print()">🖨 Print / Save PDF</button>
        <button class="btn btn-reset" onclick="resetForm()">↺ Reset Form</button>
    </div>
    <?php if ($__verifyUrl): ?>
    <div class="verify-bar" style="text-align:center; font-size:10px; color:#334155; background:#f1f5f9; border:1px solid #cbd5e1; border-radius:6px; padding:8px 10px; margin-bottom:10px; max-width:210mm;">
        <strong>Verify:</strong> <span style="word-break:break-all; font-family:monospace; font-size:9px;"><?= e($__verifyUrl) ?></span>
    </div>
    <?php endif; ?>

    <div class="ticket">

        <!-- COAT OF ARMS -->
        <div class="emblem">
            <img src="https://www.gov.ph/wp-content/uploads/2021/03/Coat-of-Arms-1-e1616650046498.png" alt="Republic of the Philippines Coat of Arms" onerror="this.style.display='none'">
            <div class="emblem-text">Republic of the Philippines</div>
        </div>

        <!-- HEADER -->
        <div class="hdr">
            <div class="hdr-title">Travel Order</div>
            <div class="hdr-sub">Department of Information and Communications Technology</div>
            <div class="hdr-agency">Regional Office No. II – Cagayan Valley</div>
        </div>

        <!-- TO NUMBER -->
        <div class="to-badge">
            <div class="to-number">
                <span>TO No.:</span> <?= e($tripTicketNumber) ?>
            </div>
        </div>

        <!-- SECTION I: DRIVER/OFFICER INFORMATION -->
        <div class="sec">
            <span class="sec-num">I</span>
            Driver / Officer Information
        </div>
        <div class="irow">
            <div class="if f3">
                <span class="lbl">Name of Driver / Officer</span>
                <input type="text" id="driverName" value="<?= e($generatorName) ?>" placeholder="Full name">
            </div>
            <div class="if f2">
                <span class="lbl">Position</span>
                <input type="text" id="position" placeholder="Position/Title">
            </div>
            <div class="if f2">
                <span class="lbl">License No. (if driver)</span>
                <input type="text" id="licenseNo" placeholder="License Number">
            </div>
            <div class="if f2">
                <span class="lbl">Contact Number</span>
                <input type="text" id="contactNo" placeholder="Contact No.">
            </div>
        </div>

        <!-- SECTION II: VEHICLE INFORMATION -->
        <div class="sec">
            <span class="sec-num">II</span>
            Vehicle Information
        </div>
        <div class="info-box">
            <div class="info-grid">
                <div class="info-item">
                    <span class="lbl">Plate Number</span>
                    <div class="val" id="plateDisplay"><?= e($vInfo->plate_number ?? '') ?></div>
                </div>
                <div class="info-item">
                    <span class="lbl">Make / Model</span>
                    <div class="val"><?= e(($vInfo->make ?? '') . ' ' . ($vInfo->model ?? '')) ?></div>
                </div>
                <div class="info-item">
                    <span class="lbl">Fuel Type</span>
                    <div class="val"><?= ucfirst(e($vInfo->fuel_type ?? 'Diesel')) ?></div>
                </div>
                <div class="info-item">
                    <span class="lbl">Color</span>
                    <div class="val"><?= ucfirst(e($vInfo->color ?? '')) ?></div>
                </div>
            </div>
        </div>

        <!-- SECTION III: TRIP ITINERARY -->
        <div class="sec">
            <span class="sec-num">III</span>
            Trip Itinerary
        </div>
        <div class="tbl-wrap">
            <table class="tbl-itinerary">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Departure<br>Time</th>
                        <th>Arrival<br>Time</th>
                        <th>Origin</th>
                        <th>Destination</th>
                        <th>Purpose</th>
                        <th>Approved By</th>
                    </tr>
                </thead>
                <tbody id="itineraryBody">
                    <?php
                    $rowCount = 0;
                    foreach ($trips as $i => $t):
                        $rowCount++;
                    ?>
                        <tr>
                            <td><input type="date" value="<?= date('Y-m-d', strtotime($t->start_date)) ?>"></td>
                            <td><input type="time" value="<?= date('H:i', strtotime($t->start_date)) ?>"></td>
                            <td><input type="time" value="<?= date('H:i', strtotime($t->end_date)) ?>"></td>
                            <td><input class="left" type="text" value="Tuguegarao City" placeholder="Origin"></td>
                            <td><input class="left" type="text" value="<?= e($t->destination) ?>" placeholder="Destination"></td>
                            <td><textarea class="left" rows="2" placeholder="Purpose" maxlength="500"><?= e($t->purpose) ?></textarea></td>
                            <td><input type="text" placeholder="Name"></td>
                        </tr>
                    <?php endforeach; ?>

                    <?php
                    $remaining = 6 - $rowCount;
                    for ($j = 0; $j < max(0, $remaining); $j++):
                    ?>
                        <tr>
                            <td><input type="date"></td>
                            <td><input type="time"></td>
                            <td><input type="time"></td>
                            <td><input class="left" type="text" placeholder="Origin"></td>
                            <td><input class="left" type="text" placeholder="Destination"></td>
                            <td><textarea class="left" rows="2" placeholder="Purpose of trip" maxlength="500"></textarea></td>
                            <td><input type="text" placeholder="Approved By"></td>
                        </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>

        <!-- SECTION IV: PASSENGERS -->
        <div class="sec">
            <span class="sec-num">IV</span>
            Passengers / Authorized Travelers
        </div>
        <div class="tbl-wrap">
            <table class="tbl-passengers">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Name</th>
                        <th>Position / Office</th>
                        <th>Signature</th>
                        <th>Time In/Out</th>
                    </tr>
                </thead>
                <tbody id="passengerBody">
                    <?php
                    $pCount = 0;
                    foreach ($trips as $t):
                        foreach ($t->all_people as $person):
                            $pCount++;
                    ?>
                            <tr>
                                <td><?= $pCount ?></td>
                                <td><input class="left" type="text" value="<?= e($person['name']) ?><?php if ($person['role'] === 'Driver'): ?> (Driver)<?php endif; ?>"></td>
                                <td><input class="left" type="text" placeholder="Position/Office"></td>
                                <td><input type="text"></td>
                                <td><input type="text" placeholder="In/Out"></td>
                            </tr>
                    <?php
                        endforeach;
                    endforeach;

                    $pRemaining = 8 - $pCount;
                    for ($j = 0; $j < max(0, $pRemaining); $j++):
                        $pCount++;
                    ?>
                        <tr>
                            <td><?= $pCount ?></td>
                            <td><input class="left" type="text" placeholder="Name"></td>
                            <td><input class="left" type="text" placeholder="Position/Office"></td>
                            <td><input type="text"></td>
                            <td><input type="text" placeholder="In/Out"></td>
                        </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>

        <!-- SECTION V: ODOMETER & FUEL -->
        <div class="sec">
            <span class="sec-num">V</span>
            Odometer Reading & Fuel Consumption
        </div>
        <div class="irow">
            <div class="if">
                <span class="lbl">Odometer Start (km)</span>
                <input type="text" id="odoStart" placeholder="—" value="<?= !empty($trips) && $trips[0]->start_mileage ? number_format($trips[0]->start_mileage) : '' ?>">
            </div>
            <div class="if">
                <span class="lbl">Odometer End (km)</span>
                <input type="text" id="odoEnd" placeholder="—" value="<?= !empty($trips) && end($trips)->end_mileage ? number_format(end($trips)->end_mileage) : '' ?>">
            </div>
            <div class="if">
                <span class="lbl">Distance Traveled (km)</span>
                <input type="text" id="distTraveled" placeholder="—" value="<?= $totalDist > 0 ? number_format($totalDist) : '' ?>">
            </div>
            <div class="if">
                <span class="lbl">Fuel Consumed (L)</span>
                <input type="text" id="fuelConsumed" placeholder="—" value="<?= $totalFuel > 0 ? number_format($totalFuel, 2) : '' ?>">
            </div>
            <div class="if">
                <span class="lbl">Fuel Cost (PHP)</span>
                <input type="text" id="fuelCost" placeholder="—" value="<?= $totalCost > 0 ? number_format($totalCost, 2) : '' ?>">
            </div>
        </div>

        <!-- SECTION VI: REMARKS -->
        <div class="sec">
            <span class="sec-num">VI</span>
            Remarks / Observations
        </div>
        <div class="remarks">
            <textarea id="remarks" placeholder="Any special remarks, incidents, or observations during the trip..." maxlength="500"></textarea>
        </div>

        <!-- SECTION VII: SIGNATURES -->
        <div class="sec">
            <span class="sec-num">VII</span>
            Certification & Signatures
        </div>

        <!-- Certification Statement -->
        <div class="irow" style="background: #f8fafc; padding: 8px 14px;">
            <div style="font-size: 9px; font-style: italic; color: var(--body); line-height: 1.5;">
                "I certify that the above information is true and correct to the best of my knowledge. I acknowledge responsibility for the vehicle and passengers during the duration of this travel."
            </div>
        </div>

        <!-- Signature Blocks -->
        <div class="sigs">
            <div class="sig">
                <div class="sig-role">Prepared & Certified by</div>
                <div style="height: 22px;"></div>
                <div class="sig-line"></div>
                <input type="text" class="sig-input" id="sigDriver" value="<?= e($generatorName) ?>" placeholder="Driver/Officer Name">
                <div class="sig-title">Driver / Field Officer</div>
            </div>
            <div class="sig">
                <div class="sig-role">Verified by</div>
                <div style="height: 22px;"></div>
                <div class="sig-line"></div>
                <select class="sig-input" style="background: transparent; text-transform: uppercase;">
                    <option value="">Select Motorpool...</option>
                    <?php foreach ($drivers as $d): ?>
                        <option value="<?= e($d->name) ?>"><?= strtoupper(e($d->name)) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="sig-title">Motorpool Unit Representative</div>
            </div>
            <div class="sig">
                <div class="sig-role">Approved by</div>
                <div style="height: 22px;"></div>
                <div class="sig-line"></div>
                <div class="sig-name">MINA FLOR T. VILLAFUERTE</div>
                <div class="sig-title">Admin and Finance Division Chief</div>
            </div>
        </div>

        <!-- FOOTER WITH QR -->
        <div class="ftr" style="display:flex; justify-content:space-between; align-items:flex-end; gap:10px;">
            <div style="flex:1;">
                <div>Department of Information and Communications Technology — Region II</div>
                <div class="ftr-ref" style="font-family:monospace; color:#003580;">Ref: TO-<?= e($tripTicketNumber) ?></div>
                <div>Date Range: <?= date('M j, Y', strtotime($dateFrom)) ?> – <?= date('M j, Y', strtotime($dateTo)) ?></div>
            </div>
            <?php if ($__qrHtml): ?>
            <div style="text-align:center; background:#fff; border:1px solid #bbb; padding:4px 6px;">
                <?= $__qrHtml ?>
                <div style="font-size:6px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:#111; margin-top:3px;">Scan to Verify</div>
                <div style="font-size:5.5px; color:#555; font-family:monospace;"><?= e($tripTicketNumber) ?></div>
            </div>
            <?php endif; ?>
        </div>

    </div><!-- /ticket -->

    <script>
        function resetForm() {
            if (!confirm('Reset all form entries?')) return;
            const keepIds = ['driverName', 'plateDisplay', 'odoStart', 'odoEnd', 'distTraveled', 'fuelConsumed', 'fuelCost', 'sigDriver'];
            document.querySelectorAll('input:not([type="hidden"]), textarea').forEach(el => {
                if (!keepIds.includes(el.id) && el.value && !el.readOnly) {
                    el.value = '';
                }
            });
        }

        window.addEventListener('load', function() {
            // Auto-calculate distance if both odometer values present
            const odoStart = document.getElementById('odoStart');
            const odoEnd = document.getElementById('odoEnd');
            const distTraveled = document.getElementById('distTraveled');

            function calcDistance() {
                const start = parseFloat(odoStart.value.replace(/,/g, '')) || 0;
                const end = parseFloat(odoEnd.value.replace(/,/g, '')) || 0;
                if (end > start) {
                    distTraveled.value = end - start;
                }
            }

            odoStart.addEventListener('input', calcDistance);
            odoEnd.addEventListener('input', calcDistance);
        });
    </script>
</body>

</html>
