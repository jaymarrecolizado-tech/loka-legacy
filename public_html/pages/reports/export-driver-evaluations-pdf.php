<?php
/**
 * LOKA - Driver Evaluation Report PDF (anonymous, DICT style)
 *
 * Page 1: KPIs, 4-category fleet analytics (filled-cell bars), full ranking
 *         (every driver with >=1 submitted evaluation — Min evaluations filter
 *         is intentionally NOT applied here).
 * Optional following pages: anonymous passenger comments grouped by driver
 *         (only when include_remarks=1).
 *
 * Confidentiality: passenger identities are never included.
 */

require_once INCLUDES_PATH . '/eval_report.php';
requireEvalReportAccess();
require_once BASE_PATH . '/vendor/tecnickcom/tcpdf/tcpdf.php';

// false = do not invent a current-month range. Rankings always passes From/To;
// Evaluations all-time (blank dates) must export all time, not this month.
$f = evalReportParseFilters(false);
$includeRemarks = get('include_remarks') === '1';
$periodLabel = evalReportPeriodLabel($f);

$kpis = evalReportKpis($f);
$cats = evalReportCategoryAverages($f);
$rankings = evalReportRankings($f, false); // do not apply Min evaluations
$remarks = $includeRemarks ? evalReportRemarks($f, 200, true) : [];

auditLog('data_export', 'driver_evaluations', null, null, [
    'format' => 'pdf',
    'rows' => count($rankings),
    'include_remarks' => $includeRemarks,
    'filters' => [
        'from' => $f['from'],
        'to' => $f['to'],
        'driver_id' => $f['driver_id'],
        'vehicle_id' => $f['vehicle_id'],
        'request_id' => $f['request_id'],
    ],
]);

$filename = 'driver-evaluation-report-' . preg_replace('/[^a-zA-Z0-9]+/', '-', strtolower($periodLabel));
$title = 'Driver Evaluation Report';

