<?php
/**
 * LOKA - Public pre-trip confirmation page (token-gated, no login)
 *
 * Reached from the confirmatory email:
 *   ?page=requests&action=confirm&token=RAW[&choice=proceed|decline]
 *
 * Actions:
 *   - proceed            : mark confirmation confirmed (trip pushes through)
 *   - decline (GET)      : show options (cancel trip / request reschedule)
 *   - decline_cancel     : cancel the trip
 *   - decline_reschedule : send the request back to the motorpool for re-approval
 *
 * Security: the 64-hex raw token is the capability; only its SHA-256 hash is
 * stored. Tokens are single-use (status transition guards re-entry).
 */

$rawToken = trim((string) get('token', ''));
$choice = (string) get('choice', '');

$confirmation = findTripConfirmationByToken($rawToken);

$error = '';
$done = false;
$showDeclineForm = false;

if (!$confirmation) {
    $error = 'This confirmation link is invalid or has expired.';
} elseif ($confirmation->status === 'pending' && $confirmation->sent_at === null) {
    $error = 'This confirmation link has not been activated yet. Please wait for the confirmation email.';
} elseif (in_array($confirmation->status, ['confirmed', 'declined_cancel', 'declined_reschedule', 'expired', 'cancelled'], true)) {
    $done = true; // show outcome below
} elseif ($confirmation->status === 'pending' && !tripConfirmationStillEligible($confirmation)) {
    // Trip already dispatched, started, or completed — cancel leftover pending row
    if (function_exists('cancelPendingTripConfirmations')) {
        cancelPendingTripConfirmations((int) $confirmation->request_id, 'confirm page: trip no longer eligible');
    } else {
        db()->update(
            'trip_confirmations',
            ['status' => 'cancelled', 'updated_at' => date(DATETIME_FORMAT)],
            'id = ? AND status = ?',
            [$confirmation->id, 'pending']
        );
    }
    $confirmation->status = 'cancelled';
    $error = 'This confirmation is no longer needed. The trip has already been dispatched, started, or completed.';
}

