<?php
/**
 * LOKA - Gas Voucher Report PDF export (no peso amounts)
 */

require_once INCLUDES_PATH . '/gas_voucher_report.php';
requireGasVoucherReportAccess();
require_once BASE_PATH . '/vendor/tecnickcom/tcpdf/tcpdf.php';

$f = gasVoucherReportParseFilters();
$kpis = gasVoucherReportKpis($f);
$analytics = gasVoucherReportAnalytics($f);
$rows = gasVoucherReportRows($f, 500);

auditLog('data_export', 'gas_vouchers', null, null, [
    'format' => 'pdf',
    'rows' => count($rows),
    'filters' => $f,
]);

$filename = 'gas-vouchers-report-' . $f['start_date'] . '_to_' . $f['end_date'];
$title = 'Gas Voucher Report';

$pdf = new TCPDF('L', PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
$pdf->SetCreator('LOKA Fleet Management');
$pdf->SetAuthor(currentUser()->name ?? 'LOKA');
$pdf->SetTitle($title);
$pdf->SetHeaderData(
    '',
    0,
    'DICT - Gas Voucher Report',
    'Period: ' . $f['start_date'] . ' to ' . $f['end_date']
        . ' | Date field: ' . $f['date_field']
        . ' | Generated: ' . date('Y-m-d H:i:s')
);
$pdf->setHeaderFont([PDF_FONT_NAME_MAIN, '', 10]);
$pdf->setFooterFont([PDF_FONT_NAME_DATA, '', 8]);
$pdf->SetMargins(12, 18, 12);
$pdf->SetAutoPageBreak(true, 14);
$pdf->AddPage();

$filterLines = [
    'Period: ' . $f['start_date'] . ' to ' . $f['end_date'],
    'Date field: ' . $f['date_field'],
];
if ($f['status'] !== '') {
    $filterLines[] = 'Status: ' . $f['status'];
}
if ($f['search'] !== '') {
    $filterLines[] = 'Search: ' . $f['search'];
}
reportPdfWriteMeta($pdf, $title, $filterLines, count($rows), 500);

$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(0, 7, 'Summary KPIs', 0, 1);
$pdf->SetFont('helvetica', '', 8);
$pdf->MultiCell(0, 5, sprintf(
    'Total: %d | Pending review: %d | Pending CAF: %d | Approved: %d | Approved unpaid: %d | Paid/processed: %d | Rejected/cancelled: %d',
    (int) $kpis->total,
    (int) $kpis->pending_review,
    (int) $kpis->pending_approval,
    (int) $kpis->approved,
    (int) $kpis->approved_unpaid_count,
    (int) $kpis->paid_count,
    (int) $kpis->rejected_cancelled
), 0, 'L', false, 1);
$pdf->Ln(1);

if (!empty($analytics['byFund'])) {
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->Cell(0, 6, 'Top fund sources (by voucher count)', 0, 1);
    $pdf->SetFont('helvetica', '', 8);
    $i = 0;
    foreach ($analytics['byFund'] as $fund) {
        if ($i++ >= 5) {
            break;
        }
        $pdf->Cell(0, 4, sprintf('%s — %d vouchers', $fund['label'], $fund['count']), 0, 1);
    }
    $pdf->Ln(2);
}

$columns = ['Voucher', 'Date', 'Requester', 'Dept', 'Plate', 'Fuel', 'Status', 'Payment'];
$colWidths = [30, 24, 36, 30, 24, 32, 28, 24];

$pdf->SetFont('helvetica', 'B', 7);
$pdf->SetFillColor(13, 110, 253);
$pdf->SetTextColor(255, 255, 255);
foreach ($columns as $i => $col) {
    $pdf->Cell($colWidths[$i], 6, $col, 1, 0, 'C', true);
}
$pdf->Ln();

$pdf->SetFillColor(248, 248, 248);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('helvetica', '', 7);
$fill = false;

$short = static function (?string $text, int $max): string {
    $text = (string) ($text ?? '-');
    return strlen($text) > $max ? substr($text, 0, $max) . '..' : $text;
};

foreach ($rows as $row) {
    $data = [
        $short($row->voucher_no, 16),
        $row->request_date ? date('Y-m-d', strtotime($row->request_date)) : '-',
        $short($row->requester_name ?? '-', 18),
        $short($row->department_name ?? '-', 16),
        $short($row->vehicle_plate ?? '-', 12),
        $short(($row->fuel_type ?? '-') . ' ' . ($row->quantity ?? ''), 14),
        $short(ucwords(str_replace('_', ' ', (string) $row->status)), 12),
        $short(ucfirst((string) ($row->payment_status ?? '-')), 10),
    ];
    foreach ($data as $i => $val) {
        $pdf->Cell($colWidths[$i], 5, $val, 1, 0, 'L', $fill);
    }
    $pdf->Ln();
    $fill = !$fill;
}

$pdf->Ln(3);
$pdf->SetFont('helvetica', 'I', 7);
$note = count($rows) >= 500 ? ' (table capped at 500 rows)' : '';
$pdf->Cell(0, 4, 'Records in table: ' . count($rows) . $note . ' | LOKA Fleet Management', 0, 1, 'C');

$pdf->Output($filename . '.pdf', 'D');
exit;
