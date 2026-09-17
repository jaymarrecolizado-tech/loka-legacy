<!-- Dispatch / Arrival Modals (Plan #24 stamp UI lives here) -->
<?php foreach ($trips as $trip): ?>
    <?php if (!$trip->actual_dispatch_datetime): ?>
        <div class="modal fade" id="dispatchModal<?= $trip->id ?>" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST" action="<?= APP_URL ?>/?page=guard&action=record_dispatch" enctype="multipart/form-data">
                        <?= csrfField() ?>
                        <input type="hidden" name="request_id" value="<?= $trip->id ?>">

                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="bi bi-box-arrow-right text-success me-2"></i>Record Dispatch
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>

                        <div class="modal-body">
                            <div class="alert alert-info">
                                <strong>Request #<?= $trip->id ?></strong><br>
                                <?= e($trip->requester_name) ?> - <?= e($trip->destination) ?>
                            </div>
                            <?php $tripOb = $obBoundByRequest[(int) $trip->id] ?? null; ?>
                            <?php if ($tripOb !== null): ?>
                            <div class="alert alert-warning py-2 px-3 small">
                                <i class="bi bi-file-earmark-text me-1"></i>
                                This will also stamp <strong>Pass Slip <?= e($tripOb->pass_slip_no) ?></strong>
                                (OB departure<?= $guardEsign !== null ? ' with your saved e-sign' : ' + your signature below' ?>).
                            </div>
                            <?php endif; ?>

                            <div class="mb-3">
                                <label class="form-label">Vehicle</label>
                                <div class="fw-medium"><?= e($trip->plate_number ?? 'Not assigned') ?></div>
                                <small class="text-muted"><?= e($trip->make . ' ' . $trip->vehicle_model) ?></small>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Driver</label>
                                <div class="fw-medium"><?= e($trip->driver_name ?? 'Not assigned') ?></div>
                            </div>

                            <div class="mb-3">
                                <label for="dispatch_time<?= $trip->id ?>" class="form-label">Dispatch Time <span class="text-danger">*</span></label>
                                <input type="datetime-local"
                                       class="form-control"
                                       id="dispatch_time<?= $trip->id ?>"
                                       name="dispatch_time"
                                       value="<?= date('Y-m-d\TH:i') ?>"
                                       required>
                                <small class="text-muted">Current time is pre-filled. Adjust if needed.</small>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Travel Documents (Optional)</label>
                                <div class="card card-body bg-light">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" name="has_travel_order" id="has_travel_order<?= $trip->id ?>" value="1" onchange="toggleTravelOrderInput(<?= $trip->id ?>)">
                                        <label class="form-check-label" for="has_travel_order<?= $trip->id ?>">
                                            <i class="bi bi-file-earmark-text me-1"></i>Travel Order Present
                                        </label>
                                        <input type="text" name="travel_order_number" id="travel_order_number<?= $trip->id ?>" class="form-control form-control-sm mt-2" placeholder="Travel Order No. (Required if checked)" style="display:none;">
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="has_official_business_slip" id="has_ob_slip<?= $trip->id ?>" value="1" onchange="toggleObSlipInput(<?= $trip->id ?>)">
                                        <label class="form-check-label" for="has_ob_slip<?= $trip->id ?>">
                                            <i class="bi bi-file-earmark me-1"></i>Official Business Slip Present
                                        </label>
                                        <input type="text" name="ob_slip_number" id="ob_slip_number<?= $trip->id ?>" class="form-control form-control-sm mt-2" placeholder="OB Slip No. (Required if checked)" style="display:none;">
                                    </div>
                                </div>
                            </div>

                            <?php
                            $odoPhase = 'dispatch';
                            require __DIR__ . '/odometer_fields.php';
                            ?>
                            <?php
                            $obsPhase = 'dispatch';
                            require __DIR__ . '/observation_fields.php';
                            ?>

                            <?php if ($tripOb !== null && $guardEsign === null): ?>
                            <div class="mb-3 border rounded bg-white p-2" id="obSigPad<?= $trip->id ?>" style="touch-action:none;">
                                <label class="form-label fw-semibold mb-1">
                                    <i class="bi bi-vector-pen me-1"></i>Pass Slip Signature <span class="text-danger">*</span>
                                    <small class="text-muted fw-normal">— no saved e-sign on file</small>
                                </label>
                                <canvas id="obSigCanvas<?= $trip->id ?>" class="w-100 d-block ob-guard-sig" style="height:130px; cursor:crosshair;"></canvas>
                                <button type="button" class="btn btn-sm btn-outline-secondary mt-1" id="obSigClear<?= $trip->id ?>"><i class="bi bi-eraser me-1"></i>Clear</button>
                                <div class="form-check mt-1">
                                    <input class="form-check-input" type="checkbox" name="ob_save_esign" value="1" id="obSaveEsign<?= $trip->id ?>">
                                    <label class="form-check-label small" for="obSaveEsign<?= $trip->id ?>">Save as my e-sign for next time</label>
                                </div>
                            </div>
                            <?php endif; ?>

                            <div class="mb-3">
                                <label for="guard_notes<?= $trip->id ?>" class="form-label">Notes (Optional)</label>
                                <textarea class="form-control"
                                          id="guard_notes<?= $trip->id ?>"
                                          name="guard_notes"
                                          rows="2"
                                          placeholder="Any observations about the vehicle condition, passengers, etc."></textarea>
                            </div>
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-check-lg me-1"></i>Confirm Dispatch
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($trip->actual_dispatch_datetime && !$trip->actual_arrival_datetime): ?>
        <div class="modal fade" id="arrivalModal<?= $trip->id ?>" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST" action="<?= APP_URL ?>/?page=guard&action=record_arrival" enctype="multipart/form-data">
                        <?= csrfField() ?>
                        <input type="hidden" name="request_id" value="<?= $trip->id ?>">

                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="bi bi-box-arrow-in-left text-primary me-2"></i>Record Arrival
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>

                        <div class="modal-body">
                            <div class="alert alert-info">
                                <strong>Request #<?= $trip->id ?></strong><br>
                                <?= e($trip->requester_name) ?> - <?= e($trip->destination) ?>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Vehicle</label>
                                <div class="fw-medium"><?= e($trip->plate_number ?? 'Not assigned') ?></div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Driver</label>
                                <div class="fw-medium"><?= e($trip->driver_name ?? 'Not assigned') ?></div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Dispatched At</label>
                                <div class="text-success">
                                    <i class="bi bi-check-circle me-1"></i>
                                    <?= formatDateTime($trip->actual_dispatch_datetime) ?>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="arrival_time<?= $trip->id ?>" class="form-label">Arrival Time <span class="text-danger">*</span></label>
                                <input type="datetime-local"
                                       class="form-control"
                                       id="arrival_time<?= $trip->id ?>"
                                       name="arrival_time"
                                       value="<?= date('Y-m-d\TH:i') ?>"
                                       required>
                                <small class="text-muted">Current time is pre-filled. Adjust if needed.</small>
                            </div>

                            <?php
                            $odoPhase = 'arrival';
                            require __DIR__ . '/odometer_fields.php';
                            ?>
                            <?php
                            $obsPhase = 'arrival';
                            require __DIR__ . '/observation_fields.php';
                            ?>

                            <div class="mb-3">
                                <label for="guard_notes<?= $trip->id ?>" class="form-label">Notes (Optional)</label>
                                <textarea class="form-control"
                                          id="guard_notes<?= $trip->id ?>"
                                          name="guard_notes"
                                          rows="2"
                                          placeholder="Any observations about the vehicle condition upon return..."></textarea>
                            </div>
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg me-1"></i>Confirm Arrival
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>
<?php endforeach; ?>

<?php if (!empty($obBoundByRequest)): ?>
<script src="<?= ASSETS_PATH ?>/js/ob-signature.js?v=<?= e(APP_VERSION) ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (!window.ObSignature) return;
    document.querySelectorAll('.modal[id^="dispatchModal"]').forEach(function (m) {
        var canvas = m.querySelector('canvas.ob-guard-sig');
        if (!canvas) return;
        m.addEventListener('show.bs.modal', function () {
            ObSignature.init({
                canvas: '#' + canvas.id,
                pad: '#obSigPad' + canvas.id.replace('obSigCanvas', ''),
                clear: '#obSigClear' + canvas.id.replace('obSigCanvas', '')
            });
            ObSignature.clear();
        });
        var form = m.querySelector('form');
        form.addEventListener('submit', function (e) {
            if (ObSignature.isEmpty()) {
                e.preventDefault();
                alert('Please sign to stamp the Pass Slip (no saved e-sign on file).');
                return;
            }
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'ob_guard_signature';
            input.value = ObSignature.toDataUrl();
            form.appendChild(input);
        });
    });
});
</script>
<?php endif; ?>

<script>
function toggleTravelOrderInput(id) {
    const checkbox = document.getElementById('has_travel_order' + id);
    const input = document.getElementById('travel_order_number' + id);
    if (checkbox && input) {
        input.style.display = checkbox.checked ? 'block' : 'none';
        input.required = checkbox.checked;
    }
}

function toggleObSlipInput(id) {
    const checkbox = document.getElementById('has_ob_slip' + id);
    const input = document.getElementById('ob_slip_number' + id);
    if (checkbox && input) {
        input.style.display = checkbox.checked ? 'block' : 'none';
        input.required = checkbox.checked;
    }
}
</script>