// Process POST decline actions before any output
if (!$error && !$done && $confirmation && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!tripConfirmationStillEligible($confirmation)) {
        $error = 'This confirmation is no longer needed. The trip has already been dispatched, started, or completed.';
    } else {
    $declineAction = (string) post('decline_action', '');
    $note = postSafe('reschedule_note', '', 1000);
    $requestId = (int) $confirmation->request_id;

    try {
        db()->beginTransaction();

        // Re-fetch with lock
        $conf = db()->fetch(
            "SELECT * FROM trip_confirmations WHERE id = ? AND status = 'pending' FOR UPDATE",
            [$confirmation->id]
        );

        if (!$conf) {
            db()->rollback();
            $error = 'This confirmation was already processed or is no longer pending.';
        } elseif ($declineAction !== 'cancel' && $declineAction !== 'reschedule') {
            db()->rollback();
            $error = 'Invalid action.';
        } else {
            $request = db()->fetch(
                "SELECT r.*, v.id AS vehicle_row_id, d.id AS driver_row_id
                 FROM requests r
                 LEFT JOIN vehicles v ON r.vehicle_id = v.id AND v.deleted_at IS NULL
                 LEFT JOIN drivers d ON r.driver_id = d.id AND d.deleted_at IS NULL
                 WHERE r.id = ? AND r.deleted_at IS NULL FOR UPDATE",
                [$requestId]
            );

            if (!$request || $request->status !== STATUS_APPROVED) {
                db()->rollback();
                $error = 'The trip is no longer in an approved state and cannot be changed here.';
            } else {
                $now = date(DATETIME_FORMAT);

                if ($declineAction === 'cancel') {
                    db()->update('trip_confirmations', [
                        'status' => 'declined_cancel',
                        'responded_at' => $now,
                        'updated_at' => $now
                    ], 'id = ?', [$conf->id]);

                    if ($request->vehicle_row_id) {
                        db()->update('vehicles', [
                            'status' => VEHICLE_AVAILABLE,
                            'updated_at' => $now
                        ], 'id = ?', [$request->vehicle_row_id]);
                    }
                    if ($request->driver_row_id) {
                        db()->update('drivers', [
                            'status' => DRIVER_AVAILABLE,
                            'updated_at' => $now
                        ], 'id = ?', [$request->driver_row_id]);
                    }

                    db()->update('requests', [
                        'status' => STATUS_CANCELLED,
                        'updated_at' => $now
                    ], 'id = ?', [$requestId]);

                    auditLog('trip_declined_cancelled', 'request', $requestId, ['status' => STATUS_APPROVED], [
                        'via' => 'confirmation_token',
                        'note' => $note ?: null
                    ]);

                    db()->commit();

                    // Notifications after commit (best-effort)
                    try {
                        notifyPassengers($requestId, 'request_cancelled', 'Trip Cancelled',
                            "The trip to {$request->destination} on " . formatDateTime($request->start_datetime)
                            . ' has been cancelled by the requester via the pre-trip confirmation.',
                            '/?page=requests&action=view&id=' . $requestId);
                        if ($request->driver_id) {
                            notifyDriver((int) $request->driver_id, 'request_cancelled', 'Trip Cancelled',
                                "A trip you were assigned to drive to {$request->destination} on "
                                . formatDateTime($request->start_datetime) . ' has been cancelled by the requester.',
                                '/?page=requests&action=view&id=' . $requestId);
                        }
                        notifyMotorpoolHeads($requestId, 'trip_cancelled_by_requester', 'Trip Cancelled by Requester',
                            "Request #{$requestId} ({$request->destination}) was cancelled by the requester "
                            . 'through the pre-trip confirmation. Vehicle/driver have been released.',
                            '/?page=requests&action=view&id=' . $requestId);
                    } catch (Throwable $e) {
                        error_log('confirm.php cancel notify: ' . $e->getMessage());
                    }

                    $confirmation->status = 'declined_cancel';
                    $done = true;
                } else {
                    // Request reschedule: back to motorpool for re-approval
                    if (empty($note)) {
                        db()->rollback();
                        $error = 'Please provide a short note describing the change you need.';
                    } else {
                        db()->update('trip_confirmations', [
                            'status' => 'declined_reschedule',
                            'responded_at' => $now,
                            'reschedule_note' => $note,
                            'updated_at' => $now
                        ], 'id = ?', [$conf->id]);

                        db()->update('requests', [
                            'status' => STATUS_PENDING_MOTORPOOL,
                            'reschedule_requested' => 1,
                            'reschedule_note' => $note,
                            'updated_at' => $now
                        ], 'id = ?', [$requestId]);

                        auditLog('trip_reschedule_requested', 'request', $requestId, ['status' => STATUS_APPROVED], [
                            'via' => 'confirmation_token',
                            'note' => $note
                        ]);

                        db()->commit();

                        try {
                            notifyMotorpoolHeads($requestId, 'trip_reschedule_requested', 'Reschedule Requested',
                                "Request #{$requestId} ({$request->destination}) needs re-approval. "
                                . "The requester requested a change via the pre-trip confirmation:\n\n{$note}\n\n"
                                . 'Please review, adjust the vehicle/driver if needed, and approve again.',
                                '/?page=requests&action=view&id=' . $requestId);
                        } catch (Throwable $e) {
                            error_log('confirm.php reschedule notify: ' . $e->getMessage());
                        }

                        $confirmation->status = 'declined_reschedule';
                        $done = true;
                    }
                }
            }
        }
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollback();
        }
        error_log('confirm.php: ' . $e->getMessage());
        $error = 'Something went wrong while processing your response. Please try again.';
    }
    } // end eligible else
}

