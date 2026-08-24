<?php
/**
 * LOKA - Admin Request Rollback
 *
 * Admin-exclusive rollback to an earlier workflow phase. Also reverses guard
 * transactions: rolling back an approved request clears dispatch/arrival
 * records (e.g., guard dispatched the wrong vehicle) and releases the
 * vehicle/driver so the request can be corrected and re-dispatched.
 */

requireRole(ROLE_ADMIN);

$requestId = (int) get('id');

if (!$requestId) {
    redirectWith('/?page=rollback', 'danger', 'Request ID required.');
}

// Valid rollback targets per current status
$targetsByStatus = [
    STATUS_PENDING_MOTORPOOL => [STATUS_PENDING],
    STATUS_APPROVED          => [STATUS_PENDING_MOTORPOOL, STATUS_PENDING],
    STATUS_COMPLETED         => [STATUS_APPROVED],
    STATUS_REVISION          => [STATUS_PENDING, STATUS_PENDING_MOTORPOOL],
    STATUS_REJECTED          => [STATUS_PENDING, STATUS_PENDING_MOTORPOOL],
];

$statusLabels = [
    STATUS_PENDING           => 'Pending Department Approval',
    STATUS_PENDING_MOTORPOOL => 'Pending Motorpool Approval',
    STATUS_APPROVED          => 'Approved',
    STATUS_COMPLETED         => 'Completed',
    STATUS_REJECTED          => 'Rejected',
    STATUS_REVISION          => 'For Revision',
    STATUS_CANCELLED         => 'Cancelled',
];

// Workflow step for each target status
$stepForTarget = [
    STATUS_PENDING           => 'department',
    STATUS_PENDING_MOTORPOOL => 'motorpool',
    STATUS_APPROVED          => 'motorpool',
];

