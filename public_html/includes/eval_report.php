<?php
/**
 * Shared filters / queries / KPIs for the anonymous Driver Evaluation reports
 * (Evaluations dashboard, Driver Rankings, CSV + PDF exports).
 *
 * ANONYMITY RULE: never SELECT evaluator_user_id, guest_label, passenger
 * names/emails, and never offer a passenger/evaluator filter. Only aggregate
 * per-driver data and anonymous remarks leave this helper.
 */

require_once INCLUDES_PATH . '/report_helpers.php';

/** Bayesian prior strength m for the rank score (IMDb-style shrinkage). */
const EVAL_RANK_PRIOR_STRENGTH = 5;

/**
 * Access gate shared by all evaluation report surfaces.
 */
function requireEvalReportAccess(): void
{
    requireReportsAccess();
}

/**
 * Parse the shared GET filter set.
 *
 * $defaultCurrentMonth: Rankings + exports default From/To to the current
 * month; the Evaluations dashboard defaults to blank (all time).
 * Self-scoped (non-approver tagged drivers) are locked to their own driver id.
 *
 * @return array{from:string,to:string,from_sql:?string,to_sql:?string,
 *               driver_id:int,vehicle_id:int,request_id:int,min_eval:int,self_scoped:bool}
 */
function evalReportParseFilters(bool $defaultCurrentMonth): array
{
    $from = trim((string) get('from', ''));
    $to = trim((string) get('to', ''));

    if ($from !== '' && !strtotime($from)) {
        $from = '';
    }
    if ($to !== '' && !strtotime($to)) {
        $to = '';
    }
    if ($defaultCurrentMonth) {
        if ($from === '') {
            $from = date('Y-m-01');
        }
        if ($to === '') {
            $to = date('Y-m-t');
        }
    }

    $selfScoped = isSelfScopedDriverReporter();
    $driverId = (int) get('driver_id', 0);
    if ($driverId < 0) {
        $driverId = 0;
    }
    if ($selfScoped) {
        $driverId = (int) (currentDriverId() ?? 0);
    }

    $vehicleId = max(0, (int) get('vehicle_id', 0));
    $requestId = max(0, (int) get('request_id', 0));

    return [
        'from' => $from,
        'to' => $to,
        'from_sql' => $from !== '' ? date('Y-m-d 00:00:00', strtotime($from)) : null,
        'to_sql' => $to !== '' ? date('Y-m-d 23:59:59', strtotime($to)) : null,
        'driver_id' => $driverId,
        'vehicle_id' => $vehicleId,
        'request_id' => $requestId,
        'min_eval' => max(1, (int) get('min_eval', 2)),
        'self_scoped' => $selfScoped,
    ];
}

/**
 * WHERE fragment (aliases: de = driver_evaluations, r = requests) for
 * per-driver aggregates. Submitted evals only unless $includeUnsubmitted.
 *
 * @return array{0:string,1:list<int|string|null>}
 */
function evalReportWhere(array $f, bool $includeUnsubmitted = false): array
{
    $sql = 'r.deleted_at IS NULL';
    $params = [];
    if (!$includeUnsubmitted) {
        $sql .= ' AND de.submitted_at IS NOT NULL';
    } else {
        $sql .= ' AND de.id IS NOT NULL';
    }
    if (!empty($f['from_sql'])) {
        $sql .= ' AND r.start_datetime >= ?';
        $params[] = $f['from_sql'];
    }
    if (!empty($f['to_sql'])) {
        $sql .= ' AND r.start_datetime <= ?';
        $params[] = $f['to_sql'];
    }
    if ($f['driver_id'] > 0) {
        $sql .= ' AND de.driver_id = ?';
        $params[] = $f['driver_id'];
    } elseif ($f['self_scoped']) {
        $sql .= ' AND 1=0'; // tagged driver with no drivers.id — show nothing
    }
    if ($f['vehicle_id'] > 0) {
        $sql .= ' AND r.vehicle_id = ?';
        $params[] = $f['vehicle_id'];
    }
    if ($f['request_id'] > 0) {
        $sql .= ' AND de.request_id = ?';
        $params[] = $f['request_id'];
    }
    return [$sql, $params];
}

