<?php
/**
 * LOKA - AI assistant tool registry (Plan #40, experimental)
 *
 * Each tool declares:
 *   id              — the name the model is allowed to call
 *   description     — shown to the model (and only the model)
 *   schema          — JSON-schema args; aiSanitizeToolArgs() enforces the types
 *   allowed         — capability callback, re-checked at EXECUTE time
 *   mutating        — requires a signed confirm token + a user click
 *   handler         — returns array{summary:string, data:array, link:?string}
 *
 * Authorisation is never taken from the model. `allowed()` runs against the
 * signed-in session (the effective role, so View-as is honoured) and, for
 * All-Father-only tools, refuses while View-as is active.
 *
 * Requires includes/ai_assistant.php to be loaded first.
 */

if (!defined('AI_TOOLS_LOADED')) {

    define('AI_TOOLS_LOADED', 1);

    // The executing-action layer. Required HERE rather than lazily behind a
    // function_exists() check: nothing else loads it, so a lazy check would
    // always be false and the actions would silently never register.
    require_once INCLUDES_PATH . '/ai_actions.php';

    /**
     * All Father tools are refused while the admin is impersonating another
     * role (Plan #40 decision 4).
     */
    function aiToolRealAllFatherOnly(): bool
    {
        return isRealAllFather() && !isViewingAs();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    function aiToolRegistry(): array
    {
        $tools = [];

        // ---------------------------------------------------------------
        // Read tools — no confirmation, but still fully scoped to the caller
        // ---------------------------------------------------------------

        $tools['search_my_trips'] = [
            'label' => 'Searching your trip requests',
            'description' => 'Search the signed-in user\'s own vehicle trip requests by destination, plate, status or request id.',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Plate, destination, status or request id', 'maxLength' => 60],
                ],
                'required' => ['query'],
            ],
            'mutating' => false,
            'allowed' => static fn(): bool => userId() !== null,
            'handler' => static function (array $args): array {
                $q = (string) ($args['query'] ?? '');
                if ($q === '') {
                    return ['summary' => 'Please give a plate, destination, status or request id.', 'data' => [], 'link' => null];
                }
                if (ctype_digit($q)) {
                    $rows = db()->fetchAll(
                        "SELECT id, status, destination, purpose, start_datetime, end_datetime
                         FROM requests
                         WHERE id = ? AND user_id = ? AND deleted_at IS NULL",
                        [(int) $q, userId()]
                    );
                } else {
                    $like = '%' . $q . '%';
                    $rows = db()->fetchAll(
                        "SELECT r.id, r.status, r.destination, r.purpose, r.start_datetime, r.end_datetime, v.plate_number
                         FROM requests r
                         LEFT JOIN vehicles v ON v.id = r.vehicle_id
                         WHERE r.user_id = ? AND r.deleted_at IS NULL
                           AND (r.destination LIKE ? OR r.purpose LIKE ? OR v.plate_number LIKE ?)
                         ORDER BY r.start_datetime DESC LIMIT 10",
                        [userId(), $like, $like, $like]
                    );
                }
                $out = array_map(static fn($r) => [
                    'id' => (int) $r->id,
                    'status' => $r->status,
                    'destination' => $r->destination,
                    'start' => $r->start_datetime,
                    'plate' => $r->plate_number ?? null,
                ], $rows);
                return [
                    'summary' => $out === []
                        ? 'No trip requests matched "' . $q . '".'
                        : count($out) . ' trip request(s) matched "' . $q . '": ' . implode('; ', array_map(
                            static fn($r) => '#' . $r['id'] . ' ' . $r['status'] . ' -> ' . $r['destination'],
                            $out
                        )),
                    'data' => $out,
                    'link' => $out === [] ? '/?page=my-trips' : '/?page=requests&action=view&id=' . $out[0]['id'],
                ];
            },
        ];

        $tools['explain_request_status'] = [
            'label' => 'Checking a trip request',
            'description' => 'Explain what stage a vehicle trip request is in and who acts next.',
            'schema' => [
                'type' => 'object',
                'properties' => ['request_id' => ['type' => 'integer', 'description' => 'Trip request id']],
                'required' => ['request_id'],
            ],
            'mutating' => false,
            'allowed' => static fn(): bool => true,
            'handler' => static function (array $args): array {
                $id = (int) ($args['request_id'] ?? 0);
                $r = db()->fetch(
                    "SELECT r.*, v.plate_number, w.step AS wf_step, w.status AS wf_status
                     FROM requests r
                     LEFT JOIN vehicles v ON v.id = r.vehicle_id
                     LEFT JOIN approval_workflow w ON w.request_id = r.id
                     WHERE r.id = ? AND r.deleted_at IS NULL",
                    [$id]
                );
                if (!$r) {
                    return ['summary' => 'Request #' . $id . ' was not found.', 'data' => [], 'link' => null];
                }
                // Ownership check at execute time, never trusted from the model.
                $isOps = canViewAllTripRequests();
                $isParticipant = ((int) $r->user_id === (int) userId()) || isAssignedOrRequestedDriver($r);
                if (!$isOps && !$isParticipant) {
                    auditLog('ai_tool_denied', 'request', $id, null, ['tool' => 'explain_request_status']);
                    return ['summary' => 'You do not have access to request #' . $id . '.', 'data' => [], 'link' => null];
                }
                $next = match ((string) $r->status) {
                    STATUS_PENDING           => 'waiting for the department approver',
                    STATUS_PENDING_MOTORPOOL => 'waiting for the Motorpool Head',
                    STATUS_APPROVED          => empty($r->actual_dispatch_datetime)
                        ? 'waiting for the guard to record the dispatch'
                        : (empty($r->actual_arrival_datetime) ? 'on the road' : 'waiting for the trip to be completed'),
                    STATUS_COMPLETED         => 'closed',
                    STATUS_REJECTED          => 'closed (rejected)',
                    STATUS_REVISION          => 'waiting for the requester to revise and resubmit',
                    default                  => 'no action needed',
                };
                return [
                    'summary' => 'Request #' . $id . ' is ' . $r->status . ' — ' . $next . '.'
                        . ($r->plate_number ? ' Vehicle ' . $r->plate_number . '.' : '')
                        . ' Destination: ' . $r->destination . '.',
                    'data' => ['id' => $id, 'status' => $r->status, 'next' => $next],
                    'link' => '/?page=requests&action=view&id=' . $id,
                ];
            },
        ];

        $tools['my_pending_approvals'] = [
            'label' => 'Checking your approval queue',
            'description' => 'List the approvals currently waiting on the signed-in user.',
            'schema' => ['type' => 'object', 'properties' => new stdClass(), 'required' => []],
            'mutating' => false,
            'allowed' => static fn(): bool => isApprover() || isAdmin(),
            'handler' => static function (array $args): array {
                $rows = [];
                if (isAdmin()) {
                    $rows = db()->fetchAll(
                        "SELECT id, destination, status FROM requests
                         WHERE deleted_at IS NULL AND status IN (?, ?) ORDER BY start_datetime LIMIT 20",
                        [STATUS_PENDING, STATUS_PENDING_MOTORPOOL]
                    );
                } else {
                    $rows = db()->fetchAll(
                        "SELECT id, destination, status FROM requests
                         WHERE deleted_at IS NULL
                           AND ((status = ? AND approver_id = ?) OR (status = ? AND motorpool_head_id = ?))
                         ORDER BY start_datetime LIMIT 20",
                        [STATUS_PENDING, userId(), STATUS_PENDING_MOTORPOOL, userId()]
                    );
                }
                $summary = $rows === []
                    ? 'Nothing is waiting for your approval right now.'
                    : count($rows) . ' request(s) waiting: ' . implode('; ', array_map(
                        static fn($r) => '#' . $r->id . ' ' . $r->status . ' -> ' . $r->destination,
                        $rows
                    ));
                return [
                    'summary' => $summary,
                    'data' => array_map(static fn($r) => ['id' => (int) $r->id, 'status' => $r->status], $rows),
                    'link' => '/?page=approvals',
                ];
            },
        ];

        $tools['search_my_ob_slips'] = [
            'label' => 'Searching your OB pass slips',
            'description' => 'Search the signed-in user\'s own OB Pass Slips by slip number, status or purpose.',
            'schema' => [
                'type' => 'object',
                'properties' => ['query' => ['type' => 'string', 'maxLength' => 60]],
                'required' => ['query'],
            ],
            'mutating' => false,
            'allowed' => static fn(): bool => userId() !== null,
            'handler' => static function (array $args): array {
                $q = (string) ($args['query'] ?? '');
                $like = '%' . $q . '%';
                $rows = db()->fetchAll(
                    "SELECT id, pass_slip_no, status, ob_date, purpose
                     FROM ob_requests
                     WHERE user_id = ? AND deleted_at IS NULL
                       AND (pass_slip_no LIKE ? OR purpose LIKE ? OR status LIKE ?)
                     ORDER BY ob_date DESC LIMIT 10",
                    [userId(), $like, $like, $like]
                );
                return [
                    'summary' => $rows === []
                        ? 'No pass slips matched "' . $q . '".'
                        : count($rows) . ' pass slip(s) matched "' . $q . '": ' . implode('; ', array_map(
                            static fn($r) => $r->pass_slip_no . ' (' . $r->status . ') ' . formatDate($r->ob_date),
                            $rows
                        )),
                    'data' => array_map(static fn($r) => ['id' => (int) $r->id, 'no' => $r->pass_slip_no, 'status' => $r->status], $rows),
                    'link' => '/?page=ob-requests',
                ];
            },
        ];

        $tools['search_my_gas_vouchers'] = [
            'label' => 'Searching your gas vouchers',
            'description' => 'Search the signed-in user\'s own gas vouchers by voucher number, plate or status.',
            'schema' => [
                'type' => 'object',
                'properties' => ['query' => ['type' => 'string', 'maxLength' => 60]],
                'required' => ['query'],
            ],
            'mutating' => false,
            'allowed' => static fn(): bool => userId() !== null,
            'handler' => static function (array $args): array {
                $q = (string) ($args['query'] ?? '');
                $like = '%' . $q . '%';
                $rows = db()->fetchAll(
                    "SELECT id, voucher_no, status, vehicle_plate, total_cost
                     FROM gas_vouchers
                     WHERE requested_by_user_id = ? AND deleted_at IS NULL
                       AND (voucher_no LIKE ? OR vehicle_plate LIKE ? OR status LIKE ?)
                     ORDER BY request_date DESC LIMIT 10",
                    [userId(), $like, $like, $like]
                );
                return [
                    'summary' => $rows === []
                        ? 'No gas vouchers matched "' . $q . '".'
                        : count($rows) . ' voucher(s) matched "' . $q . '": ' . implode('; ', array_map(
                            static fn($r) => $r->voucher_no . ' (' . $r->status . ') ' . $r->vehicle_plate,
                            $rows
                        )),
                    'data' => array_map(static fn($r) => ['id' => (int) $r->id, 'no' => $r->voucher_no, 'status' => $r->status], $rows),
                    'link' => '/?page=gas-vouchers',
                ];
            },
        ];

        $tools['care_due_this_week'] = [
            'label' => 'Checking vehicle care due this week',
            'description' => 'List vehicle care items due in the next 7 days for the vehicles the signed-in user may see.',
            'schema' => ['type' => 'object', 'properties' => new stdClass(), 'required' => []],
            'mutating' => false,
            'allowed' => static fn(): bool => function_exists('canAccessMaintenanceSchedule') && canAccessMaintenanceSchedule(),
            'handler' => static function (array $args): array {
                if (!function_exists('careVehicleVisibilitySql')) {
                    require_once INCLUDES_PATH . '/vehicle_care.php';
                }
                [$visSql, $visParams] = careVehicleVisibilitySql('vcs.vehicle_id');
                $sql = "SELECT vcs.id, vcs.title, vcs.due_date, v.plate_number
                        FROM vehicle_care_schedules vcs
                        JOIN vehicles v ON v.id = vcs.vehicle_id AND v.deleted_at IS NULL
                        WHERE vcs.deleted_at IS NULL
                          AND vcs.status IN (?, ?)
                          AND vcs.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                          AND {$visSql}
                        ORDER BY vcs.due_date ASC LIMIT 20";
                $rows = db()->fetchAll($sql, array_merge([CARE_STATUS_PENDING, CARE_STATUS_SCHEDULED], $visParams));
                $summary = $rows === []
                    ? 'No vehicle care is due in the next 7 days for the vehicles you can see.'
                    : count($rows) . ' care item(s) due within 7 days: ' . implode('; ', array_map(
                        static fn($r) => $r->plate_number . ' — ' . $r->title . ' (due ' . formatDate($r->due_date) . ')',
                        $rows
                    ));
                return [
                    'summary' => $summary,
                    'data' => array_map(static fn($r) => ['id' => (int) $r->id, 'plate' => $r->plate_number, 'due' => $r->due_date], $rows),
                    'link' => '/?page=maintenance&action=schedule',
                ];
            },
        ];

        // ---------------------------------------------------------------
        // Fleet reference tools. Each one mirrors access the caller already has
        // in the UI — none of them grant a new permission:
        //   ops roles  -> the Vehicles / Drivers / Maintenance / Audit screens
        //   guard      -> approved trips (what the Guard Dashboard lists)
        //   requester  -> the vehicles and drivers on their own requests
        // ---------------------------------------------------------------

        /**
         * Which vehicles may this caller be told about?
         *
         * Returns NULL for "unrestricted" (ops roles) and an ARRAY otherwise —
         * including an EMPTY array, which means "no vehicles at all". Collapsing
         * those two cases would hand a requester who happens to own no vehicle
         * unrestricted fleet-wide access.
         *
         * Mirrors the UI: ops see the fleet, guards see vehicles on approved
         * trips (the Guard Dashboard lists every approved trip), requesters see
         * only their own.
         *
         * @return list<int>|null
         */
        $visibleVehicleIds = static function (): ?array {
            if (isAdmin() || isMotorpool() || isApprover() || (isRealAllFather() && !isViewingAs())) {
                return null;   // unrestricted
            }
            $sql = 'SELECT DISTINCT vehicle_id FROM requests
                    WHERE deleted_at IS NULL AND vehicle_id IS NOT NULL';
            $params = [];
            if (isGuard()) {
                $sql .= " AND status = 'approved'";
            } else {
                // requester (or anything else): own requests only
                $sql .= ' AND user_id = ?';
                $params[] = userId();
            }
            return array_map('intval', array_column(db()->fetchAll($sql, $params), 'vehicle_id'));
        };

        $tools['vehicle_lookup'] = [
            'label' => 'Looking up a vehicle',
            'description' => 'Look up a fleet vehicle by plate number: make/model, status, mileage, and which trip it is currently on.',
            'schema' => [
                'type' => 'object',
                'properties' => ['plate' => ['type' => 'string', 'maxLength' => 20]],
                'required' => ['plate'],
            ],
            'mutating' => false,
            'allowed' => static fn(): bool => userId() !== null,
            'handler' => static function (array $args) use ($visibleVehicleIds): array {
                $plate = trim((string) ($args['plate'] ?? ''));
                if ($plate === '') {
                    return ['summary' => 'Please give a plate number.', 'data' => [], 'link' => null];
                }
                // A plate is an identifier: try an exact (case/space-insensitive)
                // match first and only fall back to a substring search, otherwise
                // "SAA" would match half the fleet.
                $norm = static fn(string $p): string
                    => strtoupper(str_replace([' ', '-'], '', $p));
                $normed = $norm($plate);
                $allowed = $visibleVehicleIds();
                if ($allowed === []) {
                    // No vehicles visible to this caller at all. Must NOT fall
                    // through to an unrestricted lookup.
                    return ['summary' => 'No vehicle matching "' . $plate . '" is visible to you.', 'data' => [], 'link' => null];
                }
                $idClause = '';
                $extra = [];
                if ($allowed !== null) {
                    $idClause = ' AND v.id IN (' . implode(',', array_fill(0, count($allowed), '?')) . ')';
                    $extra = $allowed;
                }

                $cols = 'v.id, v.plate_number, v.make, v.model, v.year, v.vin, v.engine_number,
                         v.status, v.mileage, v.fuel_type, v.transmission,
                         v.last_maintenance_date, v.odometer_broken';
                $rows = db()->fetchAll(
                    "SELECT {$cols} FROM vehicles v
                     WHERE v.deleted_at IS NULL
                       AND UPPER(REPLACE(REPLACE(v.plate_number, ' ', ''), '-', '')) = ?{$idClause}
                     LIMIT 5",
                    array_merge([$normed], $extra)
                );
                if ($rows === []) {
                    $rows = db()->fetchAll(
                        "SELECT {$cols} FROM vehicles v
                         WHERE v.deleted_at IS NULL AND v.plate_number LIKE ?{$idClause}
                         ORDER BY v.plate_number LIMIT 5",
                        array_merge(['%' . $plate . '%'], $extra)
                    );
                }

                if ($rows === []) {
                    return ['summary' => 'No vehicle matching "' . $plate . '" is visible to you.', 'data' => [], 'link' => null];
                }
                $out = [];
                $bits = [];
                foreach ($rows as $v) {
                    $trip = db()->fetch(
                        "SELECT r.id, r.status, r.destination, r.start_datetime, r.actual_dispatch_datetime
                         FROM requests r
                         WHERE r.vehicle_id = ? AND r.deleted_at IS NULL
                           AND (r.status = 'approved' OR r.actual_dispatch_datetime IS NOT NULL)
                         ORDER BY r.actual_dispatch_datetime DESC, r.id DESC LIMIT 1",
                        [(int) $v->id]
                    );
                    $o = [
                        'plate' => $v->plate_number,
                        'vehicle' => trim($v->make . ' ' . $v->model . ' ' . ($v->year ?? '')),
                        'status' => $v->status,
                        'mileage_km' => (int) $v->mileage,
                        'odometer_broken' => (int) $v->odometer_broken === 1,
                        'engine_no' => $v->engine_number ?: null,
                        'last_service' => $v->last_maintenance_date ?: null,
                        'active_trip' => $trip ? (int) $trip->id : null,
                    ];
                    $out[] = $o;
                    $bits[] = $o['plate'] . ' (' . $o['vehicle'] . ') — ' . $o['status']
                        . ', ' . number_format($o['mileage_km']) . ' km'
                        . ($trip ? ', on trip #' . $trip->id : '');
                }
                return [
                    'summary' => implode(' · ', $bits),
                    'data' => $out,
                    'link' => (isApprover() || isAdmin() || isMotorpool() || (isRealAllFather() && !isViewingAs()))
                        ? '/?page=vehicles&search=' . rawurlencode($plate)
                        : null,
                ];
            },
        ];

        $tools['vehicle_service_history'] = [
            'label' => 'Checking a vehicle\'s repair and service history',
            'description' => 'Open repair tickets and recorded Repair History for a vehicle — why it was in the shop and what it cost.',
            'schema' => [
                'type' => 'object',
                'properties' => ['plate' => ['type' => 'string', 'maxLength' => 20]],
                'required' => ['plate'],
            ],
            'mutating' => false,
            // Mirrors the Maintenance screen (approver and above). A requester
            // cannot open Maintenance in the UI, so they must not get repair
            // costs (or ticket detail) through the assistant either.
            'allowed' => static fn(): bool => isApprover() || isAdmin() || isMotorpool() || isRealAllFather(),
            'handler' => static function (array $args): array {
                $plate = trim((string) ($args['plate'] ?? ''));
                if ($plate === '') {
                    return ['summary' => 'Please give a plate number.', 'data' => [], 'link' => null];
                }
                // This tool is ops-only (it mirrors the Maintenance screen), so
                // every vehicle is visible — no allow-list needed.
                $veh = db()->fetch(
                    "SELECT id, plate_number, make, model FROM vehicles
                     WHERE deleted_at IS NULL AND plate_number LIKE ?
                     ORDER BY plate_number LIMIT 1",
                    ['%' . $plate . '%']
                );
                if (!$veh) {
                    return ['summary' => 'No vehicle matching "' . $plate . '" is visible to you.', 'data' => [], 'link' => null];
                }

                $tickets = db()->fetchAll(
                    "SELECT id, title, status, priority, type, reported_at, scheduled_date, completed_date,
                            estimated_cost, actual_cost
                     FROM maintenance_requests
                     WHERE vehicle_id = ? AND deleted_at IS NULL
                     ORDER BY COALESCE(completed_date, reported_at) DESC LIMIT 8",
                    [(int) $veh->id]
                );

                // Repair History is Plan #38 and ships OFF by default — say so
                // rather than silently reporting no history.
                $history = [];
                $historyNote = '';
                if (aiRepairHistoryAvailable()) {
                    $history = db()->fetchAll(
                        "SELECT id, repair_date, nature_of_repair, total_amount, source
                         FROM vehicle_repair_entries
                         WHERE vehicle_id = ? AND deleted_at IS NULL
                         ORDER BY repair_date DESC LIMIT 10",
                        [(int) $veh->id]
                    );
                } else {
                    $historyNote = ' Repair History is switched off (Plan #38 is experimental).';
                }

                $bits = [];
                foreach ($tickets as $t) {
                    $cost = $t->actual_cost !== null ? '₱' . number_format((float) $t->actual_cost, 2)
                        : ($t->estimated_cost !== null ? 'est ₱' . number_format((float) $t->estimated_cost, 2) : 'no cost');
                    $bits[] = 'repair #' . $t->id . ' ' . $t->status . ' — ' . $t->title . ' (' . $cost . ')';
                }
                foreach ($history as $h) {
                    $bits[] = $h->repair_date . ' ' . $h->nature_of_repair . ' — ₱'
                        . number_format((float) $h->total_amount, 2);
                }
                if ($bits === []) {
                    $bits[] = 'no repairs recorded';
                }

                return [
                    'summary' => $veh->plate_number . ' — ' . count($tickets) . ' repair ticket(s), '
                        . count($history) . ' repair-history entr' . (count($history) === 1 ? 'y' : 'ies')
                        . '. ' . implode(' · ', $bits) . '.' . $historyNote,
                    'data' => [
                        'plate' => $veh->plate_number,
                        'tickets' => array_map(static fn($t) => [
                            'id' => (int) $t->id, 'title' => $t->title, 'status' => $t->status,
                            'actual_cost' => $t->actual_cost,
                        ], $tickets),
                        'repair_history' => array_map(static fn($h) => [
                            'date' => $h->repair_date, 'nature' => $h->nature_of_repair,
                            'amount' => (float) $h->total_amount,
                        ], $history),
                    ],
                    'link' => '/?page=maintenance&action=view&id=' . (int) ($tickets[0]->id ?? 0),
                ];
            },
        ];

        $tools['driver_availability'] = [
            'label' => 'Checking driver availability',
            'description' => 'List drivers with their current status — available, on trip, on leave or unavailable.',
            'schema' => [
                'type' => 'object',
                'properties' => ['status' => ['type' => 'string', 'maxLength' => 20]],
                'required' => [],
            ],
            'mutating' => false,
            // Mirrors the Drivers screen (approver and above).
            'allowed' => static fn(): bool => isApprover() || isAdmin() || isMotorpool() || isRealAllFather(),
            'handler' => static function (array $args): array {
                $status = trim((string) ($args['status'] ?? ''));
                $allowed = [DRIVER_AVAILABLE, DRIVER_ON_TRIP, DRIVER_ON_LEAVE, DRIVER_UNAVAILABLE];
                if ($status !== '' && !in_array($status, $allowed, true)) {
                    $status = '';
                }
                $sql = "SELECT d.id, d.license_number, d.status, u.name, u.email, r.id AS trip_id
                        FROM drivers d
                        LEFT JOIN users u ON u.id = d.user_id AND u.deleted_at IS NULL
                        LEFT JOIN requests r ON r.driver_id = d.id AND r.deleted_at IS NULL
                               AND r.status = 'approved' AND r.actual_dispatch_datetime IS NOT NULL
                               AND r.actual_arrival_datetime IS NULL
                        WHERE d.deleted_at IS NULL";
                $params = [];
                if ($status !== '') {
                    $sql .= ' AND d.status = ?';
                    $params[] = $status;
                }
                $sql .= ' ORDER BY d.status, u.name LIMIT 25';
                $rows = db()->fetchAll($sql, $params);

                $counts = ['available' => 0, 'on_trip' => 0, 'on_leave' => 0, 'unavailable' => 0];
                foreach (db()->fetchAll(
                    "SELECT status, COUNT(*) c FROM drivers WHERE deleted_at IS NULL GROUP BY status"
                ) as $r) {
                    $counts[(string) $r->status] = (int) $r->c;
                }

                $bits = array_map(static fn($r) => ($r->name ?: ('driver #' . $r->id))
                    . ' — ' . $r->status . ($r->trip_id ? ' (trip #' . $r->trip_id . ')' : ''), $rows);

                $headline = sprintf(
                    'Drivers: %d available, %d on trip, %d on leave, %d unavailable.',
                    $counts['available'], $counts['on_trip'], $counts['on_leave'], $counts['unavailable']
                );

                return [
                    'summary' => $headline . ($bits === [] ? '' : ' ' . implode(' · ', array_slice($bits, 0, 12))),
                    'data' => ['counts' => $counts, 'drivers' => array_map(static fn($r) => [
                        'id' => (int) $r->id, 'name' => $r->name, 'status' => $r->status,
                        'trip_id' => $r->trip_id ? (int) $r->trip_id : null,
                    ], $rows)],
                    'link' => '/?page=drivers',
                ];
            },
        ];

        $tools['audit_trail_lookup'] = [
            'label' => 'Checking the audit trail',
            'description' => 'Show who did what to a record — approvals, rollbacks, status changes — with timestamps and comments.',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'reference' => ['type' => 'string', 'maxLength' => 40],
                    'entity' => ['type' => 'string', 'maxLength' => 20],
                ],
                'required' => ['reference'],
            ],
            'mutating' => false,
            // Mirrors the Audit Logs screen (admin / All Father only). View-as
            // narrows this to the impersonated role, like every other gate.
            'allowed' => static fn(): bool => isAdmin() || (isRealAllFather() && !isViewingAs()),
            'handler' => static function (array $args): array {
                $ref = trim((string) ($args['reference'] ?? ''));
                $entity = strtolower(trim((string) ($args['entity'] ?? ''))) ?: 'request';
                $map = [
                    'request' => ['request', 'id'],
                    'trip' => ['request', 'id'],
                    'ob' => ['ob_request', 'id'],
                    'voucher' => ['gas_voucher', 'id'],
                    'gas' => ['gas_voucher', 'id'],
                    'maintenance' => ['maintenance_request', 'id'],
                    'repair' => ['vehicle_repair_entry', 'id'],
                    'vehicle' => ['vehicle', 'id'],
                ];
                if (!isset($map[$entity])) {
                    return ['summary' => 'Unknown record type. Use request, ob, voucher, maintenance, repair or vehicle.', 'data' => [], 'link' => null];
                }
                $ref = ltrim($ref, '#');
                if (!ctype_digit($ref)) {
                    return ['summary' => 'That does not look like a record id. Use a number, e.g. 679.', 'data' => [], 'link' => null];
                }
                [$type, $col] = $map[$entity];
                $rows = db()->fetchAll(
                    "SELECT a.action, a.entity_type, a.entity_id, a.created_at, u.name AS actor, a.new_data
                     FROM audit_logs a
                     LEFT JOIN users u ON u.id = a.user_id
                     WHERE a.entity_type = ? AND a.entity_id = ?
                     ORDER BY a.id DESC LIMIT 25",
                    [$type, (int) $ref]
                );

                if ($rows === []) {
                    return ['summary' => 'No audit entries for ' . $entity . ' #' . $ref . ' you can see.', 'data' => [], 'link' => null];
                }
                $bits = array_map(static fn($r) => formatDateTime($r->created_at) . ' '
                    . ($r->actor ?: 'system') . ' — ' . $r->action, $rows);
                return [
                    'summary' => count($rows) . ' audit entr' . (count($rows) === 1 ? 'y' : 'ies')
                        . ' for ' . $entity . ' #' . $ref . ': ' . implode(' · ', array_slice($bits, 0, 10)),
                    'data' => array_map(static fn($r) => [
                        'at' => $r->created_at, 'actor' => $r->actor, 'action' => $r->action,
                    ], $rows),
                    'link' => '/?page=audit&search=' . rawurlencode($ref),
                ];
            },
        ];

        // ---------------------------------------------------------------
        // Guided tools — verify the caller may act, then hand off to the
        // real screen. The approval state machines (pages/approvals/process.php,
        // pages/ob-requests/process.php) are NOT duplicated here: a second copy
        // would drift and silently diverge from the audited UI path.
        // ---------------------------------------------------------------

        $tools['prepare_trip_decision'] = [
            'label' => 'Checking whether you can decide a trip request',
            'description' => 'Check whether the signed-in user may approve, reject or revise a trip request, and open that request\'s approval screen.',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'request_id' => ['type' => 'integer'],
                    'decision' => ['type' => 'string', 'maxLength' => 20],
                ],
                'required' => ['request_id', 'decision'],
            ],
            'mutating' => false,
            'allowed' => static fn(): bool => isApprover() || isAdmin(),
            'handler' => static function (array $args): array {
                $id = (int) ($args['request_id'] ?? 0);
                $decision = mb_strtolower(trim((string) ($args['decision'] ?? '')));
                if (!in_array($decision, ['approve', 'reject', 'revision'], true)) {
                    return ['summary' => 'The decision must be approve, reject or revision.', 'data' => [], 'link' => null];
                }
                $r = db()->fetch(
                    "SELECT id, status, approver_id, motorpool_head_id, destination
                     FROM requests WHERE id = ? AND deleted_at IS NULL",
                    [$id]
                );
                if (!$r) {
                    return ['summary' => 'Request #' . $id . ' was not found.', 'data' => [], 'link' => null];
                }
                $isAssignedApprover = (int) $r->approver_id === (int) userId();
                $isAssignedMotorpool = (int) $r->motorpool_head_id === (int) userId();
                if (!$isAssignedApprover && !$isAssignedMotorpool && !isAdmin()) {
                    auditLog('ai_tool_denied', 'request', $id, null, ['tool' => 'prepare_trip_decision', 'decision' => $decision]);
                    return [
                        'summary' => 'You are not the approver for request #' . $id . ', so you cannot decide it.',
                        'data' => [], 'link' => null,
                    ];
                }
                if ($r->status !== STATUS_PENDING && $r->status !== STATUS_PENDING_MOTORPOOL && $r->status !== STATUS_REVISION) {
                    return [
                        'summary' => 'Request #' . $id . ' is "' . $r->status . '" and is not waiting for a decision.',
                        'data' => ['id' => $id, 'status' => $r->status], 'link' => null,
                    ];
                }
                return [
                    'summary' => 'You can ' . $decision . ' request #' . $id . ' (' . $r->destination . '). '
                        . 'The decision has to be confirmed on the approval screen, where it is logged with your identity.',
                    'data' => ['id' => $id, 'decision' => $decision, 'may_act' => true],
                    'link' => '/?page=approvals&action=view&id=' . $id,
                ];
            },
        ];

        $tools['prepare_ob_decision'] = [
            'label' => 'Checking whether you can act on a pass slip',
            'description' => 'Check whether the signed-in user may act on an OB Pass Slip, and open that slip.',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'ob_request_id' => ['type' => 'integer'],
                    'decision' => ['type' => 'string', 'maxLength' => 20],
                ],
                'required' => ['ob_request_id', 'decision'],
            ],
            'mutating' => false,
            'allowed' => static fn(): bool => isApprover() || isAdmin(),
            'handler' => static function (array $args): array {
                $id = (int) ($args['ob_request_id'] ?? 0);
                $ob = db()->fetch(
                    "SELECT id, pass_slip_no, status, supervisor_user_id, motorpool_head_id, uses_official_vehicle, purpose
                     FROM ob_requests WHERE id = ? AND deleted_at IS NULL",
                    [$id]
                );
                if (!$ob) {
                    return ['summary' => 'Pass slip #' . $id . ' was not found.', 'data' => [], 'link' => null];
                }
                $supervisor = (int) $ob->supervisor_user_id === (int) userId();
                $motorpool = isMotorpool() && !empty($ob->uses_official_vehicle);
                if (!$supervisor && !$motorpool) {
                    auditLog('ai_tool_denied', 'ob_request', $id, null, ['tool' => 'prepare_ob_decision']);
                    return ['summary' => 'You are not holding pass slip #' . $ob->pass_slip_no . ', so you cannot decide it.', 'data' => [], 'link' => null];
                }
                return [
                    'summary' => 'Pass slip ' . $ob->pass_slip_no . ' is "' . $ob->status . '" and is yours to act on. '
                        . 'Confirm the action on the slip itself.',
                    'data' => ['id' => $id, 'status' => $ob->status],
                    'link' => '/?page=ob-requests&action=view&id=' . $id,
                ];
            },
        ];

        // ---------------------------------------------------------------
        // Mutating tools — confirmed by a signed, 2-minute, single-user token
        // ---------------------------------------------------------------

        // NOTE: there is deliberately NO "create a trip draft" tool. This app has
        // no draft lifecycle — requests/create.php always submits straight to
        // `pending`, and pages/requests/edit.php will edit a draft but nothing
        // ever promotes it. A tool that manufactures such rows would create
        // requests nobody can finish. Add the tool only together with a real
        // draft -> pending transition.

        // ---------------------------------------------------------------
        // Executing actions (Plan #40, All Father only).
        //
        // These are NOT handlers in the usual sense: they share the screens'
        // implementation (includes/maintenance_service.php,
        // includes/rollback_service.php) and run only after All Father has seen
        // a before/after diff and typed a confirmation phrase bound to this
        // exact tool+args. See includes/ai_actions.php.
        //
        // rollback_request is exposed because pages/requests/rollback.php now
        // calls rollbackServiceRun() — screen and assistant share one
        // implementation, so they cannot drift apart.
        // ---------------------------------------------------------------
        if (function_exists('aiActionsAllowed')) {
            foreach (aiActionDefinitions() as $id => $def) {
                $tools[$id] = [
                    'label' => $def['label'],
                    'description' => $def['description'] . ' Requires All Father to confirm a before/after diff.',
                    'schema' => [
                        'type' => 'object',
                        'properties' => $def['args'],
                        'required' => array_keys($def['args']),
                    ],
                    'mutating' => true,
                    'is_action' => true,
                    'allowed' => static fn(): bool => aiActionsAllowed(),
                    // Never executed through aiToolExecute() — the endpoint
                    // routes action tools to aiActionPreview()/aiActionRun() so
                    // the typed confirmation cannot be bypassed. This handler
                    // exists only so the registry stays uniform, and it returns
                    // the preview rather than performing anything.
                    'handler' => static function (array $args) use ($id): array {
                        $preview = aiActionPreview($id, $args);
                        return [
                            'summary' => $preview['ok']
                                ? $preview['summary'] . ' NOT APPLIED — awaiting typed confirmation.'
                                : $preview['error'],
                            'data' => ['before' => $preview['before'], 'after' => $preview['after'], 'phrase' => $preview['phrase']],
                            'link' => $preview['link'],
                        ];
                    },
                ];
            }
        }

        $tools['propose_care'] = [
            'label' => 'Proposing a vehicle care item',
            'description' => 'Propose a vehicle care item for Motorpool to schedule. Creates a PENDING care item that an approver must still approve.',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'vehicle_id' => ['type' => 'integer'],
                    'title' => ['type' => 'string', 'maxLength' => 255],
                    'due_date' => ['type' => 'string', 'maxLength' => 20],
                ],
                'required' => ['vehicle_id', 'title', 'due_date'],
            ],
            'mutating' => true,
            'allowed' => static function (): bool {
                require_once INCLUDES_PATH . '/vehicle_care.php';
                return canProposeCareSchedule();
            },
            'handler' => static function (array $args): array {
                require_once INCLUDES_PATH . '/vehicle_care.php';
                $vehicleId = (int) ($args['vehicle_id'] ?? 0);
                $title = trim((string) ($args['title'] ?? ''));
                $due = trim((string) ($args['due_date'] ?? ''));

                $errors = [];
                // Existence first: ops roles pass canViewCareVehicle() for any id,
                // so a bogus id would otherwise fall through to a foreign-key error.
                if (!db()->fetch("SELECT id FROM vehicles WHERE id = ? AND deleted_at IS NULL", [$vehicleId])) {
                    $errors[] = 'that vehicle does not exist';
                } elseif (!canViewCareVehicle($vehicleId)) {
                    $errors[] = 'you cannot see that vehicle';
                }
                if ($title === '' || mb_strlen($title) > 255) {
                    $errors[] = 'title is required (max 255 characters)';
                }
                if ($due === '' || !strtotime($due)) {
                    $errors[] = 'due_date must be a real date';
                }
                if ($errors) {
                    return ['summary' => 'Could not propose the care item: ' . implode('; ', $errors), 'data' => [], 'link' => null];
                }

                $id = db()->insert('vehicle_care_schedules', [
                    'vehicle_id' => $vehicleId,
                    'care_type' => CARE_TYPE_OTHER,
                    'title' => $title,
                    'notes' => 'Proposed via the AI assistant.',
                    'due_date' => date('Y-m-d', strtotime($due)),
                    'status' => CARE_STATUS_PENDING,
                    'proposed_by' => userId(),
                    'created_at' => date(DATETIME_FORMAT),
                ]);

                auditLog('ai_care_proposed', 'vehicle_care_schedule', $id, null, [
                    'vehicle_id' => $vehicleId, 'title' => $title, 'due_date' => date('Y-m-d', strtotime($due)),
                    'by_tool' => 'propose_care',
                ]);

                return [
                    'summary' => 'Care item #' . $id . ' ("' . $title . '", due ' . formatDate(date('Y-m-d', strtotime($due)))
                        . ') was proposed and is now pending approval.',
                    'data' => ['id' => $id, 'status' => CARE_STATUS_PENDING],
                    'link' => '/?page=maintenance&action=care-edit&id=' . $id,
                ];
            },
        ];

        // The key is the tool name the model may call; mirror it into the entry
        // so consumers never have to look it back up.
        foreach ($tools as $id => $tool) {
            $tool['id'] = $id;
            $tools[$id] = $tool;
        }

        return $tools;
    }

    /**
     * Authorisation + execution, with the audit trail Plan #40 requires.
     *
     * @return array{ok:bool, summary:string, data:array, link:?string, error:string}
     */
    function aiToolExecute(string $toolId, array $args): array
    {
        $tools = aiToolRegistry();
        if (!isset($tools[$toolId])) {
            auditLog('ai_tool_rejected', 'ai_tool', null, null, ['tool' => $toolId, 'reason' => 'not in registry']);
            return ['ok' => false, 'summary' => '', 'data' => [], 'link' => null, 'error' => 'Unknown tool.'];
        }
        $tool = $tools[$toolId];
        $clean = aiSanitizeToolArgs($toolId, $args, $tools);

        // Action tools (Plan #40) must go through aiActionRun(), which demands a
        // typed confirmation phrase. Reaching them here would bypass that, so
        // this path refuses rather than executes.
        if (!empty($tool['is_action'])) {
            auditLog('ai_tool_blocked', 'ai_tool', null, null, [
                'tool' => $toolId, 'reason' => 'action tools require a typed confirmation',
            ]);
            return [
                'ok' => false, 'summary' => '', 'data' => [], 'link' => null,
                'error' => 'This action must be confirmed with its before/after diff.',
            ];
        }

        // Re-check authorisation at execute time (never trust the model).
        $allowed = false;
        try {
            $allowed = (bool) ($tool['allowed'])();
        } catch (Throwable $e) {
            error_log('aiToolExecute allowed() threw for ' . $toolId . ': ' . $e->getMessage());
            $allowed = false;
        }
        if (!$allowed) {
            auditLog('ai_tool_denied', 'ai_tool', null, null, [
                'tool' => $toolId,
                'args' => $clean,
                'role' => userRole(),
                'view_as' => isViewingAs() ? getViewAsRole() : null,
            ]);
            return ['ok' => false, 'summary' => '', 'data' => [], 'link' => null, 'error' => 'You are not allowed to do that.'];
        }

        try {
            $result = ($tool['handler'])($clean);
        } catch (Throwable $e) {
            error_log('aiToolExecute handler error for ' . $toolId . ': ' . $e->getMessage());
            auditLog('ai_tool_failed', 'ai_tool', null, null, ['tool' => $toolId, 'args' => $clean]);
            // User-safe: no stack traces, no SQL, no paths.
            return ['ok' => false, 'summary' => '', 'data' => [], 'link' => null, 'error' => 'That action could not be completed.'];
        }

        auditLog('ai_tool_executed', 'ai_tool', null, null, [
            'tool' => $toolId,
            'mutating' => !empty($tool['mutating']),
            'args' => $clean,
            'summary' => mb_substr((string) ($result['summary'] ?? ''), 0, 300),
        ]);

        return [
            'ok' => true,
            'summary' => (string) ($result['summary'] ?? ''),
            'data' => (array) ($result['data'] ?? []),
            'link' => $result['link'] ?? null,
            'error' => '',
        ];
    }

    /**
     * Is Plan #38's Repair History switched on?
     *
     * vehicle_service_history must not silently report "no history" when the
     * feature is simply off — the reply says so instead.
     */
    function aiRepairHistoryAvailable(): bool
    {
        if (!function_exists('repairHistoryEnabled')) {
            require_once INCLUDES_PATH . '/repair_history.php';
        }
        return repairHistoryEnabled();
    }

    /**
     * Only the tools the signed-in user may actually use.
     *
     * The endpoint sends THIS list to the provider, so a requester or guard is
     * never even told that ops-only actions like propose_care exist. Defence in
     * depth: aiToolExecute() re-checks the same predicate at execute time, so a
     * role change between the proposal and the confirm cannot escalate either.
     *
     * @return array<string, array<string,mixed>>
     */
    function aiToolsForCurrentUser(): array
    {
        $out = [];
        foreach (aiToolRegistry() as $id => $tool) {
            try {
                $allowed = (bool) ($tool['allowed'])();
            } catch (Throwable $e) {
                error_log('aiToolsForCurrentUser: allowed() threw for ' . $id . ': ' . $e->getMessage());
                $allowed = false;
            }
            if ($allowed) {
                $out[$id] = $tool;
            }
        }
        return $out;
    }

    /** Tool descriptions for the chat UI's "what can you do" hint. */
    function aiToolSummaries(): array
    {
        $out = [];
        foreach (aiToolRegistry() as $id => $tool) {
            $out[] = [
                'id' => $id,
                'label' => aiToolLabel($id),
                'description' => $tool['description'],
                'mutating' => !empty($tool['mutating']),
            ];
        }
        return $out;
    }

    /**
     * Short human phrase for a tool call, shown in the chat as the action trace:
     *   "Searching your trip requests — query: SBY 225"
     */
    function aiToolLabel(string $toolId): string
    {
        return aiToolRegistry()[$toolId]['label'] ?? 'Running an action';
    }

    /**
     * Render tool arguments as a short, readable fragment (never a JSON blob).
     * The UI escapes whatever comes back, so a model-supplied value can never
     * smuggle markup.
     *
     * @return string
     */
    function aiToolArgSummary(string $toolId, array $args): string
    {
        $parts = [];
        foreach ($args as $k => $v) {
            $name = str_replace('_', ' ', (string) $k);
            if (is_bool($v)) {
                $parts[] = $name . ': ' . ($v ? 'yes' : 'no');
            } elseif (is_numeric($v)) {
                $parts[] = $name . ': ' . $v;
            } else {
                $s = trim((string) $v);
                if ($s === '') {
                    continue;
                }
                $parts[] = $name . ': ' . mb_substr($s, 0, 60) . (mb_strlen($s) > 60 ? '…' : '');
            }
        }
        return implode(' · ', $parts);
    }

    /** The full trace line for a tool call, e.g. "Searching your trips — query: SBY 225". */
    function aiToolTraceLine(string $toolId, array $args): string
    {
        $label = aiToolLabel($toolId);
        $fragment = aiToolArgSummary($toolId, $args);
        return $fragment === '' ? $label : $label . ' — ' . $fragment;
    }
}