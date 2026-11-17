<?php
/**
 * LOKA - OB Pass Slip view + per-role actions (Plan #22)
 * Route: ?page=ob-requests&action=view&id=
 */

if (!function_exists('obFind')) {
    require_once INCLUDES_PATH . '/ob_requests.php';
}
requireAuth();

$obId = (int) get('id', 0);
$ob = obFind($obId);
if (!$ob) {
    redirectWith('/?page=ob-requests', 'danger', 'OB Pass Slip not found.');
}

$pageTitle = 'OB Pass Slip ' . $ob->pass_slip_no;
$isOwner = (int) $ob->user_id === (int) userId();
$isSupervisor = (int) $ob->supervisor_user_id === (int) userId();
$canApproveSup = $isSupervisor && $ob->status === 'pending_supervisor';
$canApproveMp = isMotorpool() && $ob->status === 'pending_motorpool';
$canAct = $canApproveSup || $canApproveMp;
$canCancelOwner = $isOwner && in_array($ob->status, ['pending_supervisor', 'pending_motorpool', 'approved', 'revision'], true);
$canCancelApprover = $canAct; // supervisor/motorpool holding the slip may terminal-cancel (Plan #36)
$canCancel = $canCancelOwner || $canCancelApprover;
$canResubmit = $isOwner && $ob->status === 'revision';
$canPrint = in_array($ob->status, ['approved', 'departed', 'coa_received', 'completed'], true);
$canCoa = $isOwner && in_array($ob->status, ['approved', 'departed'], true) && !obCoaReceived($ob);
$coaReceived = obCoaReceived($ob);
$coaAckAt = obCoaAcknowledgedAt($ob);
$canFinalize = $isOwner && $coaReceived && in_array($ob->status, ['approved', 'departed', 'coa_received'], true);
$showTokenBanner = $isOwner && get('token') !== null && get('token') !== '';
$bound = obBoundVehicleRequest((int) $ob->id);
$canBindHere = obAttachAfterSubmitAllowed() && $isOwner && !$bound
    && in_array($ob->status, ['approved', 'departed', 'coa_received', 'completed'], true);
$participants = obListParticipants((int) $ob->id);
$printedLine = obPrintedEmployeeLine(obParticipantFullNames((int) $ob->id, (string) $ob->employee_name));
$hasLegacySignature = $ob->supervisor_signature_path !== null || $ob->motorpool_signature_path !== null
    || $ob->guard_departure_signature_path !== null || $ob->coa_signature_path !== null;

$timeline = db()->fetchAll(
    "SELECT a.*, u.name AS actor_name
     FROM ob_approvals a
     LEFT JOIN users u ON a.approver_user_id = u.id
     WHERE a.ob_request_id = ?
     ORDER BY a.created_at ASC, a.id ASC",
    [$obId]
);

