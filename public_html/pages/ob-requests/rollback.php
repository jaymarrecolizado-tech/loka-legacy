<?php
/**
 * LOKA - Admin workflow rollback for an OB Pass Slip (Plan #39 section B)
 *
 * Stages: pending_supervisor -> pending_motorpool -> approved -> departed ->
 *         coa_received -> completed
 * Targets are any earlier stage; guard and CoA fields are cleared as needed,
 * the reason is required (min 10 chars) and everything is audited + notified.
 *
 * Route: POST ?page=ob-requests&action=rollback&id=N
 */

if (!function_exists('obFind')) {
    require_once INCLUDES_PATH . '/ob_requests.php';
}
require_once INCLUDES_PATH . '/rollback.php';
requireRole(ROLE_ADMIN);

$obId = (int) get('id');
$link = '/?page=ob-requests&action=view&id=' . $obId;

$ob = $obId ? obFind($obId) : null;
if (!$ob) {
    redirectWith('/?page=rollback&tab=ob', 'danger', 'OB Pass Slip not found.');
}

// ---------------------------------------------------------------------
// POST
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $targetKey = postSafe('target_stage', '', 40);
    $reason = trim(postSafe('reason', '', 2000));

    $target = rollbackObTargetByKey($ob, $targetKey);
    if ($target === null) {
        redirectWith($link, 'danger', 'That workflow stage is not a valid rollback target right now.');
    }
    if (strlen($reason) < 10) {
        redirectWith($link, 'danger', 'Please provide a reason (at least 10 characters).');
    }

    $now = date(DATETIME_FORMAT);
    $order = array_keys(ROLLBACK_OB_STAGES);
    $targetIdx = (int) array_search($target['stage'], $order, true);
    $approvedIdx = (int) array_search('approved', $order, true);
    $currentIdx = (int) array_search(rollbackObCurrentStage($ob), $order, true);

    $cleared = [];

    try {
        db()->beginTransaction();
        db()->query("SELECT id FROM ob_requests WHERE id = ? FOR UPDATE", [$obId]);

        $update = ['status' => $target['status'], 'updated_at' => $now];

        // Leaving the approved stage and everything after it: the gate stamps and
        // the client acknowledgment are no longer valid.
        if ($currentIdx > $approvedIdx && $targetIdx <= $approvedIdx) {
            $update['ob_departure_datetime'] = null;
            $update['ob_arrival_datetime'] = null;
            $update['departure_guard_id'] = null;
            $update['arrival_guard_id'] = null;
            $update['coa_office'] = null;
            $update['coa_representative'] = null;
            $update['coa_purpose'] = null;
            $update['coa_time_from'] = null;
            $update['coa_time_to'] = null;
            $update['coa_acknowledged_at'] = null;
            $update['coa_contact_mobile'] = null;
            $update['coa_contact_email'] = null;
            $update['coa_token_hash'] = null;
            $update['coa_token_expires_at'] = null;
            $cleared[] = 'guard_stamps_and_coa';
        }

        // Un-finalize when going back from Completed.
        if ($target['stage'] !== 'completed') {
            $update['finalized_at'] = null;
            $cleared[] = 'finalized_at';
        }

        db()->update('ob_requests', $update, 'id = ?', [$obId]);

        // ob_approvals.approval_type is an ENUM (supervisor|motorpool|guard|client|
        // requester|system) — an admin rollback is recorded as 'system' with
        // action='rollback' so the timeline renders it as a distinct event.
        obLog($obId, 'system', 'rollback', userId(), $target['label'] . ' — ' . $reason);
        auditLog('ob_rollback', 'ob_request', $obId, [
            'status' => $ob->status,
            'stage'  => rollbackObCurrentStage($ob),
        ], [
            'status'      => $target['status'],
            'reason'      => $reason,
            'rolled_back_by' => userId(),
            'cleared'     => $cleared,
        ]);

        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollback();
        }
        error_log('OB rollback error: ' . $e->getMessage());
        redirectWith($link, 'danger', 'Rollback failed: ' . $e->getMessage());
    }

    // Notify after commit.
    try {
        $label = $target['label'];
        if ((int) $ob->user_id !== (int) userId()) {
            obNotify((int) $ob->user_id, 'ob_rolled_back', 'OB Pass Slip Rolled Back',
                'Pass Slip ' . $ob->pass_slip_no . ' has been rolled back to: ' . $label . '.\n\nReason: ' . $reason,
                $link);
        }
        $nextActor = $target['status'] === 'pending_motorpool'
            ? ($ob->motorpool_head_id ?: null)
            : ($target['status'] === 'pending_supervisor' ? (int) $ob->supervisor_user_id : null);
        if ($nextActor) {
            obNotify((int) $nextActor, 'ob_rolled_back', 'OB Pass Slip Rolled Back to You',
                'Pass Slip ' . $ob->pass_slip_no . ' has been rolled back to your approval level (' . $label . ').'
                . "\n\nReason: " . $reason, $link);
        }
    } catch (Throwable $e) {
        error_log('OB rollback notify: ' . $e->getMessage());
    }

    redirectWith($link, 'success', 'Pass Slip ' . $ob->pass_slip_no . ' rolled back to: ' . $target['label'] . '.');
    exit;
}

