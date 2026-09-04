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
 * Per-driver ranking rows using the same 4 categories as the submit form.
 *
 * @return list<object>
 */
function evalReportRankings(array $f, bool $applyMinEval = true): array
{
    [$where, $params] = evalReportWhere($f);
    $sql = "SELECT de.driver_id, u.name AS driver_name,
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
     GROUP BY de.driver_id";
    if ($applyMinEval) {
        $sql .= ' HAVING eval_count >= ?';
        $params[] = $f['min_eval'];
    }
    $sql .= ' ORDER BY avg_overall DESC, eval_count DESC';

    return db()->fetchAll($sql, $params);
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
