<?php
/**
 * LOKA - Export Driver Rankings CSV (anonymous aggregates only)
 * Same ranked set + same filter set as the on-screen ranking, including the
 * fair rank score (Bayesian shrinkage) and the formula footnote.
 */

require_once INCLUDES_PATH . '/eval_report.php';
requireEvalReportAccess();

$f = evalReportParseFilters(true);
$data = evalReportRankings($f);
$rows = $data['ranked'];

auditLog('data_export', 'driver_evaluations', null, null, [
    'format' => 'csv',
    'rows' => count($rows),
    'filters' => [
        'from' => $f['from'],
        'to' => $f['to'],
        'driver_id' => $f['driver_id'],
        'vehicle_id' => $f['vehicle_id'],
        'request_id' => $f['request_id'],
        'min_eval' => $f['min_eval'],
    ],
]);

$filename = 'driver-rankings_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');
// BOM for Excel
fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
fputcsv($out, ['# ' . evalReportScoreFootnote($data, $f)]);
fputcsv($out, ['# Period', $f['from'] . ' to ' . $f['to']]);
fputcsv($out, []);
fputcsv($out, ['Rank','Driver','Evaluations','Rank Score','Avg Overall (Raw)','Avg Cleanliness (Vehicle)','Avg Behavior (Driver)','Avg Appearance (Hygiene)','Avg Safety (Driving)','Period From','Period To']);
$rank = 1;
foreach ($rows as $r) {
    fputcsv($out, [
        $rank++,
        $r->driver_name,
        $r->eval_count,
        number_format((float)$r->rank_score, 2),
        $r->avg_overall !== null ? number_format((float)$r->avg_overall, 2) : '',
        $r->avg_cleanliness !== null ? number_format((float)$r->avg_cleanliness, 2) : '',
        $r->avg_behavior !== null ? number_format((float)$r->avg_behavior, 2) : '',
        $r->avg_appearance !== null ? number_format((float)$r->avg_appearance, 2) : '',
        $r->avg_safety !== null ? number_format((float)$r->avg_safety, 2) : '',
        $f['from'],
        $f['to']
    ]);
}
fclose($out);
exit;