$sigBlocks = [
    'employee' => ['Employee', $ob->employee_signature_path],
    'supervisor' => ['Immediate Supervisor', $ob->supervisor_signature_path],
    'motorpool' => [obUsesOfficialVehicle($ob) ? 'Motorpool Head' : 'Motorpool Head (N/A — private)', $ob->motorpool_signature_path],
    'guard_departure' => ['Guard on Duty', $ob->guard_departure_signature_path],
    'coa' => ['Receiving Client (CoA)', $ob->coa_signature_path],
];

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container py-4" style="max-width:900px;">
    <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
        <div>
            <h4 class="mb-1"><i class="bi bi-file-earmark-text me-2"></i><?= e($ob->pass_slip_no) ?>
                <span class="badge bg-<?= e(obStatusColor($ob->status)) ?> ms-2 align-middle"><?= e(obStatusLabel($ob->status)) ?></span>
            </h4>
            <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= APP_URL ?>/?page=ob-requests">OB Pass Slips</a></li>
                <li class="breadcrumb-item active"><?= e($ob->pass_slip_no) ?></li>
            </ol></nav>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <?php if ($canPrint): ?>
            <a href="<?= APP_URL ?>/?page=ob-requests&action=print&id=<?= (int) $ob->id ?>" class="btn btn-outline-dark" target="_blank">
                <i class="bi bi-printer me-1"></i>Print / PDF
            </a>
            <?php endif; ?>
            <?php
            // Plan #39 — admin workflow rollback, only when an earlier stage exists.
            if (isAdmin()) {
                require_once INCLUDES_PATH . '/rollback.php';
                $obTargets = rollbackObTargets($ob);
                if ($obTargets !== []): ?>
                    <a href="<?= APP_URL ?>/?page=ob-requests&action=rollback&id=<?= (int) $ob->id ?>"
                       class="btn btn-outline-warning" title="Roll this slip back to an earlier workflow stage">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Rollback (<?= count($obTargets) ?> stage<?= count($obTargets) > 1 ? 's' : '' ?>)
                    </a>
                <?php endif;
            } ?>
            <a href="<?= APP_URL ?>/?page=ob-requests" class="btn btn-outline-secondary">Back</a>
        </div>
    </div>

    <?php if ($showTokenBanner): ?>
        <div class="alert alert-success">
            <strong>Client signing link (one-time, expires in <?= (int) obCoaTokenDays() ?> day(s))</strong>
            <div class="input-group input-group-sm mt-2">
                <input class="form-control" readonly value="<?= e(APP_URL . '/?page=ob-requests&action=coa-sign&token=' . get('token')) ?>" id="coaLink">
                <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('coaLink').value).then(()=>this.textContent='Copied!')">Copy</button>
            </div>
            <small class="text-muted d-block mt-1">Send this to the receiving client — no LOKA account needed. The link stops working after they sign.</small>
        </div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header bg-white"><strong>Pass Slip</strong></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <p class="mb-1"><strong>Employee:</strong> <?= e($ob->employee_name) ?>
                        <?php if ($ob->department_name): ?><span class="text-muted">— <?= e($ob->department_name) ?></span><?php endif; ?></p>
                    <p class="mb-1"><strong>Participants:</strong>
                        <?php
                        $partNames = $participants ? array_map(static fn($p) => (string) $p->name, $participants) : [(string) $ob->employee_name];
                        echo e(obJoinNames($partNames));
                        ?>
                        <span class="text-muted">— signature line and CoA print as <?= e($printedLine) ?></span>
                    </p>
                    <p class="mb-1"><strong>Date of OB:</strong> <?= e(date('l, F j, Y', strtotime($ob->ob_date))) ?></p>
                    <p class="mb-1"><strong>Vehicle Plate No.:</strong> <?= obUsesOfficialVehicle($ob) ? e($ob->plate_number ?: '—') : 'Private vehicle' ?></p>
                </div>
                <div class="col-md-6">
                    <p class="mb-1"><strong>Immediate Supervisor:</strong> <?= e($ob->supervisor_name) ?></p>
                    <p class="mb-1"><strong>Motorpool Head:</strong> <?= obUsesOfficialVehicle($ob) ? e($ob->motorpool_name ?? '—') : 'N/A — private vehicle' ?></p>
                    <p class="mb-1"><strong>Filed:</strong> <?= e(formatDateTime($ob->created_at)) ?></p>
                </div>
                <div class="col-12">
                    <p class="mb-0"><strong>Purpose:</strong><br><?= nl2br(e($ob->purpose)) ?></p>
                </div>
            </div>
            <hr>
            <div class="row g-3 small">
                <div class="col-md-6">
                    <strong><i class="bi bi-shield-check me-1"></i>Guard times</strong><br>
                    Departure: <?= $ob->ob_departure_datetime ? e(formatDateTime($ob->ob_departure_datetime)) . ' <span class="text-muted">(' . e($ob->departure_guard_name ?? 'guard') . ')</span>' : '<span class="text-muted">not recorded</span>' ?><br>
                    Arrival: <?= $ob->ob_arrival_datetime ? e(formatDateTime($ob->ob_arrival_datetime)) . ' <span class="text-muted">(' . e($ob->arrival_guard_name ?? 'guard') . ')</span>' : '<span class="text-muted">not recorded</span>' ?>
                </div>
                <div class="col-md-6">
                    <strong><i class="bi bi-link-45deg me-1"></i>Vehicle request</strong><br>
                    <?php if ($bound): ?>
                        <a href="<?= APP_URL ?>/?page=requests&action=view&id=<?= (int) $bound->id ?>">Request #<?= (int) $bound->id ?></a>
                        <span class="text-muted">— <?= e($bound->destination) ?></span>
                    <?php else: ?><span class="text-muted">Not attached to a vehicle request.</span><?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if ($isOwner || $isSupervisor || $canApproveMp): ?>
    <div class="card mb-4">
        <div class="card-header bg-white"><strong>Actions</strong></div>
        <div class="card-body d-flex flex-wrap gap-2">
            <?php if ($canApproveSup || $canApproveMp): ?>
                <form method="POST" action="<?= APP_URL ?>/?page=ob-requests&action=process" class="d-inline">
                    <?= csrfField() ?><input type="hidden" name="ob_id" value="<?= (int) $ob->id ?>">
                    <button type="submit" name="action" value="<?= $canApproveSup ? 'approve_supervisor' : 'approve_motorpool' ?>" class="btn btn-success"
                            onclick="return confirm('Approve this pass slip<?= $canApproveSup ? ' as immediate supervisor' : ' as Motorpool Head' ?>? Your logged-in approval is recorded in the audit trail.');">
                        <i class="bi bi-check-lg me-1"></i><?= $canApproveSup ? 'Approve' : 'Approve (Motorpool)' ?>
                    </button>
                </form>
                <button class="btn btn-outline-warning" data-bs-toggle="modal" data-bs-target="#actModal" data-mode="revision">
                    <i class="bi bi-arrow-counterclockwise me-1"></i>Return for Revision
                </button>
            <?php endif; ?>
            <?php if ($canCancelApprover): ?>
                <button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#actModal" data-mode="cancel">
                    <i class="bi bi-slash-circle me-1"></i>Cancel Slip
                </button>
            <?php elseif ($canCancelOwner): ?>
                <form method="POST" action="<?= APP_URL ?>/?page=ob-requests&action=process" class="d-inline"
                      onsubmit="return confirm('Cancel this OB Pass Slip?');">
                    <?= csrfField() ?><input type="hidden" name="ob_id" value="<?= (int) $ob->id ?>">
                    <button type="submit" name="action" value="cancel" class="btn btn-outline-secondary">
                        <i class="bi bi-slash-circle me-1"></i>Cancel Slip
                    </button>
                </form>
            <?php endif; ?>
            <?php if ($canResubmit): ?>
                <form method="POST" action="<?= APP_URL ?>/?page=ob-requests&action=process" class="d-inline">
                    <?= csrfField() ?><input type="hidden" name="ob_id" value="<?= (int) $ob->id ?>">
                    <button type="submit" name="action" value="resubmit" class="btn btn-warning">
                        <i class="bi bi-arrow-repeat me-1"></i>Resubmit
                    </button>
                </form>
            <?php endif; ?>
            <?php if ($canCancelOwner && !$canCancelApprover): ?>
                <form method="POST" action="<?= APP_URL ?>/?page=ob-requests&action=process" class="d-inline"
                      onsubmit="return confirm('Cancel this OB Pass Slip?');">
                    <?= csrfField() ?><input type="hidden" name="ob_id" value="<?= (int) $ob->id ?>">
                    <button type="submit" name="action" value="cancel" class="btn btn-outline-secondary">
                        <i class="bi bi-slash-circle me-1"></i>Cancel Slip
                    </button>
                </form>
            <?php endif; ?>
            <?php if ($canCoa): ?>
                <a href="<?= APP_URL ?>/?page=ob-requests&action=coa&id=<?= (int) $ob->id ?>" class="btn btn-primary">
                    <i class="bi bi-vector-pen me-1"></i>Fill Certificate of Appearance (on-device)
                </a>
                <form method="POST" action="<?= APP_URL ?>/?page=ob-requests&action=process" class="d-inline">
                    <?= csrfField() ?><input type="hidden" name="ob_id" value="<?= (int) $ob->id ?>">
                    <button type="submit" name="action" value="coa_token" class="btn btn-outline-primary">
                        <i class="bi bi-link-45deg me-1"></i>Allow client to acknowledge
                    </button>
                </form>
            <?php endif; ?>
            <?php if ($canFinalize): ?>
                <form method="POST" action="<?= APP_URL ?>/?page=ob-requests&action=process" class="d-inline"
                      onsubmit="return confirm('Finalize this OB Pass Slip? This completes the record.');">
                    <?= csrfField() ?><input type="hidden" name="ob_id" value="<?= (int) $ob->id ?>">
                    <button type="submit" name="action" value="finalize" class="btn btn-dark">
                        <i class="bi bi-check2-circle me-1"></i>Submit for finality
                    </button>
                </form>
            <?php endif; ?>
            <?php if ($isOwner && in_array($ob->status, ['approved', 'departed'], true) && !$coaReceived): ?>
                <span class="align-self-center text-muted small"><i class="bi bi-info-circle me-1"></i>Finalizing requires the client's Certificate of Appearance acknowledgment.</span>
            <?php endif; ?>
            <?php if (!$canCancel && !$canCoa && !$canFinalize && !$canAct && !$canResubmit): ?>
                <span class="text-muted small align-self-center">No actions available for you at this stage.</span>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($canBindHere): ?>
    <div class="card mb-4 border-primary-subtle">
        <div class="card-body py-2">
            <form method="POST" action="<?= APP_URL ?>/?page=ob-requests&action=process" class="row g-2 align-items-end">
                <?= csrfField() ?>
                <input type="hidden" name="ob_id" value="<?= (int) $ob->id ?>">
                <div class="col-auto"><strong class="small">Attach to vehicle request #</strong></div>
                <div class="col-auto"><input type="number" class="form-control form-control-sm" name="request_id" required min="1" style="width:110px;"></div>
                <div class="col-auto">
                    <button type="submit" name="action" value="vehicle_bind" class="btn btn-sm btn-outline-primary">Attach</button>
                </div>
                <div class="col-auto"><small class="text-muted">1 OB = 1 vehicle request; date must match. (Delayed attach is enabled.)</small></div>
            </form>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header bg-white"><strong>Signatures</strong> <span class="badge bg-light text-dark ms-1">legacy slips</span></div>
        <div class="card-body">
            <?php if ($hasLegacySignature): ?>
            <div class="row g-3 text-center">
                <?php foreach ($sigBlocks as $who => [$label, $path]): ?>
                <div class="col-6 col-md">
                    <div class="border rounded bg-white d-flex align-items-center justify-content-center" style="height:80px;">
                        <?php if ($path): ?>
                            <img src="?page=file-view&file=<?= urlencode($path) ?>" alt="<?= e($label) ?> signature" style="max-height:72px; max-width:100%; object-fit:contain;">
                        <?php else: ?>
                            <span class="text-muted small">—</span>
                        <?php endif; ?>
                    </div>
                    <small class="text-muted d-block mt-1"><?= e($label) ?></small>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p class="text-muted small mb-0">
                Approvals are recorded by button — your logged-in identity and the Timeline below are the record (Plan #36).
                Signature images appear only on slips filed before that change.
            </p>
            <?php endif; ?>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header bg-white"><strong>Certificate of Appearance</strong></div>
        <div class="card-body">
            <?php if ($coaReceived): ?>
                <div class="row g-3">
                    <div class="col-md-8">
                        <p class="mb-1"><strong>Office/Establishment:</strong> <?= e($ob->coa_office) ?></p>
                        <p class="mb-1"><strong>Representative:</strong> <?= e($ob->coa_representative) ?></p>
                        <p class="mb-1"><strong>Purpose of visit:</strong> <?= e($ob->coa_purpose ?: '—') ?></p>
                        <p class="mb-1"><strong>Time:</strong> <?= e($ob->coa_time_from) ?> – <?= e($ob->coa_time_to) ?></p>
                        <?php if ($ob->coa_contact_mobile || $ob->coa_contact_email): ?>
                        <p class="mb-1"><strong>Contact for validation:</strong>
                            <?= $ob->coa_contact_mobile ? e($ob->coa_contact_mobile) : '' ?>
                            <?= $ob->coa_contact_mobile && $ob->coa_contact_email ? ' · ' : '' ?>
                            <?= $ob->coa_contact_email ? e($ob->coa_contact_email) : '' ?>
                        </p>
                        <?php endif; ?>
                        <p class="mb-0 text-muted small">
                            Acknowledged <?= $coaAckAt ? e(formatDateTime($coaAckAt)) : '' ?>
                            <?= $ob->coa_signature_path ? ' · legacy signature on file' : ' (proof-of-service confirmation, Plan #36)' ?>
                        </p>
                    </div>
                    <div class="col-md-4 text-center">
                        <?php
                        $coaQr = obCoaVerifyUrl($ob);
                        require_once BASE_PATH . '/vendor/tecnickcom/tcpdf/tcpdf_barcodes_2d.php';
                        $coaBarcode = new TCPDF2DBarcode($coaQr, 'QRCODE,M');
                            $coaSvg = $coaBarcode->getBarcodeSVGcode(3, 3, 'black');
                            $coaSvg = preg_replace('/<\?xml[^>]*\?>\s*/i', '', $coaSvg);
                            $coaSvg = preg_replace('/<!DOCTYPE[^>]*>\s*/i', '', $coaSvg);
                            if (preg_match('/<svg[^>]+width="([\d.]+)"[^>]+height="([\d.]+)"/i', $coaSvg, $coaDims)) {
                                $coaSvg = preg_replace('/<svg([^>]+)>/i', '<svg$1 viewBox="0 0 ' . $coaDims[1] . ' ' . $coaDims[2] . '">', $coaSvg, 1);
                            }
                            ?>
                            <div style="width:110px; line-height:0;"><?= $coaSvg ?></div>
                            <a href="<?= e($coaQr) ?>" target="_blank" class="small text-decoration-none">Verify QR</a>
                        </div>
                        <div class="small text-muted mt-1">Scan to validate this Certificate of Appearance.</div>
                    </div>
                </div>
            <?php else: ?>
                <p class="mb-0 text-muted">Not yet accomplished. The receiving client confirms the proof-of-service notice and leaves a contact — on the requester's device or via a one-time public link.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header bg-white"><strong>Timeline</strong></div>
        <div class="card-body">
            <?php foreach ($timeline as $t): ?>
                <div class="d-flex gap-2 mb-2 small">
                    <span class="text-muted text-nowrap"><?= e(date('M j, Y g:i A', strtotime($t->created_at))) ?></span>
                    <span><span class="badge bg-light text-dark"><?= e(ucfirst($t->approval_type)) ?></span>
                        <?= e(ucwords(str_replace('_', ' ', $t->action))) ?>
                        <?= $t->actor_name ? '<span class="text-muted">— ' . e($t->actor_name) . '</span>' : '' ?>
                        <?= $t->comments ? '<div class="text-muted ms-2">"' . e($t->comments) . '"</div>' : '' ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php if ($canAct || $canCancelApprover): ?>
<!-- Revision / approver-cancel modal (comments required; Plan #36) -->
<div class="modal fade" id="actModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?= APP_URL ?>/?page=ob-requests&action=process">
                <?= csrfField() ?>
                <input type="hidden" name="ob_id" value="<?= (int) $ob->id ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="actModalTitle">Return for Revision</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label">Comments <span class="text-danger">*</span></label>
                    <textarea class="form-control" name="comments" rows="3" maxlength="500" required></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" name="action" value="revision" id="actModalBtn" class="btn btn-warning">Return for Revision</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var actModal = document.getElementById('actModal');
    actModal.addEventListener('show.bs.modal', function (event) {
        var mode = event.relatedTarget.getAttribute('data-mode');
        var title = document.getElementById('actModalTitle');
        var btn = document.getElementById('actModalBtn');
        if (mode === 'cancel') {
            title.textContent = 'Cancel Slip (terminal)';
            btn.value = 'cancel';
            btn.className = 'btn btn-danger';
            btn.textContent = 'Cancel Slip';
        } else {
            title.textContent = 'Return for Revision';
            btn.value = 'revision';
            btn.className = 'btn btn-warning';
            btn.textContent = 'Return for Revision';
        }
    });
});
</script>
<?php endif; ?>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
