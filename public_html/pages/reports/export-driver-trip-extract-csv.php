<?php
/**
 * LOKA - Export Driver Trip Extract CSV (matches the on-screen extract)
 * Driver summary section + one row per assigned trip, honouring the same
 * GET column flags as the screen. Anonymous — no rater identity.
 */

require_once INCLUDES_PATH . '/eval_report.php';
requireEvalReportAccess();

$f = evalReportParseFilters(true);
$cols = evalTripExtractColumns();

$rankData = evalReportRankings($f);
$trips = evalTripExtractTrips($f);
$remarksByReq = evalTripExtractRemarks($f);
$summary = evalTripExtractSummary($trips, $rankData);

$rankByDriver = [];
foreach (array_merge($rankData['ranked'], $rankData['unranked']) as $r) {
    $rankByDriver[(int) $r->driver_id] = $r;
}

auditLog('data_export', 'driver_evaluations', null, null, [
    'format' => 'csv',
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

$fmtE = static fn(?float $v): string => $v !== null ? number_format($v, 2) : '';

$filename = 'driver-trip-extract_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');
fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
fputcsv($out, ['# Driver Trip Extract', $f['from'] . ' to ' . $f['to']]);
fputcsv($out, ['# Scope', 'Assigned drivers/trips, status approved/completed; includes trips with no ratings yet']);
if ($rankData['fleet_mean'] !== null) {
    fputcsv($out, ['# ' . evalReportScoreFootnote($rankData, $f)]);
}
fputcsv($out, []);

// ---- Driver summary ----
fputcsv($out, ['DRIVER SUMMARY']);
$sumHeader = ['Driver', 'Trips', 'Evaluations'];
if ($cols['sum_score']) $sumHeader[] = 'Rank Score';
if ($cols['sum_raw']) $sumHeader[] = 'Raw Avg Overall';
fputcsv($out, $sumHeader);
foreach ($summary as $s) {
    $row = [$s->driver_name, $s->trip_count, $s->eval_count];
    if ($cols['sum_score']) $row[] = $s->rank_score !== null ? number_format((float) $s->rank_score, 2) : '';
    if ($cols['sum_raw']) $row[] = $s->avg_overall !== null ? number_format((float) $s->avg_overall, 2) : '';
    fputcsv($out, $row);
}
fputcsv($out, []);

// ---- Trip rows ----
fputcsv($out, ['TRIPS']);
$header = [];
if ($cols['col_date']) $header[] = 'Date';
if ($cols['col_trip']) $header[] = 'Trip No.';
if ($cols['col_trip']) $header[] = 'Status';
if ($cols['col_driver']) $header[] = 'Driver';
if ($cols['col_plate']) $header[] = 'Plate';
if ($cols['col_dest']) $header[] = 'Destination';
if ($cols['col_invites']) $header[] = 'Invites';
if ($cols['col_invites']) $header[] = 'Submitted';
if ($cols['col_overall']) $header[] = 'Overall';
if ($cols['col_cats']) $header[] = 'Cleanliness';
if ($cols['col_cats']) $header[] = 'Behavior';
if ($cols['col_cats']) $header[] = 'Appearance';
if ($cols['col_cats']) $header[] = 'Safety';
if ($cols['col_score']) $header[] = 'Rank Score';
if ($cols['col_remarks']) $header[] = 'Comments (anonymous)';
fputcsv($out, $header);

foreach ($trips as $t) {
    $rankRow = $rankByDriver[(int) $t->driver_id] ?? null;
    $tripRemarks = $remarksByReq[(int) $t->id] ?? [];
    $row = [];
    if ($cols['col_date']) $row[] = date('Y-m-d H:i', strtotime($t->start_datetime));
    if ($cols['col_trip']) { $row[] = (int) $t->id; $row[] = (string) $t->status; }
    if ($cols['col_driver']) $row[] = $t->driver_name ?: '';
    if ($cols['col_plate']) $row[] = $t->plate_number ?: '';
    if ($cols['col_dest']) $row[] = $t->destination ?: '';
    if ($cols['col_invites']) { $row[] = (int) $t->total_invites; $row[] = (int) $t->submitted_cnt; }
    if ($cols['col_overall']) $row[] = $t->avg_overall !== null ? number_format((float) $t->avg_overall, 2) : '';
    if ($cols['col_cats']) {
        $row[] = $fmtE($t->avg_cleanliness !== null ? (float) $t->avg_cleanliness : null);
        $row[] = $fmtE($t->avg_behavior !== null ? (float) $t->avg_behavior : null);
        $row[] = $fmtE($t->avg_appearance !== null ? (float) $t->avg_appearance : null);
        $row[] = $fmtE($t->avg_safety !== null ? (float) $t->avg_safety : null);
    }
    if ($cols['col_score']) $row[] = $rankRow !== null ? number_format((float) $rankRow->rank_score, 2) : '';
    if ($cols['col_remarks']) $row[] = implode(' | ', array_map(static fn($q): string => '"' . $q . '"', $tripRemarks));
    fputcsv($out, $row);
}
fclose($out);
exit;