/**
 * WHERE fragment on the request level (r only) for trip/response-rate views.
 * Safe under LEFT JOIN driver_evaluations (filters never touch de columns).
 *
 * @return array{0:string,1:list<int|string|null>}
 */
function evalReportTripsWhere(array $f): array
{
    $sql = "r.status = 'completed' AND r.deleted_at IS NULL";
    $params = [];
    if (!empty($f['from_sql'])) {
        $sql .= ' AND r.start_datetime >= ?';
        $params[] = $f['from_sql'];
    }
    if (!empty($f['to_sql'])) {
        $sql .= ' AND r.start_datetime <= ?';
        $params[] = $f['to_sql'];
    }
    if ($f['driver_id'] > 0) {
        $sql .= ' AND r.driver_id = ?';
        $params[] = $f['driver_id'];
    } elseif ($f['self_scoped']) {
        $sql .= ' AND 1=0';
    }
    if ($f['vehicle_id'] > 0) {
        $sql .= ' AND r.vehicle_id = ?';
        $params[] = $f['vehicle_id'];
    }
    if ($f['request_id'] > 0) {
        $sql .= ' AND r.id = ?';
        $params[] = $f['request_id'];
    }
    return [$sql, $params];
}

/**
 * Per-driver ranking rows using the same 4 categories as the submit form,
 * plus a fair rank score (IMDb / Bayesian shrinkage toward the fleet mean):
 *
 *   score = (v / (v + m)) * R + (m / (v + m)) * C
 *
 * R = driver raw overall average, v = evaluation count, C = fleet mean for
 * the SAME filters/period, m = EVAL_RANK_PRIOR_STRENGTH. Rank order is by
 * score, more evals as tie-break; drivers below the Min evaluations
 * threshold are returned as `unranked` (no rank number).
 *
 * @return array{ranked:list<object>,unranked:list<object>,fleet_mean:?float}
 */
function evalReportRankings(array $f): array
{
    [$where, $params] = evalReportWhere($f);
    $rows = db()->fetchAll(
        "SELECT de.driver_id, u.name AS driver_name,
            COUNT(*) AS eval_count,
            AVG(de.overall) AS avg_overall,
            AVG(de.rating_cleanliness) AS avg_cleanliness,
            AVG(de.rating_behavior) AS avg_behavior,
            AVG(de.rating_appearance) AS avg_appearance,
            AVG(de.rating_safety) AS avg_safety
     FROM driver_evaluations de
     JOIN requests r ON de.request_id = r.id
     JOIN drivers d ON de.driver_id = d.id AND d.deleted_at IS NULL
     JOIN users u ON d.user_id = u.id
     WHERE {$where}
     GROUP BY de.driver_id",
        $params
    );

    $fleetMean = db()->fetchColumn(
        "SELECT AVG(de.overall)
     FROM driver_evaluations de
     JOIN requests r ON de.request_id = r.id
     WHERE {$where}",
        $params
    );
    $fleetMean = $fleetMean !== null ? (float) $fleetMean : null;

    $m = EVAL_RANK_PRIOR_STRENGTH;
    $ranked = [];
    $unranked = [];
    foreach ($rows as $r) {
        $v = (int) $r->eval_count;
        $R = (float) $r->avg_overall;
        $r->rank_score = $fleetMean !== null
            ? ($v / ($v + $m)) * $R + ($m / ($v + $m)) * $fleetMean
            : $R;
        if ($v >= $f['min_eval']) {
            $ranked[] = $r;
        } else {
            $unranked[] = $r;
        }
    }

    $byScore = static fn(object $a, object $b): int =>
        [$b->rank_score, $b->eval_count] <=> [$a->rank_score, $a->eval_count];
    usort($ranked, $byScore);
    usort($unranked, $byScore);

    return ['ranked' => $ranked, 'unranked' => $unranked, 'fleet_mean' => $fleetMean];
}

/**
 * Per-eval rows for the expandable driver breakdown (trip #, date,
 * destination, overall, 4 category scores, anonymous remark). Never selects
 * rater identity. Newest first within each driver.
 *
 * @return list<object>
 */