// Handle POST BEFORE any HTML output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('confirm_rollback') === '1') {
    $targetStatus = post('target_status');
    $reason = trim(post('reason') ?: '');
    $expectedUpdatedAt = post('expected_updated_at');

    if (!isset($targetsByStatus[$targetStatus]) && !in_array($targetStatus, [STATUS_PENDING, STATUS_PENDING_MOTORPOOL, STATUS_APPROVED])) {
        redirectWith('/?page=rollback', 'danger', 'Invalid target phase.');
    }
    if (strlen($reason) < 10) {
        redirectWith('/?page=rollback&action=process&id=' . $requestId, 'danger', 'Please provide a reason (at least 10 characters).');
    }

    try {
        db()->beginTransaction();

        // Lock the request row
        $request = db()->fetch(
            "SELECT r.*, v.status AS vehicle_status, v.plate_number
             FROM requests r
             LEFT JOIN vehicles v ON r.vehicle_id = v.id
             WHERE r.id = ? AND r.deleted_at IS NULL
             FOR UPDATE",
            [$requestId]
        );

        if (!$request) {
            throw new Exception('Request not found.');
        }

        $oldStatus = $request->status;

        // Validate transition
        $validTargets = $targetsByStatus[$oldStatus] ?? [];
        if (!in_array($targetStatus, $validTargets)) {
            throw new Exception("Cannot roll back a request in '{$statusLabels[$oldStatus]}' status to the selected phase.");
        }

        // Optimistic lock: abort if modified since the form was loaded
        if ($expectedUpdatedAt && $request->updated_at !== $expectedUpdatedAt) {
            throw new Exception('Request was modified by someone else. Please review the current state and try again.');
        }

        // Admins may undo guard transactions: an approved request that was
        // already dispatched can be rolled back, clearing dispatch records
        // below. (No in-use block — the admin is explicitly rectifying the
        // guard's dispatch error.)
        $leavingActive = in_array($oldStatus, [STATUS_APPROVED, STATUS_COMPLETED]);
        $hadDispatch = !empty($request->actual_dispatch_datetime);

        $now = date(DATETIME_FORMAT);

        // Release vehicle/driver when leaving approved/completed — only if not
        // assigned to another active request
        if ($leavingActive) {
            if ($request->vehicle_id) {
                $otherActive = db()->fetchColumn(
                    "SELECT COUNT(*) FROM requests WHERE vehicle_id = ? AND id != ? AND status = 'approved' AND deleted_at IS NULL",
                    [$request->vehicle_id, $requestId]
                );
                if (!$otherActive) {
                    db()->update('vehicles', ['status' => 'available', 'updated_at' => $now], 'id = ?', [$request->vehicle_id]);
                }
            }
            if ($request->driver_id) {
                $otherActiveDrv = db()->fetchColumn(
                    "SELECT COUNT(*) FROM requests WHERE driver_id = ? AND id != ? AND status = 'approved' AND deleted_at IS NULL",
                    [$request->driver_id, $requestId]
                );
                if (!$otherActiveDrv) {
                    db()->update('drivers', ['status' => 'available', 'updated_at' => $now], 'id = ?', [$request->driver_id]);
                }
            }

            // Undo guard transaction: clear dispatch/arrival records so the
            // request can be cleanly corrected and re-dispatched
            if ($hadDispatch) {
                db()->query(
                    "UPDATE requests
                     SET actual_dispatch_datetime = NULL, actual_arrival_datetime = NULL,
                         dispatch_guard_id = NULL, arrival_guard_id = NULL
                     WHERE id = ?",
                    [$requestId]
                );
            }

            // Trip tickets: void when going back to an approval phase; cancel-flag when completed -> approved
            if ($targetStatus === STATUS_APPROVED) {
                db()->query(
                    "UPDATE trip_tickets SET status = 'cancelled', updated_at = ? WHERE request_id = ? AND deleted_at IS NULL",
                    [$now, $requestId]
                );
            } else {
                db()->query(
                    "UPDATE trip_tickets SET deleted_at = ? WHERE request_id = ? AND deleted_at IS NULL",
                    [$now, $requestId]
                );
            }
        }

        // Update the request
        db()->query(
            "UPDATE requests SET status = ?, rollback_count = rollback_count + 1, updated_at = ? WHERE id = ?",
            [$targetStatus, $now, $requestId]
        );

        // Reset the workflow step (non-destructive: history stays in approvals)
        try {
            $workflow = db()->fetch("SELECT id FROM approval_workflow WHERE request_id = ?", [$requestId]);
            if ($workflow) {
                db()->update('approval_workflow', [
                    'step'       => $stepForTarget[$targetStatus],
                    'status'     => $targetStatus === STATUS_APPROVED ? 'approved' : 'pending',
                    'action_at'  => null,
                    'comments'   => 'Rolled back by admin: ' . $reason,
                    'updated_at' => $now,
                ], 'request_id = ?', [$requestId]);
            }
        } catch (Exception $e) {
            error_log('Workflow reset failed (non-critical): ' . $e->getMessage());
        }

        // Append rollback entry to approval history
        // approval_type holds the TARGET phase (ENUM: department|motorpool)
        db()->insert('approvals', [
            'request_id'    => $requestId,
            'approver_id'   => userId(),
            'approval_type' => $stepForTarget[$targetStatus],
            'status'        => 'rollback',
            'comments'      => $reason,
            'created_at'    => $now,
        ]);

        auditLog(
            'request_rollback',
            'request',
            $requestId,
            [
                'status' => $oldStatus,
                'actual_dispatch_datetime' => $request->actual_dispatch_datetime,
                'actual_arrival_datetime' => $request->actual_arrival_datetime,
            ],
            [
                'status'                   => $targetStatus,
                'reason'                   => $reason,
                'rolled_back_by'           => userId(),
                'rollback_count'           => (int)$request->rollback_count + 1,
                'dispatch_records_cleared' => $hadDispatch,
            ]
        );

        db()->commit();

        // Notifications AFTER commit (non-blocking)
        try {
            $label = $statusLabels[$targetStatus];
            if ($request->user_id != userId()) {
                @notify(
                    $request->user_id,
                    'request_rolled_back',
                    'Request Rolled Back',
                    "Request #{$requestId} ({$request->destination}) has been rolled back to: {$label}.\n\nReason: {$reason}",
                    '/?page=requests&action=view&id=' . $requestId,
                    $requestId
                );
            }
            $targetApprover = $targetStatus === STATUS_PENDING_MOTORPOOL
                ? ($request->motorpool_head_id ?? null)
                : ($request->approver_id ?? null);
            if ($targetApprover) {
                @notify(
                    $targetApprover,
                    'request_rolled_back',
                    'Request Rolled Back to You',
                    "Request #{$requestId} ({$request->destination}) has been rolled back to your approval level ({$label}).\n\nReason: {$reason}",
                    '/?page=approvals&action=view&id=' . $requestId,
                    $requestId
                );
            }
        } catch (Exception $e) {
            error_log('Rollback notifications failed: ' . $e->getMessage());
        }

        redirectWith('/?page=rollback', 'success', "Request #{$requestId} rolled back to: {$statusLabels[$targetStatus]}.");

    } catch (Exception $e) {
        if (db()->inTransaction()) {
            db()->rollback();
        }
        error_log('Rollback error: ' . $e->getMessage());
        redirectWith('/?page=rollback&action=process&id=' . $requestId, 'danger', 'Rollback failed: ' . $e->getMessage());
    }
    exit;
}

