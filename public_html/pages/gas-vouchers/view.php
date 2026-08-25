<?php
/**
 * LOKA - Gas Voucher View Page
 */

$voucherId = (int) get('id', 0);
if (!$voucherId) {
    redirectWith('/?page=gas-vouchers', 'danger', 'Invalid voucher ID.');
}

$voucher = db()->fetch(
    "SELECT gv.*,
            u.name AS requester_name, u.email AS requester_email,
            reviewer.name AS reviewer_name,
            approver.name AS approver_name_full,
            rejector.name AS rejector_name,
            req_reviewer.name AS requested_reviewer_name,
            req_approver.name AS requested_approver_name
     FROM gas_vouchers gv
     JOIN users u ON gv.requested_by_user_id = u.id
     LEFT JOIN users reviewer ON gv.reviewed_by = reviewer.id
     LEFT JOIN users approver ON gv.approved_by = approver.id
     LEFT JOIN users rejector ON gv.rejected_by = rejector.id
     LEFT JOIN users req_reviewer ON gv.requested_reviewer_id = req_reviewer.id
     LEFT JOIN users req_approver ON gv.requested_approver_id = req_approver.id
     WHERE gv.id = ? AND gv.deleted_at IS NULL",
    [$voucherId]
);

if (!$voucher) {
    redirectWith('/?page=gas-vouchers', 'danger', 'Gas voucher not found.');
}

// Access control: only owner, admin, approvers, or Chief Admin/Finance can view
if ($voucher->requested_by_user_id != userId() && !isAdmin() && !isApprover() && !isMotorpool() && !isChiefAdminFinance()) {
    redirectWith('/?page=gas-vouchers', 'danger', 'Access denied.');
}

