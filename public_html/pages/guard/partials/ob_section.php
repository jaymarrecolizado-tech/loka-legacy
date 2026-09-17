<?php
/**
 * LOKA - Guard partial: OB Pass Slip time stamps (Plan #22)
 * Included by pages/guard/index.php. Posts to ?page=ob-requests&action=process
 * (guard role and slip state are re-validated server-side). Departure requires
 * a canvas signature via the shared modal below; arrival is time-only.
 */

$obAwaitingDeparture = db()->fetchAll(
    "SELECT o.id, o.pass_slip_no, o.ob_date, o.purpose, o.plate_number, u.name AS employee_name
     FROM ob_requests o
     JOIN users u ON o.user_id = u.id
     WHERE o.deleted_at IS NULL
       AND o.ob_departure_datetime IS NULL
       AND o.status IN ('approved', 'coa_received', 'completed')
       AND NOT EXISTS (
           SELECT 1 FROM requests r
           WHERE r.ob_request_id = o.id AND r.deleted_at IS NULL AND r.status <> 'cancelled'
       )
     ORDER BY o.ob_date ASC, o.id ASC
     LIMIT 30"
);

$obAwaitingArrival = db()->fetchAll(
    "SELECT o.id, o.pass_slip_no, o.ob_date, o.purpose, o.plate_number, u.name AS employee_name,
            o.ob_departure_datetime
     FROM ob_requests o
     JOIN users u ON o.user_id = u.id
     WHERE o.deleted_at IS NULL
       AND o.ob_departure_datetime IS NOT NULL
       AND o.ob_arrival_datetime IS NULL
       AND o.status IN ('departed', 'coa_received', 'completed')
       AND NOT EXISTS (
           SELECT 1 FROM requests r
           WHERE r.ob_request_id = o.id AND r.deleted_at IS NULL AND r.status <> 'cancelled'
       )
     ORDER BY o.ob_date ASC, o.id ASC
     LIMIT 30"
);

$obCount = count($obAwaitingDeparture) + count($obAwaitingArrival);
$obGuardEsign = obUserEsignPath(userId());
$obPrinted = obPrintedLinesForIds(array_merge(
    array_map(static fn($s) => (int) $s->id, $obAwaitingDeparture),
    array_map(static fn($s) => (int) $s->id, $obAwaitingArrival)
));
?>

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0"><i class="bi bi-file-earmark-text me-2"></i>OB Pass Slips — Official Business Times</h6>
        <span class="badge bg-<?= $obCount > 0 ? 'warning text-dark' : 'secondary' ?>"><?= $obCount ?> waiting</span>
    </div>
    <div class="card-body">
        <?php if ($obCount === 0): ?>
            <p class="text-muted small mb-0">No OB Pass Slips waiting for guard time stamps.</p>
        <?php else: ?>
            <?php foreach ($obAwaitingDeparture as $s): ?>
                <div class="border rounded p-2 mb-2 d-flex justify-content-between flex-wrap gap-2">
                    <div class="small align-self-center">
                        <strong><?= e($s->pass_slip_no) ?></strong> — <?= e($obPrinted[(int) $s->id] ?? $s->employee_name) ?>
                        <span class="text-muted">· <?= e(date('M j', strtotime($s->ob_date))) ?> · <?= e(mb_strimwidth((string) $s->purpose, 0, 60, '…')) ?></span>
                    </div>
                    <?php if ($obGuardEsign !== null): ?>
                    <form method="POST" action="<?= APP_URL ?>/?page=ob-requests&action=process" class="d-inline"
                          onsubmit="return confirm('Stamp departure for <?= e($s->pass_slip_no) ?> now? Your saved e-sign will be used.');">
                        <?= csrfField() ?>
                        <input type="hidden" name="ob_id" value="<?= (int) $s->id ?>">
                        <button type="submit" name="action" value="guard_departure" class="btn btn-sm btn-primary">
                            <i class="bi bi-box-arrow-up-right me-1"></i>Stamp Departure
                        </button>
                    </form>
                    <?php else: ?>
                    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#obDepartModal"
                            data-ob="<?= (int) $s->id ?>" data-no="<?= e($s->pass_slip_no) ?>">
                        <i class="bi bi-box-arrow-up-right me-1"></i>Stamp Departure
                    </button>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php foreach ($obAwaitingArrival as $s): ?>
                <div class="border rounded p-2 mb-2 d-flex justify-content-between flex-wrap gap-2">
                    <div class="small align-self-center">
                        <strong><?= e($s->pass_slip_no) ?></strong> — <?= e($obPrinted[(int) $s->id] ?? $s->employee_name) ?>
                        <span class="text-muted">· departed <?= $s->ob_departure_datetime ? e(date('M j g:i A', strtotime($s->ob_departure_datetime))) : '' ?></span>
                    </div>
                    <form method="POST" action="<?= APP_URL ?>/?page=ob-requests&action=process" class="d-inline"
                          onsubmit="return confirm('Record arrival time for <?= e($s->pass_slip_no) ?> now?');">
                        <?= csrfField() ?>
                        <input type="hidden" name="ob_id" value="<?= (int) $s->id ?>">
                        <button type="submit" name="action" value="guard_arrival" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-box-arrow-in-down me-1"></i>Record Arrival
                        </button>
                    </form>
                </div>
            <?php endforeach; ?>
            <p class="text-muted small mb-0 mt-2">
                <i class="bi bi-info-circle me-1"></i><?= $obGuardEsign !== null
                    ? 'Departures use your saved e-sign; arrival is time-only. Slips bound to a fleet trip are stamped from the trip instead.'
                    : 'Departure requires your canvas signature (you can save it for next time); arrival is time-only. Slips bound to a fleet trip are stamped from the trip instead.' ?>
            </p>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($obAwaitingDeparture) && $obGuardEsign === null): ?>