function evalReportDriverEvalRows(array $f, int $limit = 2000): array
{
    [$where, $params] = evalReportWhere($f);
    $params[] = max(1, $limit);

    return db()->fetchAll(
        "SELECT de.driver_id, de.request_id, de.overall,
                de.rating_cleanliness, de.rating_behavior,
                de.rating_appearance, de.rating_safety,
                de.remarks, de.submitted_at,
                r.destination, r.start_datetime
     FROM driver_evaluations de
     JOIN requests r ON de.request_id = r.id
     WHERE {$where}
     ORDER BY de.driver_id ASC, de.submitted_at DESC
     LIMIT ?",
        $params
    );
}

/**
 * Formula footnote for the fair ranking (rendered under the tables and
 * repeated in CSV/PDF exports).
 */
function evalReportScoreFootnote(array $data, array $f): string
{
    $c = $data['fleet_mean'] !== null ? number_format($data['fleet_mean'], 2) : 'n/a';
    return 'Rank score = (v/(v+' . EVAL_RANK_PRIOR_STRENGTH . '))×R + (' . EVAL_RANK_PRIOR_STRENGTH
        . '/(v+' . EVAL_RANK_PRIOR_STRENGTH . '))×C — R = raw average, v = evaluation count, C = fleet mean ('
        . $c . ') for the same period/filters, m = ' . EVAL_RANK_PRIOR_STRENGTH
        . '. Drivers with fewer than ' . (int) $f['min_eval'] . ' evaluations are listed separately and not ranked.';
}

/**
 * Shared score-breakdown table for Rankings + Evaluations: ranked table
 * (trophy positions, expandable per-eval rows) + "Not ranked" table for
 * drivers below the threshold + one formula footnote. Anonymous — no rater
 * identity is rendered.
 */
