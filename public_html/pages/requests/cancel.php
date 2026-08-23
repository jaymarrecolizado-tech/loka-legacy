<?php
/**
 * LOKA - Cancel Request Page (Hardened Version)
 *
 * Allows requester to cancel their own request
 * Or admin/approver to cancel any request
 *
 * Handles:
 * - Status transition to 'cancelled' with state machine validation
 * - Vehicle/driver release when applicable
 * - Notification of all parties (AFTER successful commit)
 * - Audit logging with admin override tracking
 */

requireAuth();

$requestId = (int) get('id');

if (!$requestId) {
    redirectWith('/?page=dashboard', 'danger', 'Request ID required.');
}

// Get request with full details - FOR UPDATE locking
$request = db()->fetch(
    "SELECT r.*,
            u.name as requester_name, u.email as requester_email,
            v.plate_number as vehicle_plate, v.make as vehicle_make, v.model as vehicle_model,
            d.id as driver_db_id, du.name as driver_name
     FROM requests r
     JOIN users u ON r.user_id = u.id
     LEFT JOIN vehicles v ON r.vehicle_id = v.id
     LEFT JOIN drivers d ON r.driver_id = d.id
     LEFT JOIN users du ON d.user_id = du.id
     WHERE r.id = ? AND r.deleted_at IS NULL
     FOR UPDATE",
    [$requestId]
);

if (!$request) {
    redirectWith('/?page=dashboard', 'danger', 'Request not found.');
}

// Check permissions
$canCancel = false;
$isAdminOrApprover = isAdmin() || isApprover() || isMotorpool();

if ($request->user_id == userId()) {
    // Requester can cancel their own request if it's not already terminal
    $canCancel = true;
} elseif ($isAdminOrApprover) {
    // Admin/approver can cancel requests
    $canCancel = true;
}

if (!$canCancel) {
    redirectWith('/?page=requests&action=view&id=' . $requestId, 'danger', 'You cannot cancel this request.');
}

// Check if request is in a cancellable state
$cancellableStatuses = [STATUS_DRAFT, STATUS_PENDING, STATUS_PENDING_MOTORPOOL, STATUS_REVISION, STATUS_APPROVED, STATUS_REJECTED];
if (!in_array($request->status, $cancellableStatuses)) {
    $statusLabels = [
        STATUS_DRAFT => 'Draft',
        STATUS_PENDING => 'Pending Approval',
        STATUS_PENDING_MOTORPOOL => 'Pending Motorpool',
        STATUS_REVISION => 'Under Revision',
        STATUS_APPROVED => 'Approved',
        STATUS_REJECTED => 'Rejected',
        STATUS_CANCELLED => 'Cancelled',
        STATUS_COMPLETED => 'Completed'
    ];
    redirectWith('/?page=requests&action=view&id=' . $requestId, 'danger', "Cannot cancel request in '{$statusLabels[$request->status]}' status.");
}