// ---------------------------------------------------------------------
// GET: confirm form
// ---------------------------------------------------------------------
$targets = rollbackObTargets($ob);
if ($targets === []) {
    redirectWith($link, 'warning', 'This slip has no earlier workflow stage to roll back to.');
}

$stageKeys = array_keys(ROLLBACK_OB_STAGES);
$currentIdx = (int) array_search(rollbackObCurrentStage($ob), $stageKeys, true);

$pageTitle = 'Rollback ' . $ob->pass_slip_no;
require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header bg-warning text-dark">
                    <h5 class="mb-0"><i class="bi bi-arrow-counterclockwise me-2"></i>Roll back <?= e($ob->pass_slip_no) ?></h5>
                </div>
                <div class="card-body">
                    <div class="card bg-light mb-4">
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-sm-4">
                                    <label class="small text-muted">Current status</label>
                                    <div><span class="badge bg-<?= e(obStatusColor($ob->status)) ?>"><?= e(obStatusLabel($ob->status)) ?></span></div>
                                </div>
                                <div class="col-sm-8">
                                    <label class="small text-muted">Employee</label>
                                    <div class="fw-bold"><?= e($ob->employee_name) ?></div>
                                </div>
                                <div class="col-sm-6">
                                    <label class="small text-muted">OB date</label>
                                    <div><?= formatDate($ob->ob_date) ?></div>
                                </div>
                                <div class="col-sm-6">
                                    <label class="small text-muted">Purpose</label>
                                    <div><?= e($ob->purpose) ?></div>
                                </div>
                                <div class="col-sm-6">
                                    <label class="small text-muted">Vehicle</label>
                                    <div><?= !empty($ob->uses_official_vehicle) ? 'Official DICT vehicle' : 'Private vehicle' ?><?= $ob->plate_number ? ' — ' . e($ob->plate_number) : '' ?></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <form method="POST">
                        <?= csrfField() ?>

                        <div class="mb-4">
                            <label class="form-label fw-bold">Workflow stage</label>
                            <div class="d-flex flex-wrap align-items-center gap-1 mb-3">
                                <?php foreach ($stageKeys as $i => $stage): ?>
                                    <span class="badge <?= $i === $currentIdx
                                        ? 'bg-primary'
                                        : ($i < $currentIdx ? 'bg-secondary bg-opacity-50' : 'bg-light text-muted border') ?>">
                                        <?= $i + 1 ?>. <?= e(rollbackStageLabel($stage)) ?>
                                    </span>
                                    <?php if ($i < count($stageKeys) - 1): ?>
                                        <i class="bi bi-chevron-right text-muted small"></i>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>

                            <div class="list-group">
                                <?php foreach ($targets as $t): ?>
                                    <label class="list-group-item d-flex gap-3 align-items-start">
                                        <input type="radio" class="form-check-input mt-1 flex-shrink-0"
                                               name="target_stage" value="<?= e($t['key']) ?>" required>
                                        <span>
                                            <span class="fw-semibold"><?= e($t['label']) ?></span>
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
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-bold" for="reason">Reason for rollback <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="reason" name="reason" rows="3" required minlength="10"
                                      placeholder="Explain why this slip is being rolled back (min. 10 characters)..."></textarea>
                            <small class="text-muted">Recorded in the slip timeline and the audit trail.</small>
                        </div>

                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                            <a href="<?= APP_URL ?><?= e($link) ?>" class="btn btn-outline-secondary btn-lg">
                                <i class="bi bi-x-lg me-1"></i>Cancel
                            </a>
                            <button type="submit" class="btn btn-warning btn-lg">
                                <i class="bi bi-arrow-counterclockwise me-1"></i>Roll Back Pass Slip
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>