<?php
/**
 * LOKA - Gas Voucher Report CSV export (no peso amounts)
 */

require_once INCLUDES_PATH . '/gas_voucher_report.php';
requireGasVoucherReportAccess();

$f = gasVoucherReportParseFilters();
$rows = gasVoucherReportRows($f, 10000);

auditLog('data_export', 'gas_vouchers', null, null, [
    'format' => 'csv',
    'rows' => count($rows),
    'filters' => $f,
]);

$filename = 'gas-vouchers-' . $f['start_date'] . '_to_' . $f['end_date'] . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
$rowCount = count($rows);
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
reportCsvWriteMeta($out, 'Gas Voucher Report', $filterLines, $rowCount, 10000);

fputcsv($out, [
    'Voucher No',
    'Request Date',
    'Created At',
    'Requester',
    'Requester Email',
    'Department',
    'Driver',
    'Vehicle Plate',
    'Fuel Type',
    'Quantity',
    'Unit',
    'Fund Source',
    'Gas Station',
    'Purpose',
    'Chargeable Against',
    'Status',
    'Payment Status',
    'Approved At',
    'Date Withdrawn',
]);

foreach ($rows as $row) {
    fputcsv($out, [
        $row->voucher_no,
        $row->request_date,
        $row->created_at,
        $row->requester_name ?? '',
        $row->requester_email ?? '',
        $row->department_name ?? '',
        $row->driver_name ?? '',
        $row->vehicle_plate ?? '',
        $row->fuel_type ?? '',
        $row->quantity ?? '',
        $row->unit ?? '',
        $row->fund_source ?? '',
        $row->gas_station ?? '',
        $row->purpose ?? '',
        $row->chargeable_against ?? '',
        $row->status,
        $row->payment_status ?? '',
        $row->approved_at ?? '',
        $row->date_withdrawn ?? '',
    ]);
}

fclose($out);
exit;
