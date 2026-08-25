<?php
/**
 * Shared filters / KPIs / rows / analytics for Gas Voucher Reports.
 * Counts and workflow metrics only â€” no peso/amount fields.
 */

function requireGasVoucherReportAccess(): void
{
    denyGuardAccess();
    if (!canAccessOpsReports()) {
        redirectWith('/?page=dashboard', 'danger', 'Administrator or All Father access required.');
    }
}

/**
 * @return array<string, mixed>
 */
function gasVoucherReportParseFilters(): array
{
    $dateField = getSafe('date_field', 'request_date', 20);
    if (!in_array($dateField, ['request_date', 'created_at', 'approved_at'], true)) {
        $dateField = 'request_date';
    }

    $range = reportResolveDateRange();
    $search = listSearchQuery('q', 'search');
    if ($search === '') {
        $search = getSafe('search', '', 100);
    }

    return [
        'start_date' => $range['start'],
        'end_date' => $range['end'],
        'preset' => $range['preset'],
        'date_field' => $dateField,
        'status' => getSafe('status', '', 30),
        'include_drafts' => get('include_drafts') === '1',
        'payment_status' => getSafe('payment_status', '', 20),
        'fund_source' => getSafe('fund_source', '', 100),
        'gas_station' => getSafe('gas_station', '', 120),
        'fuel_type' => getSafe('fuel_type', '', 30),
        'department_id' => (int) get('department_id', 0),
        'user_id' => (int) get('user_id', 0),
        'search' => $search,
    ];
}

/**
 * @return array{0: string, 1: array}
 */
function gasVoucherReportWhere(array $f): array
{
    $dateCol = match ($f['date_field']) {
        'created_at' => 'gv.created_at',
        'approved_at' => 'gv.approved_at',
        default => 'gv.request_date',
    };

    if ($f['date_field'] === 'request_date') {
        $sql = 'gv.deleted_at IS NULL AND gv.request_date BETWEEN ? AND ?';
        $params = [$f['start_date'], $f['end_date']];
    } else {
        $sql = "gv.deleted_at IS NULL AND {$dateCol} IS NOT NULL AND {$dateCol} BETWEEN ? AND ?";
        $params = [$f['start_date'] . ' 00:00:00', $f['end_date'] . ' 23:59:59'];
    }

    if ($f['status'] !== '') {
        $sql .= ' AND gv.status = ?';
        $params[] = $f['status'];
    } elseif (!$f['include_drafts']) {
        $sql .= " AND gv.status <> 'draft'";
    }

    if ($f['payment_status'] !== '') {
        $sql .= ' AND gv.payment_status = ?';
        $params[] = $f['payment_status'];
    }
    if ($f['fund_source'] !== '') {
        $sql .= ' AND gv.fund_source = ?';
        $params[] = $f['fund_source'];
    }
    if ($f['gas_station'] !== '') {
        $sql .= ' AND gv.gas_station = ?';
        $params[] = $f['gas_station'];
    }
    if ($f['fuel_type'] !== '') {
        $sql .= ' AND gv.fuel_type = ?';
        $params[] = $f['fuel_type'];
    }
    if ($f['department_id'] > 0) {
        $sql .= ' AND u.department_id = ?';
        $params[] = $f['department_id'];
    }
    if ($f['user_id'] > 0) {
        $sql .= ' AND gv.requested_by_user_id = ?';
        $params[] = $f['user_id'];
    }
    if ($f['search'] !== '') {
        $sql .= ' AND (gv.voucher_no LIKE ? OR gv.vehicle_plate LIKE ? OR gv.driver_name LIKE ? OR gv.purpose LIKE ? OR gv.fund_source LIKE ?)';
        $term = '%' . $f['search'] . '%';
        array_push($params, $term, $term, $term, $term, $term);
    }

    return [$sql, $params];
}

function gasVoucherReportKpis(array $f): object
{
    [$where, $params] = gasVoucherReportWhere($f);
    $row = db()->fetch(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN gv.status = 'pending_review' THEN 1 ELSE 0 END) AS pending_review,
            SUM(CASE WHEN gv.status = 'pending_approval' THEN 1 ELSE 0 END) AS pending_approval,
            SUM(CASE WHEN gv.status = 'approved' THEN 1 ELSE 0 END) AS approved,
            SUM(CASE WHEN gv.status = 'approved' AND gv.payment_status = 'unpaid' THEN 1 ELSE 0 END) AS approved_unpaid_count,
            SUM(CASE WHEN gv.status = 'approved' AND gv.payment_status IN ('paid','processed') THEN 1 ELSE 0 END) AS paid_count,
            SUM(CASE WHEN gv.status IN ('rejected','cancelled') THEN 1 ELSE 0 END) AS rejected_cancelled,
            AVG(CASE WHEN gv.status = 'approved' AND gv.approved_at IS NOT NULL
                THEN TIMESTAMPDIFF(DAY, gv.created_at, gv.approved_at) END) AS avg_approve_days
         FROM gas_vouchers gv
         JOIN users u ON gv.requested_by_user_id = u.id
         WHERE {$where}",
        $params
    );

    return $row ?: (object) [
        'total' => 0,
        'pending_review' => 0,
        'pending_approval' => 0,
        'approved' => 0,
        'approved_unpaid_count' => 0,
        'paid_count' => 0,
        'rejected_cancelled' => 0,
        'avg_approve_days' => null,
    ];
}

/**
 * @return array<string, mixed>
 */