function evalReportRankTableHtml(array $data, array $evalRows, array $f): string
{
    $h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
    $fmt = static fn($v): string => $v !== null ? number_format((float) $v, 2) : '—';

    $byDriver = [];
    foreach ($evalRows as $e) {
        $byDriver[(int) $e->driver_id][] = $e;
    }

    $catCell = static function (?float $avg): string {
        $pct = $avg !== null ? (int) round(max(0.0, min(5.0, $avg)) / 5 * 100) : 0;
        return '<div class="er-cat"><div class="er-bar"><span style="width:' . $pct . '%"></span></div>'
            . '<small>' . ($avg !== null ? number_format($avg, 2) : '—') . '</small></div>';
    };

    $detailHtml = static function (int $driverId) use ($byDriver, $h, $fmt): string {
        if (empty($byDriver[$driverId])) {
            return '';
        }
        $inner = '';
        foreach ($byDriver[$driverId] as $e) {
            $inner .= '<div class="border rounded p-2 mb-2 bg-light small">'
                . '<div class="d-flex flex-wrap gap-2 justify-content-between">'
                . '<span><strong>Trip #' . (int) $e->request_id . '</strong> — ' . $h($e->destination ?: '—')
                . ' · ' . $h(date('M j, Y', strtotime($e->start_datetime))) . '</span>'
                . '<span><span class="badge bg-success">' . $fmt($e->overall) . '</span>'
                . ' <span class="text-muted">C ' . $fmt($e->rating_cleanliness)
                . ' · B ' . $fmt($e->rating_behavior)
                . ' · A ' . $fmt($e->rating_appearance)
                . ' · S ' . $fmt($e->rating_safety) . '</span></span>'
                . '</div>'
                . ($e->remarks !== null && trim((string) $e->remarks) !== ''
                    ? '<div class="mt-1"><em>"' . $h($e->remarks) . '"</em> — <span class="text-muted">Anonymous passenger</span></div>'
                    : '')
                . '</div>';
        }
        return '<tr class="er-detail-row"><td colspan="9" class="p-0 border-0"><div class="collapse er-detail" id="er-d' . $driverId . '">'
            . '<div class="p-2 pb-0">' . $inner . '</div></div></td></tr>';
    };

    $rowHtml = static function (object $r, ?int $rank) use ($h, $fmt, $catCell, $detailHtml): string {
        $rankCell = $rank === null
            ? '<span class="badge bg-light text-dark">—</span>'
            : ($rank === 1
                ? '<span class="badge bg-warning text-dark"><i class="bi bi-trophy-fill me-1"></i>1</span>'
                : ($rank === 2
                    ? '<span class="badge bg-secondary">2</span>'
                    : ($rank === 3
                        ? '<span class="badge text-white" style="background:#cd7f32;">3</span>'
                        : '<span class="badge bg-light text-dark">' . $rank . '</span>')));
        $did = (int) $r->driver_id;
        $evals = $byDriver[$did] ?? [];
        return '<tr>'
            . '<td>' . $rankCell . '</td>'
            . '<td><button class="btn btn-sm btn-link p-0 me-1 text-decoration-none" type="button"'
            . ' data-bs-toggle="collapse" data-bs-target="#er-d' . $did . '"'
            . ' aria-expanded="false" title="Show individual evaluations"><i class="bi bi-caret-down-fill"></i></button>'
            . '<strong>' . $h($r->driver_name) . '</strong>'
            . (!empty($evals) ? ' <small class="text-muted">(' . count($evals) . ')</small>' : '') . '</td>'
            . '<td class="text-center">' . (int) $r->eval_count . '</td>'
            . '<td class="text-center"><span class="badge bg-primary">' . $fmt($r->rank_score) . '</span></td>'
            . '<td class="text-center text-muted">' . $fmt($r->avg_overall) . '</td>'
            . '<td>' . $catCell($r->avg_cleanliness !== null ? (float) $r->avg_cleanliness : null) . '</td>'
            . '<td>' . $catCell($r->avg_behavior !== null ? (float) $r->avg_behavior : null) . '</td>'
            . '<td>' . $catCell($r->avg_appearance !== null ? (float) $r->avg_appearance : null) . '</td>'
            . '<td>' . $catCell($r->avg_safety !== null ? (float) $r->avg_safety : null) . '</td>'
            . '</tr>' . $detailHtml($did);
    };

    $head = '<tr><th style="width:52px;">Rank</th><th>Driver</th><th class="text-center">Evals</th>'
        . '<th class="text-center">Rank score</th><th class="text-center">Raw avg</th>'
        . '<th>Cleanliness</th><th>Behavior</th><th>Appearance</th><th>Safety</th></tr>';

    $html = '<style>'
        . '.er-cat{display:flex;align-items:center;gap:6px;}'
        . '.er-bar{flex:1;max-width:90px;height:6px;background:#e9ecef;border-radius:3px;overflow:hidden;}'
        . '.er-bar span{display:block;height:100%;background:#198754;}'
        . '.er-cat small{color:#6c757d;min-width:26px;}'
        . '.er-detail-row > td {background:#f8f9fa;}'
        . '</style>';

    if (empty($data['ranked']) && empty($data['unranked'])) {
        return $html . '<div class="card"><div class="card-body text-center py-5 text-muted">'
            . '<i class="bi bi-inbox fs-1"></i><p class="mt-2 mb-0">No submitted evaluations for this period.</p></div></div>';
    }

    $html .= '<div class="card mb-4"><div class="card-header d-flex justify-content-between align-items-center">'
        . '<h5 class="mb-0"><i class="bi bi-trophy me-2"></i>Rankings (' . count($data['ranked']) . ' drivers)</h5>'
        . '<small class="text-muted">Ranked by score — click a driver to expand individual evaluations</small></div>'
        . '<div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 align-middle">'
        . '<thead class="table-light">' . $head . '</thead><tbody>';
    foreach ($data['ranked'] as $i => $r) {
        $html .= $rowHtml($r, $i + 1);
    }
    $html .= '</tbody></table></div></div></div>';

    if (!empty($data['unranked'])) {
        $html .= '<div class="card mb-4"><div class="card-header"><h6 class="mb-0 text-muted">'
            . '<i class="bi bi-hourglass-split me-2"></i>Not ranked (too few evaluations)</h6></div>'
            . '<div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0 align-middle">'
            . '<thead class="table-light">' . $head . '</thead><tbody>';
        foreach ($data['unranked'] as $r) {
            $html .= $rowHtml($r, null);
        }
        $html .= '</tbody></table></div></div></div>';
    }

    $html .= '<p class="small text-muted mb-4"><i class="bi bi-calculator me-1"></i>'
        . $h(evalReportScoreFootnote($data, $f)) . '</p>';

    return $html;
}

/**
 * KPIs: fleet overall average, submitted count, drivers ranked, invite count
 * and response rate (completed trips in scope).
 */
