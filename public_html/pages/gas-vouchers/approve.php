<?php
/**
 * LOKA - Gas Voucher Approval Processing Page
 *
 * Two-step workflow:
 *  Step 1 (pending_review)    â†’ Reviewed by OIC Motorpool â†’ moves to pending_approval
 *  Step 2 (pending_approval)  â†’ Approved/Rejected by Chief Admin & Finance
 */

requireAnyRole([ROLE_APPROVER, ROLE_MOTORPOOL, ROLE_ADMIN, ROLE_CHIEF_ADMIN_FINANCE, ROLE_OIC_CHIEF_ADMIN_FINANCE]);

$voucherId = (int) get('id', 0);
if (!$voucherId) {
    redirectWith('/?page=gas-vouchers', 'danger', 'Invalid voucher ID.');
}

$voucher = db()->fetch(
    "SELECT gv.*, u.name AS requester_name
     FROM gas_vouchers gv
     JOIN users u ON gv.requested_by_user_id = u.id
     WHERE gv.id = ? AND gv.deleted_at IS NULL",
    [$voucherId]
);

if (!$voucher) {
    redirectWith('/?page=gas-vouchers', 'danger', 'Voucher not found.');
}

// Determine which step we're at and what actions are available
$canReview  = ($voucher->status === 'pending_review' && (isMotorpool() || isApprover() || isAdmin()));
$canApprove = ($voucher->status === 'pending_approval' && (isAdmin() || isMotorpool() || isChiefAdminFinance()));

if (!$canReview && !$canApprove) {
    redirectWith('/?page=gas-vouchers&action=view&id=' . $voucherId, 'warning', 'This voucher cannot be processed at this stage by your role.');
}

// Fetch signatories for dropdowns
$motorpoolHeads = db()->fetchAll(
    "SELECT u.id, u.name FROM users u
     WHERE u.role = ? AND u.deleted_at IS NULL AND u.status = 'active'
     ORDER BY u.name",
    [ROLE_MOTORPOOL]
);

$chiefFinanceUsers = db()->fetchAll(
    "SELECT u.id, u.name FROM users u
     WHERE u.role IN (?, ?) AND u.deleted_at IS NULL AND u.status = 'active'
     ORDER BY u.name",
    [ROLE_CHIEF_ADMIN_FINANCE, ROLE_OIC_CHIEF_ADMIN_FINANCE]
);