// Simple GET navigations
if (!$error && !$done && $confirmation) {
    if (!tripConfirmationStillEligible($confirmation) && $confirmation->status === 'pending') {
        if (function_exists('cancelPendingTripConfirmations')) {
            cancelPendingTripConfirmations((int) $confirmation->request_id, 'confirm GET: trip no longer eligible');
        }
        $confirmation->status = 'cancelled';
        $error = 'This confirmation is no longer needed. The trip has already been dispatched, started, or completed.';
    } elseif ($choice === 'proceed' && $confirmation->status === 'pending' && $confirmation->sent_at !== null) {
        $affected = db()->update('trip_confirmations', [
            'status' => 'confirmed',
            'responded_at' => date(DATETIME_FORMAT),
            'updated_at' => date(DATETIME_FORMAT)
        ], 'id = ? AND status = ?', [$confirmation->id, 'pending']);

        if ($affected > 0) {
            auditLog('trip_confirmed', 'request', (int) $confirmation->request_id, null, ['via' => 'confirmation_token']);
            try {
                notifyMotorpoolHeads((int) $confirmation->request_id, 'trip_confirmation_response', 'Trip Confirmed',
                    "Request #{$confirmation->request_id} ({$confirmation->destination}) was confirmed by the "
                    . 'requester. The trip will proceed as scheduled.',
                    '/?page=requests&action=view&id=' . $confirmation->request_id);
            } catch (Throwable $e) {
                error_log('confirm.php proceed notify: ' . $e->getMessage());
            }
            $confirmation->status = 'confirmed';
            $done = true;
        }
    } elseif ($choice === 'decline' && $confirmation->status === 'pending' && $confirmation->sent_at !== null) {
        $showDeclineForm = true;
    } elseif ($choice !== '' && !$done) {
        $error = 'Invalid or already-used confirmation link.';
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Trip Confirmation - <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5" style="max-width: 640px;">

    <?php if ($error): ?>
        <div class="card shadow-sm">
            <div class="card-body text-center py-5">
                <i class="bi bi-x-octagon-fill text-danger fs-1"></i>
                <h4 class="mt-3">Link Not Usable</h4>
                <p class="text-muted mb-0"><?= e($error) ?></p>
            </div>
        </div>

    <?php elseif ($done && $confirmation && $confirmation->status === 'confirmed'): ?>
        <div class="card shadow-sm">
            <div class="card-body text-center py-5">
                <i class="bi bi-check-circle-fill text-success fs-1"></i>
                <h4 class="mt-3">Trip Confirmed</h4>
                <p class="text-muted">Thank you! Your trip (Request #<?= (int) $confirmation->request_id ?>) will proceed as scheduled.
                The motorpool has been notified.</p>
            </div>
        </div>

    <?php elseif ($done && $confirmation && $confirmation->status === 'declined_cancel'): ?>
        <div class="card shadow-sm">
            <div class="card-body text-center py-5">
                <i class="bi bi-slash-circle-fill text-danger fs-1"></i>
                <h4 class="mt-3">Trip Cancelled</h4>
                <p class="text-muted">Your trip (Request #<?= (int) $confirmation->request_id ?>) has been cancelled.
                The vehicle and driver have been released and the motorpool has been notified.</p>
            </div>
        </div>

    <?php elseif ($done && $confirmation && $confirmation->status === 'declined_reschedule'): ?>
        <div class="card shadow-sm">
            <div class="card-body text-center py-5">
                <i class="bi bi-arrow-repeat text-primary fs-1"></i>
                <h4 class="mt-3">Reschedule Requested</h4>
                <p class="text-muted">Your reschedule request for Request #<?= (int) $confirmation->request_id ?> has been
                sent to the motorpool. Once they adjust and approve the new schedule, you will receive
                a new confirmation email.</p>
            </div>
        </div>

    <?php elseif ($done): ?>
        <div class="card shadow-sm">
            <div class="card-body text-center py-5">
                <i class="bi bi-info-circle-fill text-secondary fs-1"></i>
                <h4 class="mt-3">Already Processed</h4>
                <p class="text-muted mb-0">This confirmation link has already been used.</p>
            </div>
        </div>

    <?php elseif ($confirmation): ?>
        <div class="card shadow-sm">
            <div class="card-header bg-warning text-dark text-center">
                <h5 class="mb-0"><i class="bi bi-calendar-check me-2"></i>Confirm Your Trip</h5>
            </div>
            <div class="card-body p-4">
                <p class="mb-3">Please confirm whether your approved trip will push through.
                <strong>If we receive no response before the deadline, the trip will proceed as scheduled.</strong></p>

                <div class="border rounded p-3 bg-white mb-4">
                    <p class="mb-1"><strong>Request #:</strong> <?= (int) $confirmation->request_id ?></p>
                    <p class="mb-1"><strong>Destination:</strong> <?= e($confirmation->destination) ?></p>
                    <p class="mb-1"><strong>Purpose:</strong> <?= e($confirmation->purpose) ?></p>
                    <p class="mb-1"><strong>Departure:</strong> <?= formatDateTime($confirmation->start_datetime) ?></p>
                    <p class="mb-1"><strong>Expected Return:</strong> <?= formatDateTime($confirmation->end_datetime) ?></p>
                    <p class="mb-0"><strong>Respond by:</strong> <?= formatDateTime($confirmation->deadline_at) ?></p>
                </div>

                <?php if ($showDeclineForm): ?>
                    <h6 class="fw-bold mb-3"><i class="bi bi-exclamation-triangle me-1 text-danger"></i>
                        You chose "Don't Proceed" &mdash; choose an option:</h6>

                    <form method="POST" class="mb-3"
                          onsubmit="return confirm('Cancel this trip? The vehicle and driver will be released.');">
                        <?= csrfField() ?>
                        <input type="hidden" name="decline_action" value="cancel">
                        <button type="submit" class="btn btn-danger w-100 py-2">
                            <i class="bi bi-x-circle me-1"></i>Cancel the trip entirely
                        </button>
                    </form>

                    <hr class="text-muted my-4">
                    <div class="text-center text-muted small mb-2">OR</div>

                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="decline_action" value="reschedule">
                        <div class="mb-2">
                            <label class="form-label fw-semibold">Request a reschedule <span class="text-danger">*</span></label>
                            <textarea class="form-control" name="reschedule_note" rows="3" required
                                placeholder="Describe the change you need (e.g., preferred new date/time)..."></textarea>
                            <small class="text-muted">The motorpool will review and approve the new schedule. You will receive a new confirmation email afterwards.</small>
                        </div>
                        <button type="submit" class="btn btn-primary w-100 py-2">
                            <i class="bi bi-arrow-repeat me-1"></i>Request Reschedule
                        </button>
                    </form>

                    <div class="text-center mt-4">
                        <a href="<?= e(APP_URL . '/?page=requests&action=confirm&token=' . urlencode($rawToken)) ?>"
                           class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-arrow-left me-1"></i>Back
                        </a>
                    </div>
                <?php else: ?>
                    <div class="d-grid gap-2 d-sm-flex justify-content-sm-center">
                        <a href="<?= e(APP_URL . '/?page=requests&action=confirm&token=' . urlencode($rawToken) . '&choice=proceed') ?>"
                           class="btn btn-success btn-lg px-4">
                            <i class="bi bi-check-circle me-1"></i>Proceed
                        </a>
                        <a href="<?= e(APP_URL . '/?page=requests&action=confirm&token=' . urlencode($rawToken) . '&choice=decline') ?>"
                           class="btn btn-outline-danger btn-lg px-4">
                            <i class="bi bi-x-circle me-1"></i>Don't Proceed
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <p class="text-center text-muted small mt-4 mb-0">
        <i class="bi bi-shield-lock me-1"></i><?= e(APP_NAME) ?> &mdash; automated trip confirmation
    </p>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