function evalReportKpis(array $f): object
{
    [$whereSub, $paramsSub] = evalReportWhere($f);
    $agg = db()->fetch(
        "SELECT COUNT(*) AS total_submitted,
                AVG(de.overall) AS fleet_avg,
                COUNT(DISTINCT de.driver_id) AS drivers_ranked
         FROM driver_evaluations de
         JOIN requests r ON de.request_id = r.id
         WHERE {$whereSub}",
        $paramsSub
    );

    [$whereTrip, $paramsTrip] = evalReportTripsWhere($f);
    $inv = db()->fetch(
        "SELECT COUNT(de.id) AS invited,
                COALESCE(SUM(de.submitted_at IS NOT NULL), 0) AS submitted
         FROM requests r
         JOIN driver_evaluations de ON de.request_id = r.id
         WHERE {$whereTrip}",
        $paramsTrip
    );

    $invited = (int) ($inv->invited ?? 0);
    $submitted = (int) ($inv->submitted ?? 0);

    return (object) [
        'fleet_avg' => $agg->fleet_avg ?? null,
        'total_submitted' => (int) ($agg->total_submitted ?? 0),
        'drivers_ranked' => (int) ($agg->drivers_ranked ?? 0),
        'total_invited' => $invited,
        'total_submitted_scoped' => $submitted,
        'response_rate' => $invited > 0 ? (int) round($submitted / $invited * 100) : 0,
    ];
}

/**
 * Fleet-wide averages for the 4 star categories (PDF analytics).
 */
function evalReportCategoryAverages(array $f): object
{
    [$where, $params] = evalReportWhere($f);
    $row = db()->fetch(
        "SELECT AVG(de.rating_cleanliness) AS avg_cleanliness,
                AVG(de.rating_behavior) AS avg_behavior,
                AVG(de.rating_appearance) AS avg_appearance,
                AVG(de.rating_safety) AS avg_safety
     FROM driver_evaluations de
     JOIN requests r ON de.request_id = r.id
     WHERE {$where}",
        $params
    );

    return $row ?: (object) [
        'avg_cleanliness' => null,
        'avg_behavior' => null,
        'avg_appearance' => null,
        'avg_safety' => null,
    ];
}

/**
 * Response rate per completed trip (invites vs submitted vs average).
 *
 * @return list<object>
 */
function evalReportTrips(array $f, int $limit = 200): array
{
    [$where, $params] = evalReportTripsWhere($f);
    $params[] = max(1, $limit);

    return db()->fetchAll(
        "SELECT r.id, r.destination, r.start_datetime,
                u.name AS driver_name, v.plate_number,
                COUNT(de.id) AS total_invites,
                COALESCE(SUM(de.submitted_at IS NOT NULL), 0) AS submitted_cnt,
                AVG(IF(de.submitted_at IS NOT NULL, de.overall, NULL)) AS avg_overall
     FROM requests r
     LEFT JOIN driver_evaluations de ON de.request_id = r.id
     LEFT JOIN drivers d ON r.driver_id = d.id AND d.deleted_at IS NULL
     LEFT JOIN users u ON d.user_id = u.id
     LEFT JOIN vehicles v ON r.vehicle_id = v.id AND v.deleted_at IS NULL
     WHERE {$where}
     GROUP BY r.id, r.destination, r.start_datetime, u.name, v.plate_number
     ORDER BY r.start_datetime DESC
     LIMIT ?",
        $params
    );
}

/**
 * Anonymous remarks (quote + trip/driver context only — no rater identity).
 *
 * @return list<object>
 */
function evalReportRemarks(array $f, int $limit = 200, bool $groupByDriver = false): array
{
    [$where, $params] = evalReportWhere($f);
    $params[] = max(1, $limit);
    $order = $groupByDriver
        ? 'u.name ASC, de.submitted_at DESC'
        : 'de.submitted_at DESC';

    return db()->fetchAll(
        "SELECT de.request_id, de.overall, de.remarks, de.submitted_at,
                r.destination, r.start_datetime,
                u.name AS driver_name, v.plate_number
     FROM driver_evaluations de
     JOIN requests r ON de.request_id = r.id
     JOIN drivers d ON de.driver_id = d.id AND d.deleted_at IS NULL
     JOIN users u ON d.user_id = u.id
     LEFT JOIN vehicles v ON r.vehicle_id = v.id AND v.deleted_at IS NULL
     WHERE {$where} AND de.remarks IS NOT NULL AND TRIM(de.remarks) <> ''
     ORDER BY {$order}
     LIMIT ?",
        $params
    );
}