$errors = [];
$pageTitle = 'Process Gas Voucher: ' . $voucher->voucher_no;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $decision = post('decision', '');
    $notes    = postSafe('notes', '', 500);
    $selectedReviewer = (int) post('reviewed_by', userId());
    $selectedApprover = (int) post('approved_by', userId());

    if (!in_array($decision, ['review_approve', 'final_approve', 'reject'])) {
        $errors[] = 'Invalid decision.';
    }

    if ($decision === 'reject' && empty($notes)) {
        $errors[] = 'A rejection reason is required.';
    }

    if ($decision === 'review_approve' && $selectedReviewer <= 0) {
        $errors[] = 'Please select a reviewer.';
    }

    if ($decision === 'final_approve' && $selectedApprover <= 0) {
        $errors[] = 'Please select an approver.';
    }

    if (empty($errors)) {
        $notificationsToSend = [];
        $requesterUserId = $voucher->requested_by_user_id;

        if ($decision === 'review_approve') {
            // Notify requester
            $notificationsToSend[] = [
                'user_id' => $requesterUserId,
                'type' => 'gas_voucher_reviewed',
                'title' => 'Gas Voucher Reviewed',
                'message' => "Your gas voucher {$voucher->voucher_no} has been reviewed and is now awaiting final approval.",
                'link' => '/?page=gas-vouchers&action=view&id=' . $voucherId,
                'requestId' => $voucherId
            ];

            // Notify only the selected approver (if set), otherwise all Chief Admin & Finance
            if ($voucher->requested_approver_id) {
                $notificationsToSend[] = [
                    'user_id' => $voucher->requested_approver_id,
                    'type' => 'gas_voucher_reviewed',
                    'title' => 'Gas Voucher Awaiting Final Approval',
                    'message' => "Gas voucher {$voucher->voucher_no} has been reviewed and requires your final approval.",
                    'link' => '/?page=gas-vouchers&action=view&id=' . $voucherId,
                    'requestId' => $voucherId
                ];
            } else {
                $chiefFinanceUsers = db()->fetchAll(
                    "SELECT id FROM users WHERE role IN (?, ?) AND deleted_at IS NULL AND status = 'active'",
                    [ROLE_CHIEF_ADMIN_FINANCE, ROLE_OIC_CHIEF_ADMIN_FINANCE]
                );
                foreach ($chiefFinanceUsers as $user) {
                    $notificationsToSend[] = [
                        'user_id' => $user->id,
                        'type' => 'gas_voucher_reviewed',
                        'title' => 'Gas Voucher Awaiting Final Approval',
                        'message' => "Gas voucher {$voucher->voucher_no} has been reviewed and requires your final approval.",
                        'link' => '/?page=gas-vouchers&action=view&id=' . $voucherId,
                        'requestId' => $voucherId
                    ];
                }
            }

            db()->beginTransaction();
            try {
                db()->update('gas_vouchers', [
                    'status'         => 'pending_approval',
                    'reviewed_by'    => $selectedReviewer,
                    'reviewed_at'    => date(DATETIME_FORMAT),
                    'reviewer_notes' => $notes ?: null,
                    'updated_at'     => date(DATETIME_FORMAT),
                ], 'id = ?', [$voucherId]);
                auditLog('review', 'gas_voucher', $voucherId);
                db()->commit();

                foreach ($notificationsToSend as $notif) {
                    notify($notif['user_id'], $notif['type'], $notif['title'], $notif['message'], $notif['link'], $notif['requestId']);
                }

                redirectWith('/?page=gas-vouchers&action=view&id=' . $voucherId, 'success', 'Gas voucher reviewed. Now awaiting final approval.');
            } catch (Exception $e) {
                db()->rollback();
                $errors[] = 'Error: ' . $e->getMessage();
            }

        } elseif ($decision === 'final_approve') {
            // Notify requester
            $notificationsToSend[] = [
                'user_id' => $requesterUserId,
                'type' => 'gas_voucher_approved',
                'title' => 'Gas Voucher Approved',
                'message' => "Your gas voucher {$voucher->voucher_no} has been approved. You may now print it.",
                'link' => '/?page=gas-vouchers&action=view&id=' . $voucherId,
                'requestId' => $voucherId
            ];

            // Notify only the selected reviewer (if set), otherwise Motorpool Head
            if ($voucher->requested_reviewer_id) {
                $notificationsToSend[] = [
                    'user_id' => $voucher->requested_reviewer_id,
                    'type' => 'gas_voucher_approved',
                    'title' => 'Gas Voucher Approved',
                    'message' => "Gas voucher {$voucher->voucher_no} has been approved and is ready for fuel pickup.",
                    'link' => '/?page=gas-vouchers&action=view&id=' . $voucherId,
                    'requestId' => $voucherId
                ];
            } else {
                $motorpoolUsers = db()->fetchAll(
                    "SELECT id FROM users WHERE role = ? AND deleted_at IS NULL AND status = 'active'",
                    [ROLE_MOTORPOOL]
                );
                foreach ($motorpoolUsers as $user) {
                    $notificationsToSend[] = [
                        'user_id' => $user->id,
                        'type' => 'gas_voucher_approved',
                        'title' => 'Gas Voucher Approved',
                        'message' => "Gas voucher {$voucher->voucher_no} has been approved and is ready for fuel pickup.",
                        'link' => '/?page=gas-vouchers&action=view&id=' . $voucherId,
                        'requestId' => $voucherId
                    ];
                }
            }

            db()->beginTransaction();
            try {
                db()->update('gas_vouchers', [
                    'status'         => 'approved',
                    'approved_by'    => $selectedApprover,
                    'approved_at'    => date(DATETIME_FORMAT),
                    'approver_notes' => $notes ?: null,
                    'updated_at'     => date(DATETIME_FORMAT),
                ], 'id = ?', [$voucherId]);
                auditLog('approve', 'gas_voucher', $voucherId);
                db()->commit();

                foreach ($notificationsToSend as $notif) {
                    notify($notif['user_id'], $notif['type'], $notif['title'], $notif['message'], $notif['link'], $notif['requestId']);
                }

                redirectWith('/?page=gas-vouchers&action=view&id=' . $voucherId, 'success', 'Gas voucher approved. Print the voucher to authorize fuel pick-up.');
            } catch (Exception $e) {
                db()->rollback();
                $errors[] = 'Error: ' . $e->getMessage();
            }

        } elseif ($decision === 'reject') {
            // Notify requester
            $notificationsToSend[] = [
                'user_id' => $requesterUserId,
                'type' => 'gas_voucher_rejected',
                'title' => 'Gas Voucher Rejected',
                'message' => "Your gas voucher {$voucher->voucher_no} has been rejected." . ($notes ? " Reason: {$notes}" : ""),
                'link' => '/?page=gas-vouchers&action=view&id=' . $voucherId,
                'requestId' => $voucherId
            ];

            // Notify only the selected reviewer (if set), otherwise Motorpool Head
            if ($voucher->requested_reviewer_id) {
                $notificationsToSend[] = [
                    'user_id' => $voucher->requested_reviewer_id,
                    'type' => 'gas_voucher_rejected',
                    'title' => 'Gas Voucher Rejected',
                    'message' => "Gas voucher {$voucher->voucher_no} has been rejected." . ($notes ? " Reason: {$notes}" : ""),
                    'link' => '/?page=gas-vouchers&action=view&id=' . $voucherId,
                    'requestId' => $voucherId
                ];
            } else {
                $motorpoolUsers = db()->fetchAll(
                    "SELECT id FROM users WHERE role = ? AND deleted_at IS NULL AND status = 'active'",
                    [ROLE_MOTORPOOL]
                );
                foreach ($motorpoolUsers as $user) {
                    $notificationsToSend[] = [
                        'user_id' => $user->id,
                        'type' => 'gas_voucher_rejected',
                        'title' => 'Gas Voucher Rejected',
                        'message' => "Gas voucher {$voucher->voucher_no} has been rejected." . ($notes ? " Reason: {$notes}" : ""),
                        'link' => '/?page=gas-vouchers&action=view&id=' . $voucherId,
                        'requestId' => $voucherId
                    ];
                }
            }

            db()->beginTransaction();
            try {
                db()->update('gas_vouchers', [
                    'status'           => 'rejected',
                    'rejected_by'      => userId(),
                    'rejected_at'      => date(DATETIME_FORMAT),
                    'rejection_reason' => $notes,
                    'updated_at'       => date(DATETIME_FORMAT),
                ], 'id = ?', [$voucherId]);
                auditLog('reject', 'gas_voucher', $voucherId);
                db()->commit();

                foreach ($notificationsToSend as $notif) {
                    notify($notif['user_id'], $notif['type'], $notif['title'], $notif['message'], $notif['link'], $notif['requestId']);
                }

                redirectWith('/?page=gas-vouchers&action=view&id=' . $voucherId, 'warning', 'Gas voucher has been rejected.');
            } catch (Exception $e) {
                db()->rollback();
                $errors[] = 'Error: ' . $e->getMessage();
            }
        }
    }
}

