<?php
/**
 * LOKA - Export Driver Trip Extract PDF (matches the on-screen extract)
 * Driver summary table + one row per assigned trip, honouring the same GET
 * column flags as the screen. Anonymous — no rater identity. Landscape TCPDF,
 * DICT style.
 */

require_once INCLUDES_PATH . '/eval_report.php';
requireEvalReportAccess();
require_once BASE_PATH . '/vendor/tecnickcom/tcpdf/tcpdf.php';

$f = evalReportParseFilters(true);
$cols = evalTripExtractColumns();
$periodLabel = evalReportPeriodLabel($f);

$rankData = evalReportRankings($f);
$trips = evalTripExtractTrips($f);
$remarksByReq = evalTripExtractRemarks($f);
$summary = evalTripExtractSummary($trips, $rankData);

$rankByDriver = [];
foreach (array_merge($rankData['ranked'], $rankData['unranked']) as $r) {
    $rankByDriver[(int) $r->driver_id] = $r;
}

auditLog('data_export', 'driver_evaluations', null, null, [
    'format' => 'pdf',
    'rows' => count($trips),
    'filters' => [
        'from' => $f['from'],
        'to' => $f['to'],
        'driver_id' => $f['driver_id'],
        'vehicle_id' => $f['vehicle_id'],
        'request_id' => $f['request_id'],
        'columns' => $cols,
    ],
]);

$filename = 'driver-trip-extract-' . preg_replace('/[^a-zA-Z0-9]+/', '-', strtolower($periodLabel));
$title = 'Driver Trip Extract';