<!-- Departure signature modal (shared) -->
<div class="modal fade" id="obDepartModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?= APP_URL ?>/?page=ob-requests&action=process" id="obDepartForm">
                <?= csrfField() ?>
                <input type="hidden" name="ob_id" id="obDepartId" value="">
                <div class="modal-header">
                    <h5 class="modal-title">Stamp Departure — <span id="obDepartNo"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted">Records the departure time (<strong>now</strong>) and your canvas signature as guard on duty.</p>
                    <label class="form-label fw-semibold">Guard Signature <span class="text-danger">*</span></label>
                    <div class="border rounded bg-white" id="obSigPad" style="touch-action:none;">
                        <canvas id="obSigCanvas" class="w-100 d-block" style="height:150px; cursor:crosshair;"></canvas>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="obSigClear"><i class="bi bi-eraser me-1"></i>Clear</button>
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" name="save_esign" value="1" id="obSaveEsignChk">
                        <label class="form-check-label small" for="obSaveEsignChk">Save this as my e-sign for next time</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" name="action" value="guard_departure" class="btn btn-primary">
                        <i class="bi bi-box-arrow-up-right me-1"></i>Stamp Departure
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="<?= ASSETS_PATH ?>/js/ob-signature.js?v=<?= e(APP_VERSION) ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var modal = document.getElementById('obDepartModal');
    if (!modal) return;
    modal.addEventListener('show.bs.modal', function (event) {
        var btn = event.relatedTarget;
        document.getElementById('obDepartId').value = btn.getAttribute('data-ob');
        document.getElementById('obDepartNo').textContent = btn.getAttribute('data-no');
        if (window.ObSignature) ObSignature.clear();
    });
    ObSignature.init({ canvas: '#obSigCanvas', pad: '#obSigPad', clear: '#obSigClear' });
    document.getElementById('obDepartForm').addEventListener('submit', function (e) {
        if (ObSignature.isEmpty()) {
            e.preventDefault();
            alert('Please sign before stamping the departure.');
        } else {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'signature';
            input.value = ObSignature.toDataUrl();
            this.appendChild(input);
        }
    });
});
</script>
<?php endif; ?>
