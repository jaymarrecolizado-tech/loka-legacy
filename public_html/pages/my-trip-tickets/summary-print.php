<?php
/**
 * LOKA - Print Vehicle Summary Trip Ticket
 */
if (!defined('BASE_PATH'))
    exit;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vehicle Trip Ticket – DICT Region II</title>
    <link
        href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Mono:wght@500&display=swap"
        rel="stylesheet">
    <style>
        :root {
            --ink: #111111;
            --dark: #222222;
            --body: #333333;
            --label: #444444;
            --sub: #666666;
            --border: #bbbbbb;
            --border2: #888888;
            --accent: #003580;
            --stripe: #f4f6f9;
            --white: #ffffff;
        }

        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'DM Sans', sans-serif;
            background: #d8d8d8;
            min-height: 100vh;
            padding: 20px 16px;
            display: flex;
            flex-direction: column;
            align-items: center;
            overflow-x: auto;
        }

        .controls {
            display: flex;
            gap: 10px;
            margin-bottom: 16px;
        }

        .btn {
            font-family: 'DM Sans', sans-serif;
            font-size: 13px;
            font-weight: 600;
            padding: 9px 26px;
            border-radius: 4px;
            cursor: pointer;
            border: 2px solid var(--ink);
            transition: all .15s;
        }

        .btn-print {
            background: var(--ink);
            color: #fff;
        }

        .btn-print:hover {
            background: var(--accent);
            border-color: var(--accent);
        }

        .btn-reset {
            background: #fff;
            color: var(--ink);
        }

        .btn-reset:hover {
            background: #eee;
        }

        /* TICKET — portrait A4 */
        .ticket {
            width: 210mm;
            max-width: 100%;
            background: #fff;
            box-shadow: 0 6px 40px rgba(0, 0, 0, .18);
            border: 1px solid #bbb;
            flex-shrink: 0;
        }

        /* HEADER */
        .hdr {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            align-items: center;
            padding: 8px 14px 6px;
            border-bottom: 2px solid var(--ink);
            gap: 10px;
        }

        .hdr-left {
            font-size: 7.5px;
            color: var(--body);
            line-height: 1.6;
        }

        .hdr-left strong {
            font-size: 8.5px;
            color: var(--ink);
            font-weight: 700;
            display: block;
        }

        .hdr-center {
            text-align: center;
        }

        .hdr-title {
            font-weight: 700;
            font-size: 17px;
            letter-spacing: .09em;
            color: var(--ink);
            text-transform: uppercase;
            line-height: 1;
        }

        .hdr-bar {
            width: 32px;
            height: 2px;
            background: var(--accent);
            margin: 4px auto 3px;
        }

        .hdr-sub {
            font-size: 7.5px;
            color: var(--sub);
            letter-spacing: .08em;
            text-transform: uppercase;
            font-weight: 500;
        }

        .hdr-right {
            text-align: right;
            font-size: 7.5px;
            color: var(--body);
            line-height: 1.6;
        }

        .hdr-right .lbl {
            font-size: 7px;
            color: var(--sub);
            letter-spacing: .1em;
            text-transform: uppercase;
            font-weight: 600;
            display: block;
        }

        .hdr-right .tno {
            font-family: 'DM Mono', monospace;
            font-size: 11px;
            font-weight: 500;
            color: var(--accent);
            display: block;
            margin-top: 1px;
        }

        .hdr-right .base {
            font-size: 9px;
            font-weight: 600;
            color: var(--ink);
        }

        /* SECTION LABEL */
        .sec {
            font-size: 7.5px;
            font-weight: 700;
            letter-spacing: .14em;
            text-transform: uppercase;
            color: var(--accent);
            padding: 3.5px 10px 3px;
            border-top: 1.5px solid var(--border2);
            border-bottom: 1px solid var(--border);
            background: #f8f9fb;
        }

        .sec em {
            font-weight: 400;
            font-style: italic;
            letter-spacing: 0;
            text-transform: none;
            font-size: 7.5px;
            color: var(--sub);
        }

        /* INFO ROWS */
        .irow {
            display: flex;
            border-bottom: 1px solid var(--border);
        }

        .if {
            flex: 1;
            padding: 4px 9px 5px;
            border-right: 1px solid var(--border);
            min-height: 34px;
        }

        .if:last-child {
            border-right: none;
        }

        .if.f2 {
            flex: 2;
        }

        .if.f3 {
            flex: 3;
        }

        .if.f4 {
            flex: 4;
        }

        .if.f5 {
            flex: 5;
        }

        .if>.lbl {
            display: block;
            font-size: 7px;
            font-weight: 700;
            letter-spacing: .1em;
            text-transform: uppercase;
            color: var(--label);
            margin-bottom: 2px;
        }

        .if input,
        .if textarea {
            width: 100%;
            border: none;
            outline: none;
            font-family: 'DM Sans', sans-serif;
            font-size: 11px;
            font-weight: 700;
            color: var(--ink);
            background: transparent;
            padding: 0;
            line-height: 1.3;
        }

        .if textarea {
            resize: none;
            font-size: 9px;
            font-weight: 400;
        }

        .if input::placeholder,
        .if textarea::placeholder {
            color: #bbb;
            font-weight: 400;
        }

        .date-pair {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .date-pair>span {
            font-size: 8px;
            color: var(--sub);
            white-space: nowrap;
            font-weight: 600;
        }

        .date-pair input {
            flex: 1;
        }

        /* TABLES */
        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            font-family: 'DM Sans', sans-serif;
            font-size: 6px;
            font-weight: 700;
            letter-spacing: .05em;
            text-transform: uppercase;
            color: var(--dark);
            text-align: center;
            vertical-align: middle;
            padding: 4px 2px;
            border: 1px solid var(--border2);
            border-bottom: 1.5px solid var(--ink);
            background: var(--white);
            white-space: nowrap;
            line-height: 1.2;
        }

        td {
            border: 1px solid var(--border);
            vertical-align: middle;
            padding: 0;
            background: var(--white);
        }

        td input {
            width: 100%;
            height: 100%;
            min-height: 22px;
            border: none;
            outline: none;
            font-family: 'DM Sans', sans-serif;
            font-size: 9px;
            font-weight: 700;
            color: var(--ink);
            background: transparent;
            padding: 2px 4px;
            text-align: center;
        }

        td input::placeholder {
            color: #ccc;
        }

        td input.left {
            text-align: left;
        }

        /* Signature column larger */
        .tbl-trip td:nth-child(9) input {
            min-height: 50px;
        }

        /* Text wrapping for specific columns */
        .tbl-trip col.c-dest,
        .tbl-trip col.c-proj,
        .tbl-trip col.c-user {
            min-width: 120px;
        }

        /* Destination, Purpose, and Driver/Passenger Name columns */
        .tbl-trip td:nth-child(6) textarea,
        .tbl-trip td:nth-child(7) textarea {
            white-space: normal !important;
            word-wrap: break-word !important;
            overflow-wrap: break-word !important;
            line-height: 1.4;
            min-height: 40px !important;
            height: 100% !important;
            width: 100% !important;
            padding: 2px 4px !important;
            text-align: left !important;
            resize: none;
            border: none;
            outline: none;
            font-family: 'DM Sans', sans-serif;
            font-size: 9px !important;
            font-weight: 700;
            color: var(--ink);
            background: transparent;
            box-sizing: border-box;
            display: block;
            min-height: 22px !important;
        }

        .tbl-trip td:nth-child(6) textarea::placeholder,
        .tbl-trip td:nth-child(7) textarea::placeholder {
            color: #ccc;
            font-weight: 400;
        }

        .tbl-trip textarea {
            overflow: hidden;
            resize: none;
        }

        @media print {
            .tbl-trip textarea {
                overflow: hidden !important;
                -webkit-appearance: none;
                appearance: none;
            }
        }

        /* Portrait print */
        @media print {
            @page {
                size: A4 portrait;
                margin: 10mm 10mm 20mm 10mm;
                counter-increment: page;

                /* Page numbers in margin (appears on every page) */
                @bottom-right {
                    content: "Page " counter(page);
                    font-size: 7px;
                    color: var(--sub);
                    letter-spacing: 0.07em;
                    text-transform: uppercase;
                    font-weight: 500;
                }
            }

            body {
                background: white;
                padding: 0;
            }

            .controls {
                display: none;
            }

            .ticket {
                box-shadow: none;
                border: none;
                width: 100%;
                margin-bottom: 25px;
            }

            /* Ensure all tables have clean borders when printing */
            table {
                page-break-inside: avoid;
            }

            /* Prevent breaking within rows - keep passengers together */
            tr {
                page-break-inside: avoid;
            }

            /* Prevent breaking sections */
            .sec,
            .tbl-wrap,
            .sigs,
            .summary {
                page-break-inside: avoid;
            }

            /* Allow page breaks before major sections if needed */
            .sec {
                page-break-before: auto;
            }

            th,
            td {
                border-color: #000 !important;
            }

            /* Header should repeat on each page */
            thead {
                display: table-header-group;
            }

            /* Footer - appears at end of document only */
            .ftr {
                position: relative;
                padding: 4px 12px;
                margin: 20px 0 10px 0;
                border-top: 2px solid #000;
                background: white;
            }

            /* Hide the footer page number element - using @page margin instead */
            .page-number {
                display: none !important;
            }

            input,
            textarea,
            select {
                color: #000 !important;
            }

            .sec {
                background: transparent !important;
            }

            /* Portrait-specific adjustments */
            .tbl-trip th,
            .tbl-trip td input,
            .tbl-trip td textarea {
                font-size: 8px !important;
                padding: 2px 4px !important;
            }

            .tbl-trip th {
                padding: 3px 2px !important;
            }

            .tbl-trip td {
                padding: 0 !important;
            }
        }

        td[rowspan] input {
            height: auto;
            min-height: 40px !important;
        }

        td[rowspan] {
            vertical-align: top;
        }

        .tbl-wrap {
            border: 1.5px solid var(--border2);
        }

        /* trip col widths - portrait */
        .tbl-trip col.c-date {
            width: 8%;
        }

        .tbl-trip col.c-time {
            width: 7%;
        }

        .tbl-trip col.c-odo {
            width: 8%;
        }

        .tbl-trip col.c-dest {
            width: 20%;
        }

        .tbl-trip col.c-proj {
            width: 20%;
        }

        .tbl-trip col.c-user {
            width: 18%;
        }

        .tbl-trip col.c-pass {
            width: 12%;
        }

        .tbl-trip col.c-sig {
            width: 7%;
        }

        .tbl-trip col.c-pass {
            width: 10%;
        }

        .tbl-trip col.c-guard {
            width: 5.5%;
        }

        /* fuel col widths */
        .tbl-fuel col.c-qty {
            width: 8%;
        }

        .tbl-fuel col.c-amt {
            width: 10%;
        }

        .tbl-fuel col.c-dt {
            width: 9%;
        }

        .tbl-fuel col.c-add {
            width: 35%;
        }

        .tbl-fuel col.c-rem {
            width: 38%;
        }

        tfoot td {
            font-size: 8px;
            font-weight: 600;
            color: var(--body);
            padding: 3px 6px;
            background: var(--white);
            border: 1px solid var(--border);
            border-top: 1.5px solid var(--border2);
        }

        tfoot td input {
            font-weight: 700;
            font-size: 11px;
            color: var(--accent);
            cursor: default;
            min-height: 18px;
        }

        tfoot .total-lbl {
            text-align: right;
            padding-right: 8px;
            font-size: 7px;
            letter-spacing: .1em;
            text-transform: uppercase;
            color: var(--sub);
        }

        /* SUMMARY */
        .summary {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            border-top: 1.5px solid var(--border2);
            border-bottom: 1.5px solid var(--border2);
        }

        .sum-c {
            padding: 5px 8px 6px;
            border-right: 1px solid var(--border);
            text-align: center;
        }

        .sum-c:last-child {
            border-right: none;
        }

        .sum-c>.lbl {
            display: block;
            font-size: 7px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: var(--label);
            margin-bottom: 3px;
            line-height: 1.35;
        }

        .sum-c input {
            width: 100%;
            border: none;
            border-bottom: 1px solid var(--border);
            outline: none;
            font-family: 'DM Mono', monospace;
            font-size: 12px;
            font-weight: 700;
            color: var(--ink);
            text-align: center;
            padding: 1px 0;
            background: transparent;
        }

        .sum-c input::placeholder {
            color: #ccc;
            font-size: 8px;
        }

        /* SIGNATORIES */
        .sigs {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr 1fr;
            border-top: 1.5px solid var(--border2);
        }

        .sig {
            padding: 7px 16px 10px;
            border-right: 1px solid var(--border);
            text-align: center;
        }

        .sig:last-child {
            border-right: none;
        }

        .sig-role {
            font-size: 7px;
            font-weight: 700;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: var(--label);
            margin-bottom: 16px;
        }

        .sig-line {
            border-top: 1px solid var(--ink);
            margin: 0 10px 4px;
        }

        .sig-name {
            font-size: 9px;
            font-weight: 700;
            color: var(--ink);
            letter-spacing: .02em;
        }

        .sig-title {
            font-size: 7.5px;
            color: var(--sub);
            margin-top: 1px;
            font-weight: 500;
        }

        .sig-input {
            display: block;
            margin: 0 auto 2px;
            width: 72%;
            border: none;
            border-bottom: 1px solid var(--ink);
            outline: none;
            background: transparent;
            font-family: 'DM Sans', sans-serif;
            font-size: 11px;
            font-weight: 700;
            color: var(--ink);
            text-align: center;
            padding: 1px 2px;
            text-transform: uppercase;
        }

        .sig-input::placeholder {
            color: #bbb;
        }

        .sig-select {
            width: 100%;
            border: none;
            background: transparent;
            font-family: 'DM Sans', sans-serif;
            font-size: 9.5px;
            font-weight: 700;
            color: var(--ink);
            text-align: center;
            outline: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            text-transform: uppercase;
        }

        /* FOOTER */
        .ftr {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 4px 12px;
            border-top: 2px solid var(--ink);
        }

        .ftr span {
            font-size: 7px;
            color: var(--sub);
            letter-spacing: .07em;
            text-transform: uppercase;
            font-weight: 500;
        }

        .ftr .ftr-tno {
            font-family: 'DM Mono', monospace;
            font-size: 9px;
            color: var(--accent);
            font-weight: 500;
        }

        /* Page numbering - visible on screen for preview */
        .page-number {
            display: inline;
            font-size: 7px;
            color: var(--sub);
            letter-spacing: .07em;
            text-transform: uppercase;
            font-weight: 500;
        }
    </style>