require_once INCLUDES_PATH . '/header.php';
?>

<div class="w-100 px-4 sm:px-6 lg:px-8">
    <div class="row g-4 justify-content-center">
        <div class="col-12 col-lg-8 xl:col-span-7">

            <!-- Page Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h4 class="mb-1"><i class="bi bi-check-circle me-2"></i>Process Gas Voucher</h4>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-0">
                            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
                            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/?page=gas-vouchers">Gas Vouchers</a></li>
                            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/?page=gas-vouchers&action=view&id=<?= $voucher->id ?>"><?= e($voucher->voucher_no) ?></a></li>
                            <li class="breadcrumb-item active">Process</li>
                        </ol>
                    </nav>
                </div>
                <a href="<?= APP_URL ?>/?page=gas-vouchers&action=view&id=<?= $voucher->id ?>" class="btn btn-secondary">
                    <i class="bi bi-arrow-left me-1"></i>Back
                </a>
            </div>

            <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Voucher Summary Card -->
            <div class="card mb-4 border-primary">
                <div class="px-6 py-4 border-b border-base-200 bg-primary text-white">
                    <h6 class="mb-0"><i class="bi bi-fuel-pump me-2"></i>Voucher Summary</h6>
                </div>
                <div class="p-4">
                    <div class="row g-3">
                        <div class="col-12 col-sm-4">
                            <small class="text-muted">Voucher No.</small>
                            <div class="fw-bold fs-5"><?= e($voucher->voucher_no) ?></div>
                        </div>
                        <div class="col-12 col-sm-4">
                            <small class="text-muted">Request Date</small>
                            <div><?= e(date('M d, Y', strtotime($voucher->request_date))) ?></div>
                        </div>
                        <div class="col-12 col-sm-4">
                            <small class="text-muted">Requested By</small>
                            <div><?= e($voucher->requester_name) ?></div>
                        </div>
                        <div class="col-12 col-sm-4">
                            <small class="text-muted">Driver / Bearer</small>
                            <div class="fw-semibold"><?= e($voucher->driver_name) ?></div>
                        </div>
                        <div class="col-12 col-sm-4">
                            <small class="text-muted">Vehicle Plate</small>
                            <div><span class="badge bg-light text-dark bg-dark text-white"><?= e($voucher->vehicle_plate) ?></span></div>
                        </div>
                        <div class="col-12 col-sm-4">
                            <small class="text-muted">Fuel</small>
                            <div><?= e($voucher->quantity) ?> <?= e($voucher->unit) ?> – <?= e($voucher->fuel_type) ?></div>
                        </div>
                        <div class="col-12 col-sm-6">
                            <small class="text-muted">Fund Source</small>
                            <div><span class="badge bg-light text-dark bg-secondary"><?= e($voucher->fund_source) ?></span></div>
                        </div>
                        <div class="col-12">
                            <small class="text-muted">Purpose</small>
                            <div><?= e($voucher->purpose) ?></div>
                        </div>
                        <?php if ($voucher->other_items || $voucher->other_qty || $voucher->other_unit): ?>
                        <div class="col-12">
                            <small class="text-muted">Other Items</small>
                            <div>
                                <?php if ($voucher->other_qty || $voucher->other_unit): ?>
                                    <strong><?= e($voucher->other_qty) ?> <?= e($voucher->other_unit) ?></strong> - 
                                <?php endif; ?>
                                <?= e($voucher->other_items) ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Current Stage Banner -->
            <?php if ($canReview): ?>
            <div class="alert alert-warning">
                <i class="bi bi-person-badge me-2"></i>
                <strong>Step 1 – Review:</strong> As OIC, Motor Pool Unit, you are reviewing this voucher before it goes for final approval.
            </div>
            <?php elseif ($canApprove): ?>
            <div class="alert alert-info">
                <i class="bi bi-person-check me-2"></i>
                <strong>Step 2 – Final Approval:</strong> As Chief, Admin. and Finance Division, you are authorizing the bearer to secure fuel/items.
            </div>
            <?php endif; ?>

            <!-- Decision Form -->
            <div class="card">
                <div class="px-6 py-4 border-b border-base-200">
                    <h6 class="mb-0"><i class="bi bi-clipboard-check me-2"></i>Your Decision</h6>
                </div>
                <div class="p-4">
                    <div class="mb-4 d-flex align-items-center justify-content-between bg-light p-3 rounded border">
                        <div>
                            <strong>Need to change something?</strong>
                            <div class="text-muted small">You can correct the fund source, quantity, or purpose before approving.</div>
                        </div>
                        <a href="<?= APP_URL ?>/?page=gas-vouchers&action=edit&id=<?= $voucher->id ?>" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-pencil me-1"></i>Edit Voucher Fields
                        </a>
                    </div>
                    
                    <form method="POST">
                        <?= csrfField() ?>

                        <?php if ($canReview): ?>
                        <div class="mb-4">
                            <label class="form-label fw-semibold">Reviewed By (OIC, Motor Pool Unit) <span class="text-danger">*</label>
                            <select name="reviewed_by" class="form-control" required>
                                <option value="">-- Select Reviewer --</option>
                                <?php foreach ($motorpoolHeads as $mp): ?>
                                <option value="<?= $mp->id ?>" <?= 
                                    ($voucher->requested_reviewer_id && $mp->id == $voucher->requested_reviewer_id) ? 'selected' : 
                                    (!$voucher->requested_reviewer_id && $mp->id == userId() ? 'selected' : '')
                                ?>>
                                    <?= e($mp->name) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php elseif ($canApprove): ?>
                        <div class="mb-4">
                            <label class="form-label fw-semibold">Approved By (Chief, Admin. and Finance) <span class="text-danger">*</label>
                            <select name="approved_by" class="form-control" required>
                                <option value="">-- Select Approver --</option>
                                <?php foreach ($chiefFinanceUsers as $cf): ?>
                                <option value="<?= $cf->id ?>" <?= 
                                    ($voucher->requested_approver_id && $cf->id == $voucher->requested_approver_id) ? 'selected' : 
                                    (!$voucher->requested_approver_id && $cf->id == userId() ? 'selected' : '')
                                ?>>
                                    <?= e($cf->name) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>

                        <div class="mb-4">
                            <label class="form-label fw-semibold">Notes / Comments</label>
                            <textarea name="notes" class="form-control" rows="3"
                                      placeholder="Optional notes. Required if rejecting." maxlength="500"></textarea>
                        </div>

                        <div class="d-flex gap-3 flex-wrap">
                            <?php if ($canReview): ?>
                            <button type="submit" name="decision" value="review_approve"
                                    class="btn btn-success px-3 py-1 small fw-normal rounded-xl inline-d-flex align-items-center gap-2 transition-colors d-flex-fill"
                                    onclick="return confirm('Approve this voucher for final review?')">
                                <i class="bi bi-check-circle me-1"></i>Approve for Final Review
                            </button>
                            <?php elseif ($canApprove): ?>
                            <button type="submit" name="decision" value="final_approve"
                                    class="btn btn-success px-3 py-1 small fw-normal rounded-xl inline-d-flex align-items-center gap-2 transition-colors d-flex-fill"
                                    onclick="return confirm('Authorize this gas voucher?')">
                                <i class="bi bi-check2-all me-1"></i>Authorize Voucher
                            </button>
                            <?php endif; ?>

                            <button type="submit" name="decision" value="reject"
                                    class="btn btn-danger px-3 py-1 small fw-normal rounded-xl inline-d-flex align-items-center gap-2 transition-colors d-flex-fill"
                                    onclick="return confirm('Are you sure you want to reject this voucher?')">
                                <i class="bi bi-x-circle me-1"></i>Reject
                            </button>
                        </div>
                    </form>
                </div>
            </div>

        </div>
    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>

