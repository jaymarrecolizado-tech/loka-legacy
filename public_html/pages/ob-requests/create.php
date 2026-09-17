<?php
/**
 * LOKA - Apply for an Official Business Pass Slip (Plan #22)
 * Employee files the slip here; canvas signatures happen later (supervisor,
 * motorpool, guard, CoA). Route: ?page=ob-requests&action=create
 */

if (!function_exists('obFind')) {
    require_once INCLUDES_PATH . '/ob_requests.php';
}
requireAuth();

$pageTitle = 'Apply for OB Pass Slip';
$supervisors = obGetSupervisors();
$motorpoolHeads = getMotorpoolHeads();
$employees = obListActiveUsers();
$meId = (int) userId();
$meName = (string) (currentUser()->name ?? '');
$meShort = obShortPrintedName($meName);
$vehicles = obListVehicles();
$vehicleTrips = [];
foreach (obActiveVehicleTrips() as $t) {
    $vehicleTrips[] = [
        'plate_number' => (string) $t->plate_number,
        'start_datetime' => (string) $t->start_datetime,
        'end_datetime' => (string) $t->end_datetime,
        'start_label' => formatDateTime((string) $t->start_datetime),
        'end_label' => formatDateTime((string) $t->end_datetime),
        'destination' => (string) ($t->destination ?? ''),
        'requester_name' => (string) ($t->requester_name ?? ''),
    ];
}
$errors = [];
$postedParticipantIds = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $purpose = postSafe('purpose', '', 200);
    $obDate = trim((string) post('ob_date', ''));
    $usesOfficialRaw = (string) post('uses_official_vehicle', '');
    $plate = trim((string) post('plate_number', ''));
    $supervisorId = (int) post('supervisor_user_id', 0);
    $motorpoolHeadId = (int) post('motorpool_head_id', 0);
    $postedParticipantIds = $_POST['participant_ids'] ?? [];
    if (!is_array($postedParticipantIds)) {
        $postedParticipantIds = [];
    }
    $validEmployeeIds = array_map(static fn($e): int => (int) $e->id, $employees);
    $extraIds = [];
    foreach ($postedParticipantIds as $raw) {
        $pid = (int) $raw;
        if (in_array($pid, $validEmployeeIds, true)) {
            $extraIds[] = $pid;
        }
    }
    $participantIds = obCollectParticipantIds($extraIds, userId());

    if ($purpose === '' || mb_strlen($purpose) > 200) {
        $errors[] = 'Purpose is required (max 200 characters).';
    }
    if ($obDate === '' || !strtotime($obDate)) {
        $errors[] = 'A valid Official Business date is required.';
    }
    // Official DICT vehicle vs private vehicle (required — never inferred from a blank plate)
    if (!in_array($usesOfficialRaw, ['1', '0'], true)) {
        $errors[] = 'Please choose whether the trip uses an Official DICT vehicle or a Private vehicle.';
    }
    $usesOfficial = $usesOfficialRaw === '1';
    if (!$usesOfficial) {
        // Private: plate and Motorpool Head stay out of the flow entirely
        $plate = '';
        $motorpoolHeadId = 0;
    } else {
        $validPlates = array_map(static fn($v): string => (string) $v->plate_number, $vehicles);
        if ($plate === '' || !in_array($plate, $validPlates, true)) {
            $errors[] = 'Please select the fleet vehicle plate from the list.';
        }
    }
    $supervisorIds = array_map(fn($s) => (int) $s->id, $supervisors);
    if (!in_array($supervisorId, $supervisorIds, true)) {
        $errors[] = 'Please select your Immediate Supervisor.';
    }
    if ($usesOfficial) {
        $headIds = array_map(fn($h) => (int) $h->id, $motorpoolHeads);
        if (!in_array($motorpoolHeadId, $headIds, true)) {
            $errors[] = 'Please select a Motorpool Head.';
        }
    }

    if (empty($errors)) {
        try {
            db()->beginTransaction();

            // Pass Slip No. (YYMMDD-NNN, monthly series) — retry on the unique key
            $obId = 0;
            $passSlipNo = '';
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $passSlipNo = obGeneratePassSlipNo($obDate);
                try {
                    $obId = db()->insert('ob_requests', [
                        'pass_slip_no' => $passSlipNo,
                        'user_id' => userId(),
                        'department_id' => currentUser()->department_id ?: null,
                        'purpose' => $purpose,
                        'ob_date' => date('Y-m-d', strtotime($obDate)),
                        'uses_official_vehicle' => $usesOfficial ? 1 : 0,
                        'plate_number' => $plate !== '' ? $plate : null,
                        'supervisor_user_id' => $supervisorId,
                        'motorpool_head_id' => $motorpoolHeadId > 0 ? $motorpoolHeadId : null,
                        'status' => 'pending_supervisor',
                        'created_at' => date(DATETIME_FORMAT),
                        'updated_at' => date(DATETIME_FORMAT),
                    ]);
                    break;
                } catch (PDOException $e) {
                    if ($e->getCode() === '23000') {
                        continue; // duplicate pass slip no — re-sequence
                    }
                    throw $e;
                }
            }
            if (!$obId) {
                throw new Exception('Could not allocate a Pass Slip No.');
            }

            obSaveParticipants($obId, $participantIds);
            obLog($obId, 'requester', 'submitted', userId(), null);
            db()->commit();

            $link = '/?page=ob-requests&action=view&id=' . $obId;
            obNotify($supervisorId, 'ob_submitted', 'OB Pass Slip For Your Approval',
                'Pass Slip ' . $passSlipNo . ' requires your approval as immediate supervisor.', $link);
            if ($usesOfficial && $motorpoolHeadId > 0) {
                obNotify($motorpoolHeadId, 'ob_submitted_motorpool', 'OB Pass Slip Submitted',
                    'Pass Slip ' . $passSlipNo . ' was filed by ' . (currentUser()->name ?? 'an employee') . ' and will require motorpool approval after the supervisor.', $link);
            }

            auditLog('ob_submitted', 'ob_request', $obId, null, [
                'pass_slip_no' => $passSlipNo,
                'uses_official_vehicle' => $usesOfficial ? 1 : 0,
            ]);

            redirectWith($link, 'success', 'OB Pass Slip ' . $passSlipNo . ' submitted.');
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollback();
            error_log('ob create: ' . $e->getMessage());
            $errors[] = 'Could not submit the OB Pass Slip. Please try again.';
        }
    }
}

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container py-4" style="max-width:860px;">
    <div class="mb-4">
        <h4 class="mb-1"><i class="bi bi-file-earmark-text me-2"></i>Apply — Official Business Pass Slip</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/?page=ob-requests">OB Pass Slips</a></li>
            <li class="breadcrumb-item active">Apply</li>
        </ol></nav>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <?php if (empty($supervisors)): ?>
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle me-1"></i>No Immediate Supervisors are configured yet.
            Ask an administrator to tag users with <strong>OB Approver</strong> in User Management first.
        </div>
    <?php else: ?>
    <div class="card shadow-sm">
        <div class="card-header bg-white"><strong>Pass Slip details</strong></div>
        <div class="card-body">
            <form method="POST" id="obForm">
                <?= csrfField() ?>
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label">Purpose <span class="text-danger">*</span> <small class="text-muted fw-normal">(max 200)</small></label>
                        <textarea class="form-control" id="obPurpose" name="purpose" rows="2" maxlength="200" required
                            placeholder="e.g. Attend the LGU coordination meeting at Santiago City Hall"><?= e(post('purpose', '')) ?></textarea>
                        <div class="d-flex justify-content-end">
                            <small class="text-muted"><span id="obPurposeCount"><?= (int) mb_strlen((string) post('purpose', '')) ?></span>/200</small>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Date of Official Business <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="obDate" name="ob_date" required
                            value="<?= e(post('ob_date', date('Y-m-d'))) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Vehicle used for this Official Business <span class="text-danger">*</span></label>
                        <div class="d-flex gap-4 flex-wrap">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="uses_official_vehicle" id="obOfficial"
                                    value="1" <?= post('uses_official_vehicle', '1') === '1' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="obOfficial">Official DICT vehicle</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="uses_official_vehicle" id="obPrivate"
                                    value="0" <?= post('uses_official_vehicle', '1') === '0' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="obPrivate">Private vehicle <span class="text-muted small">(own car — skips Motorpool approval)</span></label>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 ob-official-only">
                        <label class="form-label">Fleet Vehicle Plate <span class="text-danger">*</span></label>
                        <select class="form-select" id="obPlateSelect" name="plate_number">
                            <option value="">Select fleet vehicle...</option>
                            <?php foreach ($vehicles as $v):
                                $label = (string) $v->plate_number;
                                $mm = trim((string) ($v->make ?? '') . ' ' . (string) ($v->model ?? ''));
                                if ($mm !== '') {
                                    $label .= ' — ' . $mm;
                                }
                            ?>
                            <option value="<?= e((string) $v->plate_number) ?>"
                                data-base-label="<?= e($label) ?>"
                                <?= post('plate_number') === (string) $v->plate_number ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Green = free that day · Red = already on a trip</small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Immediate Supervisor <span class="text-danger">*</span></label>
                        <select class="form-select" name="supervisor_user_id" required>
                            <option value="">Select supervisor...</option>
                            <?php foreach ($supervisors as $s): ?>
                            <option value="<?= (int) $s->id ?>" <?= post('supervisor_user_id') == $s->id ? 'selected' : '' ?>><?= e($s->name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 ob-official-only">
                        <label class="form-label">Motorpool Head <span class="text-danger">*</span></label>
                        <select class="form-select" name="motorpool_head_id" data-required-when-official>
                            <option value="">Select motorpool head...</option>
                            <?php foreach ($motorpoolHeads as $h): ?>
                            <option value="<?= (int) $h->id ?>" <?= post('motorpool_head_id') == $h->id ? 'selected' : '' ?>><?= e($h->name) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted d-none ob-private-note">Not required for private vehicles.</small>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Participants</label>
                        <select class="form-select" id="obParticipants" name="participant_ids[]" multiple>
                            <?php
                            $postedExtras = $postedParticipantIds ?? [];
                            if (!is_array($postedExtras)) {
                                $postedExtras = [];
                            }
                            $selectedIds = array_map('strval', $postedExtras);
                            if (!in_array((string) $meId, $selectedIds, true)) {
                                $selectedIds[] = (string) $meId;
                            }
                            foreach ($employees as $emp):
                                $empName = (string) $emp->name;
                                $dept = trim((string) ($emp->department_name ?? ''));
                                $optLabel = $dept !== '' ? $empName . ' — ' . $dept : $empName;
                                $isMe = (int) $emp->id === $meId;
                            ?>
                            <option value="<?= (int) $emp->id ?>"
                                data-short="<?= e(obShortPrintedName($empName)) ?>"
                                <?= in_array((string) $emp->id, $selectedIds, true) ? 'selected' : '' ?>>
                                <?= e($optLabel) ?><?= $isMe ? ' (you)' : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted d-block mt-1">
                            Pick employees from the user list. You stay on the slip; others do not sign.
                            Prints as <strong id="obPrintedLine"><?= e($meShort) ?></strong>
                            <span class="text-muted" id="obEmpHint">(printed name of employee / employees)</span>
                        </small>
                    </div>
                </div>

                <hr class="my-4">
                <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i>Submit</button>
                <a href="<?= APP_URL ?>/?page=ob-requests" class="btn btn-outline-secondary">Cancel</a>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="modal fade" id="obPlateBusyModal" tabindex="-1" aria-labelledby="obPlateBusyTitle" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content border-danger">
            <div class="modal-header bg-danger text-white py-2">
                <h6 class="modal-title" id="obPlateBusyTitle">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i>Already on a trip
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2"><strong id="obPlateBusyPlate"></strong> is already on a trip on <span id="obPlateBusyDate"></span>.</p>
                <p class="small text-muted mb-0" id="obPlateBusyDetail"></p>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" id="obPlateBusyPickOther">Choose another</button>
                <button type="button" class="btn btn-sm btn-danger" data-bs-dismiss="modal">Use anyway</button>
            </div>
        </div>
    </div>
</div>

<?php
$pageScripts = '<script src="' . e(ASSETS_PATH) . '/js/ob-plate-select.js?v=' . e(APP_VERSION) . '"></script>'
    . '<script src="' . e(ASSETS_PATH) . '/js/ob-participants.js?v=' . e(APP_VERSION) . '"></script>'
    . '<script>
document.addEventListener("DOMContentLoaded", function () {
    var purposeEl = document.getElementById("obPurpose");
    var purposeCount = document.getElementById("obPurposeCount");
    if (purposeEl && purposeCount) {
        var upd = function () {
            purposeCount.textContent = String(purposeEl.value.length);
            purposeCount.classList.toggle("text-danger", purposeEl.value.length >= 200);
        };
        purposeEl.addEventListener("input", upd);
        upd();
    }
    ObPlateSelect.init({
        select: "#obPlateSelect",
        date: "#obDate",
        modal: "#obPlateBusyModal",
        trips: ' . json_encode($vehicleTrips, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . '
    });
    ObParticipants.init({
        select: "#obParticipants",
        line: "#obPrintedLine",
        requesterId: ' . json_encode((string) $meId) . ',
        requesterShort: ' . json_encode($meShort, JSON_UNESCAPED_UNICODE) . '
    });
    var officialRadio = document.getElementById("obOfficial");
    var privateRadio = document.getElementById("obPrivate");
    function obSyncVehicleKind() {
        var official = officialRadio && officialRadio.checked;
        document.querySelectorAll(".ob-official-only").forEach(function (el) {
            el.classList.toggle("d-none", !official);
            el.querySelectorAll("select, input").forEach(function (f) {
                f.disabled = !official;
                if (f.matches("[data-required-when-official]")) f.required = official;
            });
        });
        document.querySelectorAll(".ob-private-note").forEach(function (el) {
            el.classList.toggle("d-none", official);
        });
    }
    if (officialRadio && privateRadio) {
        officialRadio.addEventListener("change", obSyncVehicleKind);
        privateRadio.addEventListener("change", obSyncVehicleKind);
        obSyncVehicleKind();
    }
});
</script>';
require_once INCLUDES_PATH . '/footer.php';
?>