// Handle POST submission BEFORE any HTML output
$redirectUrl = '';
$flashMessage = '';
$flashType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('confirm_cancel') === '1') {
    // Validate reason is required
    $reason = trim(post('reason') ?: '');
    if (empty($reason)) {
        $redirectUrl = '/?page=requests&action=view&id=' . $requestId;
        $flashMessage = 'Please provide a reason for cancellation.';
        $flashType = 'danger';
        redirectWith($redirectUrl, $flashType, $flashMessage);
        exit;
    }

    try {
        db()->beginTransaction();

        $oldStatus = $request->status;
        $now = date(DATETIME_FORMAT);

        // STATE MACHINE VALIDATION
        $validTransitions = [
            STATUS_DRAFT => [STATUS_CANCELLED],
            STATUS_PENDING => [STATUS_CANCELLED],
            STATUS_PENDING_MOTORPOOL => [STATUS_CANCELLED],
            STATUS_REVISION => [STATUS_CANCELLED],
            STATUS_APPROVED => [STATUS_CANCELLED],
            STATUS_REJECTED => [STATUS_CANCELLED]
        ];

        if (!in_array(STATUS_CANCELLED, $validTransitions[$oldStatus] ?? [])) {
            throw new Exception("Cannot cancel request in '{$oldStatus}' status.");
        }

        // Release vehicle if assigned
        if ($request->vehicle_id) {
            db()->update('vehicles', [
                'status' => 'available',
                'updated_at' => $now
            ], 'id = ?', [$request->vehicle_id]);
        }

        // Release driver if assigned
        if ($request->driver_id) {
            db()->update('drivers', [
                'status' => 'available',
                'updated_at' => $now
            ], 'id = ?', [$request->driver_id]);
        }

        // Update request status
        db()->update('requests', [
            'status' => STATUS_CANCELLED,
            'updated_at' => $now
        ], 'id = ?', [$requestId]);

        // Try to update workflow (may not support 'cancelled' status in ENUM)
        try {
            $workflow = db()->fetch(
                "SELECT * FROM approval_workflow WHERE request_id = ?",
                [$requestId]
            );

            if ($workflow) {
                db()->update('approval_workflow', [
                    'status' => 'rejected', // Use 'rejected' as 'cancelled' is not in ENUM
                    'action_at' => $now,
                    'updated_at' => $now,
                    'comments' => 'Request cancelled: ' . $reason
                ], 'request_id = ?', [$requestId]);
            }
        } catch (Exception $e) {
            // Workflow update failed, but cancellation continues
            error_log("Workflow update failed (non-critical): " . $e->getMessage());
        }

        // Audit log with admin override tracking
        $isAdminOverride = ($request->user_id != userId() && $isAdminOrApprover);
        $auditData = [
            'status' => STATUS_CANCELLED,
            'cancelled_by' => userId(),
            'is_admin_override' => $isAdminOverride,
            'reason' => $reason
        ];

        if ($isAdminOverride) {
            $auditData['original_requester_id'] = $request->user_id;
        }

        auditLog(
            'request_cancelled',
            'request',
            $requestId,
            ['status' => $oldStatus],
            $auditData
        );

        db()->commit();

        // Send notifications AFTER successful commit (non-blocking)
        $cancelledBy = ($request->user_id == userId())
            ? 'You'
            : (currentUser()->name ?? 'An administrator');

        // Try to send in-app + email notifications (don't fail if they error)
        try {
            // Notify requester
            @notify(
                $request->user_id,
                'request_cancelled',
                'Request Cancelled',
                "Your request for {$request->destination} on " . formatDate($request->start_datetime) . " has been cancelled.\n\nCancelled by: {$cancelledBy}\nReason: " . $reason,
                '/?page=requests&action=view&id=' . $requestId,
                $requestId
            );

            // Notify passengers using batch function
            @notifyPassengersBatch(
                $requestId,
                'request_cancelled',
                'Trip Cancelled',
                "The trip to {$request->destination} on " . formatDate($request->start_datetime) . " has been cancelled.\n\nCancelled by: {$cancelledBy}\nReason: " . $reason,
                '/?page=requests&action=view&id=' . $requestId
            );

            // Notify assigned driver
            if ($request->driver_id) {
                @notifyDriver(
                    $request->driver_id,
                    'request_cancelled',
                    'Trip Cancelled',
                    "A trip you were assigned to drive to {$request->destination} on " . formatDate($request->start_datetime) . " has been cancelled.\n\nCancelled by: {$cancelledBy}\nReason: " . $reason,
                    '/?page=requests&action=view&id=' . $requestId
                );
            }

            // Notify requested driver (if different)
            if ($request->requested_driver_id && $request->requested_driver_id != $request->driver_id) {
                @notifyDriver(
                    $request->requested_driver_id,
                    'request_cancelled',
                    'Trip Cancelled',
                    "A trip you were requested to drive to {$request->destination} on " . formatDate($request->start_datetime) . " has been cancelled.\n\nCancelled by: {$cancelledBy}\nReason: " . $reason,
                    '/?page=requests&action=view&id=' . $requestId
                );
            }

            // Notify approver if request was pending
            if (in_array($oldStatus, [STATUS_PENDING, STATUS_PENDING_MOTORPOOL])) {
                if ($oldStatus === STATUS_PENDING && $request->approver_id) {
                    @notify(
                        $request->approver_id,
                        'request_cancelled',
                        'Request Cancelled',
                        "Request #{$requestId} for {$request->destination} has been cancelled by the requester.",
                        '/?page=approvals&action=view&id=' . $requestId,
                        $requestId
                    );
                }
                if ($oldStatus === STATUS_PENDING_MOTORPOOL && $request->motorpool_head_id) {
                    @notify(
                        $request->motorpool_head_id,
                        'request_cancelled',
                        'Request Cancelled',
                        "Request #{$requestId} for {$request->destination} has been cancelled.",
                        '/?page=approvals&action=view&id=' . $requestId,
                        $requestId
                    );
                }
            }
        } catch (Exception $e) {
            error_log("Notifications failed: " . $e->getMessage());
        }

        // Set redirect variables
        $redirectUrl = '/?page=requests&action=view&id=' . $requestId;
        $flashMessage = 'Request has been cancelled successfully.';
        $flashType = 'success';

    } catch (Exception $e) {
        db()->rollback();
        error_log("Request cancellation error: " . $e->getMessage());
        $redirectUrl = '/?page=requests&action=view&id=' . $requestId;
        $flashMessage = 'Failed to cancel request: ' . $e->getMessage();
        $flashType = 'danger';
    }

    // Redirect after POST
    redirectWith($redirectUrl, $flashType, $flashMessage);
    exit;
}