/**
 * Human-readable period for PDF header / filename.
 */
function evalReportPeriodLabel(array $f): string
{
    if ($f['from'] === '' && $f['to'] === '') {
        return 'All time';
    }
    if ($f['from'] === '') {
        return 'Through ' . $f['to'];
    }
    if ($f['to'] === '') {
        return 'From ' . $f['from'];
    }
    return $f['from'] . ' to ' . $f['to'];
}

/**
 * Dropdown options for the filter bar (approver+ pickers).
 *
 * @return array{drivers:list<object>,vehicles:list<object>}
 */
function evalReportFilterOptions(): array
{
    $drivers = db()->fetchAll(
        "SELECT d.id, u.name
     FROM drivers d
     JOIN users u ON d.user_id = u.id AND u.deleted_at IS NULL
     WHERE d.deleted_at IS NULL
     ORDER BY u.name ASC
     LIMIT 500"
    );
    $vehicles = db()->fetchAll(
        "SELECT id, plate_number FROM vehicles
     WHERE deleted_at IS NULL
     ORDER BY plate_number ASC
     LIMIT 500"
    );

    return ['drivers' => $drivers, 'vehicles' => $vehicles];
}

/**
 * Query string for CSV/PDF/screen links carrying the shared filter set.
 */
function evalReportQueryString(array $f, array $extra = []): string
{
    $params = array_merge([
        'page' => 'reports',
        'action' => 'driver-rankings',
        'from' => $f['from'],
        'to' => $f['to'],
        'driver_id' => $f['driver_id'] ?: '',
        'vehicle_id' => $f['vehicle_id'] ?: '',
        'request_id' => $f['request_id'] ?: '',
        'min_eval' => $f['min_eval'],
    ], $extra);

    $parts = [];
    foreach ($params as $k => $v) {
        if ($v === '' || $v === null) {
            continue;
        }
        $parts[] = urlencode((string) $k) . '=' . urlencode((string) $v);
    }
    return '/?' . implode('&', $parts);
}

/**
 * Shared filter bar (From/To, Driver, Vehicle, Trip no., optional Min evals).
 */
function evalReportFilterBarHtml(array $f, string $page, string $action, bool $showMinEval, string $clearUrl): string
{
    $h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
    $opts = evalReportFilterOptions();

    if (!$f['self_scoped']) {
        $driverField = '<div class="col-6 col-md-2"><label class="form-label">Driver</label><select name="driver_id" class="form-select"><option value="">All drivers</option>';
        foreach ($opts['drivers'] as $d) {
            $sel = $f['driver_id'] === (int) $d->id ? ' selected' : '';
            $driverField .= '<option value="' . (int) $d->id . '"' . $sel . '>' . $h($d->name) . '</option>';
        }
        $driverField .= '</select></div>';
    } else {
        $driverField = '<div class="col-6 col-md-2"><label class="form-label">Driver</label><input type="text" class="form-control" value="Own trips only" disabled></div>';
    }

    $vehicleField = '';
    if (!$f['self_scoped']) {
        $vehicleField = '<div class="col-6 col-md-2"><label class="form-label">Vehicle</label><select name="vehicle_id" class="form-select"><option value="">All vehicles</option>';
        foreach ($opts['vehicles'] as $v) {
            $sel = $f['vehicle_id'] === (int) $v->id ? ' selected' : '';
            $vehicleField .= '<option value="' . (int) $v->id . '"' . $sel . '>' . $h($v->plate_number) . '</option>';
        }
        $vehicleField .= '</select></div>';
    }

    $minField = $showMinEval
        ? '<div class="col-6 col-md-2"><label class="form-label">Min Evaluations</label><input type="number" class="form-control" name="min_eval" value="' . (int) $f['min_eval'] . '" min="1" max="100"></div>'
        : '';

    return '<form method="GET" class="card mb-4"><div class="card-body"><div class="row g-3 align-items-end">
        <input type="hidden" name="page" value="' . $h($page) . '">
        <input type="hidden" name="action" value="' . $h($action) . '">
        <div class="col-6 col-md-2"><label class="form-label">From</label><input type="date" class="form-control" name="from" value="' . $h($f['from']) . '"></div>
        <div class="col-6 col-md-2"><label class="form-label">To</label><input type="date" class="form-control" name="to" value="' . $h($f['to']) . '"></div>
        ' . $driverField . $vehicleField . '
        <div class="col-6 col-md-2"><label class="form-label">Trip No.</label><input type="number" class="form-control" name="request_id" value="' . ($f['request_id'] > 0 ? (int) $f['request_id'] : '') . '" min="1" placeholder="e.g. 555"></div>
        ' . $minField . '
        <div class="col-6 col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-primary flex-fill"><i class="bi bi-funnel me-1"></i>Apply</button>
            <a href="' . $h($clearUrl) . '" class="btn btn-outline-secondary flex-fill">Clear</a>
        </div>
    </div></div></form>';
}

