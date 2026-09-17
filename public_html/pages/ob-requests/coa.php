<?php
/**
 * LOKA - Certificate of Appearance, on-device mode (Plan #22)
 * The requester opens their approved OB and hands the device to the
 * receiving client, who fills the certificate and signs the canvas.
 * Route: ?page=ob-requests&action=coa&id=
 */

if (!function_exists('obFind')) {
    require_once INCLUDES_PATH . '/ob_requests.php';
}
requireAuth();

$ob = obFind((int) get('id', 0));
if (!$ob || (int) $ob->user_id !== (int) userId()) {
    redirectWith('/?page=ob-requests', 'danger', 'OB Pass Slip not found.');
}
if (!in_array($ob->status, ['approved', 'departed'], true)) {
    redirectWith('/?page=ob-requests&action=view&id=' . $ob->id, 'warning',
        'The Certificate of Appearance can only be filled after the slip is approved.');
}
$participantNames = obParticipantFullNames((int) $ob->id, (string) $ob->employee_name);
$coaWho = obJoinNames($participantNames);
$whoLabel = count(obNameList($participantNames)) > 1 ? 'Personnel' : 'Employee';

$pageTitle = 'Certificate of Appearance — ' . $ob->pass_slip_no;
$errors = [];
$done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $office = trim((string) post('coa_office', ''));
    $rep = trim((string) post('coa_representative', ''));
    $purpose = trim((string) post('coa_purpose', ''));
    $from = trim((string) post('coa_time_from', ''));
    $to = trim((string) post('coa_time_to', ''));
    $signature = (string) post('coa_signature', '');

    if ($office === '' || mb_strlen($office) > 200) $errors[] = 'Office / Establishment is required (max 200).';
    if ($rep === '' || mb_strlen($rep) > 150) $errors[] = 'Representative name is required (max 150).';
    if (mb_strlen($purpose) > 300) $errors[] = 'Purpose of visit is too long (max 300).';
    if ($from === '' || $to === '') $errors[] = 'From and To times are required.';
    if ($signature === '') $errors[] = 'The representative signature is required.';

    if (empty($errors)) {
        $sigPath = obSaveSignature((int) $ob->id, 'coa', $signature);
        if ($sigPath === null) {
            $errors[] = 'Could not save the signature. Please sign again.';
        } else {
            $now = date(DATETIME_FORMAT);
            $newStatus = in_array($ob->status, ['approved', 'departed'], true) ? 'coa_received' : $ob->status;
            db()->update('ob_requests', [
                'coa_office' => $office,
                'coa_representative' => $rep,
                'coa_purpose' => $purpose !== '' ? $purpose : null,
                'coa_time_from' => substr($from, 0, 20),
                'coa_time_to' => substr($to, 0, 20),
                'coa_signature_path' => $sigPath,
                'coa_signed_at' => $now,
                'status' => $newStatus,
                'updated_at' => $now,
            ], 'id = ?', [$ob->id]);
            // invalidate any outstanding public link — the CoA is done
            obClearCoaToken((int) $ob->id);
            obLog((int) $ob->id, 'client', 'coa_signed', userId(), 'On-device (represented by ' . $rep . ')');
            obNotify((int) $ob->user_id, 'ob_coa_signed', 'OB Pass Slip Ready to Finalize',
                'The Certificate of Appearance for Pass Slip ' . $ob->pass_slip_no . ' was signed. It is ready to finalize.',
                '/?page=ob-requests&action=view&id=' . $ob->id);
            auditLog('ob_coa_signed', 'ob_request', (int) $ob->id, null, ['mode' => 'on-device']);
            redirectWith('/?page=ob-requests&action=view&id=' . $ob->id, 'success',
                'Certificate of Appearance saved. You can now finalize the slip.');
        }
    }
}

require_once INCLUDES_PATH . '/header.php';
?>

<div class="container py-4" style="max-width:760px;">
    <div class="mb-4">
        <h4 class="mb-1"><i class="bi bi-vector-pen me-2"></i>Certificate of Appearance</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/?page=ob-requests">OB Pass Slips</a></li>
            <li class="breadcrumb-item"><a href="<?= APP_URL ?>/?page=ob-requests&action=view&id=<?= (int) $ob->id ?>"><?= e($ob->pass_slip_no) ?></a></li>
            <li class="breadcrumb-item active">CoA</li>
        </ol></nav>
        <small class="text-muted d-block mt-2">
            Hand this device to the receiving client. They fill in the certificate and sign — no LOKA account is needed on this page.
        </small>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body">
            <p class="small text-muted mb-3"><?= e($whoLabel) ?>: <strong><?= e($coaWho) ?></strong> · Pass Slip No. <?= e($ob->pass_slip_no) ?> · <?= e(date('M j, Y', strtotime($ob->ob_date))) ?></p>
            <form method="POST" id="coaForm">
                <?= csrfField() ?>
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label">Office / Establishment <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="coa_office" maxlength="200" required
                            value="<?= e(post('coa_office', '')) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Representative Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="coa_representative" maxlength="150" required
                            value="<?= e(post('coa_representative', '')) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Purpose of visit <span class="text-muted">(optional)</span></label>
                        <input type="text" class="form-control" name="coa_purpose" maxlength="300"
                            value="<?= e(post('coa_purpose', e($ob->purpose))) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">From <span class="text-danger">*</span></label>
                        <input type="time" class="form-control" name="coa_time_from" required value="<?= e(post('coa_time_from', '')) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">To <span class="text-danger">*</span></label>
                        <input type="time" class="form-control" name="coa_time_to" required value="<?= e(post('coa_time_to', '')) ?>">
                    </div>
                </div>

                <hr class="my-4">
                <label class="form-label fw-semibold">Representative Signature <span class="text-danger">*</span></label>
                <div class="border rounded bg-white position-relative" id="obSigPad" style="touch-action:none;">
                    <canvas id="obSigCanvas" class="w-100 d-block" style="height:160px; cursor:crosshair;"></canvas>
                    <span class="position-absolute text-muted small" style="top:6px; left:10px; pointer-events:none;">
                        Sign here
                    </span>
                </div>
                <div class="d-flex gap-2 mt-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="obSigClear"><i class="bi bi-eraser me-1"></i>Clear</button>
                </div>

                <hr class="my-4">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save Certificate</button>
                <a href="<?= APP_URL ?>/?page=ob-requests&action=view&id=<?= (int) $ob->id ?>" class="btn btn-outline-secondary">Cancel</a>
            </form>
        </div>
    </div>
</div>

<script src="<?= ASSETS_PATH ?>/js/ob-signature.js?v=<?= e(APP_VERSION) ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    ObSignature.init({ canvas: '#obSigCanvas', pad: '#obSigPad', clear: '#obSigClear' });
    document.getElementById('coaForm').addEventListener('submit', function (e) {
        if (ObSignature.isEmpty()) {
            e.preventDefault();
            alert('Please ask the representative to sign before saving.');
        } else {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'coa_signature';
            input.value = ObSignature.toDataUrl();
            this.appendChild(input);
        }
    });
});
</script>

<?php require_once INCLUDES_PATH . '/footer.php'; ?>
