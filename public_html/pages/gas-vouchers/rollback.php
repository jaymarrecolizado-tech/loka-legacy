<?php
/**
 * LOKA - Admin workflow rollback for a gas voucher (Plan #39 section C)
 *
 * Stages: draft -> pending_review -> pending_budget -> pending_approval -> approved
 * Targets are any earlier stage; the approval stamps belonging to stages after
 * the target are cleared so the step is genuinely re-done.
 *
 * Route: POST ?page=gas-vouchers&action=rollback&id=N
 */

require_once INCLUDES_PATH . '/rollback.php';
requireRole(ROLE_ADMIN);

$voucherId = (int) get('id');
$link = '/?page=gas-vouchers&action=view&id=' . $voucherId;

$load = static function (int $id, bool $forUpdate = false): ?object {
    return db()->fetch(
        "SELECT * FROM gas_vouchers WHERE id = ? AND deleted_at IS NULL"
        . ($forUpdate ? ' FOR UPDATE' : ''),
        [$id]
    );
};

$voucher = $voucherId ? $load($voucherId) : null;
if (!$voucher) {
    redirectWith('/?page=rollback&tab=gas', 'danger', 'Gas voucher not found.');
}

// ---------------------------------------------------------------------
// POST
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $targetKey = postSafe('target_stage', '', 40);
    $reason = trim(postSafe('reason', '', 2000));

    $target = rollbackGasTargetByKey($voucher, $targetKey);
    if ($target === null) {
        redirectWith($link, 'danger', 'That workflow stage is not a valid rollback target right now.');
    }
    if (strlen($reason) < 10) {
        redirectWith($link, 'danger', 'Please provide a reason (at least 10 characters).');
    }

    $order = array_keys(ROLLBACK_GAS_STAGES);
    $targetIdx = (int) array_search($target['stage'], $order, true);
    $reviewIdx = (int) array_search('pending_review', $order, true);
    $budgetIdx = (int) array_search('pending_budget', $order, true);
    $approvalIdx = (int) array_search('pending_approval', $order, true);
    $now = date(DATETIME_FORMAT);
    $cleared = [];

    try {
        db()->beginTransaction();
        $voucher = $load($voucherId, true);
        if (!$voucher) {
            throw new Exception('Gas voucher not found.');
        }

        $update = ['status' => $target['status'], 'updated_at' => $now];

        if ($targetIdx <= $reviewIdx && $voucher->reviewed_by) {
            $update['reviewed_by'] = null;
            $update['reviewed_at'] = null;
            $update['reviewer_notes'] = null;
            $cleared[] = 'reviewed_by';
        }
        if ($targetIdx <= $budgetIdx && $voucher->budget_reviewed_by) {
            $update['budget_reviewed_by'] = null;
            $update['budget_reviewed_at'] = null;
            $update['budget_officer_notes'] = null;
            $cleared[] = 'budget_reviewed_by';
        }
        if ($targetIdx <= $approvalIdx && $voucher->approved_by) {
            $update['approved_by'] = null;
            $update['approved_at'] = null;
            $update['approver_notes'] = null;
            $cleared[] = 'approved_by';
        }

        db()->update('gas_vouchers', $update, 'id = ?', [$voucherId]);

        auditLog('gas_voucher_rollback', 'gas_voucher', $voucherId, [
            'status' => $voucher->status,
            'reviewed_by' => $voucher->reviewed_by,
            'budget_reviewed_by' => $voucher->budget_reviewed_by,
            'approved_by' => $voucher->approved_by,
        ], [
            'status'         => $target['status'],
            'reason'         => $reason,
            'rolled_back_by' => userId(),
            'cleared'        => $cleared,
        ]);

        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollback();
        }
        error_log('Gas voucher rollback error: ' . $e->getMessage());
        redirectWith($link, 'danger', 'Rollback failed: ' . $e->getMessage());
    }

    // Notify after commit.
    try {
        $label = $target['label'];
        $requesterId = (int) $voucher->requested_by_user_id;
        if ($requesterId > 0 && $requesterId !== (int) userId()) {
            notify($requesterId, 'gas_voucher_rolled_back', 'Gas Voucher Rolled Back',
                'Voucher ' . $voucher->voucher_no . ' has been rolled back to: ' . $label . ".\n\nReason: " . $reason,
                $link);
        }
        $nextActor = $target['status'] === 'pending_review'
            ? ($voucher->requested_reviewer_id ?: null)
            : ($target['status'] === 'pending_budget' ? ($voucher->requested_budget_officer_id ?: null)
                : ($target['status'] === 'pending_approval' ? ($voucher->requested_approver_id ?: null) : null));
        if ($nextActor) {
            notify((int) $nextActor, 'gas_voucher_rolled_back', 'Gas Voucher Rolled Back to You',
                'Voucher ' . $voucher->voucher_no . ' has been rolled back to your step (' . $label . ').'
                . "\n\nReason: " . $reason, $link);
        }
    } catch (Throwable $e) {
        error_log('Gas voucher rollback notify: ' . $e->getMessage());
    }

    redirectWith($link, 'success', 'Voucher ' . $voucher->voucher_no . ' rolled back to: ' . $target['label'] . '.');
    exit;
}

// ---------------------------------------------------------------------
// GET: confirm form
// ---------------------------------------------------------------------
$targets = rollbackGasTargets($voucher);
if ($targets === []) {
    redirectWith($link, 'warning', 'This voucher has no earlier workflow stage to roll back to.');
}

$stageKeys = array_keys(ROLLBACK_GAS_STAGES);
$currentIdx = (int) array_search(rollbackGasCurrentStage($voucher), $stageKeys, true);

$pageTitle = 'Rollback voucher ' . $voucher->voucher_no;
require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header bg-warning text-dark">
                    <h5 class="mb-0"><i class="bi bi-arrow-counterclockwise me-2"></i>Roll back <?= e($voucher->voucher_no) ?></h5>
                </div>
                <div class="card-body">
                    <div class="card bg-light mb-4">
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-sm-4">
                                    <label class="small text-muted">Current status</label>
                                    <div><?= gasVoucherStatusBadge($voucher->status) ?></div>
                                </div>
                                <div class="col-sm-8">
                                    <label class="small text-muted">Driver / Vehicle</label>
                                    <div class="fw-bold"><?= e($voucher->driver_name) ?> — <?= e($voucher->vehicle_plate) ?></div>
                                </div>
                                <div class="col-sm-6">
                                    <label class="small text-muted">Request date</label>
                                    <div><?= formatDate($voucher->request_date) ?></div>
                                </div>
                                <div class="col-sm-6">
                                    <label class="small text-muted">Total cost</label>
                                    <div><?= $voucher->total_cost !== null ? '₱' . number_format((float) $voucher->total_cost, 2) : '—' ?></div>
                                </div>
                                <div class="col-12">
                                    <label class="small text-muted">Purpose</label>
                                    <div><?= e($voucher->purpose) ?></div>
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
                                      placeholder="Explain why this voucher is being rolled back (min. 10 characters)..."></textarea>
                            <small class="text-muted">Recorded in the audit trail.</small>
                        </div>

                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                            <a href="<?= APP_URL ?><?= e($link) ?>" class="btn btn-outline-secondary btn-lg">
                                <i class="bi bi-x-lg me-1"></i>Cancel
                            </a>
                            <button type="submit" class="btn btn-warning btn-lg">
                                <i class="bi bi-arrow-counterclockwise me-1"></i>Roll Back Voucher
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>