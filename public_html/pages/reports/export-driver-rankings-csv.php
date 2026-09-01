<?php
/**
 * LOKA - Export Driver Rankings CSV (anonymous aggregates only)
 */

requireReportsAccess();

$from = get('from', date('Y-m-01'));
$to = get('to', date('Y-m-t'));
$minEval = max(1, (int) get('min_eval', 2));
$fromSql = $from ? date('Y-m-d 00:00:00', strtotime($from)) : null;
$toSql = $to ? date('Y-m-d 23:59:59', strtotime($to)) : null;
$isSelfScoped = isSelfScopedDriverReporter();

$where = "de.submitted_at IS NOT NULL";
$params = [];
if ($fromSql) { $where .= " AND r.start_datetime >= ?"; $params[] = $fromSql; }
if ($toSql) { $where .= " AND r.start_datetime <= ?"; $params[] = $toSql; }
if ($isSelfScoped) {
    $driverId = currentDriverId();
    if ($driverId) { $where .= " AND de.driver_id = ?"; $params[] = $driverId; }
}

$rows = db()->fetchAll(
    "SELECT de.driver_id, u.name AS driver_name,
            COUNT(*) AS eval_count,
            AVG(de.overall) AS avg_overall,
            AVG(de.rating_punctuality) AS avg_punctuality,
            AVG(de.rating_safety) AS avg_safety,
            AVG(de.rating_courtesy) AS avg_courtesy,
            AVG(de.rating_driving) AS avg_driving,
            AVG(de.rating_vehicle) AS avg_vehicle
     FROM driver_evaluations de
     JOIN requests r ON de.request_id = r.id AND r.deleted_at IS NULL
     JOIN drivers d ON de.driver_id = d.id AND d.deleted_at IS NULL
     JOIN users u ON d.user_id = u.id
     WHERE {$where}
     GROUP BY de.driver_id
     HAVING eval_count >= ?
     ORDER BY avg_overall DESC",
    array_merge($params, [$minEval])
);

$filename = 'driver-rankings_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');
// BOM for Excel
fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
fputcsv($out, ['Rank','Driver','Evaluations','Avg Overall','Avg Punctuality','Avg Safety','Avg Courtesy','Avg Driving','Avg Vehicle','Period From','Period To']);
$rank = 1;
foreach ($rows as $r) {
    fputcsv($out, [
        $rank++,
        $r->driver_name,
        $r->eval_count,
        number_format((float)$r->avg_overall, 2),
        number_format((float)$r->avg_punctuality, 2),
        number_format((float)$r->avg_safety, 2),
        number_format((float)$r->avg_courtesy, 2),
        number_format((float)$r->avg_driving, 2),
        number_format((float)$r->avg_vehicle, 2),
        $from,
        $to
    ]);
}
fclose($out);
exit;
