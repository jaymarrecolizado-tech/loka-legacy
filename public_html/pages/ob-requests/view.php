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
$canCancel = $isOwner && in_array($ob->status, ['pending_supervisor', 'pending_motorpool', 'approved', 'revision'], true);
$canResubmit = $isOwner && $ob->status === 'revision';
$canPrint = in_array($ob->status, ['approved', 'departed', 'coa_received', 'completed'], true);
$canCoa = $isOwner && in_array($ob->status, ['approved', 'departed'], true);
$canFinalize = $isOwner && $ob->coa_signature_path !== null && in_array($ob->status, ['approved', 'departed', 'coa_received'], true);
$showTokenBanner = $isOwner && get('token') !== null && get('token') !== '';
$bound = obBoundVehicleRequest((int) $ob->id);
$canBindHere = obAttachAfterSubmitAllowed() && $isOwner && !$bound
    && in_array($ob->status, ['approved', 'departed', 'coa_received', 'completed'], true);
$participants = obListParticipants((int) $ob->id);
$printedLine = obPrintedEmployeeLine(obParticipantFullNames((int) $ob->id, (string) $ob->employee_name));

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
                        <span class="text-muted">— prints as <?= e($printedLine) ?></span>
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
                <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#approveModal">
                    <i class="bi bi-pen me-1"></i><?= $canApproveSup ? 'Approve & Sign' : 'Approve (Motorpool) & Sign' ?>
                </button>
                <button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#actModal" data-mode="reject">
                    <i class="bi bi-x-lg me-1"></i>Reject
                </button>
                <button class="btn btn-outline-warning" data-bs-toggle="modal" data-bs-target="#actModal" data-mode="revision">
                    <i class="bi bi-arrow-counterclockwise me-1"></i>Return for Revision
                </button>
            <?php endif; ?>
            <?php if ($canResubmit): ?>
                <form method="POST" action="<?= APP_URL ?>/?page=ob-requests&action=process" class="d-inline">
                    <?= csrfField() ?><input type="hidden" name="ob_id" value="<?= (int) $ob->id ?>">
                    <button type="submit" name="action" value="resubmit" class="btn btn-warning">
                        <i class="bi bi-arrow-repeat me-1"></i>Resubmit
                    </button>
                </form>
            <?php endif; ?>
            <?php if ($canCancel): ?>
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
                        <i class="bi bi-link-45deg me-1"></i>Allow client to sign
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
            <?php if ($isOwner && in_array($ob->status, ['approved', 'departed'], true) && $ob->coa_signature_path === null): ?>
                <span class="align-self-center text-muted small"><i class="bi bi-info-circle me-1"></i>Finalizing requires a signed Certificate of Appearance.</span>
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
        <div class="card-header bg-white"><strong>Signatures</strong></div>
        <div class="card-body">
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
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header bg-white"><strong>Certificate of Appearance</strong></div>
        <div class="card-body">
            <?php if ($ob->coa_signature_path): ?>
                <p class="mb-1"><strong>Office/Establishment:</strong> <?= e($ob->coa_office) ?></p>
                <p class="mb-1"><strong>Representative:</strong> <?= e($ob->coa_representative) ?></p>
                <p class="mb-1"><strong>Purpose of visit:</strong> <?= e($ob->coa_purpose ?: '—') ?></p>
                <p class="mb-1"><strong>Time:</strong> <?= e($ob->coa_time_from) ?> – <?= e($ob->coa_time_to) ?></p>
                <p class="mb-0 text-muted small">Signed <?= e(formatDateTime($ob->coa_signed_at)) ?></p>
            <?php else: ?>
                <p class="mb-0 text-muted">Not yet accomplished. The receiving client fills this and signs — on the requester's device or via a one-time public link.</p>
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

<?php if ($canApproveSup || $canApproveMp): ?>
<!-- Approve & sign modal -->
<div class="modal fade" id="approveModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?= APP_URL ?>/?page=ob-requests&action=process" id="approveForm">
                <?= csrfField() ?>
                <input type="hidden" name="ob_id" value="<?= (int) $ob->id ?>">
                <div class="modal-header">
                    <h5 class="modal-title"><?= $canApproveSup ? 'Approve as Immediate Supervisor' : 'Approve as Motorpool Head' ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Comments <span class="text-muted">(optional)</span></label>
                        <textarea class="form-control" name="comments" rows="2" maxlength="500"></textarea>
                    </div>
                    <?php $myEsign = obUserEsignPath((int) userId()); ?>
                    <?php if ($myEsign !== null): ?>
                    <div class="border rounded bg-white p-2 mb-2 d-flex align-items-center gap-3">
                        <img src="?page=file-view&file=<?= urlencode($myEsign) ?>" alt="Your saved e-sign" style="max-height:64px; max-width:180px; object-fit:contain;">
                        <small class="text-muted">Your saved e-sign will be used for this approval.</small>
                    </div>
                    <?php else: ?>
                    <label class="form-label fw-semibold">Your Signature <span class="text-danger">*</span></label>
                    <div class="border rounded bg-white" id="obSigPad" style="touch-action:none;">
                        <canvas id="obSigCanvas" class="w-100 d-block" style="height:150px; cursor:crosshair;"></canvas>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="obSigClear"><i class="bi bi-eraser me-1"></i>Clear</button>
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" name="save_esign" value="1" id="saveEsignChk">
                        <label class="form-check-label small" for="saveEsignChk">Save this as my e-sign for next time</label>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" name="action" value="<?= $canApproveSup ? 'approve_supervisor' : 'approve_motorpool' ?>" class="btn btn-success">
                        <i class="bi bi-check-lg me-1"></i><?= $myEsign !== null ? 'Approve (saved e-sign)' : 'Approve &amp; Sign' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reject / revision modal -->
<div class="modal fade" id="actModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?= APP_URL ?>/?page=ob-requests&action=process">
                <?= csrfField() ?>
                <input type="hidden" name="ob_id" value="<?= (int) $ob->id ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="actModalTitle">Reject</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label">Comments <span class="text-danger">*</span></label>
                    <textarea class="form-control" name="comments" rows="3" maxlength="500" required></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" name="action" value="reject" id="actModalBtn" class="btn btn-danger">Reject</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="<?= ASSETS_PATH ?>/js/ob-signature.js?v=<?= e(APP_VERSION) ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var approveCanvas = document.getElementById('obSigCanvas');
    if (approveCanvas) {
        ObSignature.init({ canvas: '#obSigCanvas', pad: '#obSigPad', clear: '#obSigClear' });
        document.getElementById('approveForm').addEventListener('submit', function (e) {
            if (ObSignature.isEmpty()) {
                e.preventDefault();
                alert('Please draw your signature before approving.');
            } else {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'signature';
                input.value = ObSignature.toDataUrl();
                this.appendChild(input);
            }
        });
    }
    var actModal = document.getElementById('actModal');
    actModal.addEventListener('show.bs.modal', function (event) {
        var mode = event.relatedTarget.getAttribute('data-mode');
        document.getElementById('actModalTitle').textContent = mode === 'reject' ? 'Reject' : 'Return for Revision';
        var btn = document.getElementById('actModalBtn');
        btn.value = mode;
        btn.className = 'btn ' + (mode === 'reject' ? 'btn-danger' : 'btn-warning');
    });
});
</script>
<?php endif; ?>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