$pageTitle = 'Gas Voucher: ' . $voucher->voucher_no;

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container-fluid py-4">
    <div class="max-w-5xl mx-auto">

        <!-- Page Header -->
        <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-sm-between gap-4 mb-4">
            <div>
                <h1 class="fs-3 fw-bold d-flex align-items-center gap-2">
                    <i class="bi bi-fuel-pump"></i>Gas Voucher
                </h1>
                <div class="small text-muted mt-1">
                    <a href="<?= APP_URL ?>" class="">Dashboard</a>
                    <span class="mx-1">/</span>
                    <a href="<?= APP_URL ?>/?page=gas-vouchers" class="">Gas Vouchers</a>
                    <span class="mx-1">/</span>
                    <span><?= e($voucher->voucher_no) ?></span>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <?php if ($voucher->status === 'approved'): ?>
                <a href="<?= APP_URL ?>/?page=gas-vouchers&action=print&id=<?= $voucher->id ?>"
                   class="btn btn-success" target="_blank">
                    <i class="bi bi-printer me-1"></i>Print Voucher
                </a>
                <?php endif; ?>
                <?php
                $canEditView = false;
                if ($voucher->status === 'draft' && ($voucher->requested_by_user_id == userId() || isAdmin())) {
                    $canEditView = true;
                } elseif (in_array($voucher->status, ['pending_review', 'pending_approval']) && (isApprover() || isMotorpool() || isAdmin() || isChiefAdminFinance())) {
                    $canEditView = true;
                }
                if ($canEditView):
                ?>
                <a href="<?= APP_URL ?>/?page=gas-vouchers&action=edit&id=<?= $voucher->id ?>"
                   class="btn btn-secondary">
                    <i class="bi bi-pencil me-1"></i>Edit
                </a>
                <?php endif; ?>
                <a href="<?= APP_URL ?>/?page=gas-vouchers" class="btn btn-secondary">
                    <i class="bi bi-arrow-left me-1"></i>Back
                </a>
            </div>
        </div>

        <!-- Status Banner -->
        <div class="alert alert-<?= gasVoucherStatusColor($voucher->status) ?> mb-4">
            <i class="bi bi-info-circle-fill fs-5"></i>
            <div>
                <strong>Status: <?= gasVoucherStatusLabel($voucher->status) ?></strong>
                <?php if ($voucher->status === 'pending_review'): ?>
                — Awaiting review by OIC, Motor Pool Unit.
                <?php elseif ($voucher->status === 'pending_approval'): ?>
                — Awaiting final approval by Chief, Admin. and Finance Division.
                <?php elseif ($voucher->status === 'approved'): ?>
                — This voucher is authorized. Bearer may secure the fuel/items.
                <?php elseif ($voucher->status === 'rejected'): ?>
                — This voucher has been rejected. <?= $voucher->rejection_reason ? 'Reason: ' . e($voucher->rejection_reason) : '' ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="row g-4">

            <!-- Left Column: Main Details -->
            <div class="col-lg-2 d-flex gap-2">

                <!-- Voucher Details -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title"><i class="bi bi-file-earmark-text me-2"></i>Voucher Details</h3>
                    </div>
                    <div class="card-body">
                        <div class="row g-4">
                            <div>
                                <div class="small text-muted mb-1">Voucher No.</div>
                                <div class="fs-4 fw-bold text-primary"><?= e($voucher->voucher_no) ?></div>
                            </div>
                            <div>
                                <div class="small text-muted mb-1">Request Date</div>
                                <div class="fw-semibold"><?= e(date('M d, Y', strtotime($voucher->request_date))) ?></div>
                            </div>
                            <div>
                                <div class="small text-muted mb-1">Requested By</div>
                                <div class="fw-semibold"><?= e($voucher->requester_name) ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Vehicle & Driver -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title"><i class="bi bi-car-front me-2"></i>Vehicle & Driver</h3>
                    </div>
                    <div class="card-body">
                        <div class="row g-4">
                            <div>
                                <div class="small text-muted mb-1">Driver (Bearer)</div>
                                <div class="fw-semibold"><?= e($voucher->driver_name) ?></div>
                            </div>
                            <div>
                                <div class="small text-muted mb-1">Vehicle Plate No.</div>
                                <div class="fw-semibold"><span class="badge bg-light text-dark bg-secondary fs-5"><?= e($voucher->vehicle_plate) ?></span></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Articles / Fuel -->
                <div class="card">
                    <div class="card-header bg-warning text-dark">
                        <h3 class="card-title"><i class="bi bi-fuel-pump me-2"></i>Articles Requested</h3>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th>Qty</th>
                                        <th>Unit</th>
                                        <th>Article</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><?= e($voucher->quantity) ?></td>
                                        <td><?= e($voucher->unit) ?></td>
                                        <td><?= e($voucher->fuel_type) ?></td>
                                    </tr>
                                    <?php if ($voucher->other_items || $voucher->other_qty || $voucher->other_unit): ?>
                                    <tr>
                                        <td><?= e($voucher->other_qty) ?></td>
                                        <td><?= e($voucher->other_unit) ?></td>
                                        <td><?= e($voucher->other_items) ?></td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Purpose & Fund -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title"><i class="bi bi-clipboard-data me-2"></i>Fund & Purpose</h3>
                    </div>
                    <div class="card-body">
                        <div class="row g-4">
                            <div>
                                <div class="small text-muted mb-1">Fund Source</div>
                                <span class="badge bg-light text-dark bg-secondary fs-5"><?= e($voucher->fund_source) ?></span>
                                <div class="small text-muted mt-1">Project/program the fuel is derived from</div>
                            </div>
                            <?php if ($voucher->chargeable_against): ?>
                            <div>
                                <div class="small text-muted mb-1">Chargeable Against</div>
                                <span class="badge bg-light text-dark bg-secondary fs-5"><?= e($voucher->chargeable_against) ?></span>
                                <div class="small text-muted mt-1">Specific project/budget the fuel is charged to</div>
                            </div>
                            <?php endif; ?>
                            <?php if ($voucher->saro_no): ?>
                            <div>
                                <div class="small text-muted mb-1">SARO No.</div>
                                <div class="fw-semibold"><?= e($voucher->saro_no) ?></div>
                            </div>
                            <?php endif; ?>
                            <div class="col-sm-3">
                                <div class="small text-muted mb-1">Purpose</div>
                                <p class="mb-0"><?= e($voucher->purpose) ?></p>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Right Column: Workflow -->
            <div class="d-flex gap-2">

                <!-- Approval Workflow -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title"><i class="bi bi-diagram-3 me-2"></i>Approval Workflow</h3>
                    </div>
                    <div class="card-body p-0">
                        <ul class="divide-y divide-base-200">

                            <!-- Step 1: Submitted -->
                            <li class="d-flex align-items-start gap-3 p-4">
                                <div class="text-success mt-1"><i class="bi bi-check-circle-fill fs-5"></i></div>
                                <div>
                                    <div class="fw-semibold">Submitted</div>
                                    <div class="small text-muted">by <?= e($voucher->requester_name) ?></div>
                                    <div class="small text-muted"><?= e(date('M d, Y h:i A', strtotime($voucher->created_at))) ?></div>
                                </div>
                            </li>

                            <!-- Step 2: Reviewed by OIC Motorpool -->
                            <li class="d-flex align-items-start gap-3 p-4">
                                <?php if (in_array($voucher->status, ['pending_approval', 'approved', 'rejected']) && $voucher->reviewed_by): ?>
                                <div class="text-success mt-1"><i class="bi bi-check-circle-fill fs-5"></i></div>
                                <div>
                                    <div class="fw-semibold">Reviewed</div>
                                    <div class="small text-muted">by <?= e($voucher->reviewer_name) ?> (OIC, Motor Pool)</div>
                                    <div class="small text-muted"><?= e(date('M d, Y h:i A', strtotime($voucher->reviewed_at))) ?></div>
                                    <?php if ($voucher->reviewer_notes): ?>
                                    <div class="mt-1 small text-muted italic">"<?= e($voucher->reviewer_notes) ?>"</div>
                                    <?php endif; ?>
                                    <?php if ($voucher->requested_reviewer_name && $voucher->requested_reviewer_name !== $voucher->reviewer_name): ?>
                                    <div class="mt-1 small text-muted">Motorpool Head: <?= e($voucher->requested_reviewer_name) ?></div>
                                    <?php endif; ?>
                                </div>
                                <?php elseif ($voucher->status === 'pending_review'): ?>
                                <div class="text-warning mt-1"><i class="bi bi-hourglass-split fs-5"></i></div>
                                <div>
                                    <div class="fw-semibold">Pending Review</div>
                                    <div class="small text-muted">OIC, Motor Pool Unit</div>
                                </div>
                                <?php else: ?>
                                <div class="text-muted mt-1"><i class="bi bi-circle fs-5"></i></div>
                                <div class="text-muted">
                                    <div class="fw-semibold">Review</div>
                                    <div class="small">OIC, Motor Pool Unit</div>
                                </div>
                                <?php endif; ?>
                            </li>

                            <!-- Step 3: Approved by Chief Admin & Finance -->
                            <li class="d-flex align-items-start gap-3 p-4">
                                <?php if ($voucher->status === 'approved'): ?>
                                <div class="text-success mt-1"><i class="bi bi-check-circle-fill fs-5"></i></div>
                                <div>
                                    <div class="fw-semibold">Approved</div>
                                    <div class="small text-muted">by <?= e($voucher->approver_name_full) ?> (Chief, Admin & Finance)</div>
                                    <div class="small text-muted"><?= e(date('M d, Y h:i A', strtotime($voucher->approved_at))) ?></div>
                                    <?php if ($voucher->approver_notes): ?>
                                    <div class="mt-1 small text-muted italic">"<?= e($voucher->approver_notes) ?>"</div>
                                    <?php endif; ?>
                                    <?php if ($voucher->requested_approver_name && $voucher->requested_approver_name !== $voucher->approver_name_full): ?>
                                    <div class="mt-1 small text-muted">Chief Admin & Finance: <?= e($voucher->requested_approver_name) ?></div>
                                    <?php endif; ?>
                                </div>
                                <?php elseif ($voucher->status === 'rejected'): ?>
                                <div class="text-danger mt-1"><i class="bi bi-x-circle-fill fs-5"></i></div>
                                <div>
                                    <div class="fw-semibold text-danger">Rejected</div>
                                    <div class="small text-muted">by <?= e($voucher->rejector_name ?? 'N/A') ?></div>
                                    <?php if ($voucher->rejection_reason): ?>
                                    <div class="mt-1 small text-danger"><?= e($voucher->rejection_reason) ?></div>
                                    <?php endif; ?>
                                </div>
                                <?php elseif ($voucher->status === 'pending_approval'): ?>
                                <div class="text-warning mt-1"><i class="bi bi-hourglass-split fs-5"></i></div>
                                <div>
                                    <div class="fw-semibold">Pending Approval</div>
                                    <div class="small text-muted">Chief, Admin. and Finance Division</div>
                                </div>
                                <?php else: ?>
                                <div class="text-muted mt-1"><i class="bi bi-circle fs-5"></i></div>
                                <div class="text-muted">
                                    <div class="fw-semibold">Final Approval</div>
                                    <div class="small">Chief, Admin. and Finance Division</div>
                                </div>
                                <?php endif; ?>
                            </li>

                        </ul>
                    </div>
                </div>

                <!-- Payment Status -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title"><i class="bi bi-cash me-2"></i>Payment Status</h3>
                    </div>
                    <div class="card-body text-center">
                        <?php
                        $payColors = ['unpaid' => 'bg-warning text-dark', 'paid' => 'bg-success', 'cancelled' => 'badge-error', 'processed' => 'badge-info'];
                        $payColor = $payColors[$voucher->payment_status] ?? 'bg-secondary';
                        ?>
                        <span class="badge bg-light text-dark <?= $payColor ?> fs-5 px-3 py-1">
                            <?= ucfirst($voucher->payment_status) ?>
                        </span>
                        <?php if ($voucher->date_withdrawn): ?>
                        <div class="mt-2 small text-muted">Withdrawn: <?= e(date('M d, Y', strtotime($voucher->date_withdrawn))) ?></div>
                        <?php endif; ?>
                    </div>
                    <?php if ((isAdmin() || isChiefAdminFinance()) && $voucher->status === 'approved'): ?>
                    <div class="card-footer">
                        <form method="POST" action="<?= APP_URL ?>/?page=gas-vouchers&action=update-payment&id=<?= $voucher->id ?>">
                            <?= csrfField() ?>
                            <div class="d-flex gap-2">
                                <select name="payment_status" class="form-select form-select-sm flex-grow-1">
                                    <option value="unpaid" <?= $voucher->payment_status === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
                                    <option value="paid" <?= $voucher->payment_status === 'paid' ? 'selected' : '' ?>>Paid</option>
                                    <option value="processed" <?= $voucher->payment_status === 'processed' ? 'selected' : '' ?>>Processed</option>
                                    <option value="cancelled" <?= $voucher->payment_status === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                </select>
                                <button type="submit" class="btn btn-primary btn-sm">Update</button>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Process Actions -->
                <?php if (($voucher->status === 'pending_review' && (isMotorpool() || isApprover() || isAdmin() || isChiefAdminFinance())) ||
                          ($voucher->status === 'pending_approval' && (isAdmin() || isMotorpool() || isChiefAdminFinance()))): ?>
                <div class="card border-2 border-warning">
                    <div class="card-header bg-warning text-dark">
                        <h3 class="card-title"><i class="bi bi-check-circle me-2"></i>Process This Voucher</h3>
                    </div>
                    <div class="card-body">
                        <a href="<?= APP_URL ?>/?page=gas-vouchers&action=approve&id=<?= $voucher->id ?>"
                           class="btn btn-warning w-100">
                            <i class="bi bi-pencil-square me-1"></i>Review / Approve
                        </a>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Cancel button for owner -->
                <?php if (in_array($voucher->status, ['draft', 'pending_review']) && $voucher->requested_by_user_id == userId()): ?>
                <div class="card border-2 border-error mt-3">
                    <div class="card-body">
                        <form method="POST" action="<?= APP_URL ?>/?page=gas-vouchers&action=cancel&id=<?= $voucher->id ?>"
                              onsubmit="return confirm('Cancel this gas voucher request?')">
                            <?= csrfField() ?>
                            <button type="submit" class="btn btn-outline-danger w-100">
                                <i class="bi bi-x-circle me-1"></i>Cancel Voucher
                            </button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>

    </div>
</div>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>