$pdf = new TCPDF('L', PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
$pdf->SetCreator('LOKA Fleet Management');
$pdf->SetAuthor(currentUser()->name ?? 'LOKA');
$pdf->SetTitle($title);
$pdf->SetHeaderData('', 0, 'DICT - Driver Trip Extract', 'Period: ' . $periodLabel . ' | Generated: ' . date('Y-m-d H:i:s'));
$pdf->setHeaderFont([PDF_FONT_NAME_MAIN, '', 10]);
$pdf->setFooterFont([PDF_FONT_NAME_DATA, '', 8]);
$pdf->SetMargins(12, 18, 12);
$pdf->SetAutoPageBreak(true, 14);
$pdf->AddPage();

$filterLines = ['Period: ' . $periodLabel];
if ($f['driver_id'] > 0) {
    $filterLines[] = 'Driver ID: ' . $f['driver_id'];
}
if ($f['vehicle_id'] > 0) {
    $filterLines[] = 'Vehicle ID: ' . $f['vehicle_id'];
}
if ($f['request_id'] > 0) {
    $filterLines[] = 'Trip No.: ' . $f['request_id'];
}
reportPdfWriteMeta($pdf, $title, $filterLines, count($trips), 2000);

$pdf->SetFont('helvetica', 'I', 8);
$pdf->SetTextColor(120, 60, 0);
$pdf->Cell(0, 4, 'Scope: every assigned driver/trip (approved + completed), including trips with no ratings yet. Comments are anonymous.', 0, 1);
$pdf->SetTextColor(0, 0, 0);
$pdf->Ln(2);

$fmtE = static fn(?float $v): string => $v !== null ? number_format($v, 2) : '-';

// ---------------------------------------------------------------------
// Driver summary
// ---------------------------------------------------------------------
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(0, 6, 'Driver Summary (' . count($summary) . ' drivers with assigned trips)', 0, 1);

if (empty($summary)) {
    $pdf->SetFont('helvetica', 'I', 9);
    $pdf->Cell(0, 6, 'No assigned trips in this period.', 0, 1);
} else {
    $sumCols = [['Driver', 70], ['Trips', 20], ['Evaluations', 26]];
    if ($cols['sum_score']) $sumCols[] = ['Rank score', 26];
    if ($cols['sum_raw']) $sumCols[] = ['Raw avg', 22];

    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetFillColor(13, 110, 253);
    $pdf->SetTextColor(255, 255, 255);
    foreach ($sumCols as [$label, $w]) {
        $pdf->Cell($w, 6, $label, 1, 0, 'C', true);
    }
    $pdf->Ln();

    $pdf->SetTextColor(0, 0, 0);
    $fill = false;
    foreach ($summary as $s) {
        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetFillColor(248, 248, 248);
        $cells = [$s->driver_name, (string) $s->trip_count, (string) $s->eval_count];
        if ($cols['sum_score']) $cells[] = $s->rank_score !== null ? number_format((float) $s->rank_score, 2) : '-';
        if ($cols['sum_raw']) $cells[] = $s->avg_overall !== null ? number_format((float) $s->avg_overall, 2) : '-';
        foreach ($cells as $i => $val) {
            $pdf->Cell($sumCols[$i][1], 6, $val, 1, 0, $i === 0 ? 'L' : 'C', true);
        }
        $pdf->Ln();
        $fill = !$fill;
    }
}
$pdf->Ln(3);

// ---------------------------------------------------------------------
// Trip rows
// ---------------------------------------------------------------------
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(0, 6, 'Trips (' . count($trips) . ')', 0, 1);

$defs = [
    'date' => ['Date', 22],
    'trip' => ['Trip #', 18],
    'driver' => ['Driver', 32],
    'plate' => ['Plate', 18],
    'dest' => ['Destination', 55],
    'invites' => ['Inv./Sub.', 18],
    'overall' => ['Overall', 15],
    'cats' => ['C / B / A / S', 46],
    'score' => ['Score', 15],
    'remarks' => ['Comments (anonymous)', 45],
];
$visible = array_keys(array_filter([
    'date' => $cols['col_date'], 'trip' => $cols['col_trip'], 'driver' => $cols['col_driver'],
    'plate' => $cols['col_plate'], 'dest' => $cols['col_dest'], 'invites' => $cols['col_invites'],
    'overall' => $cols['col_overall'], 'cats' => $cols['col_cats'], 'score' => $cols['col_score'],
    'remarks' => $cols['col_remarks'],
]));
$usable = 273.0;
$totalW = 0.0;
foreach ($visible as $k) { $totalW += $defs[$k][1]; }
$scale = $totalW > 0 ? $usable / $totalW : 1;

if (empty($trips)) {
    $pdf->SetFont('helvetica', 'I', 9);
    $pdf->Cell(0, 6, 'No assigned trips in this period.', 0, 1);
} elseif (empty($visible)) {
    $pdf->SetFont('helvetica', 'I', 9);
    $pdf->Cell(0, 6, 'No columns selected.', 0, 1);
} else {
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->SetFillColor(13, 110, 253);
    $pdf->SetTextColor(255, 255, 255);
    foreach ($visible as $k) {
        $pdf->Cell($defs[$k][1] * $scale, 6, $defs[$k][0], 1, 0, 'C', true);
    }
    $pdf->Ln();

    $pdf->SetTextColor(0, 0, 0);
    $lineH = 4;
    $fill = false;

    foreach ($trips as $t) {
        $rankRow = $rankByDriver[(int) $t->driver_id] ?? null;
        $tripRemarks = $remarksByReq[(int) $t->id] ?? [];

        $cells = [];
        foreach ($visible as $k) {
            $align = 'L';
            switch ($k) {
                case 'date': $val = date('M j, Y g:i A', strtotime($t->start_datetime)); $align = 'C'; break;
                case 'trip': $val = '#' . (int) $t->id . ' (' . ucfirst((string) $t->status) . ')'; break;
                case 'driver': $val = (string) ($t->driver_name ?: '-'); break;
                case 'plate': $val = (string) ($t->plate_number ?: '-'); $align = 'C'; break;
                case 'dest': $val = (string) ($t->destination ?: '-'); break;
                case 'invites': $val = (int) $t->submitted_cnt . ' / ' . (int) $t->total_invites; $align = 'C'; break;
                case 'overall': $val = $t->avg_overall !== null ? number_format((float) $t->avg_overall, 2) : '-'; $align = 'C'; break;
                case 'cats':
                    $val = $t->avg_overall !== null
                        ? $fmtE($t->avg_cleanliness !== null ? (float) $t->avg_cleanliness : null) . ' / '
                          . $fmtE($t->avg_behavior !== null ? (float) $t->avg_behavior : null) . ' / '
                          . $fmtE($t->avg_appearance !== null ? (float) $t->avg_appearance : null) . ' / '
                          . $fmtE($t->avg_safety !== null ? (float) $t->avg_safety : null)
                        : '-';
                    $align = 'C';
                    break;
                case 'score': $val = $rankRow !== null ? number_format((float) $rankRow->rank_score, 2) : '-'; $align = 'C'; break;
                case 'remarks':
                    $val = empty($tripRemarks)
                        ? '-'
                        : implode(' | ', array_map(static fn($q): string => '"' . $q . '" — Anon.', $tripRemarks));
                    break;
                default: $val = '-';
            }
            $cells[] = [$val, $defs[$k][1] * $scale, $align];
        }

        $rowMax = 1;
        foreach ($cells as [$val, $w]) {
            $n = $pdf->getNumLines((string) $val, $w);
            if ($n > $rowMax) $rowMax = $n;
        }
        $rowH = $rowMax * $lineH;

        $pdf->SetFont('helvetica', '', 7);
        $pdf->SetFillColor($fill ? 248 : 255, $fill ? 248 : 255, $fill ? 248 : 255);
        foreach ($cells as [$val, $w, $align]) {
            $pdf->MultiCell($w, $lineH, (string) $val, 1, $align, true, 0, '', '', true, 0, false, true, $rowH, 'M');
        }
        $pdf->Ln($rowH);
        $fill = !$fill;
    }

    if ($rankData['fleet_mean'] !== null) {
        $pdf->Ln(2);
        $pdf->SetFont('helvetica', 'I', 7);
        $pdf->MultiCell(0, 4, evalReportScoreFootnote($rankData, $f), 0, 'L', false, 1);
    }
}

$pdf->Ln(3);
$pdf->SetFont('helvetica', 'I', 7);
$pdf->Cell(0, 4, 'Drivers: ' . count($summary) . ' | Trips: ' . count($trips) . ' | LOKA Fleet Management', 0, 1, 'C');

$pdf->Output($filename . '.pdf', 'D');
exit;
