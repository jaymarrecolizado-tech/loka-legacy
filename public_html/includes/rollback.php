<?php
/**
 * LOKA - Workflow stage matrices for admin rollback (Plan #39)
 *
 * One place that knows, for each of the three workflows (trips, OB Pass Slips,
 * gas vouchers), what "stage" a record is in, which earlier stages an admin may
 * roll it back to, and exactly what gets reversed on the way.
 *
 * Consumers must require this file themselves:
 *   require_once INCLUDES_PATH . '/rollback.php';
 */

if (!defined('ROLLBACK_STAGES_LOADED')) {

    define('ROLLBACK_STAGES_LOADED', 1);

    /** Ordered trip-request stages. Side statuses (revision/rejected) sit outside. */
    define('ROLLBACK_TRIP_STAGES', [
        'pending'           => ['label' => 'Pending Department Approval', 'step' => 'department'],
        'pending_motorpool' => ['label' => 'Pending Motorpool Approval', 'step' => 'motorpool'],
        'approved'          => ['label' => 'Approved',                  'step' => 'motorpool'],
        'dispatched'        => ['label' => 'Dispatched',                'step' => 'motorpool'],
        'arrived'           => ['label' => 'Arrived',                   'step' => 'motorpool'],
        'completed'         => ['label' => 'Completed',                 'step' => 'motorpool'],
    ]);

    /**
     * Stages an admin may actually roll back TO.
     *
     * `dispatched` / `arrived` are guard states, not approval stages — you
     * cannot "set" a request to Dispatched, the guard does that. They exist in
     * ROLLBACK_TRIP_STAGES only so the stepper can show where the request is;
     * rolling back to Approved from either of them simply clears the guard
     * transaction (Plan #39 decision A).
     */
    define('ROLLBACK_TRIP_TARGET_STAGES', ['pending', 'pending_motorpool', 'approved', 'completed']);

    define('ROLLBACK_OB_STAGES', [
        'pending_supervisor' => ['label' => 'Pending Supervisor Approval'],
        'pending_motorpool'  => ['label' => 'Pending Motorpool Approval'],
        'approved'           => ['label' => 'Approved'],
        'departed'           => ['label' => 'Departed'],
        'coa_received'       => ['label' => 'CoA Received'],
        'completed'          => ['label' => 'Completed'],
    ]);

    define('ROLLBACK_GAS_STAGES', [
        'draft'           => ['label' => 'Draft'],
        'pending_review'  => ['label' => 'Pending Review'],
        'pending_budget'  => ['label' => 'Pending Budget Certification'],
        'pending_approval' => ['label' => 'Pending Final Approval'],
        'approved'        => ['label' => 'Approved'],
    ]);

    /** Side statuses that are never a stage but can be rolled back. */
    define('ROLLBACK_TRIP_SIDE_STATUSES', ['revision' => 'For Revision', 'rejected' => 'Rejected']);
    define('ROLLBACK_TERMINAL_STATUSES', ['cancelled' => 'Cancelled']);

    // -----------------------------------------------------------------
    // Trips
    // -----------------------------------------------------------------

    /**
     * Which trip stage a request currently sits in.
     *
     * Dispatched/arrived are not stored statuses — they are derived from the
     * guard columns, which is exactly what made the old UI confusing.
     */
    function rollbackTripCurrentStage(object $request): string
    {
        $status = (string) $request->status;
        if ($status === STATUS_COMPLETED) {
            return 'completed';
        }
        if (in_array($status, [STATUS_REVISION, STATUS_REJECTED], true)) {
            // Came back from motorpool; both approval stages are "earlier".
            return 'approved';
        }
        if ($status === STATUS_APPROVED) {
            if (!empty($request->actual_arrival_datetime)) {
                return 'arrived';
            }
            if (!empty($request->actual_dispatch_datetime)) {
                return 'dispatched';
            }
            return 'approved';
        }
        return in_array($status, array_keys(ROLLBACK_TRIP_STAGES), true) ? $status : STATUS_PENDING;
    }

    /**
     * Rollback targets for a trip request, earliest stage first.
     *
     * @return list<array{key:string,status:string,label:string,step:string,stage:string,
     *                    same_stage:bool,effects:list<string>}>
     */
    function rollbackTripTargets(object $request): array
    {
        $order = array_keys(ROLLBACK_TRIP_STAGES);
        $current = rollbackTripCurrentStage($request);
        $currentIdx = (int) array_search($current, $order, true);
        $hasArrival = !empty($request->actual_arrival_datetime);
        $afterDispatch = ($current === 'dispatched' || $current === 'arrived');

        $targets = [];
        foreach (ROLLBACK_TRIP_TARGET_STAGES as $stage) {
            $idx = (int) array_search($stage, $order, true);
            if ($idx >= $currentIdx) {
                continue;   // forward-only stages are not rollback targets
            }
            $label = ROLLBACK_TRIP_STAGES[$stage]['label'];
            if ($stage === STATUS_APPROVED && $afterDispatch) {
                // Same-stage undo: the request stays Approved and simply loses the
                // guard transaction, so the guard can dispatch it again.
                $label = $hasArrival ? 'Approved (clear arrival & dispatch)' : 'Approved (clear dispatch)';
            }
            $targets[] = [
                'key'        => $stage,
                'status'     => $stage,
                'stage'      => $stage,
                'label'      => $label,
                'step'       => ROLLBACK_TRIP_STAGES[$stage]['step'],
                'same_stage' => ($stage === STATUS_APPROVED && $afterDispatch),
                'effects'    => rollbackTripEffects($request, $stage),
            ];
        }

        return $targets;
    }

    /**
     * Side effects reversed when rolling a request back to $targetStatus.
     *
     * @return list<string>
     */
    /**
     * Side effects reversed when rolling a request back to $targetStatus.
     *
     * Every line here must match what pages/requests/rollback.php actually
     * does — this text is shown in the confirmation box as a promise, and a
     * promise the code does not keep is worse than no promise.
     *
     * @return list<string>
     */
    function rollbackTripEffects(object $request, string $targetStatus): array
    {
        $order = array_keys(ROLLBACK_TRIP_STAGES);
        $current = rollbackTripCurrentStage($request);
        $currentIdx = (int) array_search($current, $order, true);
        $targetIdx = (int) array_search($targetStatus, $order, true);
        $dispatchedIdx = (int) array_search('dispatched', $order, true);
        $currentStatus = (string) $request->status;
        $hadDispatch = !empty($request->actual_dispatch_datetime);

        $effects = [];

        $pendingIdx = (int) array_search('pending_motorpool', $order, true);
        $sameStageUndo = ($currentStatus === STATUS_APPROVED && $targetStatus === STATUS_APPROVED);
        // Must mirror $leavingAssigned in pages/requests/rollback.php exactly.
        $releases = ($targetIdx <= $pendingIdx) || ($currentStatus === STATUS_COMPLETED);

        if ($releases) {
            $effects[] = 'Release the assigned vehicle and driver (if no other approved trip holds them)';
        }

        if ($targetIdx <= $pendingIdx) {
            $effects[] = $targetStatus === STATUS_PENDING
                ? 'Put the workflow back with the department approver (step reset to pending)'
                : 'Reset the motorpool approval step to pending';
        } elseif ($sameStageUndo) {
            $effects[] = 'Keep the approval and the vehicle assignment — only the guard transaction is cleared';
        } else {
            $effects[] = 'Restore the request to approved so the guard can dispatch it again';
        }

        if ($hadDispatch && $targetIdx < $dispatchedIdx) {
            $effects[] = 'Undo the guard transaction: dispatch/arrival times and guard records are cleared';
        }

        if (in_array($currentStatus, [STATUS_APPROVED, STATUS_COMPLETED], true)) {
            $effects[] = $targetStatus === STATUS_APPROVED
                ? 'Flag linked trip tickets as cancelled'
                : 'Void linked trip tickets (soft-delete)';
        }

        $effects[] = 'Notify the requester and the approver holding the target stage';
        return $effects;
    }

    /** Resolve a posted target key back to a descriptor, or null when invalid. */
    function rollbackTripTargetByKey(object $request, string $key): ?array
    {
        foreach (rollbackTripTargets($request) as $t) {
            if ($t['key'] === $key) {
                return $t;
            }
        }
        return null;
    }

    /** Human label for a stage key (used by the hub and timelines). */
    function rollbackStageLabel(string $stage): string
    {
        if (isset(ROLLBACK_TRIP_STAGES[$stage])) {
            return ROLLBACK_TRIP_STAGES[$stage]['label'];
        }
        if (isset(ROLLBACK_OB_STAGES[$stage])) {
            return ROLLBACK_OB_STAGES[$stage]['label'];
        }
        if (isset(ROLLBACK_GAS_STAGES[$stage])) {
            return ROLLBACK_GAS_STAGES[$stage]['label'];
        }
        return ROLLBACK_TRIP_SIDE_STATUSES[$stage] ?? ucfirst(str_replace('_', ' ', $stage));
    }

    // -----------------------------------------------------------------
    // OB Pass Slips
    // -----------------------------------------------------------------

    function rollbackObCurrentStage(object $ob): string
    {
        $status = (string) $ob->status;
        return in_array($status, array_keys(ROLLBACK_OB_STAGES), true) ? $status : 'pending_supervisor';
    }

    /**
     * @return list<array{key:string,status:string,label:string,stage:string,effects:list<string>}>
     */
    function rollbackObTargets(object $ob): array
    {
        $current = rollbackObCurrentStage($ob);
        $currentIdx = (int) array_search($current, array_keys(ROLLBACK_OB_STAGES), true);
        $official = !empty($ob->uses_official_vehicle);

        $targets = [];
        foreach (ROLLBACK_OB_STAGES as $stage => $meta) {
            $idx = (int) array_search($stage, array_keys(ROLLBACK_OB_STAGES), true);
            if ($idx >= $currentIdx) {
                continue;
            }
            // Private-vehicle slips never go through Motorpool.
            if (!$official && $stage === 'pending_motorpool') {
                continue;
            }
            $targets[] = [
                'key'     => $stage,
                'status'  => $stage,
                'stage'   => $stage,
                'label'   => $meta['label'],
                'effects' => rollbackObEffects($ob, $stage),
            ];
        }
        return $targets;
    }

    /**
     * Side effects reversed when rolling a pass slip back to $targetStage.
     * Must match pages/ob-requests/rollback.php.
     *
     * @return list<string>
     */
    function rollbackObEffects(object $ob, string $targetStage): array
    {
        $order = array_keys(ROLLBACK_OB_STAGES);
        $approvedIdx = (int) array_search('approved', $order, true);
        $currentIdx = (int) array_search(rollbackObCurrentStage($ob), $order, true);
        $targetIdx = (int) array_search($targetStage, $order, true);

        $effects = [];

        // Leaving the approved stage and everything after it: the gate stamps and
        // the client acknowledgment are no longer valid.
        if ($targetIdx <= $approvedIdx && $currentIdx > $approvedIdx) {
            $effects[] = 'Clear the guard departure/arrival stamps';
            $effects[] = 'Clear the client Certificate of Appearance acknowledgment and contacts';
        }

        // Un-finalize when going back from Completed. The handler clears
        // finalized_at for ANY target other than 'completed', so say so here.
        if ((string) $ob->status === 'completed') {
            $effects[] = 'Clear the finalization timestamp';
        }

        $effects[] = 'Record a rollback entry in the slip timeline';
        $effects[] = 'Notify the requester and the approver holding the target stage';
        return $effects;
    }

    function rollbackObTargetByKey(object $ob, string $key): ?array
    {
        foreach (rollbackObTargets($ob) as $t) {
            if ($t['key'] === $key) {
                return $t;
            }
        }
        return null;
    }

    // -----------------------------------------------------------------
    // Gas vouchers
    // -----------------------------------------------------------------

    function rollbackGasCurrentStage(object $voucher): string
    {
        $status = (string) $voucher->status;
        return in_array($status, array_keys(ROLLBACK_GAS_STAGES), true) ? $status : 'draft';
    }

    /**
     * @return list<array{key:string,status:string,label:string,stage:string,effects:list<string>}>
     */
    function rollbackGasTargets(object $voucher): array
    {
        $currentIdx = (int) array_search(rollbackGasCurrentStage($voucher), array_keys(ROLLBACK_GAS_STAGES), true);
        $targets = [];
        foreach (ROLLBACK_GAS_STAGES as $stage => $meta) {
            $idx = (int) array_search($stage, array_keys(ROLLBACK_GAS_STAGES), true);
            if ($idx >= $currentIdx) {
                continue;
            }
            $targets[] = [
                'key'     => $stage,
                'status'  => $stage,
                'stage'   => $stage,
                'label'   => $meta['label'],
                'effects' => rollbackGasEffects($voucher, $stage),
            ];
        }
        return $targets;
    }

    /** @return list<string> */
    function rollbackGasEffects(object $voucher, string $targetStage): array
    {
        $effects = [];
        if (in_array((string) $voucher->reviewed_by, [null, ''], true) === false
            && array_search($targetStage, array_keys(ROLLBACK_GAS_STAGES), true)
                <= (int) array_search('pending_review', array_keys(ROLLBACK_GAS_STAGES), true)) {
            $effects[] = 'Clear the Motorpool review stamp';
        }
        if (!empty($voucher->budget_reviewed_by)
            && array_search($targetStage, array_keys(ROLLBACK_GAS_STAGES), true)
                <= (int) array_search('pending_budget', array_keys(ROLLBACK_GAS_STAGES), true)) {
            $effects[] = 'Clear the Budget Officer certification';
        }
        if (!empty($voucher->approved_by)
            && array_search($targetStage, array_keys(ROLLBACK_GAS_STAGES), true)
                <= (int) array_search('pending_approval', array_keys(ROLLBACK_GAS_STAGES), true)) {
            $effects[] = 'Clear the final approval stamp';
    }
        $effects[] = 'Notify the requester and the reviewer who must act next';
        return $effects;
    }

    function rollbackGasTargetByKey(object $voucher, string $key): ?array
    {
        foreach (rollbackGasTargets($voucher) as $t) {
            if ($t['key'] === $key) {
                return $t;
            }
        }
        return null;
    }
}