/**
 * Compact "Export PDF" form carrying the current filters + comments checkbox.
 */
function evalReportPdfExportHtml(array $f): string
{
    $h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
    $hidden = '';
    foreach (['from', 'to'] as $k) {
        if ($f[$k] !== '') {
            $hidden .= '<input type="hidden" name="' . $k . '" value="' . $h($f[$k]) . '">';
        }
    }
    foreach (['driver_id', 'vehicle_id', 'request_id'] as $k) {
        if ($f[$k] > 0) {
            $hidden .= '<input type="hidden" name="' . $k . '" value="' . (int) $f[$k] . '">';
        }
    }

    return '<form method="get" action="' . $h(rtrim(APP_URL, '/')) . '/" class="d-inline-flex align-items-center gap-2 flex-wrap">
        <input type="hidden" name="page" value="reports">
        <input type="hidden" name="action" value="export-driver-evaluations-pdf">
        ' . $hidden . '
        <label class="form-check mb-0 small text-nowrap"><input class="form-check-input" type="checkbox" name="include_remarks" value="1"> Include comments</label>
        <button type="submit" class="btn btn-outline-danger"><i class="bi bi-file-earmark-pdf me-1"></i>Export PDF</button>
    </form>';
}

/* =======================================================================
 * Driver Trip Extract (Plan #21) — one row per assigned trip + short
 * driver summary. Includes drivers/trips with NO ratings yet (scores —).
 * ======================================================================= */

/**
 * Column flags for the extract (GET flags; hidden-input pattern keeps the
 * defaults when a checkbox is unchecked in the form).
 *
 * @return array<string,bool>
 */
function evalTripExtractColumns(): array
{
    return [
        // Driver summary — name/trip count/evals always on
        'sum_score' => get('sum_score', '1') !== '0',
        'sum_raw' => get('sum_raw', '1') !== '0',
        // Trip rows — defaults ON
        'col_date' => get('col_date', '1') !== '0',
        'col_trip' => get('col_trip', '1') !== '0',
        'col_driver' => get('col_driver', '1') !== '0',
        'col_dest' => get('col_dest', '1') !== '0',
        'col_overall' => get('col_overall', '1') !== '0',
        'col_cats' => get('col_cats', '1') !== '0',
        'col_remarks' => get('col_remarks', '1') !== '0',
        // Trip rows — defaults OFF
        'col_plate' => get('col_plate', '0') === '1',
        'col_invites' => get('col_invites', '0') === '1',
        'col_score' => get('col_score', '0') === '1',
    ];
}

/**
 * Assigned trips for the extract: driver_id set, not deleted, status
 * approved/completed, start_datetime in From/To, plus the shared
 * Driver / Vehicle / Trip no. filters. Per-trip evaluation aggregates ride
 * along (— when the trip has no submitted evaluations yet).
 *
 * @return list<object>
 */