$pageTitle = 'Cancel Request #' . $requestId;
require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <div class="row justify-content-center">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header bg-danger text-white">
                    <h5 class="mb-0"><i class="bi bi-x-circle me-2"></i>Cancel Request #<?= $requestId ?></h5>
                </div>
                <div class="card-body">
                    <div class="text-center mb-4">
                        <i class="bi bi-x-circle-fill text-danger" style="font-size: 5rem;"></i>
                    </div>

                    <h4 class="text-center mb-4">Are you sure you want to cancel this request?</h4>

                    <div class="card bg-light mb-4">
                        <div class="card-body">
                            <h6 class="text-muted mb-3">Request Details</h6>
                            <div class="row g-3">
                                <div class="col-sm-4">
                                    <label class="small text-muted">Request #</label>
                                    <div class="fw-bold"><?= $requestId ?></div>
                                </div>
                                <div class="col-sm-8">
                                    <label class="small text-muted">Purpose</label>
                                    <div class="fw-bold"><?= e($request->purpose) ?></div>
                                </div>
                                <div class="col-sm-4">
                                    <label class="small text-muted">Destination</label>
                                    <div><?= e($request->destination) ?></div>
                                </div>
                                <div class="col-sm-8">
                                    <label class="small text-muted">Date & Time</label>
                                    <div><?= formatDateTime($request->start_datetime) ?></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-danger d-flex align-items-start mb-4" role="alert">
                        <i class="bi bi-exclamation-triangle-fill flex-shrink-0 me-3 fs-4"></i>
                        <div>
                            <strong class="d-block mb-2">This action cannot be undone!</strong>
                            <ul class="mb-0">
                                <li>The request will be marked as cancelled</li>
                                <li>Assigned vehicle and driver will be released</li>
                                <li>All approvers and passengers will be notified</li>
                            </ul>
                        </div>
                    </div>

                    <?php if ($request->status === STATUS_APPROVED): ?>
                    <div class="alert alert-warning mb-4">
                        <i class="bi bi-info-circle-fill me-2"></i>
                        <strong>Attention:</strong> This request has already been approved.
                        <?php if ($request->vehicle_plate): ?>
                        <div class="mt-2"><strong>Vehicle:</strong> <?= e($request->vehicle_plate) ?> - <?= e($request->vehicle_make) ?> <?= e($request->vehicle_model) ?></div>
                        <?php endif; ?>
                        <?php if ($request->driver_name): ?>
                        <div><strong>Driver:</strong> <?= e($request->driver_name) ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($request->status === STATUS_REJECTED): ?>
                    <div class="alert alert-info mb-4">
                        <i class="bi bi-info-circle-fill me-2"></i>
                        <strong>Note:</strong> This request was rejected. Cancelling will permanently close this request.
                    </div>
                    <?php endif; ?>

                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="confirm_cancel" value="1">

                        <div class="mb-4">
                            <label for="reason" class="form-label fw-bold">
                                <i class="bi bi-chat-left-text me-1"></i>Reason for cancellation
                                <span class="text-danger">*</span>
                            </label>
                            <textarea class="form-control" id="reason" name="reason" rows="3" required
                                placeholder="Please provide a reason for cancelling this request..."></textarea>
                            <small class="text-muted">This field is required</small>
                        </div>

                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                            <a href="<?= APP_URL ?>/?page=requests&action=view&id=<?= $requestId ?>"
                               class="btn btn-outline-secondary btn-lg">
                                <i class="bi bi-x-lg me-1"></i>No, Go Back
                            </a>
                            <button type="submit" class="btn btn-danger btn-lg">
                                <i class="bi bi-check-lg me-1"></i>Yes, Cancel Request
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
