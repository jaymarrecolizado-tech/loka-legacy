<?php
/**
 * LOKA - Admin workflow rollback for a vehicle request (Plan #39)
 *
 * The picker is a workflow-stage stepper (Pending -> Pending Motorpool ->
 * Approved -> Dispatched -> Arrived -> Completed), not a vague "phase" dropdown,
 * and a dispatched/arrived request can also be rolled back to Approved while
 * keeping its assignment (that just clears the guard transaction).
 *
 * Route: POST ?page=requests&action=rollback&id=N
 */

require_once INCLUDES_PATH . '/rollback.php';
requireRole(ROLE_ADMIN);

$requestId = (int) get('id');
if (!$requestId) {
    redirectWith('/?page=rollback', 'danger', 'Request ID required.');
}

/** Load the request plus the guard/vehicle fields the matrix reads. */
$loadRequest = static function (int $id, bool $forUpdate = false): ?object {
    return db()->fetch(
        "SELECT r.*, v.status AS vehicle_status, v.plate_number
         FROM requests r
         LEFT JOIN vehicles v ON r.vehicle_id = v.id
         WHERE r.id = ? AND r.deleted_at IS NULL"
        . ($forUpdate ? ' FOR UPDATE' : ''),
        [$id]
    );
};

$request = $loadRequest($requestId);
if (!$request) {
    redirectWith('/?page=rollback', 'danger', 'Request not found.');
}

$statusLabels = [
    STATUS_PENDING           => 'Pending Department Approval',
    STATUS_PENDING_MOTORPOOL => 'Pending Motorpool Approval',
    STATUS_APPROVED          => 'Approved',
    STATUS_COMPLETED         => 'Completed',
    STATUS_REJECTED          => 'Rejected',
    STATUS_REVISION          => 'For Revision',
    STATUS_CANCELLED         => 'Cancelled',
];

// ---------------------------------------------------------------------
// POST
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('confirm_rollback') === '1') {
    $targetKey = postSafe('target_stage', '', 40);
    $reason = trim(postSafe('reason', '', 2000));
    $expectedUpdatedAt = post('expected_updated_at');

    // Optimistic lock stays HERE, not in the service: it is a form-concurrency
    // concern (someone else touched the row while the admin read the form), not
    // a business rule. The service re-validates target + reason itself.
    if ($expectedUpdatedAt && $request->updated_at !== $expectedUpdatedAt) {
        redirectWith(
            '/?page=rollback&action=process&id=' . $requestId,
            'danger',
            'Rollback failed: Request was modified by someone else. Please review the current state and try again.'
        );
    }

    // Plan #40 — one implementation shared with the AI assistant
    // (includes/rollback_service.php). What ran inline here before now runs
    // there, so the two cannot drift apart.
    require_once INCLUDES_PATH . '/rollback_service.php';
    $res = rollbackServiceRun($requestId, $targetKey, $reason, (int) userId());

    if (!$res['ok']) {
        error_log('Rollback error: ' . $res['error']);
        redirectWith('/?page=rollback&action=process&id=' . $requestId, 'danger', 'Rollback failed: ' . $res['error']);
    }

    $label = $res['after']['stage'] ?? 'the selected stage';
    redirectWith('/?page=rollback&tab=trips', 'success', "Request #{$requestId} rolled back to: {$label}.");
    exit;
}

// ---------------------------------------------------------------------
// GET: confirm form
// ---------------------------------------------------------------------
$targets = rollbackTripTargets($request);
$currentStage = rollbackTripCurrentStage($request);

if ($targets === []) {
    redirectWith(
        '/?page=rollback&tab=trips',
        'warning',
        'Requests in the ' . ($statusLabels[$request->status] ?? $request->status)
            . ' stage have no earlier workflow stage to roll back to.'
    );
}

$pageTitle = 'Rollback Request #' . $requestId;
require_once INCLUDES_PATH . '/header.php';
$stageKeys = array_keys(ROLLBACK_TRIP_STAGES);
$currentIdx = (int) array_search($currentStage, $stageKeys, true);
?>