$pdf = new TCPDF('L', PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
$pdf->SetCreator('LOKA Fleet Management');
$pdf->SetAuthor(currentUser()->name ?? 'LOKA');
$pdf->SetTitle($title);
$pdf->SetHeaderData(
    '',
    0,
    'DICT - Driver Evaluation Report',
    'Period: ' . $periodLabel
        . ' | Generated: ' . date('Y-m-d H:i:s')
);
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
reportPdfWriteMeta($pdf, $title, $filterLines, count($rankings), 500);

$pdf->SetFont('helvetica', 'I', 8);
$pdf->SetTextColor(120, 60, 0);
$pdf->Cell(0, 4, 'Confidentiality: passenger identities are never included in this report. Comments are shown as "Anonymous passenger".', 0, 1);
$pdf->SetTextColor(0, 0, 0);
$pdf->Ln(2);

// ---------------------------------------------------------------------
// KPIs
// ---------------------------------------------------------------------
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(0, 6, 'Summary', 0, 1);
$pdf->SetFont('helvetica', '', 8);
$pdf->MultiCell(0, 5, sprintf(
    'Fleet overall average: %s | Submitted evaluations: %d | Invite response rate: %d%% (of %d invites) | Drivers ranked: %d',
    $kpis->fleet_avg !== null ? number_format((float) $kpis->fleet_avg, 2) . ' / 5' : 'n/a',
    $kpis->total_submitted,
    $kpis->response_rate,
    $kpis->total_invited,
    $kpis->drivers_ranked
), 0, 'L', false, 1);
$pdf->Ln(2);

// ---------------------------------------------------------------------
// Category analytics with filled-cell bars
// ---------------------------------------------------------------------
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(0, 6, 'Category Analytics (fleet averages, 1-5)', 0, 1);

$barMax = 80;
$categoryRows = [
    ['Cleanliness of the Vehicle', $cats->avg_cleanliness],
    ['Behavior of the Driver', $cats->avg_behavior],
    ['Appearance and Hygiene', $cats->avg_appearance],
    ['Road Safety and Driving Skills', $cats->avg_safety],
];

$pdf->SetFont('helvetica', '', 8);
foreach ($categoryRows as [$label, $avg]) {
    $pdf->Cell(58, 5, $label, 0, 0, 'L');
    $pdf->Cell(14, 5, $avg !== null ? number_format((float) $avg, 2) : 'n/a', 0, 0, 'R');
    $filled = $avg !== null ? (int) round($barMax * ((float) $avg / 5)) : 0;
    if ($filled > 0) {
        $pdf->SetFillColor(25, 135, 84);
        $pdf->Cell($filled, 5, '', 0, 0, 'L', true);
    }
    if ($filled < $barMax) {
        $pdf->SetFillColor(233, 236, 239);
        $pdf->Cell($barMax - $filled, 5, '', 0, 0, 'L', true);
    }
    $pdf->Ln(5.5);
}
$pdf->Ln(3);

// ---------------------------------------------------------------------
// Full ranking table
// ---------------------------------------------------------------------
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(0, 6, 'Driver Ranking (best to worst)', 0, 1);

if (empty($rankings)) {
    $pdf->SetFont('helvetica', 'I', 9);
    $pdf->Cell(0, 6, 'No submitted evaluations in this period.', 0, 1);
} else {
    $columns = ['Rank', 'Driver', 'Evals', 'Overall', 'Cleanliness', 'Behavior', 'Appearance', 'Safety'];
    $colWidths = [14, 80, 16, 20, 26, 26, 26, 26];

    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetFillColor(13, 110, 253);
    $pdf->SetTextColor(255, 255, 255);
    foreach ($columns as $i => $col) {
        $pdf->Cell($colWidths[$i], 6, $col, 1, 0, 'C', true);
    }
    $pdf->Ln();

    $pdf->SetTextColor(0, 0, 0);
    $fill = false;
    foreach ($rankings as $idx => $row) {
        $rank = $idx + 1;
        $top3 = $rank <= 3;

        if ($top3) {
            $pdf->SetFont('helvetica', 'B', 8);
            $pdf->SetFillColor(255, 243, 205);
        } else {
            $pdf->SetFont('helvetica', '', 8);
            $pdf->SetFillColor(248, 248, 248);
        }

        $cells = [
            (string) $rank,
            (string) $row->driver_name,
            (string) (int) $row->eval_count,
            number_format((float) $row->avg_overall, 2),
            $row->avg_cleanliness !== null ? number_format((float) $row->avg_cleanliness, 2) : '-',
            $row->avg_behavior !== null ? number_format((float) $row->avg_behavior, 2) : '-',
            $row->avg_appearance !== null ? number_format((float) $row->avg_appearance, 2) : '-',
            $row->avg_safety !== null ? number_format((float) $row->avg_safety, 2) : '-',
        ];
        foreach ($cells as $i => $val) {
            $pdf->Cell($colWidths[$i], 6, $val, 1, 0, $i === 1 ? 'L' : 'C', true);
        }
        $pdf->Ln();
        $fill = !$fill;
    }
}

// ---------------------------------------------------------------------
// Optional anonymous comments (grouped by driver)
// ---------------------------------------------------------------------
if ($includeRemarks) {
    $pdf->AddPage();
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 7, 'Anonymous Passenger Comments', 0, 1);
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->Cell(0, 4, 'Newest first, capped at 200. Passengers are identified only as "Anonymous passenger".', 0, 1);
    $pdf->Ln(2);

    if (empty($remarks)) {
        $pdf->SetFont('helvetica', 'I', 9);
        $pdf->Cell(0, 6, 'No comments in this period.', 0, 1);
    } else {
        $currentDriver = null;
        foreach ($remarks as $r) {
            if ($currentDriver !== $r->driver_name) {
                $currentDriver = $r->driver_name;
                $pdf->Ln(1);
                $pdf->SetFont('helvetica', 'B', 9);
                $pdf->SetFillColor(233, 236, 239);
                $pdf->Cell(0, 6, '  ' . $r->driver_name, 1, 1, 'L', true);
            }

            $pdf->SetFont('helvetica', '', 8);
            $pdf->MultiCell(0, 4.5, sprintf(
                'Trip #%d - %s | %s | Overall: %s / 5',
                (int) $r->request_id,
                $r->destination ?: '-',
                date('M j, Y', strtotime($r->start_datetime)),
                $r->overall !== null ? number_format((float) $r->overall, 2) : 'n/a'
            ), 0, 'L', false, 1);

            $pdf->SetFont('helvetica', 'I', 8);
            $pdf->MultiCell(0, 4.5, '"' . $r->remarks . '"', 0, 'L', false, 1);
            $pdf->SetFont('helvetica', '', 7.5);
            $pdf->SetTextColor(108, 117, 125);
            $pdf->Cell(0, 4, '- Anonymous passenger', 0, 1);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Ln(1.5);
        }
    }
}

$pdf->Ln(3);
$pdf->SetFont('helvetica', 'I', 7);
$pdf->Cell(0, 4, 'Drivers ranked: ' . count($rankings) . ' | Submitted evaluations: ' . $kpis->total_submitted . ' | LOKA Fleet Management', 0, 1, 'C');

$pdf->Output($filename . '.pdf', 'D');
exit;
