<?php
/**
 * LOKA - Shared trip-rollback service (Plan #40 groundwork)
 *
 * The admin rollback transaction lives here so the confirmation screen
 * (pages/requests/rollback.php) and the AI assistant (includes/ai_actions.php)
 * share ONE implementation. Two copies would mean two ways to release a vehicle
 * and void a trip ticket — exactly the drift that turns a convenience into a
 * second, unaudited approval path.
 *
 * Split deliberately into plan + run:
 *   rollbackServicePlan()  — resolves the target and computes every side effect,
 *                            writing NOTHING (used for the assistant's diff)
 *   rollbackServiceRun()   — performs the transaction, re-validating first
 *
 * Both return ['ok'=>bool,'error'=>string,'summary'=>string,'before'=>array,'after'=>array].
 */

if (!defined('ROLLBACK_SERVICE_LOADED')) {

    define('ROLLBACK_SERVICE_LOADED', 1);

    /** Load a request plus the fields the rollback rules read. */
    function rollbackServiceLoadRequest(int $id, bool $forUpdate = false): ?object
    {
        return db()->fetch(
            "SELECT r.*, v.status AS vehicle_status, v.plate_number
             FROM requests r
             LEFT JOIN vehicles v ON v.id = r.vehicle_id
             WHERE r.id = ? AND r.deleted_at IS NULL"
            . ($forUpdate ? ' FOR UPDATE' : ''),
            [$id]
        );
    }

    /**
     * What would rolling this request back to $targetKey do? Writes nothing.
     */
    function rollbackServicePlan(object $request, string $targetKey, string $reason): array
    {
        $fail = static fn(string $e): array => [
            'ok' => false, 'error' => $e, 'summary' => '', 'before' => [], 'after' => [],
        ];

        $order = array_keys(ROLLBACK_TRIP_STAGES);
        $target = rollbackTripTargetByKey($request, $targetKey);
        if ($target === null) {
            return $fail('That workflow stage is not a valid rollback target right now.');
        }
        if (strlen($reason) < 10) {
            return $fail('Please provide a reason (at least 10 characters).');
        }

        $currentStage = rollbackTripCurrentStage($request);
        $targetIdx = (int) array_search($target['status'], $order, true);
        $currentIdx = (int) array_search($currentStage, $order, true);
        $hadDispatch = !empty($request->actual_dispatch_datetime);

        // Identical rule to the screen: the vehicle is only freed when this
        // request stops holding it. A same-stage undo (dispatched/arrived ->
        // approved) deliberately keeps the assignment so it cannot double-book.
        $leavingAssigned = ($targetIdx <= (int) array_search('pending_motorpool', $order, true))
            || ((string) $request->status === STATUS_COMPLETED);

        $releasesVehicle = false;
        $releasesDriver = false;
        if ($leavingAssigned && $currentIdx >= (int) array_search('approved', $order, true)) {
            if ($request->vehicle_id) {
                $other = db()->fetchColumn(
                    "SELECT COUNT(*) FROM requests
                     WHERE vehicle_id = ? AND id != ? AND status = 'approved' AND deleted_at IS NULL",
                    [$request->vehicle_id, (int) $request->id]
                );
                $releasesVehicle = !$other;
            }
            if ($request->driver_id) {
                $otherDrv = db()->fetchColumn(
                    "SELECT COUNT(*) FROM requests
                     WHERE driver_id = ? AND id != ? AND status = 'approved' AND deleted_at IS NULL",
                    [$request->driver_id, (int) $request->id]
                );
                $releasesDriver = !$otherDrv;
            }
        }

        $clearsGuard = $hadDispatch && $targetIdx < (int) array_search('dispatched', $order, true);

        $ticketEffect = null;
        if (in_array($request->status, [STATUS_APPROVED, STATUS_COMPLETED], true)) {
            $ticketEffect = $target['status'] === STATUS_APPROVED ? 'cancelled' : 'voided';
        }

        $bits = ['request #' . (int) $request->id . ': ' . $currentStage . ' -> ' . $target['label']];
        if ($releasesVehicle) {
            $bits[] = 'vehicle ' . ($request->plate_number ?: $request->vehicle_id) . ' released to available';
        }
        if ($releasesDriver) {
            $bits[] = 'driver #' . $request->driver_id . ' released to available';
        }
        if ($clearsGuard) {
            $bits[] = 'guard dispatch/arrival cleared';
        }
        if ($ticketEffect !== null) {
            $bits[] = 'trip tickets ' . $ticketEffect;
        }
        $bits[] = 'workflow step -> ' . $target['step'];
        $bits[] = 'requester and target approver notified';

        return [
            'ok' => true,
            'error' => '',
            'summary' => 'Rollback request #' . (int) $request->id . ' to ' . $target['label'] . ' — '
                . implode('; ', $bits) . '.',
            'before' => [
                'status' => $request->status,
                'stage' => $currentStage,
                'actual_dispatch_datetime' => $request->actual_dispatch_datetime,
                'actual_arrival_datetime' => $request->actual_arrival_datetime,
                'rollback_count' => (int) $request->rollback_count,
            ],
            'after' => [
                'status' => $target['status'],
                'stage' => $target['label'],
                'actual_dispatch_datetime' => $clearsGuard ? null : $request->actual_dispatch_datetime,
                'rollback_count' => (int) $request->rollback_count + 1,
                'releases_vehicle' => $releasesVehicle,
                'releases_driver' => $releasesDriver,
                'clears_guard' => $clearsGuard,
                'trip_tickets' => $ticketEffect,
            ],
            'target' => $target,
            'reason' => $reason,
        ];
    }

    /** Preview by id — safe to call for a before/after diff; writes nothing. */
    function rollbackServicePreview(int $requestId, string $targetKey, string $reason): array
    {
        $request = rollbackServiceLoadRequest($requestId);
        if (!$request) {
            return ['ok' => false, 'error' => 'Request not found.', 'summary' => '', 'before' => [], 'after' => []];
        }
        $plan = rollbackServicePlan($request, $targetKey, $reason);
        if (!empty($plan['ok'])) {
            $plan['link'] = '/?page=rollback&action=process&id=' . $requestId;
        }
        return $plan;
    }

    /**
     * Perform the rollback. Re-validates target and reason inside the
     * transaction — a preview is never trusted to still be true.
     */
    function rollbackServiceRun(int $requestId, string $targetKey, string $reason, int $actorId): array
    {
        try {
            db()->beginTransaction();

            $request = rollbackServiceLoadRequest($requestId, true);
            if (!$request) {
                throw new Exception('Request not found.');
            }
            $plan = rollbackServicePlan($request, $targetKey, $reason);
            if (!$plan['ok']) {
                throw new Exception($plan['error']);
            }
            $target = $plan['target'];
            $now = date(DATETIME_FORMAT);

            if (!empty($plan['after']['releases_vehicle']) && $request->vehicle_id) {
                db()->update('vehicles', ['status' => VEHICLE_AVAILABLE, 'updated_at' => $now],
                    'id = ?', [$request->vehicle_id]);
            }
            if (!empty($plan['after']['releases_driver']) && $request->driver_id) {
                db()->update('drivers', ['status' => DRIVER_AVAILABLE, 'updated_at' => $now],
                    'id = ?', [$request->driver_id]);
            }
            if (!empty($plan['after']['clears_guard'])) {
                db()->query(
                    "UPDATE requests
                     SET actual_dispatch_datetime = NULL, actual_arrival_datetime = NULL,
                         dispatch_guard_id = NULL, arrival_guard_id = NULL
                     WHERE id = ?",
                    [$requestId]
                );
            }
            if (!empty($plan['after']['trip_tickets'])) {
                if ($plan['after']['trip_tickets'] === 'cancelled') {
                    db()->query(
                        "UPDATE trip_tickets SET status = 'cancelled', updated_at = ?
                         WHERE request_id = ? AND deleted_at IS NULL",
                        [$now, $requestId]
                    );
                } else {
                    db()->query(
                        "UPDATE trip_tickets SET deleted_at = ? WHERE request_id = ? AND deleted_at IS NULL",
                        [$now, $requestId]
                    );
                }
            }

            db()->query(
                "UPDATE requests SET status = ?, rollback_count = rollback_count + 1, updated_at = ? WHERE id = ?",
                [$target['status'], $now, $requestId]
            );

            // Reset the workflow step (non-destructive: approvals keeps history).
            try {
                if (db()->fetch("SELECT id FROM approval_workflow WHERE request_id = ?", [$requestId])) {
                    db()->update('approval_workflow', [
                        'step'       => $target['step'],
                        'status'     => $target['status'] === STATUS_APPROVED ? 'approved' : 'pending',
                        'action_at'  => null,
                        'comments'   => 'Rolled back by admin: ' . $reason,
                        'updated_at' => $now,
                    ], 'request_id = ?', [$requestId]);
                }
            } catch (Throwable $e) {
                error_log('Rollback workflow reset failed (non-critical): ' . $e->getMessage());
            }

            db()->insert('approvals', [
                'request_id'    => $requestId,
                'approver_id'   => $actorId,
                'approval_type' => $target['step'],
                'status'        => 'rollback',
                'comments'      => $reason,
                'created_at'    => $now,
            ]);

            auditLog('request_rollback', 'request', $requestId, $plan['before'], [
                'status'         => $target['status'],
                'target_stage'   => $targetKey,
                'reason'         => $reason,
                'rolled_back_by' => $actorId,
                'dispatch_records_cleared' => (bool) $plan['after']['clears_guard'],
                'trip_tickets'   => $plan['after']['trip_tickets'],
                'via'            => 'rollback_service',
            ]);

            db()->commit();
        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollback();
            }
            error_log('rollbackServiceRun error: ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage(), 'summary' => '', 'before' => [], 'after' => []];
        }

        // Notifications strictly after commit — a rolled-back transaction must
        // never email anybody.
        try {
            $label = $target['label'];
            $request = rollbackServiceLoadRequest($requestId);
            if ($request) {
                if ((int) $request->user_id !== $actorId) {
                    @notify(
                        (int) $request->user_id,
                        'request_rolled_back',
                        'Request Rolled Back',
                        "Request #{$requestId} ({$request->destination}) has been rolled back to: {$label}.\n\nReason: {$reason}",
                        '/?page=requests&action=view&id=' . $requestId,
                        $requestId
                    );
                }
                $targetApprover = $target['status'] === STATUS_PENDING_MOTORPOOL
                    ? ($request->motorpool_head_id ?: null)
                    : ($target['status'] === STATUS_PENDING ? ($request->approver_id ?: null) : null);
                if ($targetApprover) {
                    @notify(
                        (int) $targetApprover,
                        'request_rolled_back',
                        'Request Rolled Back to You',
                        "Request #{$requestId} ({$request->destination}) has been rolled back to your approval level ({$label})."
                        . "\n\nReason: " . $reason,
                        '/?page=approvals&action=view&id=' . $requestId,
                        $requestId
                    );
                }
            }
        } catch (Throwable $e) {
            error_log('Rollback notifications failed: ' . $e->getMessage());
        }

        unset($plan['target'], $plan['reason']);
        return $plan;
    }
}