// ---- GET: confirm form ----
$request = db()->fetch(
    "SELECT r.*, u.name as requester_name, v.plate_number, v.make, v.model, v.status AS vehicle_status,
            du.name as driver_name
     FROM requests r
     JOIN users u ON r.user_id = u.id
     LEFT JOIN vehicles v ON r.vehicle_id = v.id
     LEFT JOIN drivers d ON r.driver_id = d.id
     LEFT JOIN users du ON d.user_id = du.id
     WHERE r.id = ? AND r.deleted_at IS NULL",
    [$requestId]
);

if (!$request) {
    redirectWith('/?page=rollback', 'danger', 'Request not found.');
}

$validTargets = $targetsByStatus[$request->status] ?? [];

if (empty($validTargets)) {
    redirectWith('/?page=rollback', 'danger', "Requests in '{$statusLabels[$request->status]}' status cannot be rolled back.");
}

$pageTitle = 'Rollback Request #' . $requestId;
require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <div class="row justify-content-center">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header bg-warning text-dark">
                    <h5 class="mb-0">
                        <i class="bi bi-arrow-counterclockwise me-2"></i>Rollback Request #<?= $requestId ?>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="card bg-light mb-4">
                        <div class="card-body">
                            <h6 class="text-muted mb-3">Request Details</h6>
                            <div class="row g-3">
                                <div class="col-sm-4">
                                    <label class="small text-muted">Current Status</label>
                                    <div><?= requestStatusBadge($request->status) ?></div>
                                </div>
                                <div class="col-sm-8">
                                    <label class="small text-muted">Requester</label>
                                    <div class="fw-bold"><?= e($request->requester_name) ?></div>
                                </div>
                                <div class="col-sm-8">
                                    <label class="small text-muted">Destination</label>
                                    <div><?= e($request->destination) ?></div>
                                </div>
                                <div class="col-sm-4">
                                    <label class="small text-muted">Date &amp; Time</label>
                                    <div><?= formatDateTime($request->start_datetime) ?></div>
                                </div>
                                <?php if ($request->plate_number): ?>
                                <div class="col-sm-6">
                                    <label class="small text-muted">Vehicle</label>
                                    <div><?= e($request->plate_number) ?> (<?= e($request->make . ' ' . $request->model) ?>)</div>
                                </div>
                                <?php endif; ?>
                                <?php if ($request->driver_name): ?>
                                <div class="col-sm-6">
                                    <label class="small text-muted">Driver</label>
                                    <div><?= e($request->driver_name) ?></div>
                                </div>
                                <?php endif; ?>
                                <?php if ((int)$request->rollback_count > 0): ?>
                                <div class="col-sm-6">
                                    <label class="small text-muted">Previous Rollbacks</label>
                                    <div><span class="badge bg-warning text-dark"><?= (int)$request->rollback_count ?></span></div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-warning d-flex align-items-start mb-4" role="alert">
                        <i class="bi bi-exclamation-triangle-fill flex-shrink-0 me-3 fs-4"></i>
                        <div>
                            <strong>Rolling back will:</strong>
                            <ul class="mb-0">
                                <li>Reset the workflow to the selected phase for re-approval</li>
                                <?php if ($request->status === STATUS_APPROVED && $request->actual_dispatch_datetime): ?>
                                <li><strong>Undo the guard transaction:</strong> dispatch/arrival times and guard records will be cleared</li>
                                <?php endif; ?>
                                <?php if (in_array($request->status, [STATUS_APPROVED, STATUS_COMPLETED])): ?>
                                <li>Release the assigned vehicle and driver (if not in use by another trip)</li>
                                <li>Remove linked trip tickets from the active queue</li>
                                <?php endif; ?>
                                <li>Notify the requester and the target approver</li>
                            </ul>
                        </div>
                    </div>

                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="confirm_rollback" value="1">
                        <input type="hidden" name="expected_updated_at" value="<?= e($request->updated_at) ?>">

                        <div class="mb-3">
                            <label class="form-label fw-bold">Roll back to phase <span class="text-danger">*</span></label>
                            <select class="form-select" name="target_status" required>
                                <option value="">Select target phase...</option>
                                <?php foreach ($validTargets as $target): ?>
                                <option value="<?= $target ?>"><?= $statusLabels[$target] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-bold">
                                Reason for rollback <span class="text-danger">*</span>
                            </label>
                            <textarea class="form-control" name="reason" rows="3" required minlength="10"
                                placeholder="Explain why this request is being rolled back (min. 10 characters)..."></textarea>
                            <small class="text-muted">Recorded in the audit trail and shown in the approval history.</small>
                        </div>

                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                            <a href="<?= APP_URL ?>/?page=rollback" class="btn btn-outline-secondary btn-lg">
                                <i class="bi bi-x-lg me-1"></i>Cancel
                            </a>
                            <button type="submit" class="btn btn-warning btn-lg">
                                <i class="bi bi-arrow-counterclockwise me-1"></i>Roll Back Request
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