<div class="container-fluid py-4">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header bg-warning text-dark">
                    <h5 class="mb-0"><i class="bi bi-arrow-counterclockwise me-2"></i>Roll back Request #<?= $requestId ?></h5>
                </div>
                <div class="card-body">
                    <div class="card bg-light mb-4">
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-sm-4">
                                    <label class="small text-muted">Current status</label>
                                    <div><?= requestStatusBadge($request->status) ?></div>
                                </div>
                                <div class="col-sm-8">
                                    <label class="small text-muted">Requester</label>
                                    <div class="fw-bold"><?= e($request->destination) ?></div>
                                </div>
                                <div class="col-sm-6">
                                    <label class="small text-muted">Scheduled</label>
                                    <div><?= formatDateTime($request->start_datetime) ?></div>
                                </div>
                                <div class="col-sm-6">
                                    <label class="small text-muted">Vehicle / Driver</label>
                                    <div>
                                        <?= $request->plate_number ? e($request->plate_number) : '—' ?>
                                        <?php if (!empty($request->driver_id)): ?>
                                            <span class="text-muted">(assigned)</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php if ((int) $request->rollback_count > 0): ?>
                                <div class="col-sm-6">
                                    <label class="small text-muted">Previous rollbacks</label>
                                    <span class="badge bg-warning text-dark"><?= (int) $request->rollback_count ?></span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Workflow stepper -->
                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="confirm_rollback" value="1">
                        <input type="hidden" name="expected_updated_at" value="<?= e($request->updated_at) ?>">

                        <div class="mb-4">
                        <label class="form-label fw-bold">Workflow stage</label>
                        <div class="d-flex flex-wrap align-items-center gap-1 mb-3">
                            <?php foreach ($stageKeys as $i => $stage): ?>
                                <?php
                                $isCurrent = ($i === $currentIdx);
                                $isSide = $isCurrent && !in_array($request->status, $stageKeys, true);
                                ?>
                                <span class="badge <?= $isCurrent
                                    ? ($isSide ? 'bg-danger' : 'bg-primary')
                                    : ($i < $currentIdx ? 'bg-secondary bg-opacity-50' : 'bg-light text-muted border') ?>">
                                    <?= $i + 1 ?>. <?= e(rollbackStageLabel($stage)) ?>
                                    <?php if ($isSide): ?><?= e($statusLabels[$request->status] ?? $request->status) ?><?php endif; ?>
                                </span>
                                <?php if ($i < count($stageKeys) - 1): ?>
                                    <i class="bi bi-chevron-right text-muted small"></i>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>

                        <?php if (empty($targets)): ?>
                            <div class="alert alert-secondary mb-0">No earlier workflow stage is available for this request.</div>
                        <?php else: ?>
                            <div class="list-group">
                                <?php foreach ($targets as $t): ?>
                                    <label class="list-group-item d-flex gap-3 align-items-start">
                                        <input type="radio" class="form-check-input mt-1 flex-shrink-0"
                                               name="target_stage" value="<?= e($t['key']) ?>" required>
                                        <span>
                                            <span class="fw-semibold"><?= e($t['label']) ?></span>
                                            <?php if ($t['same_stage']): ?>
                                                <span class="badge bg-info ms-1">stays assigned</span>
                                            <?php endif; ?>
                                            <ul class="small text-muted mb-0 mt-1 ps-3">
                                                <?php foreach ($t['effects'] as $effect): ?>
                                                    <li><?= e($effect) ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <small class="text-muted">Pick a stage to roll back to. Side effects are listed above.</small>
                        <?php endif; ?>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-bold" for="reason">Reason for rollback <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="reason" name="reason" rows="3" required minlength="10"
                                      placeholder="Explain why this request is being rolled back (min. 10 characters)..."></textarea>
                            <small class="text-muted">Recorded in the audit trail and shown in the approval history.</small>
                        </div>

                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                            <a href="<?= APP_URL ?>/?page=rollback&tab=trips" class="btn btn-outline-secondary btn-lg">
                                <i class="bi bi-x-lg me-1"></i>Cancel
                            </a>
                            <button type="submit" class="btn btn-warning btn-lg" <?= empty($targets) ? 'disabled' : '' ?>>
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