function gasVoucherReportAnalytics(array $f): array
{
    [$where, $params] = gasVoucherReportWhere($f);

    $status = db()->fetchAll(
        "SELECT gv.status AS label, COUNT(*) AS count
         FROM gas_vouchers gv JOIN users u ON gv.requested_by_user_id = u.id
         WHERE {$where}
         GROUP BY gv.status ORDER BY count DESC",
        $params
    );

    $byFund = db()->fetchAll(
        "SELECT COALESCE(NULLIF(TRIM(gv.fund_source), ''), 'Unspecified') AS label, COUNT(*) AS count
         FROM gas_vouchers gv JOIN users u ON gv.requested_by_user_id = u.id
         WHERE {$where}
         GROUP BY label ORDER BY count DESC LIMIT 8",
        $params
    );

    $byStation = db()->fetchAll(
        "SELECT COALESCE(NULLIF(TRIM(gv.gas_station), ''), 'Unspecified') AS label, COUNT(*) AS count
         FROM gas_vouchers gv JOIN users u ON gv.requested_by_user_id = u.id
         WHERE {$where}
         GROUP BY label ORDER BY count DESC LIMIT 8",
        $params
    );

    $payment = db()->fetchAll(
        "SELECT gv.payment_status AS label, COUNT(*) AS count
         FROM gas_vouchers gv JOIN users u ON gv.requested_by_user_id = u.id
         WHERE {$where} AND gv.status = 'approved'
         GROUP BY gv.payment_status ORDER BY count DESC",
        $params
    );

    $daily = db()->fetchAll(
        "SELECT DATE(gv.request_date) AS day, COUNT(*) AS count
         FROM gas_vouchers gv JOIN users u ON gv.requested_by_user_id = u.id
         WHERE {$where} AND gv.request_date IS NOT NULL
         GROUP BY DATE(gv.request_date) ORDER BY day ASC",
        $params
    );

    return [
        'status' => array_map(static fn($r) => ['label' => $r->label, 'count' => (int) $r->count], $status),
        'byFund' => array_map(static fn($r) => ['label' => $r->label, 'count' => (int) $r->count], $byFund),
        'byStation' => array_map(static fn($r) => ['label' => $r->label, 'count' => (int) $r->count], $byStation),
        'payment' => array_map(static fn($r) => ['label' => $r->label, 'count' => (int) $r->count], $payment),
        'daily' => array_map(static fn($r) => ['day' => $r->day, 'count' => (int) $r->count], $daily),
        'topFund' => $byFund[0] ?? null,
        'topStation' => $byStation[0] ?? null,
    ];
}

/**
 * @return list<object>
 */
function gasVoucherReportRows(
    array $f,
    int $limit = 500,
    string $orderSql = 'gv.request_date DESC, gv.id DESC',
    int $offset = 0
): array {
    [$where, $params] = gasVoucherReportWhere($f);
    $limit = max(1, min($limit, 10000));
    $offset = max(0, $offset);
    $params[] = $limit;
    $params[] = $offset;

    return db()->fetchAll(
        "SELECT gv.id, gv.voucher_no, gv.request_date, gv.created_at, gv.driver_name, gv.vehicle_plate,
                gv.fuel_type, gv.quantity, gv.unit, gv.fund_source, gv.gas_station, gv.purpose,
                gv.chargeable_against, gv.status, gv.payment_status, gv.approved_at, gv.date_withdrawn,
                u.name AS requester_name, u.email AS requester_email, d.name AS department_name
         FROM gas_vouchers gv
         JOIN users u ON gv.requested_by_user_id = u.id
         LEFT JOIN departments d ON u.department_id = d.id
         WHERE {$where}
         ORDER BY {$orderSql}
         LIMIT ? OFFSET ?",
        $params
    );
}

/**
 * @return array{funds: list<string>, stations: list<string>, departments: list<object>}
 */
function gasVoucherReportFilterOptions(): array
{
    $funds = db()->fetchAll(
        "SELECT DISTINCT fund_source AS v FROM gas_vouchers
         WHERE deleted_at IS NULL AND fund_source IS NOT NULL AND fund_source <> ''
         ORDER BY fund_source ASC LIMIT 50"
    );
    $stations = db()->fetchAll(
        "SELECT DISTINCT gas_station AS v FROM gas_vouchers
         WHERE deleted_at IS NULL AND gas_station IS NOT NULL AND gas_station <> ''
         ORDER BY gas_station ASC LIMIT 50"
    );
    $departments = db()->fetchAll(
        "SELECT id, name FROM departments WHERE deleted_at IS NULL ORDER BY name ASC"
    );

    return [
        'funds' => array_values(array_filter(array_map(static fn($r) => (string) $r->v, $funds))),
        'stations' => array_values(array_filter(array_map(static fn($r) => (string) $r->v, $stations))),
        'departments' => $departments,
    ];
}

/**
 * @return array<string, scalar>
 */
function gasVoucherReportQueryParams(array $f, array $extra = []): array
{
    $base = [
        'page' => 'reports',
        'start_date' => $f['start_date'],
        'end_date' => $f['end_date'],
        'date_field' => $f['date_field'],
        'status' => $f['status'],
        'include_drafts' => $f['include_drafts'] ? '1' : '',
        'payment_status' => $f['payment_status'],
        'fund_source' => $f['fund_source'],
        'gas_station' => $f['gas_station'],
        'fuel_type' => $f['fuel_type'],
        'department_id' => $f['department_id'] ?: '',
        'user_id' => $f['user_id'] ?: '',
        'search' => $f['search'],
    ];
    return array_merge($base, $extra);
}

function gasVoucherReportBuildQuery(array $params): string
{
    $parts = [];
    foreach ($params as $k => $v) {
        if ($v === '' || $v === null) {
            continue;
        }
        $parts[] = urlencode((string) $k) . '=' . urlencode((string) $v);
    }
    return $parts ? ('/?' . implode('&', $parts)) : '/';
}

function gasVoucherReportUrl(array $params): string
{
    return rtrim(APP_URL, '/') . gasVoucherReportBuildQuery($params);
}

