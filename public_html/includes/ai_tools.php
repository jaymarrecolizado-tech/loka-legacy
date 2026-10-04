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
        // Guided tools — verify the caller may act, then hand off to the
        // real screen. The approval state machines (pages/approvals/process.php,
        // pages/ob-requests/process.php) are NOT duplicated here: a second copy
        // would drift and silently diverge from the audited UI path.
        // ---------------------------------------------------------------

        $tools['prepare_trip_decision'] = [
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

        $tools['propose_care'] = [
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

    /** Tool descriptions for the chat UI's "what can you do" hint. */
    function aiToolSummaries(): array
    {
        $out = [];
        foreach (aiToolRegistry() as $id => $tool) {
            $out[] = [
                'id' => $id,
                'description' => $tool['description'],
                'mutating' => !empty($tool['mutating']),
            ];
        }
        return $out;
    }
}