function evalTripExtractTrips(array $f, int $limit = 2000): array
{
    $sql = "r.driver_id IS NOT NULL AND r.deleted_at IS NULL AND r.status IN ('approved','completed')";
    $params = [];
    if (!empty($f['from_sql'])) {
        $sql .= ' AND r.start_datetime >= ?';
        $params[] = $f['from_sql'];
    }
    if (!empty($f['to_sql'])) {
        $sql .= ' AND r.start_datetime <= ?';
        $params[] = $f['to_sql'];
    }
    if ($f['driver_id'] > 0) {
        $sql .= ' AND r.driver_id = ?';
        $params[] = $f['driver_id'];
    } elseif ($f['self_scoped']) {
        $sql .= ' AND 1=0';
    }
    if ($f['vehicle_id'] > 0) {
        $sql .= ' AND r.vehicle_id = ?';
        $params[] = $f['vehicle_id'];
    }
    if ($f['request_id'] > 0) {
        $sql .= ' AND r.id = ?';
        $params[] = $f['request_id'];
    }
    $params[] = max(1, $limit);

    return db()->fetchAll(
        "SELECT r.id, r.status, r.start_datetime, r.end_datetime, r.destination,
                r.driver_id, u.name AS driver_name, v.plate_number,
                COUNT(de.id) AS total_invites,
                COALESCE(SUM(de.submitted_at IS NOT NULL), 0) AS submitted_cnt,
                AVG(IF(de.submitted_at IS NOT NULL, de.overall, NULL)) AS avg_overall,
                AVG(IF(de.submitted_at IS NOT NULL, de.rating_cleanliness, NULL)) AS avg_cleanliness,
                AVG(IF(de.submitted_at IS NOT NULL, de.rating_behavior, NULL)) AS avg_behavior,
                AVG(IF(de.submitted_at IS NOT NULL, de.rating_appearance, NULL)) AS avg_appearance,
                AVG(IF(de.submitted_at IS NOT NULL, de.rating_safety, NULL)) AS avg_safety
     FROM requests r
     LEFT JOIN driver_evaluations de ON de.request_id = r.id
     LEFT JOIN drivers d ON r.driver_id = d.id AND d.deleted_at IS NULL
     LEFT JOIN users u ON d.user_id = u.id
     LEFT JOIN vehicles v ON r.vehicle_id = v.id AND v.deleted_at IS NULL
     WHERE {$sql}
     GROUP BY r.id, r.status, r.start_datetime, r.end_datetime, r.destination,
                r.driver_id, u.name, v.plate_number
     ORDER BY r.start_datetime ASC, r.id ASC
     LIMIT ?",
        $params
    );
}

/**
 * Anonymous remarks grouped by trip (request id) for the extract's comments
 * column. Quotes only — never rater identity.
 *
 * @return array<int,list<string>>
 */
function evalTripExtractRemarks(array $f): array
{
    $map = [];
    foreach (evalReportDriverEvalRows($f) as $e) {
        if ($e->remarks !== null && trim((string) $e->remarks) !== '') {
            $map[(int) $e->request_id][] = (string) $e->remarks;
        }
    }
    return $map;
}

/**
 * Driver summary rows for the extract: every driver with ≥1 assigned trip in
 * range (including zero evaluations), with trip count, submitted eval count
 * and (when available) the fair rank score + raw average.
 *
 * @param list<object> $trips result of evalTripExtractTrips()
 * @param array{ranked:list<object>,unranked:list<object>,fleet_mean:?float} $rankings
 * @return list<object>{driver_id,driver_name,trip_count,eval_count,rank_score,avg_overall}
 */
function evalTripExtractSummary(array $trips, array $rankings): array
{
    $byId = [];
    foreach (array_merge($rankings['ranked'], $rankings['unranked']) as $r) {
        $byId[(int) $r->driver_id] = $r;
    }

    $summary = [];
    foreach ($trips as $t) {
        $did = (int) $t->driver_id;
        if (!isset($summary[$did])) {
            $summary[$did] = (object) [
                'driver_id' => $did,
                'driver_name' => $t->driver_name ?: ('Driver #' . $did),
                'trip_count' => 0,
                'eval_count' => isset($byId[$did]) ? (int) $byId[$did]->eval_count : 0,
                'rank_score' => isset($byId[$did]) ? $byId[$did]->rank_score : null,
                'avg_overall' => isset($byId[$did]) ? $byId[$did]->avg_overall : null,
            ];
        }
        $summary[$did]->trip_count++;
    }

    $rows = array_values($summary);
    usort($rows, static fn(object $a, object $b): int => strcasecmp($a->driver_name, $b->driver_name));
    return $rows;
}