</head>

<body>

    <div class="controls">
        <button class="btn btn-print" onclick="window.print()">🖨 Print / Save PDF</button>
        <button class="btn btn-reset" onclick="resetForm()">↺ Clear Form</button>
    </div>

    <div class="ticket">

        <!-- HEADER -->
        <div class="hdr">
            <div class="hdr-left">
                <strong>Republic of the Philippines</strong>
                Department of Information and<br>
                Communications Technology<br>
                Regional Office No. II
            </div>
            <div class="hdr-center">
                <div class="hdr-title">Vehicle Trip Ticket</div>
                <div class="hdr-bar"></div>
                <div class="hdr-sub">Motorpool Unit &nbsp;·&nbsp; Admin and Finance Division</div>
            </div>
            <div class="hdr-right">
                <span class="lbl">Trip No.</span>
                <span class="tno" id="tripnoBadge">
                    <?= e($tripTicketNumber) ?>
                </span>
                <span class="lbl" style="margin-top:5px;">Location / Base</span>
                <!-- Default Base -->
                <span class="base">Tuguegarao City</span>
            </div>
        </div>

        <!-- VEHICLE INFORMATION -->
        <div class="sec">Vehicle Information</div>
        <div class="irow">
            <div class="if">
                <span class="lbl">Plate Number</span>
                <input type="text" id="plate" value="<?= e($vInfo->plate_number) ?>">
            </div>
            <div class="if f2">
                <span class="lbl">Make / Model</span>
                <input type="text" id="model" value="<?= e($vInfo->make . ' ' . $vInfo->model) ?>">
            </div>
            <div class="if">
                <span class="lbl">Fuel Type</span>
                <input type="text" id="fuel" value="<?= ucfirst(e($vInfo->fuel_type ?? 'Diesel')) ?>">
            </div>
            <div class="if f2">
                <span class="lbl">Location / Base</span>
                <input type="text" id="location" value="Tuguegarao City">
            </div>
            <div class="if f2">
                <span class="lbl">Driver Assigned</span>
                <input type="text" id="driver" value="<?= e($generatorName) ?>" placeholder="Full name of driver">
            </div>
            <div class="if f2">
                <span class="lbl">Date of Trip</span>
                <div class="date-pair">
                    <input type="date" id="dateFrom" value="<?= $dateFrom ?>">
                    <span>to</span>
                    <input type="date" id="dateTo" value="<?= $dateTo ?>">
                </div>
            </div>
            <div class="if">
                <span class="lbl">Date Prepared</span>
                <input type="date" id="datePrepared" value="<?= date('Y-m-d') ?>">
            </div>
        </div>



        <!-- TRIP DETAILS -->
        <div class="sec">Trip Details &nbsp;<em>(to be filled-up by driver assigned)</em></div>
        <div class="tbl-wrap">
            <table class="tbl-trip">
                <colgroup>
                    <col class="c-date">
                    <col class="c-time">
                    <col class="c-time">
                    <col class="c-odo">
                    <col class="c-odo">
                    <col class="c-dest">
                    <col class="c-proj">
                     <col class="c-user">
                     <col class="c-pass">
                     <col class="c-sig">
                </colgroup>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Departure<br>Time</th>
                        <th>Arrival<br>Time</th>
                        <th>Odometer<br>Departure</th>
                        <th>Odometer<br>Arrival</th>
                        <th>Destination</th>
                        <th>Purpose</th>
                         <th>Driver/Passenger Name</th>
                         <th>Signature</th>
                    </tr>
                </thead>
                <tbody id="tripBody">
                    <?php
                    $tripRowCount = 0;
                    foreach ($trips as $i => $t):
                        $peopleCount = count($t->all_people);
                        $tripRowCount += $peopleCount;

                        foreach ($t->all_people as $personIdx => $person):
                            $isFirstPerson = ($personIdx === 0);
                    ?>
                        <tr>
                            <?php if ($isFirstPerson): ?>
                                <td rowspan="<?= $peopleCount ?>"><input type="date" value="<?= date('Y-m-d', strtotime($t->start_date)) ?>"></td>
                                <td rowspan="<?= $peopleCount ?>"><input type="time" value="<?= date('H:i', strtotime($t->start_date)) ?>"></td>
                                <td rowspan="<?= $peopleCount ?>"><input type="time" value="<?= date('H:i', strtotime($t->end_date)) ?>"></td>
                                <td rowspan="<?= $peopleCount ?>"><input type="text" placeholder="km" value="<?= $t->start_mileage ?>"></td>
                                <td rowspan="<?= $peopleCount ?>"><input type="text" placeholder="km" value="<?= $t->end_mileage ?>"></td>
                                <td rowspan="<?= $peopleCount ?>"><textarea class="left" placeholder="Destination" rows="2"><?= e($t->destination) ?></textarea></td>
                                <td rowspan="<?= $peopleCount ?>"><textarea class="left" placeholder="Purpose" rows="2"><?= e($t->purpose) ?></textarea></td>
                            <?php endif; ?>
                            <td>
                                <input class="left" type="text" placeholder="Name" value="<?= e($person['name']) ?><?php if ($person['role'] === 'Driver'): ?> (Driver)<?php endif; ?>">
                                <input type="hidden" class="person-role" value="<?= e($person['role']) ?>">
                            </td>
                             <td><input type="text"></td>
                        </tr>
                    <?php
                        endforeach;
                    endforeach;

                    // Pad out remaining rows to ensure a minimum of 8
                    $remaining = 8 - $tripRowCount;
                    for ($j = 0; $j < max(0, $remaining); $j++):
                    ?>
                        <tr>
                            <td><input type="date"></td>
                            <td><input type="time"></td>
                            <td><input type="time"></td>
                            <td><input type="text" placeholder="km"></td>
                            <td><input type="text" placeholder="km"></td>
                            <td><textarea class="left" placeholder="Destination" rows="2"></textarea></td>
                            <td><textarea class="left" placeholder="Purpose" rows="2"></textarea></td>
                             <td><input class="left" type="text" placeholder="Name (Driver)"></td>
                            <td><input type="text"></td>
                        </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>

        <!-- FUEL REFILLING DATA -->
        <div class="sec">Fuel Refilling Data &nbsp;<em>(to be filled-up by driver)</em></div>
        <div class="tbl-wrap">
            <table class="tbl-fuel">
                <colgroup>
                    <col class="c-dt">
                    <col class="c-qty">
                    <col class="c-amt">
                    <col class="c-add">
                    <col class="c-rem">
                </colgroup>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Qty.<br>(Liters)</th>
                        <th>Amount<br>(PHP)</th>
                        <th>Additional Items
                            <span style="font-weight:500;text-transform:none;letter-spacing:0;font-size:7px;">(Oil, Oil Filter, Fuel Filter, etc.)</span>
                        </th>
                        <th>Remarks
                            <span style="font-weight:500;text-transform:none;letter-spacing:0;font-size:7px;">(please indicate GAS voucher number)</span>
                        </th>
                    </tr>
                </thead>
                <tbody id="fuelBody">
                    <?php foreach ($fuelEntries as $f): ?>
                        <tr>
                            <td><input type="date" value="<?= $f['date'] ?>"></td>
                            <td><input type="number" step="0.01" value="<?= $f['qty'] ?>" oninput="calcTotals()"></td>
                            <td><input type="number" step="0.01" value="<?= $f['amt'] ?>" oninput="calcTotals()"></td>
                            <td><input class="left" type="text" value="<?= e($f['items']) ?>"></td>
                            <td><input class="left" type="text" value="<?= e($f['remarks']) ?>"></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php
                    $fuelRem = 4 - count($fuelEntries); // Add 1 additional row
                    for ($j = 0; $j < max(0, $fuelRem); $j++):
                        ?>
                        <tr>
                            <td><input type="date"></td>
                            <td><input type="number" step="0.01" placeholder="0.00" oninput="calcTotals()"></td>
                            <td><input type="number" step="0.01" placeholder="0.00" oninput="calcTotals()"></td>
                            <td><input class="left" type="text" placeholder="e.g. Engine oil, oil filter, fuel filter..."></td>
                            <td><input class="left" type="text" placeholder="GAS Voucher No."></td>
                        </tr>
                    <?php endfor; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td class="total-lbl">Total</td>
                        <td><input type="text" id="fuelQtyTotal" placeholder="—" value="<?= $totalFuel > 0 ? number_format($totalFuel, 2) : '' ?>" readonly></td>
                        <td><input type="text" id="fuelAmtTotal" placeholder="—" value="<?= $totalCost > 0 ? number_format($totalCost, 2) : '' ?>" readonly></td>
                        <td></td>
                        <td style="padding:3px 10px;font-size:8px;color:var(--sub);font-style:italic;">Totals are auto-calculated from Qty and Amount entries above.</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- TRIP SUMMARY -->
        <div class="sec"></div>
        <div class="summary">
            <div class="sum-c">
                <span class="lbl">Balance(L) (Start of Trip)</span>
                <input type="text" id="balStart" placeholder="—">
            </div>
            <div class="sum-c">
                <span class="lbl">Total Fuel Loaded (L)</span>
                <input type="text" id="fuelLoaded" placeholder="—"
                    value="<?= $totalFuel > 0 ? number_format($totalFuel, 2) : '' ?>">
            </div>
            <div class="sum-c">
                <span class="lbl">Total Fuel Consumed (L)</span>
                <input type="text" id="fuelConsumed" placeholder="—"
                    value="">
            </div>
            <div class="sum-c">
                <span class="lbl">Total Distance Travelled (km)</span>
                <input type="text" id="distTotal" placeholder="—"
                    value="<?= $totalDist > 0 ? number_format($totalDist) : '' ?>">
            </div>
            <div class="sum-c">
                <span class="lbl">Balance(L) (End of Trip)</span>
                <input type="text" id="balEnd" placeholder="—">
            </div>
        </div>

        <!-- SIGNATORIES -->
        <div class="sec">Signatories</div>
        <div class="sigs">
            <div class="sig">
                <div class="sig-role">Attested By</div>
                <div style="height:20px;"></div>
                <div class="sig-line"></div>
                <select class="sig-select">
                    <option value="">Select Attestor...</option>
                    <?php foreach ($guards as $guard): ?>
                        <option value="<?= e($guard->name) ?>"><?= strtoupper(e($guard->name)) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="sig-title">Guard on Duty</div>
            </div>
            <div class="sig">
                <div class="sig-role">Prepared by</div>
                <div style="height:20px;"></div>
                <div class="sig-line"></div>
                <select class="sig-select">
                    <option value="">Select Driver...</option>
                    <?php foreach ($drivers as $driver): ?>
                        <option value="<?= e($driver->name) ?>" <?= $driver->name === $generatorName ? 'selected' : '' ?>><?= strtoupper(e($driver->name)) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="sig-title">User / Driver Assign</div>
            </div>
            <div class="sig">
                <div class="sig-role">Reviewed by</div>
                <div style="height:20px;"></div>
                <div class="sig-line"></div>
                <div class="sig-name">ENGR. RONALD S. BARIUAN</div>
                <div class="sig-title">Motorpool Unit</div>
            </div>
            <div class="sig">
                <div class="sig-role">Approved</div>
                <div style="height:20px;"></div>
                <div class="sig-line"></div>
                <div class="sig-name">MINA FLOR T. VILLAFUERTE</div>
                <div class="sig-title">Admin and Finance Division</div>
            </div>
        </div>

        <!-- FOOTER -->
        <div class="ftr" id="mainFooter">
            <span>Department of Information and Communications Technology — Region II</span>
            <span class="ftr-tno" id="footerTno">Trip No: <?= e($tripTicketNumber) ?></span>
            <span class="page-number" id="pageNumberDisplay">Page 1</span>
        </div>

    </div><!-- /ticket -->

    <script>
        function calcTotals() {
            let qty = 0, amt = 0;
            document.querySelectorAll('#fuelBody tr').forEach(r => {
                qty += parseFloat(r.cells[1].querySelector('input').value) || 0; // cells[1] is qty column
                amt += parseFloat(r.cells[2].querySelector('input').value) || 0; // cells[2] is amt column
            });
            document.getElementById('fuelQtyTotal').value = qty > 0 ? qty.toFixed(2) : '';
            document.getElementById('fuelAmtTotal').value = amt > 0 ? amt.toFixed(2) : '';
            document.getElementById('fuelLoaded').value = qty > 0 ? qty.toFixed(2) : '';
            document.getElementById('fuelConsumed').value = qty > 0 ? qty.toFixed(2) : '';
            document.getElementById('distTotal').value = '<?= $totalDist > 0 ? number_format($totalDist) : '' ?>'; // Keep as is for now
        }

        function syncTripNo(v) {
            const val = v || 'e2014;';
            document.getElementById('tripnoBadge').textContent = val;
            document.getElementById('footerTno').textContent = 'Trip No: ' + val;
        }

        function resetForm() {
            if (!confirm('Clear all entered data?')) return;
            const keep = ['plate', 'model', 'fuel', 'location', 'tripno', 'dateFrom', 'dateTo', 'datePrepared'];
            document.querySelectorAll('input, textarea').forEach(el => {
                if (!keep.includes(el.id)) el.value = '';
            });
            calcTotals();
        }

        // Page numbering for printing
        window.addEventListener('load', function() {
            calcTotals();
        });

        // Handle page numbering with CSS counter (most reliable method)
        window.addEventListener('beforeprint', function() {
            // CSS @bottom-right will handle page numbering automatically
            // The .page-number span in footer is hidden during print
        });
    </script>
</body>

</html>